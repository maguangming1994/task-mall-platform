import {
  AccountBookOutlined,
  AppstoreOutlined,
  AuditOutlined,
  BankOutlined,
  BarChartOutlined,
  BgColorsOutlined,
  CarOutlined,
  CheckCircleOutlined,
  CommentOutlined,
  CustomerServiceOutlined,
  DashboardOutlined,
  DatabaseOutlined,
  ExperimentOutlined,
  FileDoneOutlined,
  FileProtectOutlined,
  FileSearchOutlined,
  FileTextOutlined,
  FireOutlined,
  FolderOpenOutlined,
  GiftOutlined,
  HistoryOutlined,
  IdcardOutlined,
  LockOutlined,
  MessageOutlined,
  NodeIndexOutlined,
  NotificationOutlined,
  PercentageOutlined,
  PictureOutlined,
  ProfileOutlined,
  ReadOutlined,
  RobotOutlined,
  RocketOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  ShopOutlined,
  ShoppingCartOutlined,
  ShoppingOutlined,
  SnippetsOutlined,
  TagOutlined,
  TeamOutlined,
  TrophyOutlined,
  UnorderedListOutlined,
  UsergroupAddOutlined,
  WalletOutlined,
} from '@ant-design/icons'
import { lazy, useEffect, useState, type ComponentType, type LazyExoticComponent, type ReactNode } from 'react'
import type { NavigationItem } from '../api/admin'

export interface AdminPageDef {
  path: string
  title: string
  navCode: string
  /** 应用中心卡片上的说明文案 */
  description: string
  /** 应用中心卡片图标 */
  icon: ReactNode
  element: LazyExoticComponent<ComponentType>
}
export interface AdminGroupDef {
  key: string
  label: string
  icon: ReactNode
  /** 应用中心分组主色 */
  accent: string
  pages: AdminPageDef[]
}

const lazyOf = <K extends string>(loader: () => Promise<Record<K, ComponentType>>, name: K) =>
  lazy(() => loader().then((m) => ({ default: m[name] })))

