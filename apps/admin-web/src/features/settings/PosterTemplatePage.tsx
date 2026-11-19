import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tabs, Tag, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { createPosterTemplate, deletePosterTemplate, listPosterTemplates, updatePosterTemplate, type PosterDateRule, type PosterTemplateInput, type PosterTemplateRow } from '../../api/poster'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'
import { Links, PageHead, Pill } from '../../components/xq'

const CODE_OPTIONS = [
  { value: 'community_post', label: '社区帖子分享 (community_post)' },
  { value: 'material_share', label: '资料分享 (material_share)' },
]

const BG_TYPE_OPTIONS = [
  { value: 'gradient', label: '渐变（gradient）' },
  { value: 'image', label: '背景图（image）' },
  { value: 'solid', label: '纯色（solid）' },
]

const WEEKDAY_LABELS: Record<number, string> = { 1: '周一', 2: '周二', 3: '周三', 4: '周四', 5: '周五', 6: '周六', 7: '周日' }

function defaultInput(): PosterTemplateInput {
  return {
    code: 'community_post',
    name: '',
    description: '',
    canvas_width: 330,
    canvas_height: 520,
    background_type: 'gradient',
    background_value: { stops: ['#0e799d', '#19c6d1', '#76df63'], angle: 135 },
    banner_config: null,
    body_config: null,
    qrcode_config: null,
    date_rule: null,
    priority: 0,
    is_default: false,
    is_active: true,
  }
}

function formatDateRule(rule: PosterDateRule | null): string {
  if (!rule) return '全局（无日期规则）'
  if (rule.mode === 'range' && rule.ranges?.length) {
    return rule.ranges.map((r) => `${r.start} ~ ${r.end}`).join('；')
  }
  if (rule.mode === 'monthday' && rule.monthdays?.length) {
    return `每年固定日：${rule.monthdays.join('、')}`
  }
  if (rule.mode === 'weekday' && rule.weekdays?.length) {
    return `每周 ${rule.weekdays.map((d) => WEEKDAY_LABELS[d]).join('、')}`
  }
  return `${rule.mode}`
}

function renderBgPreview(type: string, value: Record<string, unknown> | null) {
  if (type === 'gradient') {
    const stops = (value?.stops as string[]) ?? ['#0e799d', '#19c6d1', '#76df63']
    const css = `linear-gradient(135deg, ${stops.join(', ')})`
    return <div style={{ width: 80, height: 50, borderRadius: 6, background: css }} />
  }
  if (type === 'image' && value?.image_url) {
    return <img src={String(value.image_url)} alt="背景" style={{ width: 80, height: 50, borderRadius: 6, objectFit: 'cover' }} />
  }
  if (type === 'solid') {
    return <div style={{ width: 80, height: 50, borderRadius: 6, background: String(value?.color ?? '#ffffff'), border: '1px solid #e8e8e8' }} />
  }
  return <div style={{ width: 80, height: 50, borderRadius: 6, background: '#f5f5f5', border: '1px solid #e8e8e8' }} />
}

function backgroundConfigPanel({ value, onChange }: { value: Record<string, unknown> | null; onChange: (next: Record<string, unknown> | null) => void }) {
  const current = value ?? {}
  const stops = Array.isArray(current.stops) ? (current.stops as string[]) : ['#0e799d', '#19c6d1', '#76df63']
  return <Space direction="vertical" style={{ width: '100%' }} size="small">
    <Typography.Text strong>渐变色（按顺序绘制）</Typography.Text>
    {stops.map((color, index) => (
      <div key={index} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <Input
          value={color}
          onChange={(e) => {
            const next = [...stops]
            next[index] = e.target.value.trim()
            onChange({ ...current, stops: next })
          }}
          placeholder="#0e799d"
          maxLength={16}
          style={{ width: 160 }}
        />
        <input
          type="color"
          value={color.startsWith('#') ? color : '#ffffff'}
          onChange={(e) => {
            const next = [...stops]
            next[index] = e.target.value
            onChange({ ...current, stops: next })
          }}
          style={{ width: 36, height: 32, padding: 0, border: '1px solid #d9d9d9', borderRadius: 4, cursor: 'pointer' }}
        />
        {stops.length > 2 && (
          <Button size="small" danger onClick={() => onChange({ ...current, stops: stops.filter((_, i) => i !== index) })}>移除</Button>
        )}
      </div>
    ))}
    <Button size="small" onClick={() => onChange({ ...current, stops: [...stops, '#ffffff'] })}>添加颜色</Button>
    <Alert showIcon type="info" message="最少需要 2 个颜色；顺序从画布左上角到右下角渐变。" />
  </Space>
}

