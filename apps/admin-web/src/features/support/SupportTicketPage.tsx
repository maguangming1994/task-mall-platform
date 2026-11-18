import { CustomerServiceOutlined, MessageOutlined, SendOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Drawer, Empty, Form, Input, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { ApiError, listManagedSupportTickets, replyManagedSupportTicket, updateManagedSupportTicket, type ManagedSupportTicket } from '../../api/admin'

const statusMeta: Record<string, { label: string; color: string }> = {
  open: { label: '待受理', color: 'gold' }, processing: { label: '处理中', color: 'processing' }, waiting_user: { label: '待用户回复', color: 'orange' },
  waiting_service: { label: '待客服回复', color: 'magenta' }, resolved: { label: '已解决', color: 'success' }, closed: { label: '已关闭', color: 'default' },
}
const priorityMeta: Record<string, { label: string; color: string }> = {
  low: { label: '低', color: 'default' }, normal: { label: '普通', color: 'blue' }, high: { label: '高', color: 'orange' }, urgent: { label: '紧急', color: 'red' },
}
const categories: Record<string, string> = { task: '任务', order: '订单', after_sale: '退款售后', assets: '积分与余额', account: '账号安全', community: '社区内容', other: '其他' }

export function SupportTicketPage() {
  const [items, setItems] = useState<ManagedSupportTicket[]>([])
  const [selected, setSelected] = useState<ManagedSupportTicket | null>(null)
  const [loading, setLoading] = useState(false)
  const [filters, setFilters] = useState({ status: '', category: '' })
  const [form] = Form.useForm<{ content: string; status: 'processing' | 'waiting_user' | 'resolved'; notify_wechat: boolean }>()
  const [messageApi, contextHolder] = message.useMessage()
  const load = useCallback(async () => {
    setLoading(true)
    try { setItems((await listManagedSupportTickets(filters)).items) }
    catch (error) { messageApi.error(error instanceof ApiError ? error.message : '客服工单加载失败') }
    finally { setLoading(false) }
  }, [filters, messageApi])
  useEffect(() => { void load() }, [load])
  const pendingCount = useMemo(() => items.filter((item) => ['open', 'waiting_service'].includes(item.status)).length, [items])

  const sync = (ticket: ManagedSupportTicket) => { setSelected(ticket); setItems((current) => current.map((item) => item.id === ticket.id ? ticket : item)) }
  const update = async (input: { status?: ManagedSupportTicket['status']; priority?: ManagedSupportTicket['priority'] }) => {
    if (!selected) return
    try { sync(await updateManagedSupportTicket(selected.id, input)); messageApi.success('工单状态已更新') }
    catch (error) { messageApi.error(error instanceof ApiError ? error.message : '更新失败') }
  }
  const reply = async (values: { content: string; status: 'processing' | 'waiting_user' | 'resolved'; notify_wechat: boolean }) => {
    if (!selected) return
    try {
      const updated = await replyManagedSupportTicket(selected.id, values.content, values.status, Boolean(values.notify_wechat))
      sync(updated); form.resetFields()
      const wechat = updated.wechat_notification
      if (wechat && wechat.status !== 'sent') messageApi.warning(`回复已发送；微信客服消息未送达：${wechat.message}`)
      else if (wechat?.status === 'sent') messageApi.success('回复已发送，并同步发送微信客服消息')
      else messageApi.success('回复已发送')
    } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '发送失败') }
  }

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">CAMPUS SERVICE DESK</Typography.Text><Typography.Title level={2}>客服工单</Typography.Title><Typography.Paragraph type="secondary">集中处理任务、订单、资产、账号和社区咨询，完整保留沟通记录。</Typography.Paragraph></div><Space><Tag color="warning">待响应 {pendingCount}</Tag><Button onClick={() => void load()}>刷新</Button></Space></div>
    <Alert showIcon type="info" message="客服处理原则" description="客服回复、状态与优先级调整均记录处理人；涉及资产调整、退款和隐私操作仍需进入对应业务模块执行。" style={{ marginBottom: 18 }} />
    <Card bordered={false}>
      <Space wrap style={{ marginBottom: 18 }}>
        <Select value={filters.status} style={{ width: 150 }} onChange={(status) => setFilters((value) => ({ ...value, status }))} options={[{ value: '', label: '全部状态' }, ...Object.entries(statusMeta).map(([value, meta]) => ({ value, label: meta.label }))]} />
        <Select value={filters.category} style={{ width: 150 }} onChange={(category) => setFilters((value) => ({ ...value, category }))} options={[{ value: '', label: '全部分类' }, ...Object.entries(categories).map(([value, label]) => ({ value, label }))]} />
      </Space>
      <Table rowKey="id" loading={loading} dataSource={items} pagination={{ pageSize: 12 }} onRow={(record) => ({ onClick: () => setSelected(record), style: { cursor: 'pointer' } })} columns={[
        { title: '工单', render: (_, item) => <Space direction="vertical" size={0}><Typography.Text strong>{item.subject}</Typography.Text><Typography.Text type="secondary" copyable={{ text: item.id }}>{item.id}</Typography.Text></Space> },
        { title: '分类', dataIndex: 'category', render: (value) => categories[value] ?? value },
        { title: '状态', dataIndex: 'status', render: (value) => <Tag color={statusMeta[value]?.color}>{statusMeta[value]?.label ?? value}</Tag> },
        { title: '优先级', dataIndex: 'priority', render: (value) => <Tag color={priorityMeta[value]?.color}>{priorityMeta[value]?.label ?? value}</Tag> },
        { title: '沟通', render: (_, item) => `${item.messages.length} 条` },
        { title: '更新时间', dataIndex: 'updated_at', render: (value) => new Date(value).toLocaleString('zh-CN', { hour12: false }) },
        { title: '操作', render: (_, item) => <Button type="link" icon={<MessageOutlined />} onClick={(event) => { event.stopPropagation(); setSelected(item) }}>处理</Button> },
      ]} />
    </Card>
    <Drawer open={Boolean(selected)} onClose={() => setSelected(null)} width={600} title={<Space><CustomerServiceOutlined />客服处理台</Space>}>
      {selected ? <Space direction="vertical" size={18} style={{ width: '100%' }}>
        <Card size="small"><Space direction="vertical" style={{ width: '100%' }}><Typography.Title level={4} style={{ margin: 0 }}>{selected.subject}</Typography.Title><Space wrap><Tag>{categories[selected.category]}</Tag><Tag color={statusMeta[selected.status]?.color}>{statusMeta[selected.status]?.label}</Tag><Tag color={priorityMeta[selected.priority]?.color}>{priorityMeta[selected.priority]?.label}优先级</Tag></Space><Typography.Text type="secondary">用户编号 {selected.user_id} · {selected.id}</Typography.Text></Space></Card>
        <Space wrap><Select value={selected.status} style={{ width: 150 }} onChange={(status) => void update({ status })} options={Object.entries(statusMeta).map(([value, meta]) => ({ value, label: meta.label }))} /><Select value={selected.priority} style={{ width: 140 }} onChange={(priority) => void update({ priority })} options={Object.entries(priorityMeta).map(([value, meta]) => ({ value, label: `${meta.label}优先级` }))} /></Space>
        <div style={{ display: 'grid', gap: 12, maxHeight: 390, overflow: 'auto', padding: 4 }}>
          {selected.messages.length ? selected.messages.map((item) => <div key={item.id} style={{ maxWidth: '86%', justifySelf: item.sender_type === 'admin' ? 'end' : 'start', padding: 14, borderRadius: 16, background: item.sender_type === 'admin' ? '#eaf6f1' : '#f3f5f7' }}><Typography.Text type="secondary" style={{ fontSize: 12 }}>{item.sender_type === 'admin' ? '客服' : '用户'} · {new Date(item.created_at).toLocaleString('zh-CN', { hour12: false })}</Typography.Text><Typography.Paragraph style={{ margin: '6px 0 0', whiteSpace: 'pre-wrap' }}>{item.content}</Typography.Paragraph></div>) : <Empty description="暂无沟通记录" />}
        </div>
        {!['resolved', 'closed'].includes(selected.status) && <Form form={form} layout="vertical" initialValues={{ status: 'waiting_user', notify_wechat: false }} onFinish={reply}><Form.Item name="content" label="回复用户" rules={[{ required: true, message: '请输入回复内容' }]}><Input.TextArea rows={5} maxLength={5000} showCount placeholder="说明处理结果或需要用户补充的材料…" /></Form.Item><Form.Item name="notify_wechat" valuePropName="checked"><Checkbox>同时发送微信客服消息（仅限公众号内授权且 48 小时内互动过的用户）</Checkbox></Form.Item><Space><Form.Item name="status" noStyle><Select style={{ width: 150 }} options={[{ value: 'waiting_user', label: '回复后待用户' }, { value: 'processing', label: '保持处理中' }, { value: 'resolved', label: '回复并解决' }]} /></Form.Item><Button type="primary" htmlType="submit" icon={<SendOutlined />}>发送回复</Button></Space></Form>}
      </Space> : null}
    </Drawer>
  </>
}