const AppCenterPage = lazyOf(() => import('../features/app-center/AppCenterPage'), 'AppCenterPage')
const DashboardPage = lazyOf(() => import('../features/dashboard/DashboardPage'), 'DashboardPage')
const TaskAuditPage = lazyOf(() => import('../features/tasks/TaskAuditPage'), 'TaskAuditPage')
const TaskManagementPage = lazyOf(() => import('../features/tasks/TaskManagementPage'), 'TaskManagementPage')
const TaskTemplateCenterPage = lazyOf(() => import('../features/tasks/TaskTemplateCenterPage'), 'TaskTemplateCenterPage')
const ProductsPage = lazyOf(() => import('../features/products/ProductsPage'), 'ProductsPage')
const CategoriesPage = lazyOf(() => import('../features/products/CategoriesPage'), 'CategoriesPage')
const InventoryPage = lazyOf(() => import('../features/products/InventoryPage'), 'InventoryPage')
const CouponsPage = lazyOf(() => import('../features/products/CouponsPage'), 'CouponsPage')
const SecondHandAuditPage = lazyOf(() => import('../features/second-hand/SecondHandAuditPage'), 'SecondHandAuditPage')
const OrdersPage = lazyOf(() => import('../features/orders/OrdersPage'), 'OrdersPage')
const AfterSalesPage = lazyOf(() => import('../features/after-sales/AfterSalesPage'), 'AfterSalesPage')
const AssetManagementPage = lazyOf(() => import('../features/assets/AssetManagementPage'), 'AssetManagementPage')
const PointsPage = lazyOf(() => import('../features/assets/PointsPage'), 'PointsPage')
const UsersPage = lazyOf(() => import('../features/users/UsersPage'), 'UsersPage')
const CommunityManagementPage = lazyOf(() => import('../features/community/CommunityManagementPage'), 'CommunityManagementPage')
const SupportPage = lazyOf(() => import('../features/support/SupportPage'), 'SupportPage')
const CampaignsPage = lazyOf(() => import('../features/operations/CampaignsPage'), 'CampaignsPage')
const OperationsPage = lazyOf(() => import('../features/operations/OperationsPage'), 'OperationsPage')
const NotifyPage = lazyOf(() => import('../features/operations/NotifyPage'), 'NotifyPage')
const DataBoardPage = lazyOf(() => import('../features/dashboard/DataBoardPage'), 'DataBoardPage')
const SettingsPage = lazyOf(() => import('../features/settings/SettingsPage'), 'SettingsPage')
const RolesPage = lazyOf(() => import('../features/security/RolesPage'), 'RolesPage')
const CampusManagementPage = lazyOf(() => import('../features/campuses/CampusManagementPage'), 'CampusManagementPage')
const AuditLogsPage = lazyOf(() => import('../features/security/AuditLogsPage'), 'AuditLogsPage')
const IdentityReviewPage = lazyOf(() => import('../features/identity/IdentityReviewPage'), 'IdentityReviewPage')
const PrivacyPage = lazyOf(() => import('../features/privacy/PrivacyPage'), 'PrivacyPage')
const ReadinessPage = lazyOf(() => import('../features/readiness/ReadinessPage'), 'ReadinessPage')
const PushTeamsPage = lazyOf(() => import('../features/push/PushTeamsPage'), 'PushTeamsPage')
const PushMembersPage = lazyOf(() => import('../features/push/PushMembersPage'), 'PushMembersPage')
const PushRulesPage = lazyOf(() => import('../features/push/PushRulesPage'), 'PushRulesPage')
const PushTasksPage = lazyOf(() => import('../features/push/PushTasksPage'), 'PushTasksPage')
const PushReviewsPage = lazyOf(() => import('../features/push/PushReviewsPage'), 'PushReviewsPage')
const PushSettlementsPage = lazyOf(() => import('../features/push/PushSettlementsPage'), 'PushSettlementsPage')
const MaterialAuditPage = lazyOf(() => import('../features/material/MaterialAuditPage'), 'MaterialAuditPage')
const MaterialCategoryPage = lazyOf(() => import('../features/material/MaterialCategoryPage'), 'MaterialCategoryPage')
const MaterialRequestPage = lazyOf(() => import('../features/material/MaterialRequestPage'), 'MaterialRequestPage')
const PosterTemplatePage = lazyOf(() => import('../features/settings/PosterTemplatePage'), 'PosterTemplatePage')
const DecorationPage = lazyOf(() => import('../features/decoration/DecorationPage'), 'DecorationPage')
const RobotPage = lazyOf(() => import('../features/robot/RobotPage'), 'RobotPage')
const ExpressPickupPage = lazyOf(() => import('../features/express-pickup/ExpressPickupPage'), 'ExpressPickupPage')
const StoresPage = lazyOf(() => import('../features/stores/StoresPage'), 'StoresPage')
const PostManagePage = lazyOf(() => import('../features/content-audit/PostManagePage'), 'PostManagePage')
const CommentManagePage = lazyOf(() => import('../features/content-audit/CommentManagePage'), 'CommentManagePage')
const AuditConfigPage = lazyOf(() => import('../features/content-audit/AuditConfigPage'), 'AuditConfigPage')
const AuditRecordPage = lazyOf(() => import('../features/content-audit/AuditRecordPage'), 'AuditRecordPage')
const CampusAgentBindingsPage = lazyOf(() => import('../features/campus-agent/CampusAgentBindingsPage'), 'CampusAgentBindingsPage')

