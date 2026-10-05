import {
  AppstoreOutlined,
  AuditOutlined,
  BarChartOutlined,
  CheckCircleOutlined,
  ClockCircleOutlined,
  CustomerServiceOutlined,
  DashboardOutlined,
  FileDoneOutlined,
  EnvironmentOutlined,
  LockOutlined,
  LogoutOutlined,
  KeyOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  MessageOutlined,
  NotificationOutlined,
  ProfileOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  ShopOutlined,
  ShoppingCartOutlined,
  SwapOutlined,
  TeamOutlined,
  UserOutlined,
  WalletOutlined,
} from '@ant-design/icons'
import {
  Alert,
  Avatar,
  Button,
  Card,
  Checkbox,
  ConfigProvider,
  Dropdown,
  Drawer,
  Form,
  Input,
  Layout,
  Menu,
  Modal,
  Result,
  Select,
  Skeleton,
  Space,
  Statistic,
  Tag,
  Typography,
  message,
} from 'antd'
import zhCN from 'antd/locale/zh_CN'
import { lazy, Suspense, useEffect, useState, type ReactNode } from 'react'
import { ApiError, changeAdminPassword, getAdminCampusScope, getOperationsAnalytics, getOperationsDashboard, getRememberedAdminAccount, setAdminCampusScope, type AdminMfaChallenge, type NavigationItem, type OperationsAnalytics, type OperationsMetrics } from './api/admin'
import { useAdminAuth } from './features/auth/AdminAuthContext'
import { AdminAuthProvider } from './features/auth/AdminAuthProvider'
import './App.css'

const SettingsPage = lazy(() => import('./features/settings/SettingsPage').then((module) => ({ default: module.SettingsPage })))
const SecurityPage = lazy(() => import('./features/security/SecurityPage').then((module) => ({ default: module.SecurityPage })))
const PrivacyPage = lazy(() => import('./features/privacy/PrivacyPage').then((module) => ({ default: module.PrivacyPage })))
const ReadinessPage = lazy(() => import('./features/readiness/ReadinessPage').then((module) => ({ default: module.ReadinessPage })))
const TaskManagementPage = lazy(() => import('./features/tasks/TaskManagementPage').then((module) => ({ default: module.TaskManagementPage })))
const TaskTemplateCenterPage = lazy(() => import('./features/tasks/TaskTemplateCenterPage').then((module) => ({ default: module.TaskTemplateCenterPage })))
const AssetManagementPage = lazy(() => import('./features/assets/AssetManagementPage').then((module) => ({ default: module.AssetManagementPage })))
const ProductManagementPage = lazy(() => import('./features/products/ProductManagementPage').then((module) => ({ default: module.ProductManagementPage })))
const AfterSaleManagementPage = lazy(() => import('./features/after-sales/AfterSaleManagementPage').then((module) => ({ default: module.AfterSaleManagementPage })))
const CommunityManagementPage = lazy(() => import('./features/community/CommunityManagementPage').then((module) => ({ default: module.CommunityManagementPage })))
const SecondHandManagementPage = lazy(() => import('./features/second-hand/SecondHandManagementPage').then((module) => ({ default: module.SecondHandManagementPage })))
const OperationsPage = lazy(() => import('./features/operations/OperationsPage').then((module) => ({ default: module.OperationsPage })))
const DataBoardPage = lazy(() => import('./features/operations/DataBoardPage').then((module) => ({ default: module.DataBoardPage })))
const UserOperationsPage = lazy(() => import('./features/users/UserOperationsPage').then((module) => ({ default: module.UserOperationsPage })))
const SupportTicketPage = lazy(() => import('./features/support/SupportTicketPage').then((module) => ({ default: module.SupportTicketPage })))
const CampusManagementPage = lazy(() => import('./features/campuses/CampusManagementPage').then((module) => ({ default: module.CampusManagementPage })))
const IdentityReviewPage = lazy(() => import('./features/identity/IdentityReviewPage').then((module) => ({ default: module.IdentityReviewPage })))

