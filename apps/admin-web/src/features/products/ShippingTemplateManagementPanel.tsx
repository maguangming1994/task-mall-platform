import { ArrowDownOutlined, ArrowUpOutlined, EditOutlined, PauseCircleOutlined, PlayCircleOutlined, PlusOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Typography, message } from 'antd'
import { useState } from 'react'
import {
  ApiError,
  createShippingTemplate,
  updateShippingTemplate,
  type ManagedShippingChargeType,
  type ManagedShippingFreeRuleType,
  type ManagedShippingTemplate,
  type ManagedShippingTemplateInput,
} from '../../api/admin'

interface ShippingRegionForm {
  region_codes_text: string
  deliverable: boolean
  remote_surcharge_amount: number
}

interface ShippingFreeRuleForm {
  type: ManagedShippingFreeRuleType
  target_ids_text?: string
  threshold_amount?: number
  threshold_quantity?: number
}

interface ShippingTemplateForm {
  name: string
  charge_type: ManagedShippingChargeType
  fixed_amount?: number
  first_unit?: number
  first_amount?: number
  additional_unit?: number
  additional_amount?: number
  regions: ShippingRegionForm[]
  free_rules: ShippingFreeRuleForm[]
  enabled: boolean
}

interface ShippingTemplateManagementPanelProps {
  templates: ManagedShippingTemplate[]
  loading?: boolean
  onChanged: () => void | Promise<void>
}

const emptyTemplate: ShippingTemplateForm = {
  name: '',
  charge_type: 'fixed',
  fixed_amount: 600,
  regions: [{ region_codes_text: '*', deliverable: true, remote_surcharge_amount: 0 }],
  free_rules: [],
  enabled: true,
}

const statusMeta: Record<ManagedShippingTemplate['status'], { label: string; color: string }> = {
  active: { label: '已启用', color: 'success' },
  inactive: { label: '已停用', color: 'default' },
  draft: { label: '草稿', color: 'warning' },
}

const chargeTypeOptions = [
  { value: 'fixed', label: '固定运费' },
  { value: 'by_quantity', label: '按件数计费' },
  { value: 'by_weight', label: '按重量计费' },
]

const freeRuleOptions = [
  { value: 'cash_threshold', label: '现金金额满额包邮' },
  { value: 'quantity_threshold', label: '商品件数满额包邮' },
  { value: 'product', label: '指定商品包邮' },
  { value: 'sku', label: '指定 SKU 包邮' },
]

function splitValues(value?: string): string[] {
  return (value ?? '').split(/[\s,，、]+/).map((item) => item.trim()).filter(Boolean)
}

function formatMoney(cents?: number | null): string {
  return `¥${((cents ?? 0) / 100).toFixed(2)}`
}

function templateToForm(template: ManagedShippingTemplate): ShippingTemplateForm {
  return {
    name: template.name,
    charge_type: template.charge_type,
    fixed_amount: template.fixed_amount ?? undefined,
    first_unit: template.first_unit ?? undefined,
    first_amount: template.first_amount ?? undefined,
    additional_unit: template.additional_unit ?? undefined,
    additional_amount: template.additional_amount ?? undefined,
    regions: template.regions.map((region) => ({
      region_codes_text: region.region_codes.join('，'),
      deliverable: region.deliverable,
      remote_surcharge_amount: region.remote_surcharge_amount,
    })),
    free_rules: template.free_rules.map((rule) => ({
      type: rule.type,
      target_ids_text: rule.target_ids?.join('，'),
      threshold_amount: rule.threshold_amount,
      threshold_quantity: rule.threshold_quantity,
    })),
    enabled: template.status === 'active',
  }
}

function formToInput(values: ShippingTemplateForm): ManagedShippingTemplateInput {
  const metered = values.charge_type !== 'fixed'

  return {
    name: values.name.trim(),
    charge_type: values.charge_type,
    fixed_amount: values.charge_type === 'fixed' ? values.fixed_amount : null,
    first_unit: metered ? values.first_unit : null,
    first_amount: metered ? values.first_amount : null,
    additional_unit: metered ? values.additional_unit : null,
    additional_amount: metered ? values.additional_amount : null,
    regions: values.regions.map((region) => ({
      region_codes: splitValues(region.region_codes_text),
      deliverable: region.deliverable,
      remote_surcharge_amount: region.deliverable ? region.remote_surcharge_amount : 0,
    })),
    free_rules: values.free_rules.map((rule) => {
      if (rule.type === 'cash_threshold') return { type: rule.type, threshold_amount: rule.threshold_amount }
      if (rule.type === 'quantity_threshold') return { type: rule.type, threshold_quantity: rule.threshold_quantity }
      return { type: rule.type, target_ids: splitValues(rule.target_ids_text) }
    }),
    enabled: values.enabled,
  }
}

