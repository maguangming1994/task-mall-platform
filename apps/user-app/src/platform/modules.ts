import type { HomeLinkType } from '@/api/home'

export type ModuleCode =
  | 'module_tasks'
  | 'module_task_publish'
  | 'module_mall'
  | 'module_mall_trade'
  | 'module_after_sale'
  | 'module_community'
  | 'module_community_post'
  | 'module_wallet'
  | 'module_wallet_recharge'
  | 'module_withdraw'
  | 'module_referrals'
  | 'module_checkin'

export interface ModuleDefinition {
  code: ModuleCode
  /** 占位页文案用名，如「任务大厅」 */
  name: string
  /** 父级开关；父级关闭时本模块视为关闭 */
  parent: ModuleCode | null
  /** 占位页 SVG 图标路径 */
  icon: string
}

export const MODULE_REGISTRY: Record<ModuleCode, ModuleDefinition> = {
  module_tasks: { code: 'module_tasks', name: '任务大厅', parent: null, icon: '/static/icons/module-tasks.svg' },
  module_task_publish: { code: 'module_task_publish', name: '发布中心', parent: 'module_tasks', icon: '/static/icons/module-task-publish.svg' },
  module_mall: { code: 'module_mall', name: '积分商城', parent: null, icon: '/static/icons/module-mall.svg' },
  module_mall_trade: { code: 'module_mall_trade', name: '商城交易', parent: 'module_mall', icon: '/static/icons/module-mall-trade.svg' },
  module_after_sale: { code: 'module_after_sale', name: '售后服务', parent: 'module_mall_trade', icon: '/static/icons/module-after-sale.svg' },
  module_community: { code: 'module_community', name: '校园社区', parent: null, icon: '/static/icons/module-community.svg' },
  module_community_post: { code: 'module_community_post', name: '发帖功能', parent: 'module_community', icon: '/static/icons/module-community-post.svg' },
  module_wallet: { code: 'module_wallet', name: '钱包资产', parent: null, icon: '/static/icons/module-wallet.svg' },
  module_wallet_recharge: { code: 'module_wallet_recharge', name: '余额充值', parent: 'module_wallet', icon: '/static/icons/module-wallet-recharge.svg' },
  module_withdraw: { code: 'module_withdraw', name: '提现', parent: 'module_wallet', icon: '/static/icons/module-withdraw.svg' },
  module_referrals: { code: 'module_referrals', name: '邀请推荐', parent: null, icon: '/static/icons/module-referrals.svg' },
  module_checkin: { code: 'module_checkin', name: '每日签到', parent: null, icon: '/static/icons/module-check-in.svg' },
}

export const MODULE_CODES = Object.keys(MODULE_REGISTRY) as ModuleCode[]

/** 首页 link_type → 模块映射（notifications / none 不受控） */
export const HOME_LINK_MODULE: Partial<Record<HomeLinkType, ModuleCode>> = {
  tasks: 'module_tasks',
  products: 'module_mall',
  community: 'module_community',
  orders: 'module_mall_trade',
  assets: 'module_wallet',
}
