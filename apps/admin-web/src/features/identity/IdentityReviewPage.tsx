import { Alert, Avatar, Button, Card, Form, Input, Modal, Radio, Space, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  listIdentityVerifications,
  reviewIdentityVerification,
  type IdentityVerificationRecord,
} from '../../api/admin'

type ReviewDecision = 'approve' | 'reject'

interface IdentityReviewForm {
  decision: ReviewDecision
  note?: string
}

const REJECT_NOTE_PRESETS = [
  '证件信息模糊或与本人不符，请核对后重新提交',
  '提交材料缺失或不完整，请按提示补全后再次申请',
  '检测到冒用他人身份信息，请本人使用真实证件申请',
]

const statusMeta: Record<string, { label: string; color: string }> = {
  pending: { label: '待审核', color: 'gold' },
  verified: { label: '已通过', color: 'success' },
  failed: { label: '未通过', color: 'error' },
  provider_error: { label: '服务商异常', color: 'warning' },
}

const failureLabels: Record<string, string> = {
  manual_rejected: '人工审核驳回',
  name_mismatch: '姓名不一致',
  id_number_invalid: '证件号无效',
  provider_error: '服务商核验异常',
}

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'
}

function compactError(error: unknown, fallback: string) {
  return error instanceof ApiError || error instanceof Error ? error.message : fallback
}

