import {
  BellOutlined,
  DownOutlined,
  KeyOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
} from '@ant-design/icons'
import {
  Alert,
  Avatar,
  Badge,
  Breadcrumb,
  Button,
  Checkbox,
  ConfigProvider,
  Drawer,
  Dropdown,
  Form,
  Input,
  Layout,
  Menu,
  Modal,
  Result,
  Select,
  Skeleton,
  Tooltip,
  message,
} from 'antd'
import zhCN from 'antd/locale/zh_CN'
import { Suspense, useEffect, useMemo, useState } from 'react'
import { ApiError, changeAdminPassword, getAdminCampusScope, getRememberedAdminAccount, setAdminCampusScope, type AdminMfaChallenge } from './api/admin'
import { useAdminAuth } from './features/auth/AdminAuthContext'
import { AdminAuthProvider } from './features/auth/AdminAuthProvider'
import { findRoute, navigate, useHashPath, visibleGroups } from './routes'
import './App.css'
import './styles/xq.css'

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
    <main className="xq-login">
      <aside className="xq-login-aside">
        <div className="xq-login-eyebrow">校圈 · 校园任务商城</div>
        <h1 className="xq-login-title">运营管理平台</h1>
        <p className="xq-login-desc">
          面向校园微信小程序与多端 H5 的统一运营后台。任务、组团、社区内容和关键资产操作均进入审计记录，保障平台安全合规运营。
        </p>
        <div className="xq-login-chips">
          <span>最小权限</span><span>全程审计</span><span>双因素认证</span>
        </div>
      </aside>
      <section className="xq-login-main">
        <div className="xq-login-form">
          <h2>{challenge ? '完成二次验证' : '登录管理后台'}</h2>
          <p className="sub">{challenge ? '输入验证器动态码或一次性恢复码' : '请输入管理员账号和密码'}</p>
          {error && <Alert showIcon type="error" title={error} className="xq-login-alert" />}
          <Form name="admin-login" autoComplete="on" layout="vertical" requiredMark={false} initialValues={{ account: rememberedAccount, remember: Boolean(rememberedAccount) }} onFinish={submit} size="large">
            {!challenge && <Form.Item label="管理员账号" name="account" rules={[{ required: true, message: '请输入管理员账号' }]}>
              <Input autoComplete="username" placeholder="请输入账号" />
            </Form.Item>}
            {!challenge && <Form.Item label="密码" name="password" rules={[{ required: true, message: '请输入密码' }]}>
              <Input.Password autoComplete="current-password" placeholder="请输入密码" />
            </Form.Item>}
            {challenge && <Form.Item label="验证码或恢复码" name="code" rules={[{ required: true, message: '请输入验证码或恢复码' }]}>
              <Input autoComplete="one-time-code" placeholder="6 位动态码" />
            </Form.Item>}
            <Button block type="primary" htmlType="submit" loading={submitting}>{challenge ? '验证并登录' : '登 录'}</Button>
            {!challenge && (
              <div className="xq-login-foot">
                <Form.Item name="remember" valuePropName="checked" noStyle>
                  <Checkbox>记住我</Checkbox>
                </Form.Item>
                <a href="#forgot" onClick={(e) => e.preventDefault()}>忘记密码？</a>
              </div>
            )}
            {challenge && <Button block type="link" onClick={() => { setChallenge(null); setError(null) }}>返回账号登录</Button>}
          </Form>
          <p className="xq-login-help">
            首位管理员由运维人员通过 <code>php artisan admin:create</code> 安全创建，不提供通用默认密码。
          </p>
        </div>
      </section>
    </main>
  )
}

const SIDEBAR_COLLAPSED_KEY = 'xq-admin-sidebar-collapsed'

