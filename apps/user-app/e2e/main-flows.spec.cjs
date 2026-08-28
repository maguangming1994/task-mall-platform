const routes = [
  { path: '/pages/index/index', selector: '.home-v4' },
  { path: '/pages/tasks/index', selector: '.task-topbar' },
  { path: '/pages/products/index', selector: '.page-nav' },
  { path: '/pages/community/index', selector: '.campus-head' },
  { path: '/pages/account/index', selector: '.account-toolbar' },
]

const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds))

async function openPage(path, selector) {
  const page = await program.reLaunch(path)
  await page.waitFor(selector, 20000)
  expect(page.path).toBe(path.slice(1))
  expect(await page.$(selector)).not.toBeNull()
  return page
}

describe('WeChat mini program production smoke flows', () => {
  const runtimeExceptions = []

  beforeAll(() => {
    program.on('exception', error => runtimeExceptions.push(error))
  })

  test.each(routes)('$path loads in WeChat DevTools', async ({ path, selector }) => {
    await openPage(path, selector)
  })

  test('all five home tabs reach the expected page', async () => {
    const expectedPaths = routes.map(item => item.path.slice(1))

    for (let index = 0; index < expectedPaths.length; index += 1) {
      const page = await openPage('/pages/index/index', '.home-v4')
      const items = await page.$$('.tabbar-item')
      expect(items).toHaveLength(5)
      await items[index].tap()
      await delay(1800)
      expect((await program.currentPage()).path).toBe(expectedPaths[index])
    }
  })

  test('an unbound account enters the login binding page', async () => {
    const account = await openPage('/pages/account/index', '.account-toolbar')
    expect(await account.$('.guest-account')).not.toBeNull()

    const loginButton = await account.$('.guest-login-button')
    expect(loginButton).not.toBeNull()
    await loginButton.tap()
    await delay(2000)

    const login = await program.currentPage()
    expect(login.path).toBe('pages/login/index')
    await login.waitFor('.login-topbar', 20000)
    expect(await login.$('.agreement-check')).not.toBeNull()
  })

  afterAll(() => {
    expect(runtimeExceptions).toEqual([])
  })
})