const iconMap: Record<string, ReactNode> = {
  DashboardOutlined: <DashboardOutlined />,
  TeamOutlined: <TeamOutlined />,
  EnvironmentOutlined: <EnvironmentOutlined />,
  ProfileOutlined: <ProfileOutlined />,
  ShopOutlined: <ShopOutlined />,
  FileDoneOutlined: <FileDoneOutlined />,
  WalletOutlined: <WalletOutlined />,
  MessageOutlined: <MessageOutlined />,
  NotificationOutlined: <NotificationOutlined />,
  SettingOutlined: <SettingOutlined />,
  SafetyCertificateOutlined: <SafetyCertificateOutlined />,
  CustomerServiceOutlined: <CustomerServiceOutlined />,
  SwapOutlined: <SwapOutlined />,
  BarChartOutlined: <BarChartOutlined />,
}

const roleNames: Record<string, string> = {
  super_admin: '超级管理员',
  platform_admin: '平台管理员',
  operations_admin: '运营管理员',
  campus_agent: '学校代理',
  task_reviewer: '任务审核员',
  community_reviewer: '社区审核员',
  customer_service: '客服人员',
  warehouse: '仓储人员',
  finance: '财务人员',
  auditor: '审计人员',
}

function LoginPage() {
  const { login, verifyMfa } = useAdminAuth()
  const rememberedAccount = getRememberedAdminAccount()
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [challenge, setChallenge] = useState<AdminMfaChallenge | null>(null)
  const [rememberLogin, setRememberLogin] = useState(Boolean(rememberedAccount))

  const submit = async (values: { account: string; password: string; code?: string; remember?: boolean }) => {
    setSubmitting(true)
    setError(null)
    try {
      if (challenge) {
        await verifyMfa(challenge, values.code ?? '', rememberLogin)
      } else {
        const remember = values.remember ?? false
        setRememberLogin(remember)
        setChallenge(await login(values.account, values.password, remember))
      }
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : '登录失败，请检查网络后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="login-story">
        <div className="brand-mark"><AppstoreOutlined /></div>
        <Typography.Text className="eyebrow">校园任务商城 · 运营管理平台</Typography.Text>
        <Typography.Title>一处管理校园任务、社区与商城</Typography.Title>
        <Typography.Paragraph>
          面向校园微信小程序与多端 H5 的统一运营后台。任务、组团、社区内容和关键资产操作均进入审计记录。
        </Typography.Paragraph>
        <div className="security-note"><SafetyCertificateOutlined /> 独立后台会话 · 最小权限 · 全程审计</div>
      </section>
      <Card className="login-card" variant="borderless">
        <Typography.Title level={2}>{challenge ? '完成二次验证' : '登录管理后台'}</Typography.Title>
        <Typography.Paragraph type="secondary">{challenge ? '输入验证器动态码或一次性恢复码' : '请输入管理员账号和密码'}</Typography.Paragraph>
        {error && <Alert showIcon type="error" title={error} className="login-alert" />}
        <Form name="admin-login" autoComplete="on" layout="vertical" requiredMark={false} initialValues={{ account: rememberedAccount, remember: Boolean(rememberedAccount) }} onFinish={submit} size="large">
          {!challenge && <Form.Item label="管理员账号" name="account" rules={[{ required: true, message: '请输入管理员账号' }]}>
            <Input prefix={<UserOutlined />} autoComplete="username" placeholder="请输入账号" />
          </Form.Item>}
          {!challenge && <Form.Item label="密码" name="password" rules={[{ required: true, message: '请输入密码' }]}>
            <Input.Password prefix={<LockOutlined />} autoComplete="current-password" placeholder="请输入密码" />
          </Form.Item>}
          {!challenge && <Form.Item
            className="login-remember-item"
            name="remember"
            valuePropName="checked"
            extra="账号会自动回填；密码由浏览器密码管理器安全保存，系统不会明文记录。"
          >
            <Checkbox>记住账号并保持登录（30 天）</Checkbox>
          </Form.Item>}
          {challenge && <Form.Item label="验证码或恢复码" name="code" rules={[{ required: true, message: '请输入验证码或恢复码' }]}>
            <Input prefix={<SafetyCertificateOutlined />} autoComplete="one-time-code" placeholder="6 位动态码" />
          </Form.Item>}
          <Button block type="primary" htmlType="submit" loading={submitting}>{challenge ? '验证并登录' : '安全登录'}</Button>
          {challenge && <Button block type="link" onClick={() => { setChallenge(null); setError(null) }}>返回账号登录</Button>}
        </Form>
        <Typography.Paragraph className="login-help" type="secondary">
          首位管理员由运维人员通过 <code>php artisan admin:create</code> 安全创建，不提供通用默认密码。
        </Typography.Paragraph>
      </Card>
    </main>
  )
}

const workbenchQuickEntries: Array<{ code: string; label: string; color: string }> = [
  { code: 'tasks', label: '任务审核', color: 'green' },
  { code: 'second_hand', label: '闲置审核', color: 'orange' },
  { code: 'finance', label: '提现审核', color: 'blue' },
  { code: 'support', label: '工单客服', color: 'default' },
  { code: 'data_board', label: '数据看板', color: 'purple' },
]

function Workbench({ onNavigate }: { onNavigate: (code: string) => void }) {
  const { admin, navigation } = useAdminAuth()
  const [metrics, setMetrics] = useState<OperationsMetrics | null>(null)
  const [analytics, setAnalytics] = useState<OperationsAnalytics | null>(null)
  const canViewOperations = admin?.permissions.includes('operations.manage') ?? false

  useEffect(() => {
    if (!canViewOperations) {
      setMetrics(null)
      setAnalytics(null)
      return
    }
    void getOperationsDashboard().then(setMetrics).catch(() => setMetrics(null))
    void getOperationsAnalytics().then(setAnalytics).catch(() => setAnalytics(null))
  }, [canViewOperations])

  if (!canViewOperations) {
    return (
      <>
        <div className="page-heading workbench-heading">
          <div>
            <Typography.Text className="eyebrow">岗位权限视图</Typography.Text>
            <Typography.Title level={2}>角色工作台</Typography.Title>
            <Typography.Paragraph type="secondary">当前后台已按岗位最小权限加载，仅显示允许访问的业务模块。</Typography.Paragraph>
          </div>
          <Tag color="processing"><SafetyCertificateOutlined /> 权限已加载</Tag>
        </div>
        <Card variant="borderless">
          <Result
            status="info"
            title="请选择左侧已授权的业务模块"
            subTitle="平台运营汇总仅向具备运营管理权限的角色展示；未授权数据不会请求，也不会以零值代替。"
          />
        </Card>
      </>
    )
  }

  const pending = [
    { label: '任务待审核', value: metrics?.tasks.pending_reviews ?? 0, icon: <AuditOutlined /> },
    { label: '待履约订单', value: metrics?.orders.pending_fulfillment ?? 0, icon: <ShoppingCartOutlined /> },
    { label: '退款与换货', value: (metrics?.after_sales.pending_refunds ?? 0) + (metrics?.after_sales.pending_exchanges ?? 0), icon: <ClockCircleOutlined /> },
    { label: '对账差异', value: metrics?.finance.reconciliation_differences ?? 0, icon: <SafetyCertificateOutlined /> },
  ]

  return (
    <>
      <div className="page-heading workbench-heading">
        <div>
          <Typography.Text className="eyebrow">校园运营总览</Typography.Text>
          <Typography.Title level={2}>运营工作台</Typography.Title>
          <Typography.Paragraph type="secondary">集中查看任务、商城、售后和资金风险。</Typography.Paragraph>
        </div>
        <Space><Tag color="processing">本地预览</Tag><Tag color="success"><CheckCircleOutlined /> 服务正常</Tag></Space>
      </div>
      <div className="stat-grid stat-grid-four">
        <Card variant="borderless"><Statistic title="活跃用户" value={metrics?.users.active_total ?? 0} suffix="人" prefix={<TeamOutlined />} /></Card>
        <Card variant="borderless"><Statistic title="已发布任务" value={metrics?.tasks.published ?? 0} suffix="个" prefix={<ProfileOutlined />} /></Card>
        <Card variant="borderless"><Statistic title="今日实付订单" value={metrics?.orders.paid_today ?? 0} suffix="单" prefix={<ShopOutlined />} /></Card>
        <Card variant="borderless"><Statistic title="未读业务消息" value={metrics?.notifications.unread_total ?? 0} suffix="条" prefix={<NotificationOutlined />} /></Card>
      </div>
      <div className="workbench-grid">
        <Card variant="borderless" className="milestone-card" title="近7天任务与订单趋势" extra={<div className="trend-legend"><span className="trend-task">任务领取</span><span className="trend-order">实付订单</span></div>}>
          <div className="trend-chart">
            {(analytics?.task_claim_trend ?? []).map((item, index) => {
              const orderCount = analytics?.order_trend[index]?.count ?? 0
              const trendMax = Math.max(1, ...(analytics?.task_claim_trend.map((point) => point.count) ?? [1]), ...(analytics?.order_trend.map((point) => point.count) ?? [1]))
              return (
                <div className="trend-day" key={item.date}>
                  <div className="trend-bars">
                    <i className="trend-task" style={{ height: `${Math.max(4, item.count / trendMax * 120)}px` }} title={`任务领取 ${item.count}`} />
                    <i className="trend-order" style={{ height: `${Math.max(4, orderCount / trendMax * 120)}px` }} title={`实付订单 ${orderCount}`} />
                  </div>
                  <small>{item.date}</small>
                </div>
              )
            })}
          </div>
        </Card>
        <Card variant="borderless" className="milestone-card" title="待处理事项" extra={<Typography.Text type="secondary">实时汇总</Typography.Text>}>
          <div className="pending-grid">
            {pending.map((item) => <div className="pending-item" key={item.label}><span className="pending-icon">{item.icon}</span><span><strong>{item.value}</strong><small>{item.label}</small></span></div>)}
          </div>
        </Card>
      </div>
      <div className="workbench-grid">
        <Card variant="borderless" className="milestone-card" title="快捷入口">
          <Space wrap size={10}>
            {workbenchQuickEntries.filter((entry) => navigation.some((item) => item.code === entry.code)).map((entry) => (
              <Tag key={entry.code} color={entry.color} style={{ cursor: 'pointer', padding: '4px 14px', fontSize: 13, fontWeight: 700 }} onClick={() => onNavigate(entry.code)}>{entry.label}</Tag>
            ))}
          </Space>
        </Card>
        <Card variant="borderless" className="milestone-card" title="资产与系统">
          <Space orientation="vertical" size={16} className="system-summary">
            <Typography.Text><SafetyCertificateOutlined /> 余额与积分流水采用不可变账本</Typography.Text>
            <Typography.Text><AuditOutlined /> 高风险配置、调账和发布支持四眼复核</Typography.Text>
            <Typography.Text><CheckCircleOutlined /> 本地自动化 UAT 与一致性审计已接入</Typography.Text>
          </Space>
        </Card>
      </div>
    </>
  )
}

function Workspace({ item, onNavigate }: { item: NavigationItem; onNavigate: (code: string) => void }) {
  if (item.code === 'campuses') return <CampusManagementPage />
  if (item.code === 'identity_review') return <IdentityReviewPage />
  if (item.code === 'users') return <UserOperationsPage />
  if (item.code === 'settings') return <SettingsPage />
  if (item.code === 'security') return <SecurityPage />
  if (item.code === 'privacy') return <PrivacyPage />
  if (item.code === 'readiness') return <ReadinessPage />
  if (item.code === 'tasks') return <TaskManagementPage />
  if (item.code === 'task_templates') return <TaskTemplateCenterPage />
  if (item.code === 'finance') return <AssetManagementPage />
  if (item.code === 'mall') return <ProductManagementPage />
  if (item.code === 'orders') return <AfterSaleManagementPage />
  if (item.code === 'community') return <CommunityManagementPage />
  if (item.code === 'second_hand') return <SecondHandManagementPage />
  if (item.code === 'operations') return <OperationsPage />
  if (item.code === 'support') return <SupportTicketPage />
  if (item.code === 'data_board') return <DataBoardPage />
  if (item.code !== 'workbench') {
    return (
      <Result
        icon={iconMap[item.icon]}
        title={item.label}
        subTitle="权限入口已经接通，具体业务页面将在对应迭代中逐项实现。"
      />
    )
  }

  return <Workbench onNavigate={onNavigate} />
}

function AdminShell() {
  const { admin, navigation, logout } = useAdminAuth()
  const [collapsed, setCollapsed] = useState(false)
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [activeCode, setActiveCode] = useState('workbench')
  const [passwordOpen, setPasswordOpen] = useState(false)
  const [passwordSubmitting, setPasswordSubmitting] = useState(false)
  const [campusScope, setCampusScopeState] = useState<string | null>(() => getAdminCampusScope())
  const [passwordForm] = Form.useForm()
  const [messageApi, contextHolder] = message.useMessage()
  const activeItem = navigation.find((item) => item.code === activeCode) ?? navigation[0]
  const navigationItems = navigation.map((item) => ({ key: item.code, icon: iconMap[item.icon], label: item.label }))
  const usesCampusScope = Boolean(admin?.roles.includes('campus_agent') && !admin.roles.includes('super_admin'))

  useEffect(() => {
    if (!usesCampusScope || !admin?.campuses.length) {
      setCampusScopeState(null)
      setAdminCampusScope(null)
      return
    }
    const stored = getAdminCampusScope()
    const next = admin.campuses.some((campus) => campus.id === stored)
      ? stored
      : (admin.campuses.find((campus) => campus.is_primary)?.id ?? admin.campuses[0].id)
    setCampusScopeState(next)
    setAdminCampusScope(next)
  }, [admin, usesCampusScope])

  const selectNavigation = (key: string) => {
    setActiveCode(key)
    setMobileNavOpen(false)
  }

  const selectCampusScope = (campusId: string) => {
    setAdminCampusScope(campusId)
    setCampusScopeState(campusId)
  }

  const signOut = async () => {
    try {
      await logout()
    } catch {
      messageApi.error('退出请求未完成，本地会话已清理。')
    }
  }

  const submitPasswordChange = async (values: { current_password: string; new_password: string; new_password_confirmation: string; mfa_code?: string }) => {
    setPasswordSubmitting(true)
    try {
      await changeAdminPassword(values)
      messageApi.success('密码修改成功，其他设备上的登录已退出。')
      setPasswordOpen(false)
      passwordForm.resetFields()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '修改密码失败，请稍后重试。')
    } finally {
      setPasswordSubmitting(false)
    }
  }

  return (
    <Layout className="admin-layout">
      {contextHolder}
      <Layout.Sider width={248} collapsedWidth={76} collapsed={collapsed} className="admin-sider">
        <div className="sider-brand"><AppstoreOutlined /><span>校园任务商城</span></div>
        <Menu
          theme="dark"
          mode="inline"
          selectedKeys={[activeItem?.code ?? 'workbench']}
          items={navigationItems}
          onClick={({ key }) => selectNavigation(key)}
        />
        <div className="sider-version">{collapsed ? 'V1' : 'V1 · OPERATIONS'}</div>
      </Layout.Sider>
      <Layout>
        <Layout.Header className="admin-header">
          <Button
            className="desktop-sider-toggle"
            type="text"
            icon={collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
            onClick={() => setCollapsed((value) => !value)}
          />
          <Button
            className="mobile-nav-toggle"
            type="text"
            aria-label="打开后台导航"
            icon={<MenuOutlined />}
            onClick={() => setMobileNavOpen(true)}
          />
          <div className="admin-header-actions">
            {usesCampusScope && admin?.campuses.length ? <Select
              className="campus-scope-select"
              aria-label="切换当前运营学校"
              value={campusScope ?? undefined}
              onChange={selectCampusScope}
              options={admin.campuses.map((campus) => ({ value: campus.id, label: `${campus.name}${campus.is_primary ? ' · 主负责' : ''}` }))}
              popupMatchSelectWidth={260}
            /> : null}
            <Dropdown
              trigger={['click']}
              placement="bottomRight"
              arrow
              menu={{ items: [
                { key: 'password', icon: <KeyOutlined />, label: '修改密码', onClick: () => setPasswordOpen(true) },
                { type: 'divider' },
                { key: 'logout', icon: <LogoutOutlined />, label: '退出登录', danger: true, onClick: signOut },
              ] }}
            >
              <Button type="text" className="profile-button" aria-label="打开管理员账号菜单">
                <Avatar>{admin?.display_name.slice(0, 1)}</Avatar>
                <span><strong>{admin?.display_name}</strong><small>{admin?.roles.map((role) => roleNames[role] ?? role).join('、')}</small></span>
              </Button>
            </Dropdown>
          </div>
        </Layout.Header>
        <Layout.Content className="admin-content">
          {activeItem && <div className="workspace-stage" key={`${activeItem.code}:${campusScope ?? 'global'}`}><Suspense fallback={<Skeleton active paragraph={{ rows: 5 }} />}><Workspace item={activeItem} onNavigate={selectNavigation} /></Suspense></div>}
        </Layout.Content>
      </Layout>
      <Drawer
        className="mobile-navigation"
        placement="left"
        size={300}
        open={mobileNavOpen}
        onClose={() => setMobileNavOpen(false)}
        title={<span className="mobile-navigation-title"><AppstoreOutlined />校园任务商城</span>}
      >
        <Menu
          theme="dark"
          mode="inline"
          selectedKeys={[activeItem?.code ?? 'workbench']}
          items={navigationItems}
          onClick={({ key }) => selectNavigation(key)}
        />
        <div className="mobile-navigation-footer">
          <div className="mobile-navigation-identity">
            <Avatar>{admin?.display_name.slice(0, 1)}</Avatar>
            <span><strong>{admin?.display_name}</strong><small>{admin?.roles.map((role) => roleNames[role] ?? role).join('、')}</small></span>
          </div>
          <div className="mobile-navigation-actions">
            <Button icon={<KeyOutlined />} onClick={() => { setMobileNavOpen(false); setPasswordOpen(true) }}>修改密码</Button>
            <Button danger icon={<LogoutOutlined />} onClick={() => { setMobileNavOpen(false); void signOut() }}>退出登录</Button>
          </div>
        </div>
      </Drawer>
      <Modal
        title="修改登录密码"
        open={passwordOpen}
        onCancel={() => { setPasswordOpen(false); passwordForm.resetFields() }}
        onOk={() => passwordForm.submit()}
        okText="确认修改"
        cancelText="取消"
        confirmLoading={passwordSubmitting}
        destroyOnHidden
      >
        <Alert type="info" showIcon title="新密码至少 12 位，必须同时包含字母和数字；修改后其他设备将退出登录。" style={{ marginBottom: 16 }} />
        <Form form={passwordForm} layout="vertical" onFinish={submitPasswordChange}>
          <Form.Item name="current_password" label="当前密码" rules={[{ required: true, message: '请输入当前密码' }]}>
            <Input.Password autoComplete="current-password" />
          </Form.Item>
          <Form.Item name="new_password" label="新密码" rules={[{ required: true, min: 12, message: '请输入至少 12 位的新密码' }, { pattern: /^(?=.*[A-Za-z])(?=.*\d).+$/, message: '新密码必须同时包含字母和数字' }]}>
            <Input.Password autoComplete="new-password" />
          </Form.Item>
          <Form.Item name="new_password_confirmation" label="确认新密码" dependencies={['new_password']} rules={[{ required: true, message: '请再次输入新密码' }, ({ getFieldValue }) => ({ validator: (_, value) => !value || getFieldValue('new_password') === value ? Promise.resolve() : Promise.reject(new Error('两次输入的新密码不一致')) })]}>
            <Input.Password autoComplete="new-password" />
          </Form.Item>
          {admin?.requires_mfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true, message: '请输入动态验证码或恢复码' }]}>
            <Input autoComplete="one-time-code" inputMode="numeric" />
          </Form.Item>}
        </Form>
      </Modal>
    </Layout>
  )
}

