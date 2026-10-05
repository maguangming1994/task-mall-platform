import { AppstoreOutlined, InfoCircleOutlined, LockOutlined } from '@ant-design/icons'
import {
  Alert,
  Button,
  Card,
  Drawer,
  Form,
  Input,
  Modal,
  Popconfirm,
  Segmented,
  Space,
  Table,
  Tag,
  Tooltip,
  Typography,
  message,
} from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  applyCampusModulePackage,
  getCampusModuleMatrix,
  updateCampusModule,
  verifyAdminRecent,
  type CampusModuleMatrix,
  type CampusModuleMatrixRow,
  type CampusModuleOverrideStatus,
  type CampusModulePackageCode,
  type ManagedCampus,
} from '../../api/admin'

type VerifyForm = { current_password: string; mfa_code?: string }

interface Props {
  open: boolean
  campus: ManagedCampus | null
  /** 是否具备 campus.module_manage：仅平台超管可写，学校代理只读 */
  canWrite: boolean
  requiresMfa?: boolean
  onClose: () => void
}

const STATUS_OPTIONS: Array<{ label: string; value: CampusModuleOverrideStatus }> = [
  { label: '继承全局', value: 'inherit' },
  { label: '开启', value: 'enabled' },
  { label: '关闭', value: 'disabled' },
]

function formatTime(value: string | null) {
  return value ? new Date(value).toLocaleString() : '—'
}

/** 全局开关状态标签（percentage 灰度以匿名预览为准） */
function GlobalTag({ global }: { global: CampusModuleMatrixRow['global'] }) {
  if (global.mode === 'percentage') {
    return <Tooltip title="按比例灰度：不同用户/设备可能不同，当前矩阵以匿名设备预览最终生效">
      <Tag color="processing">灰度 {global.rollout_percentage ?? 0}%</Tag>
    </Tooltip>
  }
  return global.enabled ? <Tag color="success">已开启</Tag> : <Tag>已关闭</Tag>
}

