import { isModuleCode, MODULE_REGISTRY, type ModuleCode } from '@/platform/modules'
import { useModulesStore } from '@/stores/modules'

/**
 * 统一处理后端 403 MODULE_DISABLED：
 * 1. 中性 toast（不出现 403/参数错误等技术文案）；
 * 2. 强制刷新校区模块开关——受控页与入口基于响应式 store 自动切换为占位/隐藏。
 */
export function handleModuleDisabled(code?: string | null) {
  const moduleCode: ModuleCode | null = isModuleCode(code) ? code : null
  const name = moduleCode ? MODULE_REGISTRY[moduleCode].name : '该功能'

  uni.showToast({ title: `${name}正在开发中，敬请期待。`, icon: 'none' })
  void useModulesStore().load(true)
}
