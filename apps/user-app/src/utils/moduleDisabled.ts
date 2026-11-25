import { isModuleCode, type ModuleCode } from '@/platform/modules'
import { useModulesStore } from '@/stores/modules'

/**
 * 统一处理后端 403 MODULE_DISABLED。
 *
 * 后端返回该错误本身就是权威判定：该模块当前已关闭（审核期最常见）。
 * 因此不做任何「开发中」提示——只同步开关让入口/受控页切为占位，
 * 避免审核过程中出现未完成功能的文案被判不过。
 */
export function handleModuleDisabled(code?: string | null) {
  const moduleCode: ModuleCode | null = isModuleCode(code) ? code : null
  const store = useModulesStore()

  if (moduleCode) {
    // 乐观置为关闭：立刻隐藏入口，随后以服务端开关为准
    store.flags[moduleCode] = false
    void store.load(true)
    return
  }

  // 未携带模块标识（异常兜底）：审核期同样静默，仅刷新开关
  if (store.reviewing) {
    void store.load(true)
    return
  }

  uni.showToast({ title: '该功能正在开发中，敬请期待。', icon: 'none' })
  void store.load(true)
}
