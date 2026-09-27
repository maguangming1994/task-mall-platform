import { EyeOutlined, ReloadOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Descriptions, Drawer, Empty, Input, Select, Space, Table, Tag, Typography } from 'antd'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  ApiError,
  getManagedReferralTeam,
  listManagedReferralTeams,
  type ManagedReferralTeam,
  type ManagedReferralTeamDetail,
  type ManagedReferralTeamList,
  type ManagedReferralTeamMember,
  type ReferralTeamPerformance,
} from '../../api/admin'

type TeamFilters = { keyword: string; status: string; page: number; page_size: number }
type MemberFilters = { keyword: string; status: string; level: number | ''; effective: '' | 'true' | 'false'; page: number; page_size: number }

const initialTeamFilters: TeamFilters = { keyword: '', status: '', page: 1, page_size: 20 }
const initialMemberFilters: MemberFilters = { keyword: '', status: '', level: '', effective: '', page: 1, page_size: 20 }
const userStatusLabels: Record<string, string> = { active: '正常', disabled: '已停用', cancelled: '已注销', merged: '已合并' }
const referralStatusLabels: Record<string, string> = {
  tracking: '进行中', qualified: '已达标', rewarded: '已奖励', qualified_cap_reached: '达标待下月', revoked: '已撤销',
}

function formatMoney(cents: number) { return `¥${(cents / 100).toFixed(2)}` }
function formatTime(value: string | null) { return value ? new Date(value).toLocaleString() : '暂无记录' }
function userStatusTag(status: string) {
  const color = status === 'active' ? 'success' : status === 'disabled' ? 'warning' : 'default'
  return <Tag color={color}>{userStatusLabels[status] ?? status}</Tag>
}
function taskReward(performance: ReferralTeamPerformance) {
  return `${performance.task_reward_points} 积分 · ${formatMoney(performance.task_reward_wallet_cents)} · ${performance.task_reward_coupons} 张券`
}

