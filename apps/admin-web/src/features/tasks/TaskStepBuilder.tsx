import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, DownOutlined, PlusOutlined } from '@ant-design/icons'
import { Button, Card, Dropdown, Input, InputNumber, Space, Switch, Tag, Typography } from 'antd'
import { MediaGalleryInput, type GalleryImageValue } from '../../components/MediaGalleryInput'
import type { ManagedTaskStep, TaskStepKind } from '../../api/admin'
import './TaskStepBuilder.css'

interface Props {
  value?: ManagedTaskStep[]
  onChange?: (steps: ManagedTaskStep[]) => void
}

const stepTypes: Array<{ value: TaskStepKind; label: string; note: string }> = [
  { value: 'link', label: '访问网址', note: '展示安全链接，由用户打开后确认完成' },
  { value: 'qr_code', label: '扫描二维码', note: '展示二维码图片，适合小程序或活动入口' },
  { value: 'copy_text', label: '复制内容', note: '提供口令、邀请码或标准文案的一键复制' },
  { value: 'image_guide', label: '图文说明', note: '展示示例图或操作指引，不收集材料' },
  { value: 'verification_image', label: '上传截图凭证', note: '收集一张或多张截图，供审核人员核验' },
  { value: 'form_input', label: '填写结果信息', note: '收集订单号、手机号后四位或文字说明' },
  { value: 'confirmation', label: '完成确认', note: '用户勾选确认已完成该步骤' },
]

const typeMeta = Object.fromEntries(stepTypes.map((item) => [item.value, item])) as Record<TaskStepKind, typeof stepTypes[number]>

