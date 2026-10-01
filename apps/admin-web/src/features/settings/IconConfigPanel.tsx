import { DeleteOutlined, EditOutlined, PlusOutlined, ReloadOutlined, SafetyCertificateOutlined, SearchOutlined } from '@ant-design/icons'
import {
  Alert,
  Button,
  Card,
  Empty,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Select,
  Space,
  Switch,
  Tabs,
  Tag,
  Tooltip,
  Typography,
  message,
} from 'antd'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ApiError,
  createIconConfig,
  deleteIconConfig,
  listIconConfigs,
  updateIconConfig,
  verifyAdminRecent,
  type IconConfigCategory,
  type IconConfigItem,
  type IconConfigTone,
} from '../../api/admin'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'

// 小程序包内内置 SVG，构建后台时一并纳入以提供实时预览
const builtinIcons = import.meta.glob('../../../../user-app/src/static/icons/*.svg', {
  eager: true,
  query: '?url',
  import: 'default',
}) as Record<string, string>

const categoryOptions: Array<{ value: IconConfigCategory; label: string }> = [
  { value: 'tabbar', label: '底部导航' },
  { value: 'module', label: '功能模块' },
  { value: 'order', label: '订单状态' },
  { value: 'community', label: '校园社区' },
  { value: 'service', label: '常用服务' },
  { value: 'common', label: '通用操作' },
]

const categoryLabel: Record<IconConfigCategory, string> = Object.fromEntries(
  categoryOptions.map((item) => [item.value, item.label]),
) as Record<IconConfigCategory, string>

const toneMap: Record<IconConfigTone, { label: string; bg: string; color: string }> = {
  default: { label: '墨灰', bg: '#F2F5F8', color: '#5A6B7B' },
  yellow: { label: '暖黄', bg: '#FDF1DE', color: '#E8920C' },
  cyan: { label: '青碧', bg: '#E2F7F3', color: '#0FA3B1' },
  green: { label: '成长绿', bg: '#E7F6EF', color: '#0B7A5C' },
  blue: { label: '信息蓝', bg: '#EAF2FE', color: '#2F7DE1' },
  purple: { label: '活力紫', bg: '#F0EDFE', color: '#7656E8' },
  red: { label: '警示红', bg: '#FDECEC', color: '#E5484D' },
}

const toneOptions = (Object.keys(toneMap) as IconConfigTone[]).map((value) => ({ value, ...toneMap[value] }))

type EditorValues = {
  code: string
  name: string
  category: IconConfigCategory
  svg_path: string
  fallback_char: string
  label: string
  description: string
  tone: IconConfigTone
  sort_order: number
  is_active: boolean
}

