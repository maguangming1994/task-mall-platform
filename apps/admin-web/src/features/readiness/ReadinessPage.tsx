import { CheckCircleOutlined, CloseCircleOutlined, ReloadOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Progress, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { ApiError, getProductionReadiness, type ProductionReadiness } from '../../api/admin'

export function ReadinessPage() {
  const [result, setResult] = useState<ProductionReadiness | null>(null)
  const [loading, setLoading] = useState(false)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setResult(await getProductionReadiness())
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '生产就绪检查失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const percent = result ? Math.round((result.summary.passed / result.summary.total) * 100) : 0
  return (
    <>
      {contextHolder}
      <div className="page-heading">
        <div>
          <Typography.Text className="eyebrow">PRODUCTION READINESS</Typography.Text>
          <Typography.Title level={2}>上线准备检查</Typography.Title>
          <Typography.Paragraph type="secondary">只显示配置是否就绪，不回传密钥值。发布流水线应执行严格检查并在未通过时停止。</Typography.Paragraph>
        </div>
        <Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>重新检查</Button>
      </div>
      {result && <Alert
        showIcon type={result.ready ? 'success' : 'warning'}
        message={result.ready ? '生产发布前置条件已全部满足' : `仍有 ${result.summary.failed} 项前置条件未满足`}
        description={result.ready ? '仍需按 UAT 和发布清单执行真机、回滚及监控验收。' : '本地开发可以继续；生产发布和真实渠道验收会保持关闭。'}
      />}
      <Card bordered={false} style={{ marginTop: 16 }}>
        <Space direction="vertical" style={{ width: '100%' }} size={20}>
          <Progress percent={percent} status={result?.ready ? 'success' : 'active'} />
          <Table
            rowKey="code" loading={loading} dataSource={result?.checks ?? []} pagination={false}
            columns={[
              { title: '检查项', dataIndex: 'label' },
              { title: '状态', dataIndex: 'ready', width: 120, render: (ready: boolean) => ready
                ? <Tag color="success" icon={<CheckCircleOutlined />}>通过</Tag>
                : <Tag color="error" icon={<CloseCircleOutlined />}>未通过</Tag> },
              { title: '待办', dataIndex: 'action', render: (value: string | null) => value ?? '-' },
            ]}
          />
        </Space>
      </Card>
    </>
  )
}