function makeStep(kind: TaskStepKind): ManagedTaskStep {
  const base = {
    id: `step-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    kind,
    title: typeMeta[kind].label,
    description: '',
    required: true,
    input_type: 'none' as ManagedTaskStep['input_type'],
    link_url: null,
    copy_text: null,
    example_image_url: null,
    button_text: null,
    placeholder: null,
    min_files: undefined,
    max_files: undefined,
  }
  if (kind === 'link') return { ...base, input_type: 'link', button_text: '打开链接', link_url: '' }
  if (kind === 'copy_text') return { ...base, copy_text: '', button_text: '复制内容' }
  if (kind === 'verification_image') return { ...base, input_type: 'image', min_files: 1, max_files: 3 }
  if (kind === 'form_input') return { ...base, input_type: 'text', placeholder: '请输入任务结果' }
  return base
}

export function TaskStepBuilder({ value = [], onChange }: Props) {
  const update = (index: number, patch: Partial<ManagedTaskStep>) => onChange?.(value.map((step, stepIndex) => stepIndex === index ? { ...step, ...patch } : step))
  const remove = (index: number) => onChange?.(value.filter((_, stepIndex) => stepIndex !== index))
  const move = (index: number, offset: number) => {
    const target = index + offset
    if (target < 0 || target >= value.length) return
    const next = [...value]
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange?.(next)
  }
  const changeKind = (index: number, kind: TaskStepKind) => {
    const replacement = makeStep(kind)
    update(index, { ...replacement, id: value[index].id, title: value[index].title || replacement.title })
  }
  const add = (kind: TaskStepKind) => {
    if (value.length >= 20) return
    onChange?.([...value, makeStep(kind)])
  }
  const imageValue = (step: ManagedTaskStep): GalleryImageValue[] => step.example_image_url ? [{ url: step.example_image_url, alt: step.title, sort_order: 0 }] : []

  return <Space direction="vertical" size={12} style={{ width: '100%' }}>
    <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
      创建顺序就是用户执行顺序。访问、扫码和复制属于动作步骤；截图和填写信息属于审核证据，服务端会按必填规则校验。
    </Typography.Paragraph>
    {value.map((step, index) => <Card className="task-step-card" key={step.id} size="small" title={<Space><Tag color="blue">第 {index + 1} 步</Tag><strong>{typeMeta[step.kind]?.label ?? '自定义步骤'}</strong></Space>} extra={<Space>
      <Button type="text" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, -1)} aria-label="上移步骤" />
      <Button type="text" icon={<ArrowDownOutlined />} disabled={index === value.length - 1} onClick={() => move(index, 1)} aria-label="下移步骤" />
      <Button danger type="text" icon={<DeleteOutlined />} onClick={() => remove(index)}>删除</Button>
    </Space>}>
      <Space direction="vertical" size={12} style={{ width: '100%' }}>
        <div className="task-step-config-grid">
          <label className="task-step-field"><span className="task-step-field-label">步骤类型</span><Dropdown trigger={['click']} menu={{ items: stepTypes.map((item) => ({ key: item.value, label: <div><strong>{item.label}</strong><div className="task-step-menu-note">{item.note}</div></div> })), onClick: ({ key }) => changeKind(index, key as TaskStepKind) }}><Button className="task-step-type-button"><span>{typeMeta[step.kind]?.label ?? '选择步骤类型'}</span><DownOutlined /></Button></Dropdown></label>
          <label className="task-step-field task-step-title-field"><span className="task-step-field-label">步骤标题</span><Input value={step.title} maxLength={100} placeholder="例如：上传订单截图" onChange={(event) => update(index, { title: event.target.value })} /></label>
          <label className="task-step-required-field"><span className="task-step-field-label">是否必做</span><Switch checked={step.required} checkedChildren="必做" unCheckedChildren="选做" onChange={(required) => update(index, { required })} /></label>
        </div>
        <Input.TextArea value={step.description ?? ''} rows={2} maxLength={2000} showCount placeholder="说明用户要做什么、什么样的结果可以通过审核" onChange={(event) => update(index, { description: event.target.value })} />
        {step.kind === 'link' && <Space direction="vertical" style={{ width: '100%' }}><Input addonBefore="HTTPS 地址" value={step.link_url ?? ''} placeholder="https://example.com/activity" onChange={(event) => update(index, { link_url: event.target.value.trim() })} /><Input addonBefore="按钮文字" value={step.button_text ?? ''} maxLength={20} onChange={(event) => update(index, { button_text: event.target.value })} /></Space>}
        {step.kind === 'copy_text' && <Space direction="vertical" style={{ width: '100%' }}><Input.TextArea value={step.copy_text ?? ''} rows={3} maxLength={5000} placeholder="用户需要一键复制的口令、邀请码或文案" onChange={(event) => update(index, { copy_text: event.target.value })} /><Input addonBefore="按钮文字" value={step.button_text ?? ''} maxLength={20} onChange={(event) => update(index, { button_text: event.target.value })} /></Space>}
        {['qr_code', 'image_guide', 'verification_image'].includes(step.kind) && <MediaGalleryInput max={1} value={imageValue(step)} onChange={(images) => update(index, { example_image_url: images[0]?.url ?? null })} primaryLabel={step.kind === 'qr_code' ? '二维码' : step.kind === 'verification_image' ? '凭证示例' : '说明图'} itemLabel="图片" helperText="支持本地上传和图片网址导入，图片会统一存入素材存储" />}
        {step.kind === 'verification_image' && <Space wrap><div><Typography.Text>最少上传</Typography.Text><InputNumber style={{ display: 'block', marginTop: 6 }} min={1} max={9} value={step.min_files ?? 1} onChange={(min_files) => update(index, { min_files: min_files ?? 1 })} /></div><div><Typography.Text>最多上传</Typography.Text><InputNumber style={{ display: 'block', marginTop: 6 }} min={1} max={9} value={step.max_files ?? 3} onChange={(max_files) => update(index, { max_files: max_files ?? 3 })} /></div></Space>}
        {step.kind === 'form_input' && <Input addonBefore="输入提示" value={step.placeholder ?? ''} maxLength={120} placeholder="例如：请输入订单号" onChange={(event) => update(index, { placeholder: event.target.value })} />}
        <Typography.Text type="secondary">{typeMeta[step.kind]?.note}</Typography.Text>
      </Space>
    </Card>)}
    <div className="task-step-add-row"><Dropdown trigger={['click']} disabled={value.length >= 20} menu={{ items: stepTypes.map((item) => ({ key: item.value, label: <div><strong>{item.label}</strong><div className="task-step-menu-note">{item.note}</div></div> })), onClick: ({ key }) => add(key as TaskStepKind) }}><Button type="dashed" icon={<PlusOutlined />}>添加任务步骤 <DownOutlined /></Button></Dropdown><Typography.Text type="secondary">已配置 {value.length}/20 步</Typography.Text></div>
  </Space>
}