function AppContent() {
  const { admin, loading } = useAdminAuth()
  if (loading) return <div className="app-loading"><Skeleton active paragraph={{ rows: 4 }} /></div>

  return admin ? <AdminShell /> : <LoginPage />
}

function App() {
  return (
    <ConfigProvider locale={zhCN} theme={{ token: { colorPrimary: '#1769ff', colorInfo: '#1769ff', colorSuccess: '#00a86b', colorWarning: '#f08a24', colorError: '#e5484d', colorText: '#101828', colorTextSecondary: '#667085', colorBgLayout: '#fffaf0', colorBgContainer: '#ffffff', colorBorder: '#d8dee9', colorBorderSecondary: '#e8e4d8', borderRadius: 11, borderRadiusLG: 18, controlHeight: 40, boxShadowTertiary: '0 10px 30px rgba(16, 24, 40, .06)', fontFamily: 'Inter, "PingFang SC", "Microsoft YaHei", sans-serif' }, components: { Menu: { darkItemBg: '#071a33', darkSubMenuItemBg: '#071a33', darkItemSelectedBg: '#1769ff', darkItemSelectedColor: '#ffffff', darkItemHoverBg: '#12335b' }, Button: { primaryShadow: '0 3px 0 rgba(7,61,159,.24)' }, Table: { headerBg: '#fff8d8', headerColor: '#26324a' }, Tabs: { itemSelectedColor: '#1769ff', inkBarColor: '#ffbf1f' } } }}>
      <AdminAuthProvider><AppContent /></AdminAuthProvider>
    </ConfigProvider>
  )
}

export default App