function DateRulePanel({ value, onChange }: { value: PosterDateRule | null; onChange: (next: PosterDateRule | null) => void }) {
  const [mode, setMode] = useState<'none' | 'range' | 'monthday' | 'weekday'>('none')

  useEffect(() => {
    if (value === null || value === undefined) setMode('none')
    else setMode(value.mode as 'range' | 'monthday' | 'weekday')
  }, [value])

  const applyMode = (nextMode: 'none' | 'range' | 'monthday' | 'weekday') => {
    setMode(nextMode)
    if (nextMode === 'none') {
      onChange(null)
    } else if (nextMode === 'range') {
      onChange({ mode: 'range', ranges: value?.ranges ?? [{ start: '', end: '' }] })
    } else if (nextMode === 'monthday') {
      onChange({ mode: 'monthday', monthdays: value?.monthdays ?? [] })
    } else {
      onChange({ mode: 'weekday', weekdays: value?.weekdays ?? [] })
    }
  }

  return <Space direction="vertical" style={{ width: '100%' }} size="middle">
    <Alert showIcon type="info" message="设置日期规则后，只有在命中日期时才会使用此模板。无日期规则的模板会作为全局兜底。" />
    <Select value={mode} onChange={applyMode} style={{ width: 240 }} options={[
      { value: 'none', label: '全局模板（无日期限制）' },
      { value: 'range', label: '按日期范围（多个区间）' },
      { value: 'monthday', label: '每年固定月日（如 02-14、12-24）' },
      { value: 'weekday', label: '按星期几（如周末）' },
    ]} />

    {mode === 'range' && value?.mode === 'range' && (
      <Space direction="vertical" style={{ width: '100%' }} size="small">
        {(value.ranges ?? []).map((range, index) => (
          <div key={index} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <Input
              type="date"
              value={range.start}
              onChange={(e) => onChange({ ...value, ranges: (value.ranges ?? []).map((r, i) => i === index ? { ...r, start: e.target.value } : r) })}
              style={{ width: 180 }}
            />
            <span>至</span>
            <Input
              type="date"
              value={range.end}
              onChange={(e) => onChange({ ...value, ranges: (value.ranges ?? []).map((r, i) => i === index ? { ...r, end: e.target.value } : r) })}
              style={{ width: 180 }}
            />
            {(value.ranges ?? []).length > 1 && (
              <Button size="small" danger onClick={() => onChange({ ...value, ranges: (value.ranges ?? []).filter((_, i) => i !== index) })}>移除</Button>
            )}
          </div>
        ))}
        <Button size="small" onClick={() => onChange({ ...value, ranges: [...(value.ranges ?? []), { start: '', end: '' }] })}>新增范围</Button>
      </Space>
    )}

    {mode === 'monthday' && value?.mode === 'monthday' && (
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', width: '100%' }}>
        {(['01-01', '02-14', '03-08', '05-01', '06-01', '07-01', '09-10', '10-01', '12-24', '12-31'] as const).map((md) => {
          const checked = (value.monthdays ?? []).includes(md)
          return <Tag.CheckableTag key={md} checked={checked} onChange={() => {
            const next = checked
              ? (value.monthdays ?? []).filter((d) => d !== md)
              : [...(value.monthdays ?? []), md]
            onChange({ ...value, monthdays: next })
          }}>{md}</Tag.CheckableTag>
        })}
      </div>
    )}

    {mode === 'weekday' && value?.mode === 'weekday' && (
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {([1, 2, 3, 4, 5, 6, 7] as const).map((d) => {
          const checked = (value.weekdays ?? []).includes(d)
          return <Tag.CheckableTag key={d} checked={checked} onChange={() => {
            const next = checked
              ? (value.weekdays ?? []).filter((w) => w !== d)
              : [...(value.weekdays ?? []), d]
            onChange({ ...value, weekdays: next })
          }}>{WEEKDAY_LABELS[d]}</Tag.CheckableTag>
        })}
      </div>
    )}
  </Space>
}

