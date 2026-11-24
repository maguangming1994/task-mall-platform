import { Alert, Form, Input, Modal, message } from 'antd'
import { useCallback, useRef, useState } from 'react'
import { ApiError, verifyAdminRecent } from '../../api/admin'

interface VerifyFormValues {
  current_password: string
  mfa_code?: string
}

interface PendingAction {
  action: () => Promise<unknown>
  resolve: (value: unknown) => void
}

/** 高敏写操作收到近期核验要求时，核验身份并使用原操作（含原幂等键）重试。 */
export function useRobotRecentVerification(requiresMfa: boolean) {
  const [messageApi, messageContext] = message.useMessage()
  const [verifyForm] = Form.useForm<VerifyFormValues>()
  const [verifyOpen, setVerifyOpen] = useState(false)
  const [verifying, setVerifying] = useState(false)
  const pendingRef = useRef<PendingAction | null>(null)

  const clearPending = useCallback(() => {
    pendingRef.current = null
    setVerifyOpen(false)
    verifyForm.resetFields()
  }, [verifyForm])

  const runWrite = useCallback(async <T,>(action: () => Promise<T>, failureMessage = '操作失败，请稍后重试。'): Promise<T | undefined> => {
    if (pendingRef.current) {
      messageApi.warning('请先完成或取消当前身份核验。')
      return undefined
    }
    try {
      return await action()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'ADMIN_RECENT_VERIFICATION_REQUIRED') {
        return new Promise<T | undefined>((resolve) => {
          pendingRef.current = {
            action: action as () => Promise<unknown>,
            resolve: (value) => resolve(value as T | undefined),
          }
          verifyForm.setFieldsValue({ current_password: '', mfa_code: '' })
          setVerifyOpen(true)
        })
      }
      messageApi.error(error instanceof ApiError ? error.message : failureMessage)
      return undefined
    }
  }, [messageApi, verifyForm])

  const confirmVerify = async (values: VerifyFormValues) => {
    const pending = pendingRef.current
    if (!pending) return
    setVerifying(true)
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code?.trim() || undefined)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '身份核验失败。')
      setVerifying(false)
      return
    }

    try {
      const result = await pending.action()
      pending.resolve(result)
      clearPending()
      messageApi.success('身份已核验，操作已继续。')
    } catch (error) {
      pending.resolve(undefined)
      clearPending()
      messageApi.error(error instanceof ApiError ? error.message : '身份已核验，但原操作未完成。')
    } finally {
      setVerifying(false)
    }
  }

  const cancelVerify = () => {
    if (verifying) return
    pendingRef.current?.resolve(undefined)
    clearPending()
  }

  const verificationContext = <>
    {messageContext}
    <Modal
      title="操作前请再次核验身份"
      open={verifyOpen}
      onCancel={cancelVerify}
      onOk={() => verifyForm.submit()}
      confirmLoading={verifying}
      cancelButtonProps={{ disabled: verifying }}
      maskClosable={!verifying}
      okText="核验并继续"
      cancelText="取消"
      destroyOnHidden
    >
      <Alert showIcon type="warning" message="该操作会修改机器人配置或提交测试消息，请输入当前管理员密码以继续。" style={{ marginBottom: 16 }} />
      <Form<VerifyFormValues> form={verifyForm} layout="vertical" onFinish={(values) => void confirmVerify(values)}>
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true, message: '请输入当前管理员密码。' }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        {requiresMfa ? <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true, message: '请输入动态验证码或恢复码。' }]}>
          <Input autoComplete="one-time-code" />
        </Form.Item> : null}
      </Form>
    </Modal>
  </>

  return { runWrite, verificationContext }
}
