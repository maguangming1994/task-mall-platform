import { ReloadOutlined, SearchOutlined } from '@ant-design/icons'
import { Alert, Button, Form, Input, Select, Space, Table, Tag, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useState } from 'react'
import {
  listCommunityContentSecurityRecords,
  type CommunityContentSecurityProvider,
  type CommunityContentSecurityRecord,
  type CommunityContentSecurityRecordStats,
  type CommunityContentSecurityRiskLevel,
  type CommunityContentSecurityVerdict,
} from '../../api/admin'
import { PageHead, StatCard } from '../../components/xq'
import { errorText, formatTime, securityProviderLabels, securityRiskLabelLabel, securityRiskMeta, securityVerdictMeta } from './shared'

interface RecordFilters {
  content_type?: 'post' | 'comment'
  provider?: CommunityContentSecurityProvider
  verdict?: CommunityContentSecurityVerdict
  risk_level?: CommunityContentSecurityRiskLevel
  keyword: string
}

const emptyFilters: RecordFilters = { keyword: '' }
const emptyStats = (): CommunityContentSecurityRecordStats => ({ total: 0, pass: 0, review: 0, block: 0, skipped: 0 })

export function AuditRecordPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [items, setItems] = useState<CommunityContentSecurityRecord[]>([])
  const [stats, setStats] = useState<CommunityContentSecurityRecordStats>(emptyStats)
  const [filters, setFilters] = useState<RecordFilters>(emptyFilters)
  const [appliedFilters, setAppliedFilters] = useState<RecordFilters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      const result = await listCommunityContentSecurityRecords({
        page,
        page_size: pageSize,
        content_type: appliedFilters.content_type,
        provider: appliedFilters.provider,
        verdict: appliedFilters.verdict,
        risk_level: appliedFilters.risk_level,
        keyword: appliedFilters.keyword.trim() || undefined,
      })
      setItems(result.items)
      setStats(result.stats)
      setTotal(result.pagination.total)
      if (page > result.pagination.last_page && result.pagination.last_page > 0) setPage(result.pagination.last_page)
    } catch (error) {
      const text = errorText(error, '机审记录加载失败，请稍后重试。')
      setLoadError(text)
      messageApi.error(text)
    } finally {
      setLoading(false)
    }
  }, [appliedFilters, messageApi, page, pageSize])

  useEffect(() => { void load() }, [load])

  const applyFilters = () => {
    setAppliedFilters({ ...filters, keyword: filters.keyword.trim() })
    setPage(1)
  }

  const resetFilters = () => {
    setFilters({ ...emptyFilters })
    setAppliedFilters({ ...emptyFilters })
    setPage(1)
  }

  const evaluated = stats.pass + stats.review + stats.block
  const passRate = evaluated ? `${(stats.pass / evaluated * 100).toFixed(1)}%` : '—'

  const columns: ColumnsType<CommunityContentSecurityRecord> = [
    { title: '记录 ID', dataIndex: 'id', width: 190, fixed: 'left', render: (value: string) => <Typography.Text copyable={{ text: value }}>{value}</Typography.Text> },
    {
      title: '内容与作者',
      width: 210,
      render: (_, item) => <Space direction="vertical" size={4}>
        <Space size={4} wrap>
          <Tag color={item.content_type === 'post' ? 'blue' : 'cyan'}>{item.content_type === 'post' ? '帖子' : '评论'}</Tag>
          <Tag>{item.content_field === 'title' ? '标题' : '正文/评论'}</Tag>
        </Space>
        <Typography.Text>{item.author.nickname}</Typography.Text>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>{item.author.id}</Typography.Text>
      </Space>,
    },
    {
      title: '内容 ID / 版本',
      width: 200,
      render: (_, item) => item.content_id ? <Space direction="vertical" size={2}>
        <Typography.Text copyable={{ text: item.content_id }}>{item.content_id}</Typography.Text>
        <Typography.Text type="secondary">{item.content_version_no === null ? '未关联版本' : `内容版本 v${item.content_version_no}`}</Typography.Text>
      </Space> : <Tag>入库前拦截 · 无内容 ID</Tag>,
    },
    {
      title: '标题快照',
      dataIndex: 'subject_snapshot',
      width: 200,
      render: (value: string | null) => value ? <Typography.Text ellipsis={{ tooltip: value }}>{value}</Typography.Text> : <Typography.Text type="secondary">—</Typography.Text>,
    },
    {
      title: '内容快照',
      dataIndex: 'content_snapshot',
      width: 300,
      render: (value: string) => <Typography.Paragraph style={{ marginBottom: 0, whiteSpace: 'pre-wrap' }} ellipsis={{ rows: 3, tooltip: value }}>{value}</Typography.Paragraph>,
    },
    { title: '服务商', dataIndex: 'provider', width: 135, render: (value: CommunityContentSecurityProvider) => securityProviderLabels[value] },
    {
      title: '机审结果',
      dataIndex: 'verdict',
      width: 125,
      render: (value: CommunityContentSecurityVerdict) => <Tag color={securityVerdictMeta[value].color}>{securityVerdictMeta[value].label}</Tag>,
    },
    {
      title: '风险等级',
      dataIndex: 'risk_level',
      width: 110,
      render: (value: CommunityContentSecurityRiskLevel | null) => {
        const meta = value ? securityRiskMeta[value] : null
        return value ? <Tag color={meta?.color ?? 'orange'}>{meta?.label ?? value}</Tag> : <Typography.Text type="secondary">未评级</Typography.Text>
      },
    },
    {
      title: '风险标签',
      dataIndex: 'risk_labels',
      width: 190,
      render: (values: string[]) => values.length ? <Space size={4} wrap>{values.map((value) => <Tag key={value}>{securityRiskLabelLabel(value)}</Tag>)}</Space> : <Typography.Text type="secondary">无</Typography.Text>,
    },
    {
      title: '降级原因',
      width: 210,
      render: (_, item) => <Space direction="vertical" size={2}>
        <Typography.Text type={item.skip_reason ? 'warning' : 'secondary'}>{item.skip_reason || '—'}</Typography.Text>
        {item.request_id && <Typography.Text type="secondary" style={{ fontSize: 12 }} ellipsis={{ tooltip: item.request_id }}>请求 ID：{item.request_id}</Typography.Text>}
      </Space>,
    },
    { title: '响应时间', dataIndex: 'response_time_ms', width: 115, render: (value: number | null) => value === null ? '—' : `${value} ms` },
    { title: '检测时间', dataIndex: 'created_at', width: 175, render: formatTime },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="审核记录" extra={<Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button>} />

      {loadError && <Alert type="error" showIcon message={loadError} action={<Button size="small" onClick={() => void load()}>重试</Button>} style={{ marginBottom: 16 }} />}

      <div className="xq-stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(135px, 1fr))', marginBottom: 16 }}>
        <StatCard title="总机审数" value={stats.total} delta="当前校区帖子与评论检测记录" />
        <StatCard title="通过" value={stats.pass} delta="外部机审通过" valueColor="var(--xq-green-deep)" />
        <StatCard title="需复审" value={stats.review} delta="进入人工审核" valueColor="var(--xq-warning)" />
        <StatCard title="拦截" value={stats.block} delta="入库前或入库后识别风险" valueColor="var(--xq-danger)" />
        <StatCard title="降级跳过" value={stats.skipped} delta="不计入通过率分母" />
        <StatCard title="通过率" value={passRate} delta="pass / (pass + review + block)" />
      </div>

      <div className="xq-card" style={{ marginBottom: 16 }}>
        <Form layout="inline" onFinish={applyFilters} style={{ rowGap: 12 }}>
          <Form.Item label="内容类型" style={{ marginInlineEnd: 12 }}>
            <Select value={filters.content_type} onChange={(value?: 'post' | 'comment') => setFilters((current) => ({ ...current, content_type: value }))} options={[{ value: 'post', label: '帖子' }, { value: 'comment', label: '评论' }]} placeholder="全部类型" allowClear style={{ width: 125 }} />
          </Form.Item>
          <Form.Item label="服务商" style={{ marginInlineEnd: 12 }}>
            <Select value={filters.provider} onChange={(value?: CommunityContentSecurityProvider) => setFilters((current) => ({ ...current, provider: value }))} options={Object.entries(securityProviderLabels).map(([value, label]) => ({ value, label }))} placeholder="全部服务商" allowClear style={{ width: 155 }} />
          </Form.Item>
          <Form.Item label="机审结果" style={{ marginInlineEnd: 12 }}>
            <Select value={filters.verdict} onChange={(value?: CommunityContentSecurityVerdict) => setFilters((current) => ({ ...current, verdict: value }))} options={Object.entries(securityVerdictMeta).map(([value, meta]) => ({ value, label: meta.label }))} placeholder="全部结果" allowClear style={{ width: 145 }} />
          </Form.Item>
          <Form.Item label="风险等级" style={{ marginInlineEnd: 12 }}>
            <Select value={filters.risk_level} onChange={(value?: CommunityContentSecurityRiskLevel) => setFilters((current) => ({ ...current, risk_level: value }))} options={Object.entries(securityRiskMeta).map(([value, meta]) => ({ value, label: meta.label }))} placeholder="全部等级" allowClear style={{ width: 135 }} />
          </Form.Item>
          <Form.Item label="关键词" style={{ marginInlineEnd: 12 }}>
            <Input value={filters.keyword} onChange={(event) => setFilters((current) => ({ ...current, keyword: event.target.value }))} placeholder="记录 ID / 内容 ID / 快照" allowClear style={{ width: 240 }} />
          </Form.Item>
          <Form.Item>
            <Space>
              <Button type="primary" htmlType="submit" icon={<SearchOutlined />}>查询</Button>
              <Button icon={<ReloadOutlined />} onClick={resetFilters}>重置</Button>
            </Space>
          </Form.Item>
        </Form>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={items}
          locale={{ emptyText: '没有符合当前条件的机审记录' }}
          scroll={{ x: 2200 }}
          pagination={{
            current: page,
            pageSize,
            total,
            showSizeChanger: true,
            pageSizeOptions: [10, 20, 50],
            showTotal: (value) => `共 ${value} 条`,
            onChange: (nextPage, nextPageSize) => {
              if (nextPageSize !== pageSize) {
                setPageSize(nextPageSize)
                setPage(1)
              } else {
                setPage(nextPage)
              }
            },
          }}
        />
      </div>
    </div>
  )
}