export function PosterTemplatePage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [items, setItems] = useState<PosterTemplateRow[]>([])
  const [loading, setLoading] = useState(false)
  const [codeFilter, setCodeFilter] = useState<string | undefined>()
  const [editing, setEditing] = useState<PosterTemplateRow | null>(null)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editorTab, setEditorTab] = useState('basic')
  const [deleteTarget, setDeleteTarget] = useState<PosterTemplateRow | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const [form] = Form.useForm<PosterTemplateInput>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await listPosterTemplates({ code: codeFilter })
      setItems(res.items)
    } catch {
      messageApi.error('海报模板加载失败。')
    } finally {
      setLoading(false)
    }
  }, [codeFilter, messageApi])

  useEffect(() => { void load() }, [load])

  const openCreate = () => {
    setEditing(null)
    form.setFieldsValue(defaultInput())
    setEditorTab('basic')
    setEditorOpen(true)
  }

  const openEdit = (row: PosterTemplateRow) => {
    setEditing(row)
    form.setFieldsValue({
      code: row.code,
      name: row.name,
      description: row.description ?? undefined,
      canvas_width: row.canvas_width,
      canvas_height: row.canvas_height,
      background_type: row.background_type,
      background_value: row.background_value,
      banner_config: row.banner_config,
      body_config: row.body_config,
      qrcode_config: row.qrcode_config,
      date_rule: row.date_rule,
      priority: row.priority,
      is_default: row.is_default,
      is_active: row.is_active,
    })
    setEditorTab('basic')
    setEditorOpen(true)
  }

  const submit = async () => {
    setSubmitting(true)
    try {
      const values = await form.validateFields()
      // 清洗 JSON 字段：空字符串/null 统一为 null
      const payload: PosterTemplateInput = {
        ...values,
        background_value: values.background_type === 'gradient'
          ? (values.background_value ?? {})
          : values.background_type === 'image'
            ? { image_url: (values.background_value as any)?.image_url ?? '' }
            : { color: (values.background_value as any)?.color ?? '#ffffff' },
        banner_config: values.banner_config ?? null,
        body_config: values.body_config ?? null,
        qrcode_config: values.qrcode_config ?? null,
        date_rule: values.date_rule ?? null,
      }

      if (editing) {
        await updatePosterTemplate(editing.public_id, payload)
        messageApi.success('模板已更新。')
      } else {
        await createPosterTemplate(payload)
        messageApi.success('模板已创建。')
      }
      setEditorOpen(false)
      await load()
    } catch (error) {
      if (error instanceof Error && error.message) messageApi.error(error.message)
      else messageApi.error('保存失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const toggleActive = async (row: PosterTemplateRow) => {
    try {
      await updatePosterTemplate(row.public_id, { is_active: !row.is_active })
      messageApi.success(row.is_active ? '已停用。' : '已启用。')
      await load()
    } catch {
      messageApi.error('操作失败。')
    }
  }

  const remove = async () => {
    if (!deleteTarget) return
    setSubmitting(true)
    try {
      await deletePosterTemplate(deleteTarget.public_id)
      messageApi.success('模板已删除。')
      setDeleteTarget(null)
      await load()
    } catch {
      messageApi.error('删除失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<PosterTemplateRow> = [
    { title: '名称', dataIndex: 'name', render: (v: string, r) => (
      <Space direction="vertical" size={2}>
        <strong>{v}</strong>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>{r.code}</Typography.Text>
      </Space>
    ) },
    {
      title: '背景',
      width: 110,
      render: (_, r) => <Space size={8}>
        {renderBgPreview(r.background_type, r.background_value)}
        <Tag color={r.background_type === 'gradient' ? 'blue' : r.background_type === 'image' ? 'purple' : 'default'}>{r.background_type}</Tag>
      </Space>,
    },
    { title: '画布', width: 110, render: (_, r) => `${r.canvas_width} × ${r.canvas_height}` },
    { title: '优先级', dataIndex: 'priority', width: 70 },
    {
      title: '日期规则',
      width: 220,
      render: (_, r) => formatDateRule(r.date_rule),
    },
    {
      title: '状态',
      width: 160,
      render: (_, r) => (
        <Space>
          <Pill tone={r.is_active ? 'green' : 'gray'}>{r.is_active ? '启用' : '停用'}</Pill>
          {r.is_default && <Tag color="gold">默认</Tag>}
          {r.date_rule !== null && <Tag color="orange">日期限定</Tag>}
        </Space>
      ),
    },
    {
      title: '操作',
      width: 200,
      render: (_, r) => <Links items={[
        { label: '编辑', onClick: () => openEdit(r) },
        { label: r.is_active ? '停用' : '启用', onClick: () => void toggleActive(r) },
        { label: '删除', danger: true, onClick: () => setDeleteTarget(r) },
      ]} />,
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="海报模板管理"
        extra={<Button type="primary" onClick={openCreate}>新增模板</Button>}
      />

      <Space style={{ marginBottom: 16 }} wrap>
        <Select
          allowClear
          placeholder="全部业务场景"
          style={{ width: 260 }}
          value={codeFilter}
          onChange={(v) => { setCodeFilter(v); void load() }}
          options={CODE_OPTIONS}
        />
      </Space>

      <div className="xq-card xq-table">
        <Table rowKey="public_id" size="middle" loading={loading} columns={columns} dataSource={items} pagination={{ pageSize: 10, showSizeChanger: true }} scroll={{ x: 1100 }} />
      </div>

      <Modal
        title={editing ? '编辑海报模板' : '新增海报模板'}
        open={editorOpen}
        onCancel={() => setEditorOpen(false)}
        onOk={() => void submit()}
        confirmLoading={submitting}
        okText="保存模板"
        cancelText="取消"
        width={900}
        styles={{ body: { maxHeight: '70vh', overflowY: 'auto' } }}
        destroyOnClose
      >
        <Form form={form} layout="vertical" requiredMark={false}>
          <Tabs
            activeKey={editorTab}
            onChange={setEditorTab}
            items={[
              {
                key: 'basic',
                label: '基础信息',
                children: <Space direction="vertical" style={{ width: '100%' }}>
                  <Form.Item name="code" label="业务场景" rules={[{ required: true, message: '请选择业务场景' }]}>
                    <Select options={CODE_OPTIONS} disabled={!!editing} />
                  </Form.Item>
                  <Form.Item name="name" label="模板名称" rules={[{ required: true, max: 60, message: '请输入名称（最多 60 字）' }]}>
                    <Input maxLength={60} placeholder="例如：社区帖子-春节红金" />
                  </Form.Item>
                  <Form.Item name="description" label="备注说明">
                    <Input.TextArea rows={2} maxLength={500} showCount placeholder="后台管理员查看用，用户看不到" />
                  </Form.Item>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12 }}>
                    <Form.Item name="canvas_width" label="画布宽度（px）">
                      <InputNumber min={100} max={1000} style={{ width: '100%' }} />
                    </Form.Item>
                    <Form.Item name="canvas_height" label="画布高度（px）">
                      <InputNumber min={100} max={1500} style={{ width: '100%' }} />
                    </Form.Item>
                    <Form.Item name="priority" label="优先级">
                      <InputNumber min={0} max={9999} style={{ width: '100%' }} />
                    </Form.Item>
                    <Form.Item label="状态" style={{ marginBottom: 0 }}>
                      <Space>
                        <Form.Item name="is_active" valuePropName="checked" noStyle><Switch checkedChildren="启用" unCheckedChildren="停用" /></Form.Item>
                        <Form.Item name="is_default" valuePropName="checked" noStyle><Switch checkedChildren="默认兜底" unCheckedChildren="否" /></Form.Item>
                      </Space>
                    </Form.Item>
                  </div>
                </Space>,
              },
              {
                key: 'background',
                label: '背景',
                children: <Space direction="vertical" style={{ width: '100%' }}>
                  <Form.Item name="background_type" label="背景类型" rules={[{ required: true }]}>
                    <Select options={BG_TYPE_OPTIONS} />
                  </Form.Item>
                  <Form.Item
                    noStyle
                    shouldUpdate={(prev, cur) => prev.background_type !== cur.background_type}
                  >
                    {({ getFieldValue }) => {
                      const type = getFieldValue('background_type')
                      if (type === 'gradient') {
                        return <Form.Item name="background_value" label="渐变颜色">
                          <Form.Item noStyle shouldUpdate>
                            {({ getFieldValue: fv2 }) => {
                              const current = fv2('background_value') as Record<string, unknown> | null
                              return backgroundConfigPanel({
                                value: current ?? { stops: ['#0e799d', '#19c6d1', '#76df63'], angle: 135 },
                                onChange: (next) => form.setFieldValue('background_value', next),
                              })
                            }}
                          </Form.Item>
                        </Form.Item>
                      }
                      if (type === 'image') {
                        return <Form.Item name="background_value" label="背景图">
                          <Form.Item noStyle shouldUpdate>
                            {({ getFieldValue: fv2 }) => {
                              const current = fv2('background_value') as Record<string, unknown> | null
                              const url = String(current?.image_url ?? '')
                              return <MediaGalleryInput
                                value={url ? [{ url, alt: '海报背景', sort_order: 0 }] : []}
                                onChange={(images) => form.setFieldValue('background_value', { image_url: images[0]?.url ?? '' })}
                                max={1}
                                primaryLabel="海报背景图"
                                helperText="建议尺寸 660×1040 以上；小程序端 Canvas 会以 cover 模式铺满画布。"
                              />
                            }}
                          </Form.Item>
                        </Form.Item>
                      }
                      if (type === 'solid') {
                        return <Form.Item name="background_value" label="纯色">
                          <Form.Item noStyle shouldUpdate>
                            {({ getFieldValue: fv2 }) => {
                              const current = (fv2('background_value') as Record<string, unknown> | null) ?? { color: '#ffffff' }
                              const color = String(current.color ?? '#ffffff')
                              return <Space>
                                <Input
                                  value={color}
                                  onChange={(e) => form.setFieldValue('background_value', { ...current, color: e.target.value.trim() })}
                                  maxLength={16}
                                  placeholder="#FFFFFF"
                                  style={{ width: 180 }}
                                />
                                <input
                                  type="color"
                                  value={color.startsWith('#') ? color : '#ffffff'}
                                  onChange={(e) => form.setFieldValue('background_value', { ...current, color: e.target.value })}
                                  style={{ width: 36, height: 32, padding: 0, border: '1px solid #d9d9d9', borderRadius: 4, cursor: 'pointer' }}
                                />
                              </Space>
                            }}
                          </Form.Item>
                        </Form.Item>
                      }
                      return null
                    }}
                  </Form.Item>
                </Space>,
              },
              {
                key: 'copy',
                label: '文案配置',
                children: <Space direction="vertical" style={{ width: '100%' }} size="middle">
                  <Alert showIcon type="info" message="以下配置均为 JSON 对象，可直接编辑；留空时前端会使用内置默认值。" />
                  <Card size="small" title="Banner 顶部区域">
                    <Form.Item name="banner_config">
                      <Form.Item noStyle shouldUpdate>
                        {({ getFieldValue }) => {
                          const value = getFieldValue('banner_config')
                          const text = value ? JSON.stringify(value, null, 2) : ''
                          return <Input.TextArea
                            rows={6}
                            placeholder={JSON.stringify({ label_text: 'CAMPUS COMMUNITY', label_color: '#10213a', label_bg: '#ffdb4d', title_text: '校园动态', title_color: '#ffffff' }, null, 2)}
                            value={text}
                            onChange={(e) => {
                              const v = e.target.value.trim()
                              form.setFieldValue('banner_config', v ? JSON.parse(v) : null)
                            }}
                          />
                        }}
                      </Form.Item>
                    </Form.Item>
                  </Card>
                  <Card size="small" title="主体卡片">
                    <Form.Item name="body_config">
                      <Form.Item noStyle shouldUpdate>
                        {({ getFieldValue }) => {
                          const value = getFieldValue('body_config')
                          const text = value ? JSON.stringify(value, null, 2) : ''
                          return <Input.TextArea
                            rows={6}
                            placeholder={JSON.stringify({ card_bg: 'rgba(255,255,255,.96)', category_color: '#60798d', content_color: '#173c58' }, null, 2)}
                            value={text}
                            onChange={(e) => {
                              const v = e.target.value.trim()
                              form.setFieldValue('body_config', v ? JSON.parse(v) : null)
                            }}
                          />
                        }}
                      </Form.Item>
                    </Form.Item>
                  </Card>
                  <Card size="small" title="二维码区域">
                    <Form.Item name="qrcode_config">
                      <Form.Item noStyle shouldUpdate>
                        {({ getFieldValue }) => {
                          const value = getFieldValue('qrcode_config')
                          const text = value ? JSON.stringify(value, null, 2) : ''
                          return <Input.TextArea
                            rows={8}
                            placeholder={JSON.stringify({ cta_text: '扫码查看动态详情', cta_color: '#173c58', helper_text: '分享给同学，一起发现校园', helper_color: '#173c58', brand_watermark: '任务商城 · 校园社区', brand_color: '#748895', footer_text: '任务商城 · 校园成长与奖励平台', footer_color: '#ffffff' }, null, 2)}
                            value={text}
                            onChange={(e) => {
                              const v = e.target.value.trim()
                              form.setFieldValue('qrcode_config', v ? JSON.parse(v) : null)
                            }}
                          />
                        }}
                      </Form.Item>
                    </Form.Item>
                  </Card>
                </Space>,
              },
              {
                key: 'daterule',
                label: '日期规则',
                children: <Form.Item name="date_rule" label="日期规则（后端按当前日期匹配最高优先级的模板）">
                  <Form.Item noStyle shouldUpdate>
                    {({ getFieldValue }) => <DateRulePanel
                      value={getFieldValue('date_rule')}
                      onChange={(next) => form.setFieldValue('date_rule', next)}
                    />}
                  </Form.Item>
                </Form.Item>,
              },
            ]}
          />
        </Form>
      </Modal>

      <Modal
        title="删除模板"
        open={!!deleteTarget}
        onCancel={() => setDeleteTarget(null)}
        onOk={() => void remove()}
        confirmLoading={submitting}
        okText="确认删除"
        okButtonProps={{ danger: true }}
        cancelText="取消"
      >
        确认删除海报模板「{deleteTarget?.name}」？删除后线上立即切换到同业务场景下的下一个默认模板。
      </Modal>
    </div>
  )
}
