import { Alert, Button, Card, Descriptions, Form, Input, Modal, Segmented, Select, Space, Statistic, Table, Tabs, Tag, Tooltip, Typography, message } from 'antd'
import { AuditOutlined, CheckCircleOutlined, GiftOutlined, ReloadOutlined, SafetyCertificateOutlined, TeamOutlined, UserAddOutlined, WalletOutlined } from '@ant-design/icons'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useDebouncedValue } from '../../hooks/useDebouncedValue'
import {
  ApiError,
  getUserCenterOverview,
  listManagedAccountVerifications,
  listManagedEndUsers,
  listManagedReferrals,
  updateManagedEndUserTaskPublishingPermission,
  type ManagedAccountVerification,
  type ManagedEndUser,
  type ManagedTaskPublishingPermission,
  type ManagedReferral,
  type UserCenterOverview,
} from '../../api/admin'
import { businessLabel, userStatusLabels } from '../../constants/businessLabels'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { ReferralTeamPanel } from './ReferralTeamPanel'

const verificationLabels = { verified: '已完成账户验证', partial: '部分完成', unverified: '未验证' }
const referralStatusLabels: Record<string, string> = { tracking: '进行中', qualified: '已达标', rewarded: '已奖励', qualified_cap_reached: '达标待下月', revoked: '已撤销' }

function money(cents: number) { return `¥${(cents / 100).toFixed(2)}` }
function time(value: string | null) { return value ? new Date(value).toLocaleString() : '—' }
function yesTag(value: boolean, yes = '已完成', no = '未完成') { return <Tag color={value ? 'success' : 'default'}>{value ? yes : no}</Tag> }
function publishingPermissionTag(permission: ManagedTaskPublishingPermission) {
  if (permission.granted) return <Tag color="success">任务发布管理员</Tag>
  if (permission.status === 'revoked') return <Tag color="error">发布权限已撤销</Tag>
  return <Tag>未获得发布权限</Tag>
}