export function ReferralTeamPanel() {
  const [teamFilters, setTeamFilters] = useState<TeamFilters>({ ...initialTeamFilters })
  const [teamKeyword, setTeamKeyword] = useState('')
  const [teamData, setTeamData] = useState<ManagedReferralTeamList | null>(null)
  const [teamLoading, setTeamLoading] = useState(true)
  const [teamError, setTeamError] = useState<string | null>(null)
  const [teamTarget, setTeamTarget] = useState<ManagedReferralTeam | null>(null)
  const [teamDetail, setTeamDetail] = useState<ManagedReferralTeamDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [memberFilters, setMemberFilters] = useState<MemberFilters>({ ...initialMemberFilters })
  const [memberKeyword, setMemberKeyword] = useState('')
  const teamRequestId = useRef(0)
  const detailRequestId = useRef(0)

  const loadTeams = useCallback(async () => {
    const requestId = ++teamRequestId.current
    setTeamLoading(true)
    setTeamError(null)
    setTeamData(null)
    try {
      const result = await listManagedReferralTeams(teamFilters)
      if (requestId === teamRequestId.current) setTeamData(result)
    } catch (error) {
      if (requestId === teamRequestId.current) setTeamError(error instanceof ApiError ? error.message : '邀请团队加载失败，请稍后重试。')
    } finally {
      if (requestId === teamRequestId.current) setTeamLoading(false)
    }
  }, [teamFilters])

  useEffect(() => {
    void loadTeams()
    return () => { teamRequestId.current += 1 }
  }, [loadTeams])

  const loadTeamDetail = useCallback(async () => {
    if (!teamTarget) return
    const requestId = ++detailRequestId.current
    setDetailLoading(true)
    setDetailError(null)
    setTeamDetail(null)
    try {
      const result = await getManagedReferralTeam(teamTarget.id, {
        keyword: memberFilters.keyword,
        status: memberFilters.status,
        level: memberFilters.level === '' ? undefined : memberFilters.level,
        effective: memberFilters.effective === '' ? undefined : memberFilters.effective === 'true',
        page: memberFilters.page,
        page_size: memberFilters.page_size,
      })
      if (requestId === detailRequestId.current) setTeamDetail(result)
    } catch (error) {
      if (requestId === detailRequestId.current) setDetailError(error instanceof ApiError ? error.message : '团队详情加载失败，请稍后重试。')
    } finally {
      if (requestId === detailRequestId.current) setDetailLoading(false)
    }
  }, [memberFilters, teamTarget])

  useEffect(() => {
    if (teamTarget) void loadTeamDetail()
    return () => { detailRequestId.current += 1 }
  }, [loadTeamDetail, teamTarget])

  const openTeam = (team: ManagedReferralTeam) => {
    setTeamDetail(null)
    setDetailError(null)
    setMemberKeyword('')
    setMemberFilters({ ...initialMemberFilters })
    setTeamTarget(team)
  }

  const closeTeam = () => {
    detailRequestId.current += 1
    setTeamTarget(null)
    setTeamDetail(null)
    setDetailError(null)
  }

  const resetTeamFilters = () => {
    setTeamKeyword('')
    setTeamFilters({ ...initialTeamFilters })
  }

  const resetMemberFilters = () => {
    setMemberKeyword('')
    setMemberFilters({ ...initialMemberFilters })
  }

  const currentTeam = teamDetail?.team ?? teamTarget
  const levelOptions = Array.from({ length: currentTeam?.max_depth ?? 0 }, (_, index) => ({ value: index + 1, label: `第 ${index + 1} 层` }))

  return <>
    <Alert
      showIcon
      type="info"
      title="团队视图仅统计真实邀请链和实际业务结果"
      description="团队层级用于运营观察，不产生多级分成；团队业绩仅包含下级成员，不包含负责人本人。"
      style={{ marginTop: 16 }}
    />
    {teamError && <Alert showIcon type="error" title="邀请团队暂时无法加载" description={teamError} action={<Button size="small" loading={teamLoading} onClick={() => void loadTeams()}>重试</Button>} style={{ marginTop: 16 }} />}
    <Card variant="borderless" className="user-center-card">
      <Space wrap>
        <Input.Search
          allowClear
          value={teamKeyword}
          onChange={(event) => setTeamKeyword(event.target.value)}
          onSearch={(keyword) => setTeamFilters((current) => ({ ...current, keyword: keyword.trim(), page: 1 }))}
          placeholder="负责人 ID / 昵称"
          style={{ width: 290 }}
        />
        <Select
          allowClear
          placeholder="负责人状态"
          value={teamFilters.status || undefined}
          onChange={(value) => setTeamFilters((current) => ({ ...current, status: value ?? '', page: 1 }))}
          options={Object.entries(userStatusLabels).map(([value, label]) => ({ value, label }))}
          style={{ width: 150 }}
        />
        <Button onClick={resetTeamFilters}>重置</Button>
        <Button icon={<ReloadOutlined />} loading={teamLoading} onClick={() => void loadTeams()}>刷新</Button>
      </Space>
      <Table<ManagedReferralTeam>
        rowKey="id"
        loading={teamLoading}
        dataSource={teamData?.items ?? []}
        scroll={{ x: 1510 }}
        style={{ marginTop: 16 }}
        pagination={{
          current: teamData?.pagination.page ?? teamFilters.page,
          pageSize: teamData?.pagination.page_size ?? teamFilters.page_size,
          total: teamData?.pagination.total ?? 0,
          showSizeChanger: true,
          pageSizeOptions: [20, 50],
          showTotal: (total) => `共 ${total} 个邀请团队`,
          onChange: (page, pageSize) => setTeamFilters((current) => ({ ...current, page: pageSize === current.page_size ? page : 1, page_size: pageSize })),
        }}
        locale={{ emptyText: teamError ? '团队列表加载失败，请重试' : '没有符合当前筛选条件的邀请团队' }}
        columns={[
          { title: '团队负责人', width: 220, render: (_, team) => <><Typography.Text strong>{team.leader.nickname || '未设置昵称'}</Typography.Text><Typography.Text type="secondary" className="table-subline">{team.leader.id}</Typography.Text><Typography.Text type="secondary" className="table-subline">{team.leader.phone_masked ?? '未绑定手机'}</Typography.Text><span className="table-subline">{userStatusTag(team.leader.status)}</span></> },
          { title: '团队规模', width: 185, render: (_, team) => <><strong>{team.member_count} 名下级成员</strong><span className="table-subline">直邀 {team.direct_member_count} · 有效 {team.effective_member_count}</span><span className="table-subline">活跃 {team.active_member_count} · 最深 {team.max_depth} 层</span></> },
          { title: '任务业绩', width: 230, render: (_, team) => <><strong>{team.performance.approved_tasks} 个审核通过任务</strong><span className="table-subline">任务奖励 {taskReward(team.performance)}</span></> },
          { title: '现金订单业绩', width: 250, render: (_, team) => <><strong>{team.performance.paid_orders} 笔完成订单</strong><span className="table-subline">总实付 {formatMoney(team.performance.paid_order_gross_amount_cents)}</span><span className="table-subline">退款 {formatMoney(team.performance.paid_order_refunded_amount_cents)} · 净业绩 {formatMoney(team.performance.paid_order_net_amount_cents)}</span></> },
          { title: '邀请奖励', width: 155, render: (_, team) => <><strong>{team.performance.referral_reward_count} 人次</strong><span className="table-subline">{team.performance.referral_reward_points} 积分</span></> },
          { title: '数据范围', width: 165, render: (_, team) => <>{team.traversal.truncated ? <Tag color="warning">遍历已截断</Tag> : <Tag color="success">完整遍历</Tag>}<span className="table-subline">关系更新于 {formatTime(team.updated_at)}</span></> },
          { title: '操作', fixed: 'right', width: 105, render: (_, team) => <Button size="small" icon={<EyeOutlined />} onClick={() => openTeam(team)}>查看团队</Button> },
        ]}
      />
    </Card>

    <Drawer
      title={currentTeam ? `邀请团队 · ${currentTeam.leader.nickname || currentTeam.leader.id}` : '邀请团队详情'}
      width={940}
      open={teamTarget !== null}
      onClose={closeTeam}
      loading={detailLoading && teamDetail === null}
      extra={<Button icon={<ReloadOutlined />} loading={detailLoading} onClick={() => void loadTeamDetail()}>刷新</Button>}
      destroyOnHidden
    >
      {detailError && <Alert showIcon type="error" title="团队详情暂时无法加载" description={detailError} action={<Button size="small" loading={detailLoading} onClick={() => void loadTeamDetail()}>重试</Button>} style={{ marginBottom: 16 }} />}
      {teamDetail && <Space orientation="vertical" size={20} style={{ width: '100%' }}>
        <Alert showIcon type="info" title="业绩统计仅含下级成员，不含负责人本人" description="团队指标为下级成员累计结果；成员列表中的业绩只统计该成员本人。" />
        {teamDetail.team.traversal.truncated && <Alert showIcon type="warning" title="团队关系遍历已截断" description={`当前团队超过安全遍历上限，统计和成员范围可能不完整。最多遍历 ${teamDetail.team.traversal.max_depth_limit} 层、${teamDetail.team.traversal.member_limit} 人。`} />}
        <Descriptions bordered size="small" column={2}>
          <Descriptions.Item label="负责人">{teamDetail.team.leader.nickname} · {teamDetail.team.leader.phone_masked ?? '未绑定手机'}</Descriptions.Item>
          <Descriptions.Item label="负责人状态">{userStatusTag(teamDetail.team.leader.status)}</Descriptions.Item>
          <Descriptions.Item label="团队规模">{teamDetail.team.member_count} 人（直邀 {teamDetail.team.direct_member_count} 人）</Descriptions.Item>
          <Descriptions.Item label="有效 / 活跃成员">{teamDetail.team.effective_member_count} / {teamDetail.team.active_member_count} 人</Descriptions.Item>
          <Descriptions.Item label="最深层级">{teamDetail.team.max_depth} 层</Descriptions.Item>
          <Descriptions.Item label="关系更新时间">{formatTime(teamDetail.team.updated_at)}</Descriptions.Item>
        </Descriptions>

        <div style={{ width: '100%' }}>
          <Typography.Title level={5}>团队累计业绩</Typography.Title>
          <Descriptions bordered size="small" column={2}>
            <Descriptions.Item label="审核通过任务">{teamDetail.team.performance.approved_tasks} 个</Descriptions.Item>
            <Descriptions.Item label="任务奖励">{taskReward(teamDetail.team.performance)}</Descriptions.Item>
            <Descriptions.Item label="完成现金订单">{teamDetail.team.performance.paid_orders} 笔</Descriptions.Item>
            <Descriptions.Item label="总实付">{formatMoney(teamDetail.team.performance.paid_order_gross_amount_cents)}</Descriptions.Item>
            <Descriptions.Item label="累计退款">{formatMoney(teamDetail.team.performance.paid_order_refunded_amount_cents)}</Descriptions.Item>
            <Descriptions.Item label="净业绩"><Typography.Text strong>{formatMoney(teamDetail.team.performance.paid_order_net_amount_cents)}</Typography.Text></Descriptions.Item>
            <Descriptions.Item label="邀请奖励人数">{teamDetail.team.performance.referral_reward_count} 人次</Descriptions.Item>
            <Descriptions.Item label="邀请奖励积分">{teamDetail.team.performance.referral_reward_points} 积分</Descriptions.Item>
          </Descriptions>
        </div>

        <div style={{ width: '100%' }}>
          <Typography.Title level={5}>团队成员</Typography.Title>
          <Space wrap style={{ marginBottom: 14 }}>
            <Input.Search allowClear value={memberKeyword} onChange={(event) => setMemberKeyword(event.target.value)} onSearch={(keyword) => setMemberFilters((current) => ({ ...current, keyword: keyword.trim(), page: 1 }))} placeholder="成员 ID / 昵称" style={{ width: 260 }} />
            <Select allowClear placeholder="成员状态" value={memberFilters.status || undefined} onChange={(value) => setMemberFilters((current) => ({ ...current, status: value ?? '', page: 1 }))} options={Object.entries(userStatusLabels).map(([value, label]) => ({ value, label }))} style={{ width: 135 }} />
            <Select allowClear placeholder="成员层级" value={memberFilters.level || undefined} onChange={(value) => setMemberFilters((current) => ({ ...current, level: value ?? '', page: 1 }))} options={levelOptions} style={{ width: 125 }} />
            <Select allowClear placeholder="有效成员" value={memberFilters.effective || undefined} onChange={(value) => setMemberFilters((current) => ({ ...current, effective: value ?? '', page: 1 }))} options={[{ value: 'true', label: '仅有效成员' }, { value: 'false', label: '仅非有效成员' }]} style={{ width: 145 }} />
            <Button onClick={resetMemberFilters}>重置</Button>
          </Space>
          <Table<ManagedReferralTeamMember>
            size="small"
            rowKey={(member) => member.user.id}
            loading={detailLoading}
            dataSource={teamDetail.members.items}
            scroll={{ x: 1370 }}
            pagination={{
              current: teamDetail.members.pagination.page,
              pageSize: teamDetail.members.pagination.page_size,
              total: teamDetail.members.pagination.total,
              showSizeChanger: true,
              pageSizeOptions: [20, 50, 100],
              showTotal: (total) => `共 ${total} 名成员`,
              onChange: (page, pageSize) => setMemberFilters((current) => ({ ...current, page: pageSize === current.page_size ? page : 1, page_size: pageSize })),
            }}
            locale={{ emptyText: detailError ? '成员列表加载失败，请重试' : '没有符合当前筛选条件的团队成员' }}
            columns={[
              { title: '成员', width: 205, render: (_, member) => <><Typography.Text strong>{member.user.nickname || '未设置昵称'}</Typography.Text><span className="table-subline">{member.user.id}</span><span className="table-subline">{member.user.phone_masked ?? '未绑定手机'}</span></> },
              { title: '层级关系', width: 170, render: (_, member) => <><Tag color={member.direct ? 'blue' : undefined}>第 {member.level} 层{member.direct ? ' · 直邀' : ''}</Tag><span className="table-subline">上级：{member.parent?.nickname ?? '团队负责人'}</span></> },
              { title: '状态', width: 155, render: (_, member) => <><Space size={[4, 4]} wrap>{userStatusTag(member.user.status)}<Tag color={member.effective ? 'success' : 'default'}>{member.effective ? '有效成员' : '非有效成员'}</Tag></Space><span className="table-subline">邀请：{referralStatusLabels[member.referral_status] ?? member.referral_status}</span></> },
              { title: '任务业绩', width: 230, render: (_, member) => <><strong>{member.performance.approved_tasks} 个审核通过任务</strong><span className="table-subline">奖励 {taskReward(member.performance)}</span></> },
              { title: '现金订单业绩', width: 235, render: (_, member) => <><strong>{member.performance.paid_orders} 笔完成订单</strong><span className="table-subline">实付 {formatMoney(member.performance.paid_order_gross_amount_cents)} · 退款 {formatMoney(member.performance.paid_order_refunded_amount_cents)}</span><span className="table-subline">净业绩 {formatMoney(member.performance.paid_order_net_amount_cents)}</span></> },
              { title: '邀请奖励', width: 125, render: (_, member) => <><span>{member.performance.referral_reward_count} 人次</span><span className="table-subline">{member.performance.referral_reward_points} 积分</span></> },
              { title: '加入时间', width: 160, render: (_, member) => formatTime(member.joined_at) },
            ]}
          />
        </div>
      </Space>}
      {!detailLoading && !detailError && !teamDetail && <Empty description="暂无团队详情" />}
    </Drawer>
  </>
}