export function IdentityReviewPage() {
  const [records, setRecords] = useState<IdentityVerificationRecord[]>([])
  const [pagination, setPagination] = useState({ page: 1, page_size: 20, total: 0 })
  const [status, setStatus] = useState('pending')
  const [loading, setLoading] = useState(false)
  const [reviewing, setReviewing] = useState<IdentityVerificationRecord | null>(null)
  const [busy, setBusy] = useState(false)
  const [form] = Form.useForm<IdentityReviewForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const decision = Form.useWatch('decision', form)

  const load = useCallback(async (page: number, nextStatus: string) => {
    setLoading(true)
    try {
      const result = await listIdentityVerifications({ status: nextStatus, page, page_size: 20 })
      setRecords(result.items)
      setPagination({ page: result.pagination.page, page_size: result.pagination.page_size, total: result.pagination.total })
    } catch (error) {
      messageApi.error(compactError(error, '实名认证列表加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load(1, status) }, [load, status])

  const submit = async (values: IdentityReviewForm) => {
    if (!reviewing) return
    setBusy(true)
    try {
      await reviewIdentityVerification(reviewing.id, {
        decision: values.decision,
        note: values.note?.trim() || undefined,
      })
      messageApi.success(values.decision === 'approve' ? '已通过实名认证，站内通知已发送。' : '已驳回该实名认证申请。')
      setReviewing(null)
      form.resetFields()
      await load(pagination.page, status)
    } catch (error) {
      const code = error instanceof ApiError ? error.code : ''
      if (code === 'REVIEW_ALREADY_FINISHED') {
        messageApi.warning('该申请已被其他审核员处理，列表已刷新。')
        setReviewing(null)
        await load(pagination.page, status)
        return
      }
      messageApi.error(compactError(error, '审核操作失败，请稍后重试。'))
    } finally {
      setBusy(false)
    }
  }

  return <>
    {contextHolder}
    <div className="page-heading">
      <div>
        <Typography.Text className="eyebrow">IDENTITY VERIFICATION</Typography.Text>
        <Typography.Title level={2}>实名认证审核</Typography.Title>
        <Typography.Paragraph type="secondary">人工复核学生提交的实名信息；页面仅展示脱敏信息，审核结论会通过站内通知同步给本人，全过程记录审计日志。</Typography.Paragraph>
      </div>
      <Space wrap><Tag color="gold">人工复核</Tag><Tag color="processing">信息脱敏</Tag></Space>
    </div>

    <Card variant="borderless">
      <Tabs items={[
        {
          key: 'pending',
          label: '待审核',
          children: null,
        },
        {
          key: 'verified',
          label: '已通过',
          children: null,
        },
        {
            key: 'failed',
            label: '未通过',
            children: null,
          },
          {
            key: 'provider_error',
            label: '服务商异常',
            children: null,
          },
      ]} activeKey={status} onChange={setStatus}
        tabBarExtraContent={<Button onClick={() => void load(pagination.page, status)}>刷新</Button>}
      />
      <Table
        rowKey="id"
        loading={loading}
        dataSource={records}
        scroll={{ x: 1080 }}
        locale={{ emptyText: '当前没有符合条件的实名认证记录' }}
        pagination={{
          current: pagination.page,
          pageSize: pagination.page_size,
          total: pagination.total,
          showSizeChanger: false,
          onChange: (page) => void load(page, status),
        }}
        columns={[
          {
            title: '用户',
            width: 220,
            render: (_: unknown, row: IdentityVerificationRecord) => row.user
              ? <Space>
                {/* avatar 字段为文件 ID 而非可访问 URL，仅展示昵称首字，避免裂图 */}
                <Avatar>{row.user.nickname.slice(0, 1)}</Avatar>
                <Space direction="vertical" size={0}>
                  <strong>{row.user.nickname}</strong>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>UID: {row.user.id}</Typography.Text>
                </Space>
              </Space>
              : <Tag>用户不可见或已注销</Tag>,
          },
          { title: '姓名（脱敏）', width: 140, dataIndex: 'real_name_masked', render: (value: string | null) => value ?? '—' },
          { title: '证件号（脱敏）', width: 200, dataIndex: 'identity_number_masked', render: (value: string | null) => value ?? '—' },
          { title: '认证渠道', width: 110, dataIndex: 'provider' },
          {
            title: '状态',
            width: 120,
            render: (_: unknown, row: IdentityVerificationRecord) => {
              const meta = statusMeta[row.status] ?? { label: row.status, color: 'default' }
              return <Tag color={meta.color}>{meta.label}</Tag>
            },
          },
          {
            title: '失败原因',
            width: 140,
            render: (_: unknown, row: IdentityVerificationRecord) => row.failure_code
              ? <Tag color={row.failure_code === 'manual_rejected' ? 'error' : 'warning'}>{failureLabels[row.failure_code] ?? row.failure_code}</Tag>
              : '—',
          },
          { title: '提交时间', width: 170, render: (_: unknown, row: IdentityVerificationRecord) => formatDate(row.submitted_at) },
          {
            title: '审核留痕',
            width: 200,
            render: (_: unknown, row: IdentityVerificationRecord) => row.status === 'pending'
              ? <Typography.Text type="secondary">待审核</Typography.Text>
              : <Space direction="vertical" size={0}>
                  <Typography.Text style={{ fontSize: 12 }}>{row.reviewed_by_name ?? '系统'}</Typography.Text>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>{formatDate(row.reviewed_at || row.verified_at)}</Typography.Text>
                </Space>,
          },
          {
            title: '操作',
            width: 110,
            fixed: 'right',
            render: (_: unknown, row: IdentityVerificationRecord) => row.status === 'pending'
              ? <Button size="small" type="primary" onClick={() => { form.setFieldsValue({ decision: 'approve', note: '' }); setReviewing(row) }}>审核</Button>
              : <Button size="small" onClick={() => setReviewing(row)}>查看</Button>,
          },
        ]}
      />
    </Card>

    <Modal
      title={reviewing?.status === 'pending' ? `实名审核：${reviewing?.real_name_masked ?? ''}` : `审核记录：${reviewing?.real_name_masked ?? ''}`}
      open={reviewing !== null}
      onCancel={() => { setReviewing(null); form.resetFields() }}
      onOk={() => reviewing?.status === 'pending' ? form.submit() : undefined}
      okText="提交审核"
      okButtonProps={{ danger: decision === 'reject' }}
      confirmLoading={busy}
      cancelText="关闭"
      destroyOnHidden
    >
      {reviewing && <Space direction="vertical" size={12} style={{ width: '100%' }}>
        {reviewing.status !== 'pending' && <Alert
          showIcon
          type={reviewing.status === 'verified' ? 'success' : 'error'}
          message={reviewing.status === 'verified'
            ? '该实名申请已通过'
            : reviewing.status === 'provider_error' ? '核验服务商异常，用户可稍后重新申请' : '该实名申请未通过'}
          description={reviewing.review_note ? `审核说明：${reviewing.review_note}` : undefined}
        />}
        <Typography.Text>用户：{reviewing.user ? `${reviewing.user.nickname}（UID: ${reviewing.user.id}）` : '用户不可见或已注销'}</Typography.Text>
        <Typography.Text>姓名：{reviewing.real_name_masked ?? '—'}</Typography.Text>
        <Typography.Text>证件号：{reviewing.identity_number_masked ?? '—'}</Typography.Text>
        <Typography.Text type="secondary">提交时间：{formatDate(reviewing.submitted_at)} · 渠道：{reviewing.provider}</Typography.Text>
        {reviewing.status !== 'pending' && <Typography.Text type="secondary">审核人：{reviewing.reviewed_by_name ?? '系统'} · {formatDate(reviewing.reviewed_at || reviewing.verified_at)}</Typography.Text>}

        {reviewing.status === 'pending' && <Form form={form} layout="vertical" onFinish={submit} style={{ marginTop: 8 }}>
          <Form.Item name="decision" label="审核结论" rules={[{ required: true, message: '请选择审核结论' }]}>
            <Radio.Group options={[{ value: 'approve', label: '核验一致，通过' }, { value: 'reject', label: '信息不符，驳回' }]} optionType="button" buttonStyle="solid" />
          </Form.Item>
          {decision === 'reject' && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="驳回后用户可在小程序内查看原因并重新提交；请确保原因具体、可操作。" />}
          <Form.Item
            name="note"
            label={decision === 'reject' ? '驳回说明（必填）' : '审核备注（可选）'}
            rules={decision === 'reject'
              ? [{ required: true, min: 5, message: '请填写至少 5 个字的驳回说明' }]
              : [{ max: 500, message: '备注不超过 500 字' }]}
            extra={decision === 'reject' ? <Space size={[8, 8]} wrap style={{ marginTop: 8 }}>
              {REJECT_NOTE_PRESETS.map((preset) => <Tag.CheckableTag
                key={preset}
                checked={form.getFieldValue('note') === preset}
                style={{ border: '1px solid #ffd8bf', background: '#fff7e6', padding: '2px 10px', fontSize: 12 }}
                onChange={() => form.setFieldValue('note', preset)}
              >{preset.split('，')[0]}</Tag.CheckableTag>)}
            </Space> : null}
          >
            <Input.TextArea rows={4} maxLength={500} showCount placeholder={decision === 'reject' ? '说明会展示给用户，例如：证件信息模糊或与本人不符，请核对后重新提交' : '仅内部记录，不会展示给用户'} />
          </Form.Item>
        </Form>}
      </Space>}
    </Modal>
  </>
}
