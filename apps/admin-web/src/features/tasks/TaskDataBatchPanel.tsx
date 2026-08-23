import { DownloadOutlined, EyeOutlined, InboxOutlined, PlusOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, Modal, Space, Table, Tag, Typography, Upload, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { ApiError, downloadTaskDataBatch, getTaskDataBatch, importTaskDataBatch, listTaskDataBatches, type TaskDataBatch } from '../../api/admin'

interface Props { canManage: boolean; onChanged?: () => void }
interface ImportForm { name: string; mapping: string; file: { fileList?: Array<{ originFileObj?: File }> } }

const statusMeta: Record<string, { label: string; color: string }> = {
  ready: { label: '预检通过', color: 'success' }, preflight_failed: { label: '预检失败', color: 'error' }, locked: { label: '已绑定发布', color: 'processing' },
}

export function TaskDataBatchPanel({ canManage, onChanged }: Props) {
  const [items, setItems] = useState<TaskDataBatch[]>([])
  const [preview, setPreview] = useState<TaskDataBatch | null>(null)
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [form] = Form.useForm<ImportForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const load = useCallback(async () => { setLoading(true); try { setItems((await listTaskDataBatches()).items) } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '数据批次加载失败。') } finally { setLoading(false) } }, [messageApi])
  useEffect(() => { void load() }, [load])

  const submit = async (values: ImportForm) => {
    const file = values.file?.fileList?.[0]?.originFileObj
    if (!file) return messageApi.error('请选择 CSV 文件。')
    let fieldMapping: Record<string, string>
    try { fieldMapping = JSON.parse(values.mapping) as Record<string, string> } catch { return messageApi.error('字段映射必须是有效 JSON，例如 {"student_id":"学号"}。') }
    setLoading(true)
    try {
      const result = await importTaskDataBatch({ name: values.name, file, field_mapping: fieldMapping })
      setOpen(false); form.resetFields(); await load(); onChanged?.()
      messageApi.success(result.invalid_count ? `已导入，但有 ${result.invalid_count} 行未通过预检。` : '数据批次已导入并通过预检。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '数据批次导入失败。') }
    finally { setLoading(false) }
  }

  const inspect = async (item: TaskDataBatch) => { try { setPreview(await getTaskDataBatch(item.id)) } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '批次预览加载失败。') } }
  const download = async (item: TaskDataBatch) => {
    try { const blob = await downloadTaskDataBatch(item.id); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `${item.name}-结果.csv`; link.click(); URL.revokeObjectURL(url) }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '结果导出失败。') }
  }

  return <Card bordered={false} style={{ marginTop: 16 }} title="批量数据任务" extra={canManage && <Button icon={<PlusOutlined />} onClick={() => setOpen(true)}>导入 CSV 批次</Button>}>
    {contextHolder}
    <Alert showIcon type="info" message="CSV 先完成字段映射与逐行预检，再绑定任务草稿；发布后锁定快照，领取时一人原子分配一条，放弃或最终驳回会释放回池。" style={{ marginBottom: 16 }} />
    <Table rowKey="id" loading={loading} dataSource={items} pagination={false} locale={{ emptyText: '暂无数据批次' }} columns={[
      { title: '批次', render: (_: unknown, item: TaskDataBatch) => <Space direction="vertical" size={0}><strong>{item.name}</strong><Typography.Text type="secondary">{item.original_filename}</Typography.Text></Space> },
      { title: '行数', render: (_: unknown, item: TaskDataBatch) => `${item.valid_count} 有效 / ${item.invalid_count} 异常 / 共 ${item.row_count}` },
      { title: '分配', render: (_: unknown, item: TaskDataBatch) => `${item.available_count} 可用 / ${item.allocated_count} 已分配` },
      { title: '状态', dataIndex: 'status', render: (value: string) => { const meta = statusMeta[value] ?? { label: value, color: 'default' }; return <Tag color={meta.color}>{meta.label}</Tag> } },
      { title: '操作', render: (_: unknown, item: TaskDataBatch) => <Space><Button icon={<EyeOutlined />} onClick={() => void inspect(item)}>预览</Button><Button icon={<DownloadOutlined />} onClick={() => void download(item)}>导出结果</Button></Space> },
    ]} />
    <Modal title="导入任务数据批次" open={open} onCancel={() => setOpen(false)} onOk={() => form.submit()} okText="导入并预检" confirmLoading={loading} destroyOnHidden><Form form={form} layout="vertical" onFinish={submit} initialValues={{ mapping: '{\n  "student_id": "学号",\n  "target": "任务对象"\n}' }}><Form.Item name="name" label="批次名称" rules={[{ required: true }]}><Input maxLength={120} /></Form.Item><Form.Item name="file" label="CSV 文件" rules={[{ required: true }]}><Upload.Dragger accept=".csv,text/csv" maxCount={1} beforeUpload={() => false}><p><InboxOutlined style={{ fontSize: 32 }} /></p><p>选择 UTF-8 CSV 文件</p><Typography.Text type="secondary">单批最多 100000 行、20 MB</Typography.Text></Upload.Dragger></Form.Item><Form.Item name="mapping" label="字段映射 JSON" extra="键是任务输出字段，值是 CSV 表头；输出字段使用英文字母、数字和下划线。" rules={[{ required: true }]}><Input.TextArea rows={6} /></Form.Item></Form></Modal>
    <Modal title={preview ? `批次预览 · ${preview.name}` : '批次预览'} width={860} open={preview !== null} onCancel={() => setPreview(null)} footer={null}><Table rowKey="row_no" dataSource={preview?.preview ?? []} pagination={false} scroll={{ x: true }} columns={[{ title: '行', dataIndex: 'row_no', width: 70 }, { title: '映射结果', dataIndex: 'mapped_payload', render: (value: Record<string, string>) => <Typography.Text code>{JSON.stringify(value)}</Typography.Text> }, { title: '预检', render: (_: unknown, row) => row.validation_errors?.length ? <Tag color="error">{row.validation_errors.join('；')}</Tag> : <Tag color="success">通过</Tag> }]} /></Modal>
  </Card>
}
