import {
  DashboardOutlined,
  TeamOutlined,
  ProfileOutlined,
  ShopOutlined,
  ShoppingCartOutlined,
  WalletOutlined,
  MessageOutlined,
  NotificationOutlined,
  RocketOutlined,
  ReadOutlined,
  SettingOutlined,
  SafetyCertificateOutlined,
} from '@ant-design/icons'
import { lazy, useEffect, useState, type ComponentType, type LazyExoticComponent, type ReactNode } from 'react'
import type { NavigationItem } from '../api/admin'

export interface AdminPageDef {
  path: string
  title: string
  navCode: string
  element: LazyExoticComponent<ComponentType>
}
export interface AdminGroupDef {
  key: string
  label: string
  icon: ReactNode
  pages: AdminPageDef[]
}

const lazyOf = (loader: () => Promise<Record<string, ComponentType>>, name: string) =>
  lazy(() => loader().then((m) => ({ default: m[name] })))

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

export const ADMIN_GROUPS: AdminGroupDef[] = [
  { key: 'workbench', label: '工作台', icon: <DashboardOutlined />, pages: [
    { path: '/workbench', title: '工作台', navCode: 'workbench', element: DashboardPage },
  ] },
  { key: 'users', label: '用户中心', icon: <TeamOutlined />, pages: [
    { path: '/users', title: '用户管理', navCode: 'users', element: UsersPage },
    { path: '/users/identity-review', title: '实名审核', navCode: 'identity_review', element: IdentityReviewPage },
  ] },
  { key: 'tasks', label: '任务中心', icon: <ProfileOutlined />, pages: [
    { path: '/tasks/audit', title: '任务审核', navCode: 'tasks', element: TaskAuditPage },
    { path: '/tasks/list', title: '任务管理', navCode: 'tasks', element: TaskManagementPage },
    { path: '/tasks/templates', title: '任务模板中心', navCode: 'task_templates', element: TaskTemplateCenterPage },
  ] },
  { key: 'mall', label: '商城中心', icon: <ShopOutlined />, pages: [
    { path: '/mall/products', title: '商品管理', navCode: 'mall', element: ProductsPage },
    { path: '/mall/second-hand', title: '闲置审核', navCode: 'second_hand', element: SecondHandAuditPage },
    { path: '/mall/categories', title: '商品分类', navCode: 'mall', element: CategoriesPage },
    { path: '/mall/inventory', title: '库存管理', navCode: 'mall', element: InventoryPage },
    { path: '/mall/coupons', title: '优惠券', navCode: 'mall', element: CouponsPage },
  ] },
  { key: 'orders', label: '订单与售后', icon: <ShoppingCartOutlined />, pages: [
    { path: '/orders', title: '订单管理', navCode: 'orders', element: OrdersPage },
    { path: '/after-sales', title: '售后管理', navCode: 'orders', element: AfterSalesPage },
  ] },
  { key: 'finance', label: '资产与财务', icon: <WalletOutlined />, pages: [
    { path: '/finance/withdrawals', title: '资产与提现复核', navCode: 'finance', element: AssetManagementPage },
    { path: '/finance/points', title: '积分管理', navCode: 'finance', element: PointsPage },
  ] },
  { key: 'community', label: '社区内容', icon: <MessageOutlined />, pages: [
    { path: '/community', title: '社区运营', navCode: 'community', element: CommunityManagementPage },
  ] },
  { key: 'material', label: '资料共享', icon: <ReadOutlined />, pages: [
    { path: '/material/audit', title: '资料审核', navCode: 'material', element: MaterialAuditPage },
    { path: '/material/categories', title: '资料分类', navCode: 'material', element: MaterialCategoryPage },
    { path: '/material/requests', title: '求资料与举报', navCode: 'material', element: MaterialRequestPage },
  ] },
  { key: 'operations', label: '运营与消息', icon: <NotificationOutlined />, pages: [
    { path: '/operations/campaigns', title: '活动运营', navCode: 'operations', element: CampaignsPage },
    { path: '/operations/release-control', title: '功能灰度发布', navCode: 'operations', element: OperationsPage },
    { path: '/operations/data-board', title: '数据看板', navCode: 'data_board', element: DataBoardPage },
    { path: '/operations/notify', title: '消息通知', navCode: 'operations', element: NotifyPage },
  ] },
  { key: 'push', label: '地推运营', icon: <RocketOutlined />, pages: [
    { path: '/push/teams', title: '团队管理', navCode: 'push', element: PushTeamsPage },
    { path: '/push/members', title: '成员与等级', navCode: 'push', element: PushMembersPage },
    { path: '/push/rules', title: '等级佣金规则', navCode: 'push', element: PushRulesPage },
    { path: '/push/tasks', title: '推广任务配置', navCode: 'push', element: PushTasksPage },
    { path: '/push/reviews', title: '申请与交单审核', navCode: 'push', element: PushReviewsPage },
    { path: '/push/settlements', title: '结算批次', navCode: 'push', element: PushSettlementsPage },
  ] },
  { key: 'config', label: '配置中心', icon: <SettingOutlined />, pages: [
    { path: '/config', title: '配置中心', navCode: 'settings', element: SettingsPage },
    { path: '/support/tickets', title: '工单客服', navCode: 'support', element: SupportPage },
    { path: '/config/privacy', title: '隐私与合规', navCode: 'privacy', element: PrivacyPage },
    { path: '/config/readiness', title: '上线检查', navCode: 'readiness', element: ReadinessPage },
    { path: '/config/poster-templates', title: '海报模板管理', navCode: 'settings', element: PosterTemplatePage },
  ] },
  { key: 'security', label: '权限与审计', icon: <SafetyCertificateOutlined />, pages: [
    { path: '/security/roles', title: '角色权限', navCode: 'security', element: RolesPage },
    { path: '/security/campuses', title: '学校与代理', navCode: 'campuses', element: CampusManagementPage },
    { path: '/security/logs', title: '操作日志', navCode: 'security', element: AuditLogsPage },
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
