import { ArrowLeftOutlined, CopyOutlined, DeleteOutlined, DownOutlined, EditOutlined, PlusOutlined, ReloadOutlined, SaveOutlined, UpOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Empty, Input, Popconfirm, Segmented, Select, Space, Switch, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  createReceiptTemplate,
  deleteReceiptTemplate,
  duplicateReceiptTemplate,
  getReceiptTemplates,
  listDecorationCampuses,
  saveReceiptTemplateBindings,
  toggleReceiptTemplate,
  updateReceiptTemplate,
  type ReceiptBlock,
  type ReceiptBlockProps,
  type ReceiptBlockType,
  type ReceiptTemplate,
  type ReceiptTemplateInput,
  type ReceiptTemplateOverview,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { useRobotRecentVerification } from '../robot/useRobotRecentVerification'

type BindingSlot = 'receipt' | 'split' | 'label'

const SAMPLE_VARIABLES: Record<string, string> = {
  order_no: 'EP20260101120000ABCDEF',
  pickup_site: '菜鸟驿站（东门）',
  pickup_code: '8-2-3021',
  package_info: '纸箱小件',
  package_count: '1',
  deliver_building: '学生公寓 3 号楼',
  deliver_address: '3-412',
  contact_name: '张同学',
  contact_phone: '13800001234',
  reward: '5.00',
  expected_at: '18:00 前',
  remark: '轻拿轻放',
  created_at: '2026-01-01 12:00',
  campus_name: '示例校区',
  product_name: '示例商品',
  quantity: '1',
  amount: '9.90',
  distribution_code: 'D20260101',
}

const BLOCK_DEFAULTS: Record<ReceiptBlockType, ReceiptBlockProps> = {
  text: { content: '文本信息', align: 'left', font_size: 0, bold: 0 },
  product_header: { content: '商品名称        数量    金额', align: 'left', font_size: 0, bold: 1 },
  product_item: { content: '${product_name}  ${quantity}  ${amount}', align: 'left', font_size: 0, bold: 0 },
  qr_code: { content: '${order_no}', align: 'center', font_size: 0, bold: 0 },
  distribution_code: { content: '推广码：${distribution_code}', align: 'left', font_size: 0, bold: 0 },
  divider: { content: '', align: 'left', font_size: 0, bold: 0 },
  blank: { content: '', align: 'left', font_size: 0, bold: 0 },
}

function createBlock(type: ReceiptBlockType, overrides: Partial<ReceiptBlockProps> = {}): ReceiptBlock {
  return {
    id: `blk-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`,
    type,
    props: { ...BLOCK_DEFAULTS[type], ...overrides },
  }
}

function starterBlocks(): ReceiptBlock[] {
  return [
    createBlock('text', { content: '快递代取', align: 'center', font_size: 1, bold: 1 }),
    createBlock('divider'),
    createBlock('text', { content: '取件点：${pickup_site}' }),
    createBlock('text', { content: '取件码：${pickup_code}' }),
    createBlock('text', { content: '包裹：${package_info} × ${package_count}' }),
    createBlock('text', { content: '送达：${deliver_building} ${deliver_address}' }),
    createBlock('text', { content: '联系：${contact_name} ${contact_phone}' }),
    createBlock('text', { content: '跑腿费：￥${reward}' }),
    createBlock('text', { content: '期望送达：${expected_at}' }),
    createBlock('divider'),
    createBlock('text', { content: '订单号：${order_no}' }),
    createBlock('qr_code'),
  ]
}

function newTemplateDraft(printType: string): ReceiptTemplate {
  return {
    id: `new-${Date.now().toString(36)}`,
    name: '58mm 小票模板',
    campus_id: null,
    campus_name: '全站',
    paper_width: 58,
    print_type: printType,
    status: 'enabled',
    blocks: starterBlocks(),
    created_at: '',
    updated_at: '',
  }
}

function substitute(text: string): string {
  return text.replace(/\$\{([a-z0-9_]+)\}/gi, (_match, key: string) => SAMPLE_VARIABLES[key] ?? '')
}

function alignLine(line: string, align: ReceiptBlockProps['align'], width: number): string {
  if (align === 'center') return ' '.repeat(Math.max(0, Math.floor((width - line.length) / 2))) + line
  if (align === 'right') return ' '.repeat(Math.max(0, width - line.length)) + line
  return line
}

/** 前端实时预览与后端编译器保持同一口径：58mm 32 列、80mm 48 列。 */
function compilePreview(blocks: ReceiptBlock[], paperWidth: 58 | 80): string {
  const width = paperWidth === 80 ? 48 : 32
  const lines: string[] = []
  blocks.forEach((block) => {
    if (block.type === 'divider') {
      lines.push('-'.repeat(width))
      return
    }
    if (block.type === 'blank') {
      lines.push('')
      return
    }
    const fallback = block.type === 'qr_code' ? '${order_no}' : ''
    const content = substitute(block.props.content.trim() || fallback)
    content.split(/\r\n|\r|\n/).forEach((line) => lines.push(alignLine(line, block.props.align, width)))
  })
  return lines.join('\n')
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function ReceiptTemplatePage() {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const [overview, setOverview] = useState<ReceiptTemplateOverview | null>(null)
  const [campuses, setCampuses] = useState<Array<{ id: number; name: string }>>([])
  const [loading, setLoading] = useState(true)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [editing, setEditing] = useState<ReceiptTemplate | null>(null)
  const [bindings, setBindings] = useState<ReceiptTemplateOverview['bindings'] | null>(null)
  const [savingBindings, setSavingBindings] = useState(false)
  const { runWrite, verificationContext } = useRobotRecentVerification(
    admin?.requires_mfa ?? false,
    '该操作会修改小票打印模板，请输入当前管理员密码以继续。',
  )

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [data, campusData] = await Promise.all([getReceiptTemplates(), listDecorationCampuses().catch(() => ({ items: [] }))])
      setOverview(data)
      setBindings(data.bindings)
      setCampuses(campusData.items)
    } catch (error) {
      messageApi.error(errorMessage(error, '小票模板加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const handleToggle = async (template: ReceiptTemplate) => {
    setBusyId(template.id)
    const result = await runWrite(() => toggleReceiptTemplate(template.id))
    setBusyId(null)
    if (!result) return
    setOverview(result.overview)
    setBindings(result.overview.bindings)
    messageApi.success(result.template.status === 'enabled' ? '小票模板已启用。' : '小票模板已停用。')
  }

  const handleDuplicate = async (template: ReceiptTemplate) => {
    setBusyId(template.id)
    const result = await runWrite(() => duplicateReceiptTemplate(template.id))
    setBusyId(null)
    if (!result) return
    setOverview(result.overview)
    setBindings(result.overview.bindings)
    messageApi.success('小票模板已复制。')
  }

  const handleDelete = async (template: ReceiptTemplate) => {
    setBusyId(template.id)
    const result = await runWrite(() => deleteReceiptTemplate(template.id))
    setBusyId(null)
    if (!result) return
    messageApi.success('小票模板已删除。')
    await load()
  }

  const updateBinding = (scene: string, slot: BindingSlot, value: string | null) => {
    setBindings((current) => current ? { ...current, [scene]: { ...current[scene], [slot]: value } } : current)
  }

  const saveBindings = async () => {
    if (!bindings) return
    setSavingBindings(true)
    const result = await runWrite(() => saveReceiptTemplateBindings(bindings))
    setSavingBindings(false)
    if (!result) {
      await load()
      return
    }
    setOverview(result)
    setBindings(result.bindings)
    messageApi.success('打印模板绑定已保存。')
  }

  if (editing) {
    return <>
      {contextHolder}
      <ReceiptTemplateDesigner
        key={editing.id}
        template={editing}
        blockTypes={overview?.block_types ?? []}
        scenes={overview?.scenes ?? []}
        variables={overview?.variables ?? []}
        campuses={campuses}
        onBack={() => { setEditing(null); void load() }}
        onSaved={(savedOverview, savedTemplate) => { setOverview(savedOverview); setBindings(savedOverview.bindings); setEditing(savedTemplate) }}
      />
    </>
  }

  const sceneLabel = (code: string) => overview?.scenes.find((scene) => scene.code === code)?.label ?? code
  const templateOptions = (overview?.templates ?? []).map((template) => ({
    value: template.id,
    label: `${template.name}（${template.paper_width}mm${template.status === 'disabled' ? '·停用' : ''}）`,
  }))

  return <>
    {contextHolder}
    {verificationContext}
    <Alert
      showIcon
      type="info"
      style={{ marginBottom: 12 }}
      message="小票模板支持 58mm / 80mm 纸宽与组件化排版。绑定到「快递代取」整单并启用后，会覆盖小票机里自定义的文本模板；未绑定时保持小票机原有打印内容。"
    />
    <Card
      bordered={false}
      title={<Space><Typography.Text strong>小票模板</Typography.Text><Tag>{overview?.templates.length ?? 0} 个</Tag></Space>}
      extra={<Space>
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
        <Button type="primary" icon={<PlusOutlined />} disabled={!overview} onClick={() => setEditing(newTemplateDraft('express_pickup'))}>新增模板</Button>
      </Space>}
    >
      <Table
        rowKey="id"
        size="small"
        loading={loading}
        dataSource={overview?.templates ?? []}
        pagination={false}
        scroll={{ x: 980 }}
        locale={{ emptyText: <Empty description="还没有小票模板，点击「新增模板」开始装修小票页面" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
        columns={[
          { title: '模板名称', dataIndex: 'name', width: 180 },
          { title: '所属区域', dataIndex: 'campus_name', width: 120 },
          { title: '模板类型', dataIndex: 'paper_width', width: 100, render: (value: number) => `${value}mm` },
          { title: '打印类型', dataIndex: 'print_type', width: 110, render: (value: string) => sceneLabel(value) },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (value: string) => <Tag color={value === 'enabled' ? 'green' : 'default'}>{value === 'enabled' ? '启用' : '停用'}</Tag>,
          },
          { title: '最后修改', dataIndex: 'updated_at', width: 160, render: (value: string) => value ? value.slice(0, 19).replace('T', ' ') : '—' },
          {
            title: '操作',
            width: 250,
            fixed: 'right',
            render: (_: unknown, record: ReceiptTemplate) => <Space size={4} wrap>
              <Button size="small" type="link" icon={<EditOutlined />} onClick={() => setEditing(record)}>编辑</Button>
              <Button size="small" type="link" icon={<CopyOutlined />} loading={busyId === record.id} onClick={() => void handleDuplicate(record)}>复制</Button>
              <Button size="small" type="link" loading={busyId === record.id} onClick={() => void handleToggle(record)}>{record.status === 'enabled' ? '停用' : '启用'}</Button>
              <Popconfirm title="删除后绑定关系会一并清除，确认删除？" onConfirm={() => void handleDelete(record)}>
                <Button size="small" type="link" danger icon={<DeleteOutlined />}>删除</Button>
              </Popconfirm>
            </Space>,
          },
        ]}
      />
    </Card>

    <Card
      bordered={false}
      style={{ marginTop: 16 }}
      title="打印模板设置"
      extra={<Button type="primary" loading={savingBindings} onClick={() => void saveBindings()} disabled={!overview}>保存绑定</Button>}
    >
      <Typography.Paragraph type="secondary" style={{ marginTop: -8 }}>
        为不同订单类型选择整单、分单与标签模板。当前只有「快递代取」已接入真实打印，其余场景会先保存绑定，待对应订单模块接入后生效。
      </Typography.Paragraph>
      <Table
        rowKey="code"
        size="small"
        pagination={false}
        dataSource={overview?.scenes ?? []}
        columns={[
          {
            title: '订单类型',
            dataIndex: 'label',
            width: 140,
            render: (label: string, scene: { code: string; status: string }) => <Space size={4}>
              <span>{label}</span>
              {scene.status === 'pending' ? <Tag>待接入</Tag> : <Tag color="green">已接入</Tag>}
            </Space>,
          },
          ...(overview?.slots ?? []).map((slot) => ({
            title: slot.label,
            width: 200,
            render: (_: unknown, scene: { code: string }) => <Select
              allowClear
              style={{ width: 180 }}
              placeholder="未绑定"
              value={bindings?.[scene.code]?.[slot.code] ?? null}
              options={templateOptions}
              onChange={(value) => updateBinding(scene.code, slot.code as BindingSlot, value ?? null)}
            />,
          })),
        ]}
      />
    </Card>
  </>
}

interface DesignerProps {
  template: ReceiptTemplate
  blockTypes: ReceiptTemplateOverview['block_types']
  scenes: ReceiptTemplateOverview['scenes']
  variables: ReceiptTemplateOverview['variables']
  campuses: Array<{ id: number; name: string }>
  onBack: () => void
  onSaved: (overview: ReceiptTemplateOverview, template: ReceiptTemplate) => void
}

function ReceiptTemplateDesigner({ template, blockTypes, scenes, variables, campuses, onBack, onSaved }: DesignerProps) {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const [name, setName] = useState(template.name)
  const [paperWidth, setPaperWidth] = useState<58 | 80>(template.paper_width)
  const [printType, setPrintType] = useState(template.print_type)
  const [status, setStatus] = useState<'enabled' | 'disabled'>(template.status)
  const [campusId, setCampusId] = useState<number | null>(template.campus_id)
  const [blocks, setBlocks] = useState<ReceiptBlock[]>(template.blocks)
  const [selectedId, setSelectedId] = useState<string | null>(template.blocks[0]?.id ?? null)
  const [rightTab, setRightTab] = useState('block')
  const [saving, setSaving] = useState(false)
  const { runWrite, verificationContext } = useRobotRecentVerification(
    admin?.requires_mfa ?? false,
    '该操作会保存小票打印模板，请输入当前管理员密码以继续。',
  )

  const selected = blocks.find((block) => block.id === selectedId) ?? null
  const preview = useMemo(() => compilePreview(blocks, paperWidth), [blocks, paperWidth])

  const updateBlock = (id: string, patch: Partial<ReceiptBlockProps>) => {
    setBlocks((current) => current.map((block) => block.id === id ? { ...block, props: { ...block.props, ...patch } } : block))
  }

  const moveBlock = (index: number, offset: number) => {
    setBlocks((current) => {
      const target = index + offset
      if (target < 0 || target >= current.length) return current
      const next = [...current]
      ;[next[index], next[target]] = [next[target], next[index]]
      return next
    })
  }

  const addBlock = (type: ReceiptBlockType) => {
    const block = createBlock(type)
    setBlocks((current) => [...current, block])
    setSelectedId(block.id)
    setRightTab('block')
  }

  const removeBlock = (id: string) => {
    setBlocks((current) => current.filter((block) => block.id !== id))
    if (selectedId === id) setSelectedId(null)
  }

  const autoFormat = () => {
    let firstText = true
    setBlocks((current) => current.map((block) => {
      const content = block.props.content.trim()
      if (block.type !== 'text') return { ...block, props: { ...block.props, content } }
      const isTitle = firstText
      firstText = false
      return {
        ...block,
        props: {
          ...block.props,
          content,
          align: isTitle ? 'center' : 'left',
          font_size: isTitle ? 1 : 0,
          bold: isTitle ? 1 : 0,
        },
      }
    }))
    messageApi.success('已按标题居中、正文左对齐完成一键排版。')
  }

  const save = async () => {
    if (name.trim() === '') {
      messageApi.warning('请填写模板名称。')
      return
    }
    const input: ReceiptTemplateInput = {
      name: name.trim(),
      campus_id: campusId,
      paper_width: paperWidth,
      print_type: printType,
      status,
      blocks,
    }
    setSaving(true)
    const isNew = template.id.startsWith('new-')
    const result = await runWrite(() => isNew ? createReceiptTemplate(input) : updateReceiptTemplate(template.id, input))
    setSaving(false)
    if (!result) return
    onSaved(result.overview, result.template)
    messageApi.success('小票模板已保存。')
  }

  return <>
    {contextHolder}
    {verificationContext}
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
      <Space wrap>
        <Button icon={<ArrowLeftOutlined />} onClick={onBack}>返回模板列表</Button>
        <Tag color="blue">{paperWidth}mm</Tag>
        <Input value={name} onChange={(event) => setName(event.target.value)} maxLength={40} style={{ width: 220 }} placeholder="模板名称" />
      </Space>
      <Space wrap>
        <Segmented
          value={paperWidth}
          options={[{ label: '58mm', value: 58 }, { label: '80mm', value: 80 }]}
          onChange={(value) => setPaperWidth(Number(value) === 80 ? 80 : 58)}
        />
        <Segmented
          value={status}
          options={[{ label: '启用', value: 'enabled' }, { label: '停用', value: 'disabled' }]}
          onChange={(value) => setStatus(value === 'enabled' ? 'enabled' : 'disabled')}
        />
        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={() => void save()}>保存模板</Button>
      </Space>
    </div>

    <Alert
      showIcon
      type="info"
      style={{ marginBottom: 12 }}
      message="左侧选择组件添加到小票，中间实时预览打印效果；右侧调整对齐、字号与内容。启用并绑定到打印场景后由小票机输出。"
    />

    <div style={{ display: 'grid', gridTemplateColumns: '240px minmax(320px, 1fr) 360px', gap: 16 }}>
      <div>
        <Card size="small" title="组件库">
          <Space wrap size={6}>
            {blockTypes.map((blockType) => <Button key={blockType.code} size="small" onClick={() => addBlock(blockType.code)}>{blockType.label}</Button>)}
          </Space>
        </Card>
        <Card size="small" title={`组件顺序 · ${blocks.length}`} style={{ marginTop: 12 }}>
          <Space direction="vertical" style={{ width: '100%' }} size={4}>
            {blocks.map((block, index) => <div
              key={block.id}
              onClick={() => { setSelectedId(block.id); setRightTab('block') }}
              style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 6,
                padding: '6px 8px', borderRadius: 8, cursor: 'pointer',
                border: `1px solid ${selectedId === block.id ? '#2E7D5B' : '#EDF0F5'}`,
                background: selectedId === block.id ? '#F1F8F4' : '#fff',
              }}
            >
              <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontSize: 12 }}>
                {blockTypes.find((item) => item.code === block.type)?.label ?? block.type}
                {block.props.content ? ` · ${block.props.content.slice(0, 10)}` : ''}
              </span>
              <Space size={0}>
                <Button size="small" type="text" icon={<UpOutlined />} onClick={(event) => { event.stopPropagation(); moveBlock(index, -1) }} />
                <Button size="small" type="text" icon={<DownOutlined />} onClick={(event) => { event.stopPropagation(); moveBlock(index, 1) }} />
                <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={(event) => { event.stopPropagation(); removeBlock(block.id) }} />
              </Space>
            </div>)}
          </Space>
        </Card>
      </div>

      <Card size="small" title="小票预览">
        <div style={{ display: 'flex', justifyContent: 'center' }}>
          <div style={{
            width: paperWidth === 80 ? 380 : 300,
            background: '#FFFDF7',
            border: '1px dashed #D8D2C4',
            borderRadius: 6,
            padding: '16px 14px',
            minHeight: 420,
          }}>
            <pre style={{ margin: 0, fontFamily: 'Menlo, Consolas, monospace', fontSize: 12, lineHeight: 1.6, whiteSpace: 'pre' }}>
              {preview || '还没有组件，从左侧组件库添加。'}
            </pre>
          </div>
        </div>
      </Card>

      <Card size="small">
        <Tabs
          activeKey={rightTab}
          onChange={setRightTab}
          items={[
            {
              key: 'block',
              label: '文本信息设置',
              children: selected ? <Space direction="vertical" style={{ width: '100%' }}>
                <Typography.Text type="secondary">对齐</Typography.Text>
                <Segmented
                  block
                  value={selected.props.align}
                  options={[{ label: '左', value: 'left' }, { label: '中', value: 'center' }, { label: '右', value: 'right' }]}
                  onChange={(value) => updateBlock(selected.id, { align: value as ReceiptBlockProps['align'] })}
                />
                <Typography.Text type="secondary">字号</Typography.Text>
                <Segmented
                  block
                  value={selected.props.font_size}
                  options={[{ label: '0 常规', value: 0 }, { label: '1 放大', value: 1 }, { label: '2 特大', value: 2 }]}
                  onChange={(value) => updateBlock(selected.id, { font_size: Number(value) })}
                />
                <Typography.Text type="secondary">字体加粗</Typography.Text>
                <Segmented
                  block
                  value={selected.props.bold}
                  options={[{ label: '0', value: 0 }, { label: '1', value: 1 }, { label: '2', value: 2 }]}
                  onChange={(value) => updateBlock(selected.id, { bold: Number(value) })}
                />
                <Typography.Text type="secondary">内容（支持 {'${变量}'} 占位符）</Typography.Text>
                <Input.TextArea
                  rows={5}
                  value={selected.props.content}
                  maxLength={500}
                  placeholder={selected.type === 'qr_code' ? '${order_no}' : '输入小票文字'}
                  onChange={(event) => updateBlock(selected.id, { content: event.target.value })}
                />
                <Space>
                  <Button size="small" onClick={autoFormat}>一键排版</Button>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>标题居中加粗，正文左对齐</Typography.Text>
                </Space>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  打印机仅支持统一的加粗放大层级，字号 1/2 与加粗 1/2 都会输出为加粗放大。
                </Typography.Text>
              </Space> : <Empty description="在左侧组件顺序中选择一个组件" image={Empty.PRESENTED_IMAGE_SIMPLE} />,
            },
            {
              key: 'template',
              label: '模板设置',
              children: <Space direction="vertical" style={{ width: '100%' }}>
                <Typography.Text type="secondary">模板名称</Typography.Text>
                <Input value={name} maxLength={40} onChange={(event) => setName(event.target.value)} />
                <Typography.Text type="secondary">所属区域</Typography.Text>
                <Select
                  allowClear
                  placeholder="全站"
                  value={campusId}
                  options={campuses.map((campus) => ({ value: campus.id, label: campus.name }))}
                  onChange={(value) => setCampusId(value ?? null)}
                  style={{ width: '100%' }}
                />
                <Typography.Text type="secondary">模板类型</Typography.Text>
                <Segmented
                  block
                  value={paperWidth}
                  options={[{ label: '58mm', value: 58 }, { label: '80mm', value: 80 }]}
                  onChange={(value) => setPaperWidth(Number(value) === 80 ? 80 : 58)}
                />
                <Typography.Text type="secondary">打印类型</Typography.Text>
                <Select
                  value={printType}
                  options={scenes.map((scene) => ({
                    value: scene.code,
                    label: scene.status === 'pending' ? `${scene.label}（待接入）` : scene.label,
                  }))}
                  onChange={setPrintType}
                  style={{ width: '100%' }}
                />
                <Typography.Text type="secondary">模板状态</Typography.Text>
                <Switch checked={status === 'enabled'} checkedChildren="启用" unCheckedChildren="停用" onChange={(checked) => setStatus(checked ? 'enabled' : 'disabled')} />
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  设置所属区域后，只有该校区的小票会使用此模板；全站模板对所有校区生效。
                </Typography.Text>
              </Space>,
            },
            {
              key: 'vars',
              label: '可用变量',
              children: <Space wrap size={6}>
                {variables.map((variable) => <Tag
                  key={variable.key}
                  style={{ cursor: 'pointer' }}
                  onClick={() => {
                    if (!selected || selected.type === 'divider' || selected.type === 'blank') {
                      messageApi.warning('请先选择一个文本或二维码组件。')
                      return
                    }
                    updateBlock(selected.id, { content: `${selected.props.content}${variable.key}` })
                  }}
                >
                  {variable.label} <Typography.Text type="secondary" style={{ fontSize: 11 }}>{variable.key}</Typography.Text>
                </Tag>)}
              </Space>,
            },
          ]}
        />
      </Card>
    </div>
  </>
}