function resolveIconUrl(svgPath: string | null | undefined): string | undefined {
  if (!svgPath) return undefined
  if (/^https?:\/\//i.test(svgPath) || svgPath.startsWith('data:')) return svgPath
  const match = svgPath.match(/([^/]+\.svg)$/i)
  if (!match) return undefined
  const key = Object.keys(builtinIcons).find((candidate) => candidate.endsWith(`/${match[1]}`))
  return key ? builtinIcons[key] : undefined
}

function IconPreview({ svgPath, fallbackChar, tone, size = 40 }: {
  svgPath: string | null | undefined
  fallbackChar?: string | null
  tone?: IconConfigTone | null
  size?: number
}) {
  const url = resolveIconUrl(svgPath)
  const palette = toneMap[tone ?? 'default'] ?? toneMap.default
  return <span
    style={{
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: size,
      height: size,
      borderRadius: size * 0.3,
      background: palette.bg,
      color: palette.color,
      flex: 'none',
      overflow: 'hidden',
    }}
  >
    {url
      ? <img src={url} alt="图标预览" style={{ width: size * 0.62, height: size * 0.62, objectFit: 'contain' }} />
      : <strong style={{ fontSize: size * 0.4, lineHeight: 1 }}>{fallbackChar || '?'}</strong>}
  </span>
}

function SvgPathField({ value = '', onChange }: { value?: string; onChange?: (value: string) => void }) {
  return <Space direction="vertical" style={{ width: '100%' }} size={8}>
    <Input
      placeholder="小程序包内路径（/static/icons/xxx.svg）或 HTTPS 链接"
      value={value}
      onChange={(event) => onChange?.(event.target.value)}
    />
    <MediaGalleryInput
      value={/^https?:\/\//i.test(value) ? [{ url: value }] : []}
      max={1}
      primaryLabel="图标图形"
      helperText="上传 SVG/PNG 后自动填充链接；包内内置图标无需上传，保留路径即可。"
      onChange={(images) => onChange?.(images[0]?.url ?? '')}
    />
  </Space>
}

const emptyForm: EditorValues = {
  code: '',
  name: '',
  category: 'service',
  svg_path: '',
  fallback_char: '',
  label: '',
  description: '',
  tone: 'default',
  sort_order: 100,
  is_active: true,
}

export function IconConfigPanel() {
  const [items, setItems] = useState<IconConfigItem[]>([])
  const [loading, setLoading] = useState(true)
  const [activeCategory, setActiveCategory] = useState<IconConfigCategory | 'all'>('all')
  const [keyword, setKeyword] = useState('')
  const [editing, setEditing] = useState<IconConfigItem | 'new' | null>(null)
  const [saving, setSaving] = useState(false)
  const [form] = Form.useForm<EditorValues>()
  const [messageApi, contextHolder] = message.useMessage()

  const [verifyOpen, setVerifyOpen] = useState(false)
  const [verifyLoading, setVerifyLoading] = useState(false)
  const [verifyForm] = Form.useForm<{ current_password: string; mfa_code?: string }>()
  const pendingWrite = useRef<null | (() => Promise<void>)>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await listIconConfigs()
      setItems(data.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '图标配置加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => {
    void load()
  }, [load])

  const filtered = useMemo(() => {
    const word = keyword.trim().toLowerCase()
    return items.filter((item) => {
      if (activeCategory !== 'all' && item.category !== activeCategory) return false
      if (!word) return true
      return [item.code, item.name, item.label, item.description]
        .some((value) => String(value ?? '').toLowerCase().includes(word))
    })
  }, [activeCategory, items, keyword])

  const counts = useMemo(() => {
    const result = new Map<string, number>()
    items.forEach((item) => result.set(item.category, (result.get(item.category) ?? 0) + 1))
    return result
  }, [items])

  const openEditor = (item: IconConfigItem | 'new') => {
    setEditing(item)
    if (item === 'new') {
      form.setFieldsValue(emptyForm)
    } else {
      form.setFieldsValue({
        code: item.code,
        name: item.name,
        category: item.category,
        svg_path: item.svg_path,
        fallback_char: item.fallback_char ?? '',
        label: item.label ?? '',
        description: item.description ?? '',
        tone: item.tone ?? 'default',
        sort_order: item.sort_order,
        is_active: item.is_active,
      })
    }
  }

  const runWithVerification = useCallback(async (action: () => Promise<void>) => {
    try {
      await action()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'ADMIN_RECENT_VERIFICATION_REQUIRED') {
        pendingWrite.current = action
        setVerifyOpen(true)
      } else {
        throw error
      }
    }
  }, [])

  const submitVerify = async () => {
    try {
      const values = await verifyForm.validateFields()
      setVerifyLoading(true)
      await verifyAdminRecent(values.current_password, values.mfa_code)
      messageApi.success('身份验证通过，正在继续操作。')
      setVerifyOpen(false)
      verifyForm.resetFields()
      const action = pendingWrite.current
      pendingWrite.current = null
      if (action) await action()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setVerifyLoading(false)
    }
  }

  const handleSave = async () => {
    const values = await form.validateFields()
    setSaving(true)
    const payload = {
      ...values,
      fallback_char: values.fallback_char || null,
      label: values.label || null,
      description: values.description || null,
    }
    try {
      await runWithVerification(async () => {
        if (editing === 'new') {
          await createIconConfig(payload)
          messageApi.success('图标配置已创建。')
        } else if (editing) {
          const { code: _code, ...updatePayload } = payload
          void _code
          await updateIconConfig(editing.id, updatePayload)
          messageApi.success('图标配置已更新，用户端下次进入时生效。')
        }
        setEditing(null)
        await load()
      })
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '保存失败，请稍后重试。')
    } finally {
      setSaving(false)
    }
  }

  const handleToggleActive = async (item: IconConfigItem, checked: boolean) => {
    try {
      await runWithVerification(async () => {
        await updateIconConfig(item.id, { is_active: checked })
        setItems((prev) => prev.map((entry) => entry.id === item.id ? { ...entry, is_active: checked } : entry))
        messageApi.success(checked ? `「${item.name}」已启用。` : `「${item.name}」已停用。`)
      })
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '操作失败，请稍后重试。')
    }
  }

  const handleDelete = async (item: IconConfigItem) => {
    try {
      await runWithVerification(async () => {
        await deleteIconConfig(item.id)
        messageApi.success(`「${item.name}」已删除。`)
        await load()
      })
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '删除失败，请稍后重试。')
    }
  }

  const tabItems = [
    { key: 'all', label: `全部 ${items.length}` },
    ...categoryOptions.map((option) => ({
      key: option.value,
      label: `${option.label} ${counts.get(option.value) ?? 0}`,
    })),
  ]

  const watchSvgPath = Form.useWatch('svg_path', form)
  const watchFallback = Form.useWatch('fallback_char', form)
  const watchTone = Form.useWatch('tone', form) as IconConfigTone | undefined

  return <>
    {contextHolder}
    <Card
      bordered={false}
      className="settings-table-card"
      title={<Space>
        <span>小程序图标与文案</span>
        <Tag color="green">{items.length} 个图标</Tag>
      </Space>}
      extra={<Space wrap>
        <Tooltip title="重新从服务器拉取配置">
          <Button icon={<ReloadOutlined />} onClick={() => void load()} loading={loading}>刷新</Button>
        </Tooltip>
        <Button type="primary" icon={<PlusOutlined />} onClick={() => openEditor('new')}>新增图标</Button>
      </Space>}
    >
      <Alert
        showIcon
        type="info"
        style={{ marginBottom: 16 }}
        message="这里统一管理小程序内全部图标的图形、展示文字、说明和配色。"
        description="内置图标可直接改文字/配色/替换图形；建议上传正方形 SVG（24×24 基准、描边线性风格），也支持素材库中的 PNG 或 HTTPS 外链。停用后该位置不展示，删除内置图标会回退为字符占位。"
      />
      <Tabs
        activeKey={activeCategory}
        onChange={(key) => setActiveCategory(key as IconConfigCategory | 'all')}
        items={tabItems}
        style={{ marginBottom: 8 }}
      />
      <Input
        allowClear
        prefix={<SearchOutlined />}
        placeholder="搜索图标名称、编码或展示文字"
        value={keyword}
        onChange={(event) => setKeyword(event.target.value)}
        style={{ maxWidth: 320, marginBottom: 16 }}
      />
      {filtered.length === 0 && !loading
        ? <Empty description={keyword ? '没有匹配的图标，换个关键词试试' : '该分类下暂无图标'} style={{ padding: '32px 0' }} />
        : <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(240px,1fr))', gap: 12 }}>
          {filtered.map((item) => {
            const palette = toneMap[item.tone ?? 'default'] ?? toneMap.default
            return <Card
              key={item.id}
              size="small"
              styles={{ body: { padding: 14 } }}
              style={{ opacity: item.is_active ? 1 : 0.62 }}
            >
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                <IconPreview svgPath={item.svg_path} fallbackChar={item.fallback_char} tone={item.tone} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                    <Typography.Text strong ellipsis style={{ maxWidth: 110 }}>{item.name}</Typography.Text>
                    <Switch size="small" checked={item.is_active} checkedChildren="启" unCheckedChildren="停" onChange={(checked) => void handleToggleActive(item, checked)} />
                  </div>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }} ellipsis>
                    <code>{item.code}</code>
                  </Typography.Text>
                </div>
              </div>
              <div style={{ marginTop: 10, minHeight: 34 }}>
                <Space size={4} wrap>
                  <Typography.Text style={{ fontSize: 13 }}>{item.label || '—'}</Typography.Text>
                  {item.description && <Typography.Text type="secondary" style={{ fontSize: 12 }}>· {item.description}</Typography.Text>}
                </Space>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 }}>
                <Space size={6}>
                  <Tag style={{ marginInlineEnd: 0, color: palette.color, background: palette.bg, borderColor: 'transparent' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                      <i style={{ width: 8, height: 8, borderRadius: '50%', background: palette.color, display: 'inline-block' }} />
                      {palette.label}
                    </span>
                  </Tag>
                  <Tag>{categoryLabel[item.category]}</Tag>
                </Space>
                <Space size={2}>
                  <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEditor(item)}>编辑</Button>
                  <Popconfirm
                    title="删除该图标？"
                    description="删除后小程序对应位置将回退为字符占位。"
                    okText="确认删除"
                    okButtonProps={{ danger: true }}
                    cancelText="取消"
                    onConfirm={() => void handleDelete(item)}
                  >
                    <Button type="text" size="small" danger icon={<DeleteOutlined />} />
                  </Popconfirm>
                </Space>
              </div>
            </Card>
          })}
        </div>}
    </Card>

    <Modal
      title={editing === 'new' ? '新增图标配置' : editing ? `编辑图标：${editing.name}` : '编辑图标'}
      open={Boolean(editing)}
      onCancel={() => setEditing(null)}
      onOk={() => void handleSave()}
      confirmLoading={saving}
      okText="保存"
      cancelText="取消"
      width={620}
      destroyOnHidden
      styles={{ body: { maxHeight: '72vh', overflowY: 'auto', paddingRight: 8 } }}
    >
      <Form form={form} layout="vertical" requiredMark={false} initialValues={emptyForm}>
        <div style={{ display: 'flex', gap: 16, alignItems: 'center', padding: 12, marginBottom: 16, background: '#F7F9FC', borderRadius: 10 }}>
          <IconPreview svgPath={watchSvgPath} fallbackChar={watchFallback} tone={watchTone} size={56} />
          <Typography.Text type="secondary" style={{ fontSize: 12.5 }}>
            实时预览：小程序将以此图形、底色和文字展示。推荐 SVG 正方形画布、描边线性风格。
          </Typography.Text>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 16' }}>
          <Form.Item label="图标编码" name="code" rules={[
            { required: true, message: '请输入唯一编码' },
            { pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/, message: '仅支持小写字母、数字和中划线，例如 service-wallet' },
          ]} tooltip="小程序通过该编码读取配置，创建后不可修改">
            <Input placeholder="例如 service-wallet" disabled={editing !== 'new'} />
          </Form.Item>
          <Form.Item label="图标名称" name="name" rules={[{ required: true, message: '请输入图标名称' }, { max: 32 }]}>
            <Input placeholder="后台识别用，例如 校园钱包" />
          </Form.Item>
          <Form.Item label="所属分类" name="category" rules={[{ required: true }]}>
            <Select options={categoryOptions} disabled={editing !== 'new'} />
          </Form.Item>
          <Form.Item label="配色" name="tone">
            <Select
              options={toneOptions.map((tone) => ({
                value: tone.value,
                label: <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                  <i style={{ width: 12, height: 12, borderRadius: 4, background: tone.bg, border: `1px solid ${tone.color}`, display: 'inline-block' }} />
                  {tone.label}
                </span>,
              }))}
            />
          </Form.Item>
          <Form.Item label="展示文字" name="label" rules={[{ max: 32 }]} tooltip="小程序中图标下方/旁边显示的文字">
            <Input placeholder="例如 钱包" />
          </Form.Item>
          <Form.Item label="文字说明" name="description" rules={[{ max: 64 }]}>
            <Input placeholder="一句话说明，例如 余额与积分管理" />
          </Form.Item>
          <Form.Item label="排序" name="sort_order" tooltip="数字越小越靠前">
            <InputNumber min={0} max={9999} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item label="字符占位" name="fallback_char" rules={[{ max: 8 }]} tooltip="图形加载失败或删除图标时显示的兜底字符">
            <Input placeholder="例如 ◈" />
          </Form.Item>
        </div>

        <Form.Item label="启用该图标" name="is_active" valuePropName="checked">
          <Switch checkedChildren="启用" unCheckedChildren="停用" />
        </Form.Item>

        <Form.Item
          label="图标图形"
          name="svg_path"
          rules={[{ required: true, message: '请上传图标或填写图标路径（/static/icons/xxx.svg 或 HTTPS 链接）' }]}
        >
          <SvgPathField />
        </Form.Item>
      </Form>
    </Modal>

    <Modal
      title={<Space><SafetyCertificateOutlined style={{ color: '#f08a24' }} />管理员身份二次验证</Space>}
      open={verifyOpen}
      onCancel={() => { setVerifyOpen(false); pendingWrite.current = null }}
      onOk={() => void submitVerify()}
      confirmLoading={verifyLoading}
      okText="验证并继续"
      cancelText="取消"
      destroyOnHidden
    >
      <Alert
        showIcon
        type="warning"
        style={{ marginBottom: 16 }}
        message="图标配置属于线上展示内容，需要近期完成管理员身份验证。"
      />
      <Form form={verifyForm} layout="vertical">
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true, message: '请输入当前管理员密码' }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        <Form.Item name="mfa_code" label="MFA 验证码（已启用 MFA 时填写）">
          <Input inputMode="numeric" maxLength={6} placeholder="6 位动态码" />
        </Form.Item>
      </Form>
    </Modal>
  </>
}
