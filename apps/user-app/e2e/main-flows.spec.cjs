const routes = [
  { path: '/pages/index/index', selector: '.home-v4' },
  { path: '/pages/tasks/index', selector: '.task-topbar' },
  { path: '/pages/products/index', selector: '.page-nav' },
  { path: '/pages/community/index', selector: '.campus-head' },
  { path: '/pages/account/index', selector: '.account-toolbar' },
]

const protectedGuestRoutes = [
  '/pages/identity-verification/index',
  '/pages/assets/index',
  '/pages/wallet-recharge/index',
  '/pages/withdraw/index',
  '/pages/referrals/index',
  '/pages/referral-poster/index',
  '/pages/check-in/index',
  '/pages/cart/index',
  '/pages/checkout/index',
  '/pages/orders/index',
  '/pages/after-sales/index',
  '/pages/community-compose/index',
  '/pages/community-activity-compose/index',
  '/pages/notifications/index',
  '/pages/addresses/index',
  '/pages/privacy/index',
]

const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds))

async function waitForPath(expectedPath, timeout = 10000) {
  const deadline = Date.now() + timeout

  while (Date.now() < deadline) {
    const page = await program.currentPage()
    if (page?.path === expectedPath) return page
    await delay(250)
  }

  const page = await program.currentPage()
  throw new Error(`等待页面 ${expectedPath} 超时，当前页面为 ${page?.path || '未知'}`)
}

async function openPage(path, selector) {
  const page = await program.reLaunch(path)
  await page.waitFor(selector, 20000)
  expect(page.path).toBe(path.slice(1))
  expect(await page.$(selector)).not.toBeNull()
  return page
}

describe('WeChat mini program production smoke flows', () => {
  const runtimeExceptions = []
  let isGuest = true

  beforeAll(async () => {
    program.on('exception', error => runtimeExceptions.push(error))
    const account = await openPage('/pages/account/index', '.account-toolbar')
    isGuest = (await account.$('.guest-account')) !== null
  })

  test.each(routes)('$path loads in WeChat DevTools', async ({ path, selector }) => {
    await openPage(path, selector)
  })

  test('campus selector row stays between the safe area and home content', async () => {
    const page = await openPage('/pages/index/index', '.home-v4')
    const safeArea = await page.$('.home-v4-safe')
    const selector = await page.$('.home-campus-context')
    const homeContent = (await page.$('.home-v10-auth-shell')) || (await page.$('.home-v4-header'))

    expect(safeArea).not.toBeNull()
    expect(selector).not.toBeNull()
    expect(homeContent).not.toBeNull()

    const safeAreaOffset = await safeArea.offset()
    const safeAreaSize = await safeArea.size()
    const selectorOffset = await selector.offset()
    const selectorSize = await selector.size()
    const contentOffset = await homeContent.offset()

    expect(selectorSize.height).toBeGreaterThanOrEqual(48)
    expect(selectorOffset.top).toBeGreaterThanOrEqual(safeAreaOffset.top + safeAreaSize.height - 1)
    expect(contentOffset.top).toBeGreaterThanOrEqual(selectorOffset.top + selectorSize.height - 1)
  })

  test('all five home tabs reach the expected page', async () => {
    const expectedPaths = routes.map(item => item.path.slice(1))

    for (let index = 0; index < expectedPaths.length; index += 1) {
      const page = await openPage('/pages/index/index', '.home-v4')
      await page.waitFor('#home-tab-bar', 20000)
      const tabBar = await page.$('#home-tab-bar')
      expect(tabBar).not.toBeNull()
      const items = await tabBar.$$('.tabbar-item')
      expect(items).toHaveLength(5)
      await items[index].tap()
      await waitForPath(expectedPaths[index], 15000)
    }
  })

  test('account entry matches the current authentication state', async () => {
    const account = await openPage('/pages/account/index', '.account-toolbar')
    const guestAccount = await account.$('.guest-account')

    if (!isGuest) {
      expect(guestAccount).toBeNull()

      const avatarControl = await account.$('.profile-avatar-control')
      const nicknameControl = await account.$('.profile-nickname')
      expect(avatarControl).not.toBeNull()
      expect(nicknameControl).not.toBeNull()
      expect(await avatarControl.attribute('open-type')).toBe('chooseAvatar')
      expect(await nicknameControl.attribute('type')).toBe('nickname')
      return
    }

    expect(guestAccount).not.toBeNull()

    const loginButton = await account.$('.guest-login-button')
    expect(loginButton).not.toBeNull()
    await loginButton.tap()
    const login = await waitForPath('pages/login/index', 15000)
    await login.waitFor('.login-topbar', 20000)
    expect(await login.$('.agreement-check')).not.toBeNull()
  })

  test.each(protectedGuestRoutes)('%s enforces the current authentication state', async route => {
    await program.reLaunch(route)

    if (!isGuest) {
      await waitForPath(route.slice(1))
      return
    }

    const login = await waitForPath('pages/login/index')
    await login.waitFor('.login-topbar', 20000)
    expect(await login.$('.agreement-check')).not.toBeNull()
  })

  afterAll(() => {
    expect(runtimeExceptions).toEqual([])
  })
})
