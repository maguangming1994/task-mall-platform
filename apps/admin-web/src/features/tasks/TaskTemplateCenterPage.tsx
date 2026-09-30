import { Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { ApiError, listManagedTasks, listRewardBudgetPools, type ManagedTask, type RewardBudgetPool } from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { TaskTemplatePanel } from './TaskTemplatePanel'

export function TaskTemplateCenterPage() {
  const { admin } = useAdminAuth()
  const canManage = admin?.permissions.includes('task.manage') ?? false
  const [tasks, setTasks] = useState<ManagedTask[]>([])
  const [pools, setPools] = useState<RewardBudgetPool[]>([])
  const [loading, setLoading] = useState(false)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [taskResult, poolResult] = await Promise.all([listManagedTasks(), listRewardBudgetPools()])
      setTasks(taskResult.items)
      setPools(poolResult.items)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '任务模板数据加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  return (
    <>
      {contextHolder}
      <div className="page-heading">
        <div>
          <Typography.Text className="eyebrow">校园任务管理</Typography.Text>
          <Typography.Title level={2}>任务模板中心</Typography.Title>
          <Typography.Paragraph type="secondary">复用任务步骤与奖励结构，实际预算池与时间在套用时重新选择；模板版本不可覆盖，停用或更新不会影响已生成、发布或领取的任务。</Typography.Paragraph>
        </div>
      </div>
      <TaskTemplatePanel tasks={tasks} pools={pools} canManage={canManage} onTaskCreated={load} />
      {loading && <Typography.Text type="secondary">加载中…</Typography.Text>}
    </>
  )
}
