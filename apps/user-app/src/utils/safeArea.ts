export type TopSafeAreaStyle = Record<string, string>

type MiniProgramUni = typeof uni & {
  getMenuButtonBoundingClientRect?: () => { bottom?: number }
}

function positiveNumber(value: unknown) {
  const number = Number(value)
  return Number.isFinite(number) && number > 0 ? number : 0
}

export function createTopSafeAreaStyle(): TopSafeAreaStyle {
  let statusBarHeight = 0
  let menuButtonBottom = 0

  try {
    statusBarHeight = positiveNumber(uni.getSystemInfoSync().statusBarHeight)
  } catch {
    statusBarHeight = 0
  }

  // #ifdef MP-WEIXIN
  try {
    const menuButton = (uni as MiniProgramUni).getMenuButtonBoundingClientRect?.()
    menuButtonBottom = positiveNumber(menuButton?.bottom)
  } catch {
    menuButtonBottom = 0
  }
  // #endif

  const customHeaderTop = Math.max(8, statusBarHeight + 8, menuButtonBottom + 8)

  return {
    '--tm-status-bar-height': `${statusBarHeight}px`,
    '--tm-menu-button-bottom': `${menuButtonBottom}px`,
    '--tm-custom-header-top': `${customHeaderTop}px`,
  }
}