export const ADMIN_GROUPS: AdminGroupDef[] = [
  { key: 'apps', label: '应用中心', icon: <AppstoreOutlined />, accent: '#0ea5e9', pages: [
    { path: '/apps', title: '应用中心', navCode: 'workbench', description: '全部模块与功能入口的集中导航。', icon: <AppstoreOutlined />, element: AppCenterPage },
  ] },
  { key: 'workbench', label: '工作台', icon: <DashboardOutlined />, accent: '#22c55e', pages: [
    { path: '/workbench', title: '工作台', navCode: 'workbench', description: '今日关键指标、待办事项与趋势概览。', icon: <DashboardOutlined />, element: DashboardPage },
  ] },
  { key: 'users', label: '用户中心', icon: <TeamOutlined />, accent: '#3b82f6', pages: [
    { path: '/users', title: '用户管理', navCode: 'users', description: '查询用户资料、校区归属与账号状态。', icon: <TeamOutlined />, element: UsersPage },
    { path: '/users/identity-review', title: '实名审核', navCode: 'identity_review', description: '审核学生实名认证材料。', icon: <IdcardOutlined />, element: IdentityReviewPage },
  ] },
  { key: 'tasks', label: '任务中心', icon: <ProfileOutlined />, accent: '#16a34a', pages: [
    { path: '/tasks/audit', title: '任务审核', navCode: 'tasks', description: '审核新发布任务的合规性与内容。', icon: <AuditOutlined />, element: TaskAuditPage },
    { path: '/tasks/list', title: '任务管理', navCode: 'tasks', description: '查询、下架与维护平台任务。', icon: <UnorderedListOutlined />, element: TaskManagementPage },
    { path: '/tasks/templates', title: '任务模板中心', navCode: 'task_templates', description: '维护可复用的任务发布模板。', icon: <SnippetsOutlined />, element: TaskTemplateCenterPage },
  ] },
  { key: 'mall', label: '商城中心', icon: <ShopOutlined />, accent: '#f59e0b', pages: [
    { path: '/mall/products', title: '商品管理', navCode: 'mall', description: '商品上下架、价格与 SKU 维护。', icon: <ShoppingOutlined />, element: ProductsPage },
    { path: '/mall/second-hand', title: '闲置审核', navCode: 'second_hand', description: '审核校园闲置物品发布内容。', icon: <TagOutlined />, element: SecondHandAuditPage },
    { path: '/mall/categories', title: '商品分类', navCode: 'mall', description: '维护商城分类层级与排序。', icon: <AppstoreOutlined />, element: CategoriesPage },
    { path: '/mall/inventory', title: '库存管理', navCode: 'mall', description: '查看库存数量与变动记录。', icon: <DatabaseOutlined />, element: InventoryPage },
    { path: '/mall/coupons', title: '优惠券', navCode: 'mall', description: '创建与发放商城优惠券。', icon: <GiftOutlined />, element: CouponsPage },
  ] },
  { key: 'orders', label: '订单与售后', icon: <ShoppingCartOutlined />, accent: '#6366f1', pages: [
    { path: '/orders', title: '订单管理', navCode: 'orders', description: '查询订单、处理发货与异常订单。', icon: <ShoppingCartOutlined />, element: OrdersPage },
    { path: '/after-sales', title: '售后管理', navCode: 'orders', description: '处理退款、换货与售后工单。', icon: <CustomerServiceOutlined />, element: AfterSalesPage },
  ] },
  { key: 'finance', label: '资产与财务', icon: <WalletOutlined />, accent: '#14b8a6', pages: [
    { path: '/finance/withdrawals', title: '资产与提现复核', navCode: 'finance', description: '复核提现申请并登记打款结果。', icon: <WalletOutlined />, element: AssetManagementPage },
    { path: '/finance/points', title: '积分管理', navCode: 'finance', description: '查看积分流水与人工调整积分。', icon: <TrophyOutlined />, element: PointsPage },
  ] },
  { key: 'community', label: '社区内容', icon: <MessageOutlined />, accent: '#ec4899', pages: [
    { path: '/community', title: '社区运营', navCode: 'community', description: '社区分类、话题与举报处理。', icon: <MessageOutlined />, element: CommunityManagementPage },
  ] },
  { key: 'material', label: '资料共享', icon: <ReadOutlined />, accent: '#8b5cf6', pages: [
    { path: '/material/audit', title: '资料审核', navCode: 'material', description: '审核用户上传的学习资料。', icon: <ReadOutlined />, element: MaterialAuditPage },
    { path: '/material/categories', title: '资料分类', navCode: 'material', description: '维护资料库分类与展示顺序。', icon: <FolderOpenOutlined />, element: MaterialCategoryPage },
    { path: '/material/requests', title: '求资料与举报', navCode: 'material', description: '处理资料求助与违规举报。', icon: <FileSearchOutlined />, element: MaterialRequestPage },
  ] },
  { key: 'operations', label: '运营与消息', icon: <NotificationOutlined />, accent: '#f97316', pages: [
    { path: '/operations/campaigns', title: '活动运营', navCode: 'operations', description: '配置平台活动与推广位。', icon: <FireOutlined />, element: CampaignsPage },
    { path: '/operations/release-control', title: '功能灰度发布', navCode: 'operations', description: '按端与校区控制模块开关。', icon: <ExperimentOutlined />, element: OperationsPage },
    { path: '/operations/data-board', title: '数据看板', navCode: 'data_board', description: '业务核心数据的汇总视图。', icon: <BarChartOutlined />, element: DataBoardPage },
    { path: '/operations/notify', title: '消息通知', navCode: 'operations', description: '发送站内与订阅消息通知。', icon: <NotificationOutlined />, element: NotifyPage },
  ] },
  { key: 'push', label: '地推运营', icon: <RocketOutlined />, accent: '#ef4444', pages: [
    { path: '/push/teams', title: '团队管理', navCode: 'push', description: '管理地推团队与负责校区。', icon: <RocketOutlined />, element: PushTeamsPage },
    { path: '/push/members', title: '成员与等级', navCode: 'push', description: '维护团队成员与等级晋升。', icon: <UsergroupAddOutlined />, element: PushMembersPage },
    { path: '/push/rules', title: '等级佣金规则', navCode: 'push', description: '配置等级佣金与阶梯奖励。', icon: <PercentageOutlined />, element: PushRulesPage },
    { path: '/push/tasks', title: '推广任务配置', navCode: 'push', description: '配置推广任务与考核指标。', icon: <NodeIndexOutlined />, element: PushTasksPage },
    { path: '/push/reviews', title: '申请与交单审核', navCode: 'push', description: '审核入团申请与推广交单。', icon: <FileDoneOutlined />, element: PushReviewsPage },
    { path: '/push/settlements', title: '结算批次', navCode: 'push', description: '生成与核对佣金结算批次。', icon: <AccountBookOutlined />, element: PushSettlementsPage },
  ] },
  { key: 'express', label: '快递代取', icon: <CarOutlined />, accent: '#0ea5e9', pages: [
    { path: '/express-pickup', title: '快递代取', navCode: 'express_pickup', description: '管理代取订单与配送状态。', icon: <CarOutlined />, element: ExpressPickupPage },
  ] },
  { key: 'stores', label: '门店管理', icon: <ShopOutlined />, accent: '#06b6d4', pages: [
    { path: '/stores', title: '门店管理', navCode: 'stores', description: '维护合作门店资料与营业状态。', icon: <ShopOutlined />, element: StoresPage },
  ] },
  { key: 'robot', label: 'XP 机器人', icon: <RobotOutlined />, accent: '#7c3aed', pages: [
    { path: '/robot', title: 'XP 机器人', navCode: 'robot', description: '配置机器人转发与校区开放范围。', icon: <RobotOutlined />, element: RobotPage },
  ] },
  { key: 'content_audit', label: '内容审核', icon: <SafetyCertificateOutlined />, accent: '#dc2626', pages: [
    { path: '/content-audit/posts', title: '帖子管理', navCode: 'community', description: '管理社区帖子与审核状态。', icon: <FileTextOutlined />, element: PostManagePage },
    { path: '/content-audit/comments', title: '评论管理', navCode: 'community', description: '处理评论审核与违规内容。', icon: <CommentOutlined />, element: CommentManagePage },
    { path: '/content-audit/config', title: '审核配置', navCode: 'settings', description: '配置内容安全服务与审核策略。', icon: <SafetyCertificateOutlined />, element: AuditConfigPage },
    { path: '/content-audit/records', title: '审核记录', navCode: 'community', description: '查询机器审核与人工复核记录。', icon: <HistoryOutlined />, element: AuditRecordPage },
  ] },
  { key: 'config', label: '配置中心', icon: <SettingOutlined />, accent: '#64748b', pages: [
    { path: '/config', title: '配置中心', navCode: 'settings', description: '平台参数、支付与三方服务配置。', icon: <SettingOutlined />, element: SettingsPage },
    { path: '/decoration', title: '装修中心', navCode: 'settings', description: '配置小程序首页与频道装修。', icon: <BgColorsOutlined />, element: DecorationPage },
    { path: '/support/tickets', title: '工单客服', navCode: 'support', description: '处理用户咨询与售后工单。', icon: <CustomerServiceOutlined />, element: SupportPage },
    { path: '/config/privacy', title: '隐私与合规', navCode: 'privacy', description: '维护协议文本与隐私合规配置。', icon: <FileProtectOutlined />, element: PrivacyPage },
    { path: '/config/readiness', title: '上线检查', navCode: 'readiness', description: '上线前的配置与依赖自检清单。', icon: <CheckCircleOutlined />, element: ReadinessPage },
    { path: '/config/poster-templates', title: '海报模板管理', navCode: 'settings', description: '维护分享海报模板与文案。', icon: <PictureOutlined />, element: PosterTemplatePage },
  ] },
  { key: 'security', label: '权限与审计', icon: <SafetyCertificateOutlined />, accent: '#475569', pages: [
    { path: '/security/roles', title: '角色权限', navCode: 'security', description: '配置角色与菜单权限范围。', icon: <LockOutlined />, element: RolesPage },
    { path: '/security/campuses', title: '学校与代理', navCode: 'campuses', description: '维护学校信息与代理归属。', icon: <BankOutlined />, element: CampusManagementPage },
    { path: '/security/campus-agent-bindings', title: '代理绑定', navCode: 'campuses', description: '管理代理微信 openid 绑定与交流板帖子。', icon: <TeamOutlined />, element: CampusAgentBindingsPage },
    { path: '/security/logs', title: '操作日志', navCode: 'security', description: '查询后台敏感操作的审计记录。', icon: <AuditOutlined />, element: AuditLogsPage },
  ] },
]

export function visibleGroups(navigation: NavigationItem[]): AdminGroupDef[] {
  const codes = new Set(navigation.map((n) => n.code))
  return ADMIN_GROUPS
    .map((g) => ({ ...g, pages: g.pages.filter((p) => codes.has(p.navCode)) }))
    .filter((g) => g.pages.length > 0)
}

export function findRoute(path: string, groups: AdminGroupDef[]): { group: AdminGroupDef; page: AdminPageDef } | null {
  for (const g of groups) {
    const p = g.pages.find((pg) => pg.path === path)
    if (p) return { group: g, page: p }
  }
  return null
}

export function useHashPath(): string {
  const read = () => window.location.hash.replace(/^#/, '') || '/workbench'
  const [path, setPath] = useState(read)
  useEffect(() => {
    const on = () => setPath(read())
    window.addEventListener('hashchange', on)
    return () => window.removeEventListener('hashchange', on)
  }, [])
  return path
}

export function navigate(path: string) {
  window.location.hash = path
}
