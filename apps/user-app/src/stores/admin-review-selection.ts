import { defineStore } from 'pinia'
import type { AdminIdentityRecord } from '@/api/admin/identity'
import type { AdminMaterial } from '@/api/admin/material'
import type { AdminRefund, AdminSecondHandRefund } from '@/api/admin/after-sale'
import type { AdminCommunityActivity } from '@/api/admin/community'
import type { AdminPushSubmission } from '@/api/admin/push'

/**
 * 审核列表 → 详情页的数据传递。
 *
 * 列表接口已返回详情所需字段，选中后暂存即可，避免详情页重复请求或新增接口。
 */
export const useAdminReviewSelection = defineStore('admin-review-selection', {
  state: () => ({
    identity: null as AdminIdentityRecord | null,
    material: null as AdminMaterial | null,
    refund: null as AdminRefund | null,
    secondHandRefund: null as AdminSecondHandRefund | null,
    activity: null as AdminCommunityActivity | null,
    pushSubmission: null as AdminPushSubmission | null,
  }),
})
