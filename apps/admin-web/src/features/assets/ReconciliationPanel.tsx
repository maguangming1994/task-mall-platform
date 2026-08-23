import { Alert, Button, Card, Form, Input, Modal, Select, Table, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { ApiError, importReconciliationRun, listReconciliationRuns, resolveReconciliationEntry, type ReconciliationEntry, type ReconciliationRun } from '../../api/admin'
import { BusinessStatusTag } from '../../components/BusinessStatusTag'
import { businessLabel, reconciliationResolutionLabels, reconciliationResultLabels, reconciliationStatusLabels } from '../../constants/businessLabels'

interface ImportForm { business_date: string; rows_json: string }
interface ResolveForm { action: 'confirmed_channel_truth' | 'confirmed_local_truth' | 'ignored_with_reason'; reason: string }

export function ReconciliationPanel() {
  const [runs, setRuns] = useState<ReconciliationRun[]>([])
  const [importOpen, setImportOpen] = useState(false)
  const [resolveTarget, setResolveTarget] = useState<ReconciliationEntry | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [importForm] = Form.useForm<ImportForm>()
  const [resolveForm] = Form.useForm<ResolveForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const load = useCallback(async () => { try { setRuns((await listReconciliationRuns()).items) } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '对账批次加载失败。') } }, [messageApi])
  useEffect(() => { void load() }, [load])

  async function submitImport(values: ImportForm) {
    setSubmitting(true)
    try {
      const parsed: unknown = JSON.parse(values.rows_json)
      if (!Array.isArray(parsed)) throw new Error('账单 JSON 必须是数组。')
      await importReconciliationRun(values.business_date, parsed as Array<{ payment_no: string; provider_trade_no?: string; cash_amount: number; state: string }>)
      setImportOpen(false); importForm.resetFields(); await load(); messageApi.success('账单已对账，差异已进入人工队列。')
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '账单导入失败。') }
    finally { setSubmitting(false) }
  }

  async function submitResolve(values: ResolveForm) {
    if (!resolveTarget) return
    setSubmitting(true)
    try { await resolveReconciliationEntry(resolveTarget.id, values.action, values.reason); setResolveTarget(null); resolveForm.resetFields(); await load(); messageApi.success('差异处置已追加审计记录。') }
    catch (error) { messageApi.error(error instanceof ApiError ? error.message : '差异处置失败。') }
    finally { setSubmitting(false) }
  }

  return <>
    {contextHolder}
    <Card bordered={false} style={{ marginTop: 16 }} title="微信支付日对账" extra={<Button type="primary" onClick={() => setImportOpen(true)}>导入标准账单</Button>}>
      <Alert showIcon type="info" message="渠道成功记录与本地成功支付按支付单号、金额和状态逐笔匹配；差异只能追加处置记录，不能直接覆盖支付事实。" />
      <Table style={{ marginTop: 16 }} rowKey="id" dataSource={runs} pagination={false} scroll={{ x: 860 }} expandable={{ expandedRowRender: (run) => <Table rowKey="id" dataSource={run.entries} pagination={false} size="small" scroll={{ x: 760 }} columns={[
        { title: '支付单', dataIndex: 'payment_no' }, { title: '渠道/本地金额', render: (_: unknown, entry: ReconciliationEntry) => `${entry.provider_amount ?? '-'} / ${entry.local_amount ?? '-'}` },
        { title: '结果', dataIndex: 'result', render: (value: string) => <BusinessStatusTag status={value === 'matched' ? 'completed' : 'failed'} label={businessLabel(reconciliationResultLabels, value)} /> }, { title: '处置', dataIndex: 'resolution_status', render: (value: string) => businessLabel(reconciliationResolutionLabels, value) },
        { title: '操作', render: (_: unknown, entry: ReconciliationEntry) => entry.resolution_status === 'open' ? <Button onClick={() => setResolveTarget(entry)}>处置差异</Button> : null },
      ]} /> }} columns={[
        { title: '账期', dataIndex: 'business_date' }, { title: '状态', dataIndex: 'status', render: (value: string) => <BusinessStatusTag status={value === 'matched' ? 'completed' : value === 'processing' ? 'processing' : 'pending'} label={businessLabel(reconciliationStatusLabels, value)} /> },
        { title: '渠道笔数/金额', render: (_: unknown, run: ReconciliationRun) => `${run.provider_count} / ${run.provider_amount}` }, { title: '本地笔数/金额', render: (_: unknown, run: ReconciliationRun) => `${run.local_count} / ${run.local_amount}` }, { title: '差异', dataIndex: 'difference_count' },
      ]} />
    </Card>
    <Modal title="导入标准化微信账单" open={importOpen} onCancel={() => setImportOpen(false)} onOk={() => importForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={importForm} layout="vertical" onFinish={submitImport} initialValues={{ business_date: new Date().toISOString().slice(0, 10), rows_json: '[\n  {"payment_no":"P...","provider_trade_no":"wx...","cash_amount":1990,"state":"SUCCESS"}\n]' }}><Form.Item name="business_date" label="账单日期" rules={[{ required: true }]}><Input type="date" /></Form.Item><Form.Item name="rows_json" label="标准账单 JSON" rules={[{ required: true }]}><Input.TextArea rows={9} /></Form.Item><Typography.Text type="secondary">正式环境由渠道下载器转换为同一结构；此入口用于人工补录和验收。</Typography.Text></Form></Modal>
    <Modal title="处置对账差异" open={resolveTarget !== null} onCancel={() => setResolveTarget(null)} onOk={() => resolveForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={resolveForm} layout="vertical" onFinish={submitResolve}><Form.Item name="action" label="结论" rules={[{ required: true }]}><Select options={[{ value: 'confirmed_channel_truth', label: '确认渠道为准，转异常补偿' }, { value: 'confirmed_local_truth', label: '确认本地为准' }, { value: 'ignored_with_reason', label: '有理由忽略' }]} /></Form.Item><Form.Item name="reason" label="处置说明" rules={[{ required: true, min: 5 }]}><Input.TextArea rows={4} /></Form.Item></Form></Modal>
  </>
}