export function UserOperationsPage() {
  const { admin } = useAdminAuth()
  const [overview, setOverview] = useState<UserCenterOverview | null>(null)
  const [users, setUsers] = useState<ManagedEndUser[]>([])
  const [referrals, setReferrals] = useState<ManagedReferral[]>([])
  const [referralView, setReferralView] = useState<'relationships' | 'teams'>('relationships')
  const [verifications, setVerifications] = useState<ManagedAccountVerification[]>([])
  const [keyword, setKeyword] = useState('')
  const [debouncedKeyword] = useDebouncedValue(keyword, 400)
  const [userStatus, setUserStatus] = useState('')
  const [verification, setVerification] = useState('')
  const [referralFilter, setReferralFilter] = useState('')
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [permissionTarget, setPermissionTarget] = useState<ManagedEndUser | null>(null)
  const [permissionSubmitting, setPermissionSubmitting] = useState(false)
  const [permissionError, setPermissionError] = useState<string | null>(null)
  const [permissionForm] = Form.useForm<{ reason: string }>()
  const [messageApi, contextHolder] = message.useMessage()
  const canManagePublishingPermission = admin?.permissions.includes('rbac.manage') ?? false

  const load = useCallback(async (overrideKeyword?: string) => {
    const effectiveKeyword = overrideKeyword ?? debouncedKeyword
    setLoading(true)
    setLoadError(null)
    try {
      const [summary, userData, referralData, verificationData] = await Promise.all([
        getUserCenterOverview(),
        listManagedEndUsers({ keyword: effectiveKeyword, status: userStatus, verification, referral: referralFilter }),
        listManagedReferrals(),
        listManagedAccountVerifications(),
      ])
      setOverview(summary); setUsers(userData.items); setReferrals(referralData.items); setVerifications(verificationData.items)
    } catch (error) {
      const errorMessage = error instanceof ApiError ? error.message : '用户运营数据加载失败，请检查网络后重试。'
      setLoadError(errorMessage)
      messageApi.error(errorMessage)
    } finally { setLoading(false) }
  }, [debouncedKeyword, messageApi, referralFilter, userStatus, verification])

  useEffect(() => { void load() }, [load])

  const openPublishingPermission = (user: ManagedEndUser) => {
    permissionForm.resetFields()
    setPermissionError(null)
    setPermissionTarget(user)
  }

  const closePublishingPermission = () => {
    if (permissionSubmitting) return
    setPermissionTarget(null)
    setPermissionError(null)
    permissionForm.resetFields()
  }

  const submitPublishingPermission = async ({ reason }: { reason: string }) => {
    if (!permissionTarget) return
    const nextGranted = !permissionTarget.task_publishing_permission.granted
    setPermissionSubmitting(true)
    setPermissionError(null)
    try {
      await updateManagedEndUserTaskPublishingPermission(permissionTarget.id, nextGranted, reason.trim())
      messageApi.success(nextGranted ? `已将“${permissionTarget.nickname}”设为任务发布管理员。` : `已撤销“${permissionTarget.nickname}”的任务发布管理员资格。`)
      setPermissionTarget(null)
      permissionForm.resetFields()
      await load()
    } catch (error) {
      const errorMessage = error instanceof ApiError ? error.message : '任务发布权限更新失败，请稍后重试。'
      setPermissionError(errorMessage)
      messageApi.error(errorMessage)
    } finally {
      setPermissionSubmitting(false)
    }
  }

  const trendMax = useMemo(() => Math.max(1, ...(overview?.trend.flatMap((item) => [item.new_users, item.task_claims, item.paid_orders]) ?? [1])), [overview])
  const summaryCards = overview ? [
    { title: '今日新增用户', value: overview.summary.new_users_today, icon: <UserAddOutlined /> },
    { title: '总用户', value: overview.summary.total_users, icon: <TeamOutlined /> },
    { title: '手机号已验证', value: overview.summary.phone_verified_users, icon: <SafetyCertificateOutlined /> },
    { title: '资料已完善', value: overview.summary.profile_completed_users, icon: <CheckCircleOutlined /> },
    { title: '今日领取任务', value: overview.summary.task_claims_today, icon: <GiftOutlined /> },
    { title: '今日实付订单', value: overview.summary.paid_orders_today, icon: <AuditOutlined /> },
    { title: '今日提现完成', value: overview.summary.withdrawn_today_cents / 100, prefix: '¥', precision: 2, icon: <WalletOutlined /> },
    { title: '活跃状态用户', value: overview.summary.active_users, icon: <TeamOutlined /> },
  ] : []

  const overviewTab = <>
    <div className="user-stat-grid">{summaryCards.map((item) => <Card bordered={false} key={item.title}><Statistic {...item} prefix={<span className="user-stat-icon">{item.icon}</span>} /></Card>)}</div>
    <div className="user-overview-grid">
      <Card bordered={false} title="待处理事务" className="user-center-card">
        <div className="user-pending-grid">
          {overview && Object.entries({
            '任务奖励余量预警': overview.pending.low_task_reward_quota,
            '任务提交待审核': overview.pending.task_reviews,
            '任务发布待复核': overview.pending.task_publish_approvals,
            '提现待处理': overview.pending.withdrawals,
            '举报与投诉': overview.pending.complaints,
            '订单待履约': overview.pending.pending_fulfillment,
            '售后待处理': overview.pending.after_sales,
            '资料待完善': overview.pending.incomplete_profiles,
          }).map(([label, value]) => <div className="user-pending-item" key={label}><strong>{value}</strong><span>{label}</span></div>)}
        </div>
      </Card>
      <Card bordered={false} title="用户与增长规则" className="user-center-card">
        {overview && <Descriptions column={1} size="small">
          <Descriptions.Item label="自主注册">{yesTag(overview.policies.self_registration_enabled, '开放', '关闭')}</Descriptions.Item>
          <Descriptions.Item label="成长等级">{yesTag(overview.policies.growth_levels_enabled, '启用', '停用')}</Descriptions.Item>
          <Descriptions.Item label="邀请达标">首次任务完成＋首笔现金订单完成</Descriptions.Item>
          <Descriptions.Item label="每月奖励上限">{overview.policies.referral_monthly_cap} 人</Descriptions.Item>
          <Descriptions.Item label="每人邀请奖励">{overview.policies.referral_reward_points} 积分</Descriptions.Item>
          <Descriptions.Item label="实名材料采集"><Tag color="blue">暂不采集身份证原图</Tag></Descriptions.Item>
        </Descriptions>}
      </Card>
    </div>
    <Card bordered={false} title="近 7 日用户 / 任务 / 订单趋势" className="user-center-card">
      <div className="trend-legend"><span className="trend-user">新增用户</span><span className="trend-task">任务领取</span><span className="trend-order">实付订单</span></div>
      <div className="trend-chart">{overview?.trend.map((item) => <div className="trend-day" key={item.date}><div className="trend-bars"><i className="trend-user" style={{ height: `${Math.max(4, item.new_users / trendMax * 120)}px` }} title={`新增用户 ${item.new_users}`} /><i className="trend-task" style={{ height: `${Math.max(4, item.task_claims / trendMax * 120)}px` }} title={`任务领取 ${item.task_claims}`} /><i className="trend-order" style={{ height: `${Math.max(4, item.paid_orders / trendMax * 120)}px` }} title={`实付订单 ${item.paid_orders}`} /></div><small>{item.date}</small></div>)}</div>
    </Card>
  </>

  const userTab = <>
    {loadError && <Alert showIcon type="error" title="用户数据暂时无法刷新" description={loadError} action={<Button size="small" onClick={() => void load()} loading={loading}>重试</Button>} />}
    <Card bordered={false} className="user-center-card"><Space wrap>
      <Input.Search allowClear value={keyword} onChange={(event) => setKeyword(event.target.value)} onSearch={() => void load(keyword)} placeholder="用户ID / 精确手机号 / 昵称" style={{ width: 280 }} />
      <Select allowClear placeholder="用户状态" value={userStatus || undefined} onChange={(value) => setUserStatus(value ?? '')} style={{ width: 140 }} options={[{ value: 'active', label: '正常' }, { value: 'disabled', label: '已停用' }, { value: 'cancelled', label: '已注销' }]} />
      <Select allowClear placeholder="账户验证" value={verification || undefined} onChange={(value) => setVerification(value ?? '')} style={{ width: 160 }} options={[{ value: 'verified', label: '已验证' }, { value: 'partial', label: '部分完成' }, { value: 'unverified', label: '未验证' }]} />
      <Select allowClear placeholder="邀请关系" value={referralFilter || undefined} onChange={(value) => setReferralFilter(value ?? '')} style={{ width: 140 }} options={[{ value: 'inviter', label: '邀请人' }, { value: 'referred', label: '受邀用户' }, { value: 'none', label: '未绑定邀请' }]} />
      <Button type="primary" onClick={() => void load(keyword)} loading={loading}>搜索</Button>
      <Button icon={<ReloadOutlined />} onClick={() => void load()} loading={loading}>刷新</Button>
      <Tooltip title="批量导出默认关闭；如后续开放，必须增加敏感资料权限、二次验证、水印和导出审计。"><Button disabled>导出用户</Button></Tooltip>
    </Space></Card>
    <Card bordered={false} className="user-center-card"><Table rowKey="id" dataSource={users} loading={loading} pagination={false} scroll={{ x: 1600 }} locale={{ emptyText: loadError ? '用户列表加载失败，请重试' : '没有符合当前筛选条件的用户' }} columns={[
      { title: '用户', width: 210, render: (_, row) => <><strong>{row.nickname}</strong><Typography.Text type="secondary" className="table-subline">{row.id}</Typography.Text><Typography.Text type="secondary" className="table-subline">{row.phone_masked ?? '未绑定手机'}</Typography.Text></> },
      { title: '成长等级', width: 100, render: (_, row) => <Tag color="blue">{row.level.name}</Tag> },
      { title: '资产', width: 180, render: (_, row) => <><span>余额 {money(row.assets.wallet_available_cents)}</span><span className="table-subline">积分 {row.assets.points_available} · 券 {row.assets.coupons_available}</span></> },
      { title: '任务 / 订单', width: 155, render: (_, row) => <><span>任务 {row.task_metrics.approved}/{row.task_metrics.claimed}</span><span className="table-subline">现金订单 {row.cash_order_count}</span></> },
      { title: '邀请人数', dataIndex: 'referral_count', width: 100 },
      { title: '账户验证', width: 180, render: (_, row) => <Space size={[0, 4]} wrap>{yesTag(row.verification.phone, '手机', '手机未验')}{yesTag(row.verification.profile, '资料', '资料未全')}{yesTag(row.verification.wechat, '微信', '微信未绑')}</Space> },
      { title: '任务发布权限', width: 210, render: (_, row) => <>{publishingPermissionTag(row.task_publishing_permission)}<Typography.Text type="secondary" className="table-subline" title={row.task_publishing_permission.publisher_id ?? undefined}>{row.task_publishing_permission.publisher_id ? `主体 ${row.task_publishing_permission.publisher_id}` : '尚未创建发布主体'}</Typography.Text><Typography.Text type="secondary" className="table-subline">{row.task_publishing_permission.updated_at ? `更新于 ${time(row.task_publishing_permission.updated_at)}` : '暂无变更记录'}</Typography.Text></> },
      { title: '状态', width: 90, render: (_, row) => <Tag color={row.status === 'active' ? 'success' : 'default'}>{businessLabel(userStatusLabels, row.status)}</Tag> },
      { title: '注册 / 最近登录', width: 190, render: (_, row) => <><span>{time(row.created_at)}</span><span className="table-subline">{time(row.last_login_at)}</span></> },
      { title: '操作', fixed: 'right', width: 180, render: (_, row) => canManagePublishingPermission
        ? <Button danger={row.task_publishing_permission.granted} size="small" loading={permissionSubmitting && permissionTarget?.id === row.id} disabled={permissionSubmitting && permissionTarget?.id !== row.id} onClick={() => openPublishingPermission(row)}>{row.task_publishing_permission.granted ? '撤销发布管理员' : '设为任务发布管理员'}</Button>
        : <Tooltip title="当前角色没有权限管理能力"><span><Button size="small" disabled>{row.task_publishing_permission.granted ? '撤销发布管理员' : '设为任务发布管理员'}</Button></span></Tooltip> },
    ]} /></Card>
  </>

  const levelTab = <>
    <Alert showIcon type="info" message="采用成长等级，不采用付费会员、永久分成或多级代理佣金" description="等级只依据资料完善、任务完成和真实现金订单自动计算；不售卖等级，不承诺收益，不因拉人头产生多级分成。" />
    <Card bordered={false} className="user-center-card"><Table rowKey="code" pagination={false} dataSource={overview?.level_rules ?? []} columns={[
      { title: '等级', render: (_, row) => <Tag color={row.code === 'core' ? 'gold' : 'blue'}>{row.name}</Tag> },
      { title: '资料要求', render: (_, row) => row.profile_required ? '必须完善资料' : '无需' },
      { title: '审核通过任务', render: (_, row) => `≥ ${row.approved_tasks}` },
      { title: '完成现金订单', render: (_, row) => `≥ ${row.cash_orders}` },
      { title: '权益', render: (_, row) => row.benefits.join('、') },
      { title: '升级方式', render: () => <Tag color="success">系统自动计算</Tag> },
    ]} /></Card>
  </>

  const referralTab = <>
    <Alert showIcon type="warning" message="邀请绑定不等于奖励到账" description="注册绑定关系后，必须同时完成第一次任务和第一笔真实现金订单；每月奖励人数上限、奖励积分均由配置中心控制。" />
    <div style={{ marginTop: 16 }}><Segmented value={referralView} onChange={(value) => setReferralView(value as 'relationships' | 'teams')} options={[{ label: '邀请明细', value: 'relationships' }, { label: '团队视图', value: 'teams' }]} /></div>
    {referralView === 'relationships' ? <Card bordered={false} className="user-center-card"><Table rowKey="id" dataSource={referrals} pagination={false} scroll={{ x: 980 }} columns={[
      { title: '邀请人', render: (_, row) => <><strong>{row.inviter.nickname}</strong><span className="table-subline">{row.inviter.phone_masked ?? '—'}</span></> },
      { title: '受邀用户', render: (_, row) => <><strong>{row.referred_user.nickname}</strong><span className="table-subline">{row.referred_user.phone_masked ?? '—'}</span></> },
      { title: '首次任务', render: (_, row) => yesTag(row.first_task_completed) },
      { title: '首笔现金订单', render: (_, row) => yesTag(row.first_cash_order_completed) },
      { title: '奖励', render: (_, row) => row.reward_points ? `${row.reward_points} 积分` : '待达标' },
      { title: '状态', render: (_, row) => <Tag color={row.rewarded_at ? 'success' : 'processing'}>{businessLabel(referralStatusLabels, row.status)}</Tag> },
      { title: '绑定时间', render: (_, row) => time(row.bound_at) },
    ]} /></Card> : <ReferralTeamPanel />}
  </>

  const verificationTab = <>
    <Alert showIcon type="info" message="认证页面改为账户可信度核验" description="目前仅展示手机号验证、资料完善和微信绑定。不直接保存身份证号码及正反面原图；提现渠道确需实名时，接入持牌服务并保存认证结果和凭证摘要。" />
    <Card bordered={false} className="user-center-card"><Table rowKey={(row) => row.user.id} dataSource={verifications} pagination={false} columns={[
      { title: '用户', render: (_, row) => <><strong>{row.user.nickname}</strong><span className="table-subline">{row.user.id} · {row.user.phone_masked ?? '未绑手机'}</span></> },
      { title: '手机号', render: (_, row) => yesTag(row.phone_verified, '已验证', '未验证') },
      { title: '资料', render: (_, row) => yesTag(row.profile_completed, '已完善', '未完善') },
      { title: '微信', render: (_, row) => yesTag(row.wechat_bound, '已绑定', '未绑定') },
      { title: '综合状态', render: (_, row) => <Tag color={row.status === 'verified' ? 'success' : row.status === 'partial' ? 'processing' : 'default'}>{verificationLabels[row.status]}</Tag> },
      { title: '实名材料', render: () => <Tag>不采集原图</Tag> },
      { title: '更新时间', render: (_, row) => time(row.updated_at) },
    ]} /></Card>
  </>

  const permissionGranted = permissionTarget?.task_publishing_permission.granted ?? false

  return <>{contextHolder}<div className="page-heading"><div><Typography.Text className="eyebrow">校园用户运营</Typography.Text><Typography.Title level={2}>用户运营中心</Typography.Title><Typography.Paragraph type="secondary">用户统计、成长等级、邀请里程碑和账户可信度集中管理。</Typography.Paragraph></div><Space><Tag color="blue">手机号默认脱敏</Tag><Tag color="green">资产操作走复核</Tag></Space></div>
    <Tabs items={[{ key: 'overview', label: '运营概览', children: overviewTab }, { key: 'users', label: '用户列表', children: userTab }, { key: 'levels', label: '成长等级', children: levelTab }, { key: 'referrals', label: '邀请关系', children: referralTab }, { key: 'verification', label: '账户验证', children: verificationTab }]} />
    <Modal
      title={permissionGranted ? '撤销任务发布管理员' : '设为任务发布管理员'}
      open={permissionTarget !== null}
      onCancel={closePublishingPermission}
      onOk={() => permissionForm.submit()}
      okText={permissionGranted ? '确认撤销发布管理员' : '确认设为发布管理员'}
      cancelText="取消"
      okButtonProps={{ danger: permissionGranted }}
      cancelButtonProps={{ disabled: permissionSubmitting }}
      confirmLoading={permissionSubmitting}
      closable={!permissionSubmitting}
      maskClosable={!permissionSubmitting}
      destroyOnHidden
    >
      {permissionTarget && <>
        <Alert
          showIcon
          type={permissionGranted ? 'error' : 'warning'}
          title={permissionGranted ? '撤销后该用户将不再是任务发布管理员' : '确认将该用户设为任务发布管理员'}
          description={permissionGranted ? '该用户将无法继续发布或管理新任务；已发布任务不自动下线，如需处置存量任务，请前往任务中心逐项复核。' : '该用户只会获得任务发布权限，不会获得后台其他管理权限。请确认身份、用途与校园归属，并填写可审计的授权理由。'}
          style={{ marginBottom: 16 }}
        />
        <Descriptions bordered size="small" column={1} style={{ marginBottom: 16 }}>
          <Descriptions.Item label="用户">{permissionTarget.nickname}（{permissionTarget.id}）</Descriptions.Item>
          <Descriptions.Item label="当前权限">{publishingPermissionTag(permissionTarget.task_publishing_permission)}</Descriptions.Item>
          <Descriptions.Item label="发布主体 ID">{permissionTarget.task_publishing_permission.publisher_id ?? '尚未创建'}</Descriptions.Item>
          <Descriptions.Item label="变更后">{permissionGranted ? <Tag color="error">发布权限已撤销</Tag> : <Tag color="success">任务发布管理员</Tag>}</Descriptions.Item>
        </Descriptions>
        {permissionError && <Alert showIcon type="error" title="任务发布权限变更失败" description={permissionError} style={{ marginBottom: 16 }} />}
        <Form form={permissionForm} layout="vertical" onFinish={submitPublishingPermission} requiredMark="optional">
          <Form.Item name="reason" label={permissionGranted ? '撤销理由' : '授权理由'} rules={[
            { required: true, whitespace: true, message: permissionGranted ? '请填写撤销理由' : '请填写授权理由' },
            { min: 5, message: '请填写至少 5 个字，便于后续审计' },
            { max: 500, message: '理由不能超过 500 个字' },
          ]}>
            <Input.TextArea rows={4} maxLength={500} showCount placeholder={permissionGranted ? '说明撤销依据、风险处置或用户申请情况' : '说明授权用途、核验依据和适用范围'} />
          </Form.Item>
        </Form>
      </>}
    </Modal>
  </>
}
