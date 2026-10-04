export const orderStatusLabels: Record<string, string> = {
  pending_payment: '待付款',
  pending_fulfillment: '待发货',
  partially_fulfilled: '部分发货',
  fulfilled: '已履约',
  shipped: '已发货',
  delivered: '已送达',
  completed: '已完成',
  cancelled: '已取消',
  closed: '已关闭',
  refund_processing: '退款处理中',
  refunded: '已退款',
  partially_refunded: '部分退款',
}

export const fulfillmentTypeLabels: Record<string, string> = {
  logistics: '物流配送',
  no_logistics: '无需物流',
  virtual_delivery: '虚拟交付',
}

export const fulfillmentStatusLabels: Record<string, string> = {
  pending: '待处理',
  shipped: '已发货',
  delivered: '已交付',
  completed: '已完成',
  cancelled: '已取消',
}

export const paymentMethodLabels: Record<string, string> = {
  wallet: '余额全额支付',
  wechat: '微信支付',
  none: '无需现金支付',
}

export const purchaseModeLabels: Record<string, string> = {
  cash: '现金',
  points: '积分',
  cash_points: '现金 + 积分',
}

export const assetTypeLabels: Record<string, string> = {
  points: '积分',
  wallet: '余额',
  wallet_cash: '余额',
  coupon: '优惠券',
}

export const assetAdjustmentActionLabels: Record<string, string> = {
  increase: '增加',
  decrease: '减少',
  clear: '清零',
}

export const assetAdjustmentStatusLabels: Record<string, string> = {
  pending: '待财务复核',
  approved: '已批准',
  rejected: '已拒绝',
  applied: '已记账',
}

export const withdrawalStatusLabels: Record<string, string> = {
  submitted: '待审核',
  reviewing: '待审核',
  processing: '出款中',
  succeeded: '已到账',
  failed: '出款失败',
  rejected: '已拒绝',
}

export const reconciliationResultLabels: Record<string, string> = {
  matched: '账目一致',
  missing_local: '本地记录缺失',
  missing_provider: '渠道记录缺失',
  amount_mismatch: '金额不一致',
  state_mismatch: '状态不一致',
}

export const reconciliationStatusLabels: Record<string, string> = {
  processing: '对账中',
  matched: '全部一致',
  differences_found: '发现差异',
}

export const reconciliationResolutionLabels: Record<string, string> = {
  not_required: '无需处置',
  open: '待人工处置',
  confirmed_channel_truth: '已确认渠道为准',
  confirmed_local_truth: '已确认本地为准',
  ignored_with_reason: '已说明忽略',
}

export const riskLevelLabels: Record<string, string> = {
  low: '低风险',
  medium: '中风险',
  high: '高风险',
}

export const taskRiskSourceLabels: Record<string, string> = {
  submission_rule: '提交内容规则',
  user_report: '用户举报',
}

export const userStatusLabels: Record<string, string> = {
  active: '正常',
  disabled: '已停用',
  cancelled: '已注销',
}

export const afterSaleStatusLabels: Record<string, string> = {
  submitted: '待审核',
  approved: '已通过',
  awaiting_return: '待用户退货',
  waiting_return: '待用户寄回',
  return_in_transit: '退货运输中',
  replacement_pending: '待替换履约',
  replacement_shipped: '替换商品已发出',
  processing: '处理中',
  succeeded: '售后已完成',
  completed: '已完成',
  rejected: '已拒绝',
  cancelled: '用户已撤销',
  closed: '已关闭',
  expired: '已超时关闭',
}

export const responsibilityLabels: Record<string, string> = {
  buyer: '买家责任',
  seller: '卖家责任',
  carrier: '物流方责任',
  platform: '平台责任',
  pending: '待审核判定',
  negotiated: '客服协商确定',
  none: '无责任方',
}

export const shippingPayerLabels: Record<string, string> = {
  buyer: '买家承担',
  seller: '卖家承担',
  carrier: '物流方承担',
  platform: '平台承担',
  pending: '待审核判定',
  negotiated: '客服协商确定',
  none: '无需承担',
}

export const auditActionLabels: Record<string, string> = {
  'admin.login': '管理员登录',
  'admin.logout': '管理员退出',
  'admin.role.created': '新建角色',
  'admin.role.updated': '调整角色权限',
  'admin.user.created': '新建管理员',
  'admin.user.roles_updated': '调整管理员角色',
  'admin.user.status_updated': '调整管理员状态',
  'task.created': '新建任务',
  'task.updated': '编辑任务',
  'task.status_changed': '变更任务状态',
  'product.created': '新建商品',
  'product.updated': '编辑商品',
  'product.status_changed': '变更商品状态',
  'refund.reviewed': '审核退款',
  'exchange.reviewed': '审核换货',
  'fulfillment.created': '创建发货记录',
  'setting.published': '发布系统配置',
}

export function businessLabel(labels: Record<string, string>, value: string | null | undefined, fallback = '—') {
  if (!value) return fallback
  return labels[value] ?? `未识别状态（${value}）`
}