function AdminShell() {
  const { admin, navigation, logout } = useAdminAuth()
  const path = useHashPath()
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1'
    } catch {
      return false
    }
  })
  const [openKeys, setOpenKeys] = useState<string[]>([])
  const [passwordOpen, setPasswordOpen] = useState(false)
  const [passwordSubmitting, setPasswordSubmitting] = useState(false)
  const [campusScope, setCampusScopeState] = useState<string | null>(() => getAdminCampusScope())
  const [passwordForm] = Form.useForm()
  const [messageApi, contextHolder] = message.useMessage()
  const [isMobile, setIsMobile] = useState(() => typeof window !== 'undefined' ? window.matchMedia('(max-width: 768px)').matches : false)
  const [mobileNavOpen, setMobileNavOpen] = useState(false)

  const groups = useMemo(() => visibleGroups(navigation), [navigation])
  const route = findRoute(path, groups) ?? findRoute('/workbench', groups) ?? (groups[0] ? { group: groups[0], page: groups[0].pages[0] } : null)
  const usesCampusScope = Boolean(admin?.roles.includes('campus_agent') && !admin.roles.includes('super_admin'))

  useEffect(() => {
    if (route) setOpenKeys([route.group.key])
  }, [route?.group.key]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const mql = window.matchMedia('(max-width: 768px)')
    const onChange = (event: MediaQueryListEvent) => {
      setIsMobile(event.matches)
      if (!event.matches) setMobileNavOpen(false)
    }
    mql.addEventListener('change', onChange)
    return () => mql.removeEventListener('change', onChange)
  }, [])

  useEffect(() => {
    if (route && path !== route.page.path) navigate(route.page.path)
  }, [route, path])

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

  const goTo = (key: string) => {
    navigate(key)
    if (isMobile) setMobileNavOpen(false)
  }

  const menuItems = groups.map((g) => g.pages.length === 1
    ? { key: g.pages[0].path, icon: g.icon, label: g.label }
    : { key: g.key, icon: g.icon, label: g.label, children: g.pages.map((p) => ({ key: p.path, label: p.title })) })

  const toggleCollapsed = () => {
    setCollapsed((value) => {
      const next = !value
      try {
        window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, next ? '1' : '0')
      } catch {
        // 隐私模式下 localStorage 不可用时仅本次生效
      }
      return next
    })
  }

  const renderMenu = (inDrawer = false) => (
    <Menu
      theme="dark"
      mode="inline"
      inlineCollapsed={inDrawer ? false : collapsed}
      selectedKeys={route ? [route.page.path] : []}
      openKeys={inDrawer ? openKeys : (collapsed ? undefined : openKeys)}
      onOpenChange={(keys) => setOpenKeys(keys as string[])}
      items={menuItems}
      onClick={({ key }) => goTo(String(key))}
    />
  )

  const PageComponent = route?.page.element

  return (
    <Layout className="xq-layout">
      {contextHolder}
      {!isMobile && (
        <Layout.Sider width={216} collapsedWidth={72} collapsed={collapsed} className="xq-sider">
          <div className="xq-sider-brand" title={collapsed ? '校圈 Admin' : undefined}>
            <span className="logo">校</span>{!collapsed && <span>校圈 Admin</span>}
          </div>
          {renderMenu(false)}
          <div className="xq-sider-foot">{collapsed ? 'v3.2.0' : '校圈 v3.2.0 · build 20261004'}</div>
        </Layout.Sider>
      )}
      <Drawer
        open={mobileNavOpen}
        onClose={() => setMobileNavOpen(false)}
        placement="left"
        width={260}
        closable={false}
        className="xq-mobile-drawer"
      >
        <div className="xq-sider-brand"><span className="logo">校</span><span>校圈 Admin</span></div>
        {renderMenu(true)}
        <div className="xq-mobile-drawer-footer">
          <div className="xq-mobile-drawer-user">
            <Avatar size={32}>{admin?.display_name.slice(0, 1)}</Avatar>
            <span>
              <strong>{admin?.display_name}</strong>
              <small>{admin?.roles.map((role) => roleNames[role] ?? role).join('、')}</small>
            </span>
          </div>
          <div className="xq-mobile-drawer-actions">
            <Button type="text" icon={<KeyOutlined />} onClick={() => { setMobileNavOpen(false); setPasswordOpen(true) }}>修改密码</Button>
            <Button type="text" danger icon={<LogoutOutlined />} onClick={signOut}>退出登录</Button>
          </div>
        </div>
      </Drawer>
      <Layout>
        <Layout.Header className="xq-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
            {isMobile ? (
              <Button type="text" className="xq-mobile-menu-btn" icon={<MenuOutlined />} onClick={() => setMobileNavOpen(true)} />
            ) : (
              <Tooltip title={collapsed ? '展开侧边栏' : '收起侧边栏'} placement="bottomLeft">
                <Button
                  type="text"
                  aria-label={collapsed ? '展开侧边栏' : '收起侧边栏'}
                  icon={collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
                  onClick={toggleCollapsed}
                />
              </Tooltip>
            )}
            <Breadcrumb items={[
              { title: '首页' },
              ...(route?.group.key === 'workbench' || route?.group.key === 'apps'
                ? [{ title: route?.page.title ?? '' }]
                : [{ title: route?.group.label ?? '' }, { title: route?.page.title ?? '' }]),
            ]} />
          </div>
          <div className="xq-header-right">
            {usesCampusScope && admin?.campuses.length ? (
              <Select
                aria-label="切换当前运营学校"
                value={campusScope ?? undefined}
                onChange={(v) => { setAdminCampusScope(v); setCampusScopeState(v) }}
                options={admin.campuses.map((campus) => ({ value: campus.id, label: `${campus.name}${campus.is_primary ? ' · 主负责' : ''}` }))}
                popupMatchSelectWidth={260}
              />
            ) : null}
            <Badge dot color="#f59e0b"><span className="xq-header-bell"><BellOutlined /></span></Badge>
            <Button type="text" className="xq-header-action-desktop" icon={<KeyOutlined />} onClick={() => setPasswordOpen(true)}>修改密码</Button>
            <Button type="text" danger className="xq-header-action-desktop" icon={<LogoutOutlined />} onClick={signOut}>退出登录</Button>
            <Dropdown
              trigger={['click']}
              placement="bottomRight"
              menu={{
                items: [
                  { key: 'password', icon: <KeyOutlined />, label: '修改密码' },
                  { type: 'divider' },
                  { key: 'logout', icon: <LogoutOutlined />, danger: true, label: '退出登录' },
                ],
                onClick: ({ key }) => {
                  if (key === 'password') setPasswordOpen(true)
                  if (key === 'logout') signOut()
                },
              }}
            >
              <div className="xq-header-user">
                <Avatar size={32}>{admin?.display_name.slice(0, 1)}</Avatar>
                <span><strong style={{ fontSize: 13 }}>{admin?.display_name}</strong><small>{admin?.roles.map((role) => roleNames[role] ?? role).join('、')}</small></span>
                <DownOutlined style={{ fontSize: 10, color: '#9ca3af' }} />
              </div>
            </Dropdown>
          </div>
        </Layout.Header>
        <Layout.Content className="xq-content">
          {PageComponent && (
            <div key={`${route?.page.path}:${campusScope ?? 'global'}`}>
              <Suspense fallback={<Skeleton active paragraph={{ rows: 6 }} />}>
                <PageComponent />
              </Suspense>
            </div>
          )}
          {!PageComponent && <Result status="404" title="页面不存在或未授权" />}
        </Layout.Content>
      </Layout>
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
    <ConfigProvider locale={zhCN} theme={{ token: { colorPrimary: '#22c55e', colorInfo: '#22c55e', colorSuccess: '#16a34a', colorWarning: '#d97706', colorError: '#dc2626', colorText: '#1f2937', colorTextSecondary: '#6b7280', colorBgLayout: '#f3f6f4', colorBgContainer: '#ffffff', colorBorder: '#d8ded9', colorBorderSecondary: '#eef1ef', borderRadius: 8, borderRadiusLG: 12, controlHeight: 36, fontFamily: 'Inter, "PingFang SC", "Microsoft YaHei", sans-serif' }, components: { Menu: { darkItemBg: 'transparent', darkSubMenuItemBg: 'transparent', darkItemSelectedBg: '#15803d', darkItemSelectedColor: '#ffffff', darkItemHoverBg: '#10492f', darkItemColor: '#9fc3b2' }, Table: { headerBg: 'transparent', headerColor: '#6b7280' } } }}>
      <AdminAuthProvider><AppContent /></AdminAuthProvider>
    </ConfigProvider>
  )
}

export default App