export function CampusModuleDrawer({ open, campus, canWrite, requiresMfa, onClose }: Props) {
  const [matrix, setMatrix] = useState<CampusModuleMatrix | null>(null)
  const [loading, setLoading] = useState(false)
  const [busyCode, setBusyCode] = useState<string | null>(null)
  const [busyPackage, setBusyPackage] = useState<CampusModulePackageCode | null>(null)
  // 需要二次身份核验时，暂存待重试的写操作
  const [pendingAction, setPendingAction] = useState<(() => Promise<CampusModuleMatrix>) | null>(null)
  const [verifyOpen, setVerifyOpen] = useState(false)
  const [verifying, setVerifying] = useState(false)
  const [verifyForm] = Form.useForm<VerifyForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const loadMatrix = useCallback(async (silent = false) => {
    if (!campus) return
    if (!silent) setLoading(true)
    try {
      setMatrix(await getCampusModuleMatrix(campus.id))
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '功能模块矩阵加载失败。')
    } finally {
      setLoading(false)
    }
  }, [campus, messageApi])

  useEffect(() => {
    if (open && campus) void loadMatrix()
    if (!open) {
      setMatrix(null)
      setPendingAction(null)
      setVerifyOpen(false)
    }
  }, [open, campus, loadMatrix])

  /**
   * 执行写操作：后端对高敏写挂了二次验证中间件，
   * 收到 ADMIN_RECENT_VERIFICATION_REQUIRED 时弹窗核验后自动重试。
   */
  const runWrite = useCallback(async (action: () => Promise<CampusModuleMatrix>) => {
    try {
      setMatrix(await action())
    } catch (error) {
      if (error instanceof ApiError && error.code === 'ADMIN_RECENT_VERIFICATION_REQUIRED') {
        setPendingAction(() => action)
        setVerifyOpen(true)
        verifyForm.setFieldsValue({ current_password: '', mfa_code: '' })
        return
      }
      messageApi.error(error instanceof ApiError ? error.message : '操作失败，请稍后重试。')
      // 乐观锁冲突等 422：重新拉取矩阵，让界面回到服务端最新状态
      if (error instanceof ApiError && error.status === 422) await loadMatrix(true)
    }
  }, [loadMatrix, messageApi, verifyForm])

  const changeModule = (row: CampusModuleMatrixRow, status: CampusModuleOverrideStatus) => {
    if (!campus || status === row.status) return
    setBusyCode(row.code)
    void runWrite(() => updateCampusModule(campus.id, row.code, { status, expected_version: row.version }))
      .finally(() => setBusyCode(null))
  }

  const applyPackage = (packageCode: CampusModulePackageCode) => {
    if (!campus) return
    setBusyPackage(packageCode)
    void runWrite(() => applyCampusModulePackage(campus.id, packageCode))
      .finally(() => setBusyPackage(null))
  }

  const confirmVerify = async (values: VerifyForm) => {
    if (!pendingAction) return
    setVerifying(true)
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      setMatrix(await pendingAction())
      setVerifyOpen(false)
      setPendingAction(null)
      messageApi.success('身份已核验，配置已生效。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '身份核验失败。')
    } finally {
      setVerifying(false)
    }
  }

  // 按分组（保持模块注册顺序）组织行
  const grouped = useMemo(() => {
    if (!matrix) return [] as Array<{ group: string; name: string; rows: CampusModuleMatrixRow[] }>
    const map = new Map<string, CampusModuleMatrixRow[]>()
    matrix.modules.forEach((row) => {
      const list = map.get(row.group) ?? []
      list.push(row)
      map.set(row.group, list)
    })
    return Array.from(map.entries()).map(([group, rows]) => ({ group, name: matrix.groups[group] ?? group, rows }))
  }, [matrix])

  const writeDisabled = !canWrite

  return <>
    {contextHolder}
    <Drawer
      title={<Space><AppstoreOutlined />{campus ? `功能模块 · ${campus.name}` : '功能模块'}</Space>}
      open={open}
      onClose={onClose}
      width={920}
      destroyOnHidden
    >
      <Alert
        showIcon
        type="info"
        style={{ marginBottom: 16 }}
        message="按学校、分阶段开放功能"
        description="「继承全局」跟随平台总开关；可单独为该校强制开启或关闭。平台总闸门关闭的模块，即使校区选择开启也不会生效。父模块关闭时，其全部子模块同步关闭。"
      />
      {writeDisabled && <Alert
        showIcon
        type="warning"
        icon={<LockOutlined />}
        style={{ marginBottom: 16 }}
        message="当前为只读视图"
        description="学校代理仅可查看本校功能配置；模块开关与阶段套餐需由平台超级管理员调整。"
      />}

      <Card size="small" variant="borderless" title="阶段套餐（一键配置全校功能节奏）" style={{ marginBottom: 16 }}>
        <Space wrap>
          {matrix?.packages.map((pkg) => <Popconfirm
            key={pkg.code}
            title={`应用「${pkg.name}」？`}
            description="将整体覆盖该校当前的单模块配置，可在应用后继续微调。"
            okText="应用套餐"
            cancelText="取消"
            disabled={writeDisabled}
            onConfirm={() => applyPackage(pkg.code)}
          >
            <Tooltip title={pkg.description}>
              <Button loading={busyPackage === pkg.code} disabled={writeDisabled}>
                <strong>{pkg.name}</strong>
                <Typography.Text type="secondary" style={{ marginLeft: 8, fontSize: 12 }}>{pkg.modules.length} 个模块</Typography.Text>
              </Button>
            </Tooltip>
          </Popconfirm>)}
        </Space>
      </Card>

      {grouped.map((section) => <Card
        key={section.group}
        size="small"
        variant="borderless"
        title={section.name}
        style={{ marginBottom: 12 }}
        loading={loading}
      >
        <Table<CampusModuleMatrixRow>
          rowKey="code"
          size="small"
          pagination={false}
          dataSource={section.rows}
          columns={[
            {
              title: '功能模块',
              dataIndex: 'name',
              width: 210,
              render: (_, row) => <div style={{ paddingLeft: row.parent ? 24 : 0 }}>
                {row.parent && <Typography.Text type="secondary" style={{ marginRight: 6 }}>└</Typography.Text>}
                <Typography.Text strong>{row.name}</Typography.Text>
                <div>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>{row.code}</Typography.Text>
                </div>
              </div>,
            },
            {
              title: '平台全局',
              width: 110,
              render: (_, row) => <GlobalTag global={row.global} />,
            },
            {
              title: '该校策略',
              width: 248,
              render: (_, row) => <Segmented
                size="small"
                value={row.status}
                options={STATUS_OPTIONS}
                disabled={writeDisabled || busyCode === row.code || busyPackage !== null}
                onChange={(value) => changeModule(row, value as CampusModuleOverrideStatus)}
              />,
            },
            {
              title: '最终生效',
              width: 100,
              render: (_, row) => row.effective
                ? <Tag color="success">已开放</Tag>
                : <Tooltip title={row.status === 'enabled' && !row.global.enabled ? '校区已开启，但平台总闸门未开启，仍不可用' : undefined}><Tag>{row.status === 'enabled' && !row.global.enabled && <InfoCircleOutlined style={{ marginInlineEnd: 4 }} />}未开放</Tag></Tooltip>,
            },
            {
              title: '最近调整',
              render: (_, row) => row.updated_by_admin
                ? <Space orientation="vertical" size={0}><Typography.Text style={{ fontSize: 12 }}>{row.updated_by_admin.display_name}</Typography.Text><Typography.Text type="secondary" style={{ fontSize: 12 }}>{formatTime(row.updated_at)}</Typography.Text></Space>
                : <Typography.Text type="secondary" style={{ fontSize: 12 }}>跟随全局</Typography.Text>,
            },
          ]}
        />
      </Card>)}
    </Drawer>

    <Modal
      title="配置变更前请再次核验身份"
      open={verifyOpen}
      onCancel={() => { setVerifyOpen(false); setPendingAction(null) }}
      onOk={() => verifyForm.submit()}
      confirmLoading={verifying}
      okText="核验并应用"
      cancelText="取消"
      destroyOnHidden
    >
      <Alert showIcon type="warning" style={{ marginBottom: 16 }} message="功能模块调整属于高风险操作，请输入当前管理员密码以继续。" />
      <Form form={verifyForm} layout="vertical" onFinish={confirmVerify}>
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        {requiresMfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true }]}>
          <Input autoComplete="one-time-code" />
        </Form.Item>}
      </Form>
    </Modal>
  </>
}