function templateToInput(template: ManagedShippingTemplate, enabled: boolean): ManagedShippingTemplateInput {
  return { ...formToInput(templateToForm(template)), enabled }
}

function chargeSummary(template: ManagedShippingTemplate): string {
  if (template.charge_type === 'fixed') return `固定 ${formatMoney(template.fixed_amount)}`
  const unit = template.charge_type === 'by_quantity' ? '件' : '千克'
  return `首 ${template.first_unit ?? 0} ${unit} ${formatMoney(template.first_amount)}；续 ${template.additional_unit ?? 0} ${unit} +${formatMoney(template.additional_amount)}`
}

function freeRuleSummary(template: ManagedShippingTemplate): string[] {
  return template.free_rules.map((rule) => {
    if (rule.type === 'cash_threshold') return `现金满 ${formatMoney(rule.threshold_amount)}`
    if (rule.type === 'quantity_threshold') return `满 ${rule.threshold_quantity ?? 0} 件`
    if (rule.type === 'product') return `指定商品 ${rule.target_ids?.length ?? 0} 个`
    return `指定 SKU ${rule.target_ids?.length ?? 0} 个`
  })
}

export function ShippingTemplateManagementPanel({ templates, loading = false, onChanged }: ShippingTemplateManagementPanelProps) {
  const [editorOpen, setEditorOpen] = useState(false)
  const [source, setSource] = useState<ManagedShippingTemplate | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<ShippingTemplateForm>()
  const [messageApi, messageContext] = message.useMessage()
  const [modalApi, modalContext] = Modal.useModal()

  const openEditor = (template?: ManagedShippingTemplate) => {
    setSource(template ?? null)
    form.resetFields()
    form.setFieldsValue(template ? templateToForm(template) : emptyTemplate)
    setEditorOpen(true)
  }

  const save = async (values: ShippingTemplateForm) => {
    setSubmitting(true)
    try {
      const input = formToInput(values)
      if (source) await updateShippingTemplate(source.id, { ...input, version: source.version })
      else await createShippingTemplate(input)
      setEditorOpen(false)
      setSource(null)
      form.resetFields()
      await onChanged()
      messageApi.success(source ? '运费模板已更新，并生成新的规则版本。' : '运费模板已创建。')
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '运费模板保存失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const changeStatus = (template: ManagedShippingTemplate) => {
    const enabled = template.status !== 'active'
    modalApi.confirm({
      title: enabled ? `启用“${template.name}”` : `停用“${template.name}”`,
      content: enabled
        ? '启用后可供新商品和新订单使用；历史订单仍使用下单时锁定的模板版本。'
        : '停用后不能再被新商品选择，已下单订单和既有价格快照不受影响。',
      okText: enabled ? '确认启用' : '确认停用',
      okButtonProps: { danger: !enabled },
      onOk: async () => {
        setSubmitting(true)
        try {
          await updateShippingTemplate(template.id, { ...templateToInput(template, enabled), version: template.version })
          await onChanged()
          messageApi.success(enabled ? '运费模板已启用。' : '运费模板已停用。')
        } catch (cause) {
          messageApi.error(cause instanceof ApiError ? cause.message : '运费模板状态更新失败。')
          throw cause
        } finally {
          setSubmitting(false)
        }
      },
    })
  }

  return <>
    {messageContext}
    {modalContext}
    <Card
      bordered={false}
      style={{ marginTop: 16 }}
      title="运费模板"
      extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => openEditor()}>新建运费模板</Button>}
    >
      <Alert
        showIcon
        type="info"
        message="运费始终以现金结算。区域规则按列表顺序优先匹配，最后必须保留“*”默认规则；编辑、启停都会生成新版本，历史订单不回算。"
        style={{ marginBottom: 16 }}
      />
      <Table
        rowKey="id"
        loading={loading}
        dataSource={templates}
        pagination={false}
        scroll={{ x: 1080 }}
        columns={[
          { title: '模板', width: 190, render: (_: unknown, item: ManagedShippingTemplate) => <Space direction="vertical" size={0}><strong>{item.name}</strong><Typography.Text type="secondary">规则版本 v{item.definition_version}</Typography.Text></Space> },
          { title: '计费方式', width: 240, render: (_: unknown, item: ManagedShippingTemplate) => chargeSummary(item) },
          { title: '配送范围', width: 230, render: (_: unknown, item: ManagedShippingTemplate) => {
            const defaultRule = item.regions.find((region) => region.region_codes.includes('*'))
            const remoteCount = item.regions.filter((region) => region.remote_surcharge_amount > 0).length
            const blockedCount = item.regions.filter((region) => !region.deliverable).length
            return <Space direction="vertical" size={0}><span>默认{defaultRule?.deliverable ? '可配送' : '不配送'} · {Math.max(0, item.regions.length - 1)} 条区域规则</span><Typography.Text type="secondary">{blockedCount} 条禁配 · {remoteCount} 条偏远附加费</Typography.Text></Space>
          } },
          { title: '包邮规则', width: 210, render: (_: unknown, item: ManagedShippingTemplate) => {
            const labels = freeRuleSummary(item)
            return labels.length ? <Space size={[0, 4]} wrap>{labels.map((label, index) => <Tag key={`${label}-${index}`}>{label}</Tag>)}</Space> : <Typography.Text type="secondary">未设置</Typography.Text>
          } },
          { title: '状态', width: 100, dataIndex: 'status', render: (value: ManagedShippingTemplate['status']) => <Tag color={statusMeta[value].color}>{statusMeta[value].label}</Tag> },
          { title: '操作', width: 220, fixed: 'right' as const, render: (_: unknown, item: ManagedShippingTemplate) => <Space wrap><Button icon={<EditOutlined />} onClick={() => openEditor(item)}>编辑</Button>{item.status === 'active' ? <Button danger disabled={submitting} icon={<PauseCircleOutlined />} onClick={() => changeStatus(item)}>停用</Button> : <Button disabled={submitting} icon={<PlayCircleOutlined />} onClick={() => changeStatus(item)}>启用</Button>}</Space> },
        ]}
      />
    </Card>

    <Modal
      title={source ? `编辑运费模板：${source.name}` : '新建运费模板'}
      open={editorOpen}
      onCancel={() => { setEditorOpen(false); setSource(null) }}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      width={960}
      styles={{ body: { maxHeight: '72vh', overflowY: 'auto', paddingRight: 8 } }}
      okText={source ? '保存并生成新版本' : '创建模板'}
      cancelText="取消"
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={save} initialValues={emptyTemplate}>
        {source && <Alert showIcon type="warning" message={`当前为规则版本 v${source.definition_version}。保存后生成新版本，只影响后续新订单。`} style={{ marginBottom: 16 }} />}
        <Space align="start" wrap style={{ display: 'flex' }}>
          <Form.Item name="name" label="模板名称" rules={[{ required: true, message: '请输入模板名称' }]} style={{ flex: 1, minWidth: 260 }}><Input maxLength={120} placeholder="例如：全国标准配送" /></Form.Item>
          <Form.Item name="charge_type" label="计费方式" rules={[{ required: true }]} style={{ width: 210 }}><Select options={chargeTypeOptions} /></Form.Item>
          <Form.Item name="enabled" label="立即启用" valuePropName="checked"><Switch checkedChildren="启用" unCheckedChildren="停用" /></Form.Item>
        </Space>

        <Form.Item noStyle shouldUpdate={(before, after) => before.charge_type !== after.charge_type}>
          {({ getFieldValue }) => getFieldValue('charge_type') === 'fixed'
            ? <Form.Item name="fixed_amount" label="固定运费（分）" rules={[{ required: true, message: '请输入固定运费' }]}><InputNumber min={0} precision={0} style={{ width: 220 }} addonAfter="分" /></Form.Item>
            : <Space align="start" wrap>
              <Form.Item name="first_unit" label={getFieldValue('charge_type') === 'by_quantity' ? '首件数量（件）' : '首重（千克）'} rules={[{ required: true, message: '请输入首段单位' }]}><InputNumber min={0.001} /></Form.Item>
              <Form.Item name="first_amount" label="首段运费（分）" rules={[{ required: true, message: '请输入首段运费' }]}><InputNumber min={0} precision={0} /></Form.Item>
              <Form.Item name="additional_unit" label={getFieldValue('charge_type') === 'by_quantity' ? '续件单位（件）' : '续重单位（千克）'} rules={[{ required: true, message: '请输入续段单位' }]}><InputNumber min={0.001} /></Form.Item>
              <Form.Item name="additional_amount" label="每续段加收（分）" rules={[{ required: true, message: '请输入续段运费' }]}><InputNumber min={0} precision={0} /></Form.Item>
            </Space>}
        </Form.Item>

        <Typography.Title level={5}>配送区域与偏远附加费</Typography.Title>
        <Typography.Paragraph type="secondary">可填写省、市或区县名称，多个编码用逗号或换行分隔。上方规则优先匹配；“*”表示未命中特定区域时的默认规则。</Typography.Paragraph>
        <Form.List name="regions" rules={[{ validator: async (_, regions: ShippingRegionForm[]) => {
          if (!regions?.length) throw new Error('至少配置一条配送规则')
          if (!regions.some((region) => splitValues(region.region_codes_text).includes('*'))) throw new Error('必须保留一条包含“*”的默认配送规则')
        } }]}>
          {(fields, { add, remove, move }, { errors }) => <Space direction="vertical" size={12} style={{ width: '100%' }}>
            {fields.map((field, index) => <Card
              key={field.key}
              size="small"
              title={`区域规则 ${index + 1}`}
              extra={<Space><Button type="text" size="small" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, index - 1)} /><Button type="text" size="small" icon={<ArrowDownOutlined />} disabled={index === fields.length - 1} onClick={() => move(index, index + 1)} />{fields.length > 1 && <Button danger type="link" size="small" onClick={() => remove(field.name)}>删除</Button>}</Space>}
            >
              <Space align="start" wrap>
                <Form.Item name={[field.name, 'region_codes_text']} label="地区编码/名称" rules={[{ required: true, message: '请输入地区编码、名称或 *' }, { validator: async (_, text: string) => { if (splitValues(text).some((code) => code.length > 32)) throw new Error('单个地区编码不能超过 32 个字符') } }]}><Input.TextArea autoSize={{ minRows: 1, maxRows: 3 }} style={{ width: 360 }} placeholder="例如：新疆，西藏；默认规则填 *" /></Form.Item>
                <Form.Item name={[field.name, 'deliverable']} label="允许配送" valuePropName="checked"><Switch checkedChildren="可配送" unCheckedChildren="不配送" /></Form.Item>
                <Form.Item noStyle shouldUpdate>
                  {({ getFieldValue }) => getFieldValue(['regions', field.name, 'deliverable']) ? <Form.Item name={[field.name, 'remote_surcharge_amount']} label="偏远附加费（分）" rules={[{ required: true }]}><InputNumber min={0} precision={0} /></Form.Item> : null}
                </Form.Item>
              </Space>
            </Card>)}
            <Form.ErrorList errors={errors} />
            <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ region_codes_text: '', deliverable: true, remote_surcharge_amount: 0 })}>添加区域规则</Button>
          </Space>}
        </Form.List>

        <Typography.Title level={5} style={{ marginTop: 24 }}>包邮规则</Typography.Title>
        <Typography.Paragraph type="secondary">满足任意一条规则即免除基础运费和偏远附加费；现金门槛仅按商品现金实付小计判断。</Typography.Paragraph>
        <Form.List name="free_rules">
          {(fields, { add, remove }) => <Space direction="vertical" size={12} style={{ width: '100%' }}>
            {fields.map((field, index) => <Card key={field.key} size="small" title={`包邮规则 ${index + 1}`} extra={<Button danger type="link" size="small" onClick={() => remove(field.name)}>删除</Button>}>
              <Space align="start" wrap>
                <Form.Item name={[field.name, 'type']} label="规则类型" rules={[{ required: true }]}><Select style={{ width: 210 }} options={freeRuleOptions} /></Form.Item>
                <Form.Item noStyle shouldUpdate>
                  {({ getFieldValue }) => {
                    const type = getFieldValue(['free_rules', field.name, 'type']) as ManagedShippingFreeRuleType
                    if (type === 'cash_threshold') return <Form.Item name={[field.name, 'threshold_amount']} label="现金门槛（分）" rules={[{ required: true, message: '请输入现金门槛' }]}><InputNumber min={0} precision={0} /></Form.Item>
                    if (type === 'quantity_threshold') return <Form.Item name={[field.name, 'threshold_quantity']} label="件数门槛" rules={[{ required: true, message: '请输入件数门槛' }]}><InputNumber min={1} precision={0} /></Form.Item>
                    return <Form.Item name={[field.name, 'target_ids_text']} label={type === 'product' ? '商品 ID' : 'SKU ID'} rules={[{ required: true, message: '至少填写一个目标 ID' }]}><Input.TextArea autoSize={{ minRows: 1, maxRows: 3 }} style={{ width: 420 }} placeholder="多个 ID 用逗号或换行分隔" /></Form.Item>
                  }}
                </Form.Item>
              </Space>
            </Card>)}
            <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ type: 'cash_threshold', threshold_amount: 9900 })}>添加包邮规则</Button>
          </Space>}
        </Form.List>
      </Form>
    </Modal>
  </>
}
