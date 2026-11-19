<template>
  <view class="major-selector">
    <view class="major-input" :class="{ focused: focused }">
      <input
        :value="inputValue"
        :maxlength="maxlength"
        :placeholder="placeholder"
        placeholder-class="major-placeholder"
        confirm-type="done"
        @input="onInput"
        @focus="onFocus"
        @blur="onBlur"
        @confirm="closeDropdown"
      />
      <text v-if="inputValue" class="major-clear" @click="clearValue">清除</text>
    </view>

    <view v-if="open" class="major-dropdown" @touchmove.stop>
      <view v-if="loading" class="major-state">正在搜索…</view>
      <view v-else-if="suggestions.length" class="major-list">
        <view
          v-for="item in suggestions"
          :key="item.code"
          class="major-item"
          @click="pick(item)"
        >
          <text class="major-item-name">{{ item.name }}</text>
          <text class="major-item-cat">{{ item.category }}</text>
        </view>
      </view>
      <view v-else class="major-state major-state-empty">
        {{ inputValue ? '没有匹配的专业，可直接输入自定义专业' : '输入专业名称可联想选择，也支持自定义填写' }}
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { fetchMajors, type Major } from '@/api/majors'

const props = withDefaults(defineProps<{
  modelValue: string
  placeholder?: string
  maxlength?: number
}>(), {
  placeholder: '如 计算机科学与技术',
  maxlength: 30,
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const inputValue = ref(props.modelValue)
const focused = ref(false)
const open = ref(false)
const loading = ref(false)
const suggestions = ref<Major[]>([])
const cachedSuggestions = ref<Major[] | null>(null)

let searchTimer: ReturnType<typeof setTimeout> | null = null
let blurTimer: ReturnType<typeof setTimeout> | null = null

watch(() => props.modelValue, (value) => {
  if (value !== inputValue.value) inputValue.value = value
})

function emitValue(value: string) {
  inputValue.value = value
  emit('update:modelValue', value)
}

function onInput(event: Event) {
  const value = (event as unknown as { detail: { value: string } }).detail.value
  emitValue(value)
  scheduleSearch(value)
}

function scheduleSearch(value: string) {
  if (searchTimer) clearTimeout(searchTimer)
  const keyword = value.trim()
  if (!keyword) {
    loading.value = false
    suggestions.value = cachedSuggestions.value ?? []
    open.value = true
    return
  }
  loading.value = true
  open.value = true
  searchTimer = setTimeout(() => {
    void fetchMajors({ keyword, limit: 20 })
      .then((result) => {
        suggestions.value = Array.isArray(result.items) ? result.items : []
      })
      .catch(() => {
        suggestions.value = []
      })
      .finally(() => {
        loading.value = false
      })
  }, 300)
}

async function loadSuggestions() {
  if (cachedSuggestions.value !== null) {
    suggestions.value = cachedSuggestions.value
    return
  }
  loading.value = true
  try {
    const result = await fetchMajors({ limit: 24 })
    const items = Array.isArray(result.items) ? result.items : []
    cachedSuggestions.value = items
    suggestions.value = items
  } catch {
    suggestions.value = []
  } finally {
    loading.value = false
  }
}

function onFocus() {
  focused.value = true
  if (blurTimer) {
    clearTimeout(blurTimer)
    blurTimer = null
  }
  open.value = true
  if (!inputValue.value.trim()) {
    void loadSuggestions()
  }
}

function onBlur() {
  focused.value = false
  // 延迟关闭，确保下拉项的点击先于收起触发
  blurTimer = setTimeout(() => {
    open.value = false
  }, 180)
}

function pick(item: Major) {
  emitValue(item.name)
  open.value = false
}

function clearValue() {
  emitValue('')
  suggestions.value = cachedSuggestions.value ?? []
  open.value = true
}

function closeDropdown() {
  open.value = false
}
</script>

<style scoped>
.major-selector { position: relative; width: 100%; }
.major-input { display: flex; align-items: center; gap: 12rpx; height: 88rpx; padding: 0 24rpx; box-sizing: border-box; border: 1rpx solid var(--tm-line, #EDEFEF); border-radius: 22rpx; background: #F8FAF8; }
.major-input.focused { border-color: var(--tm-growth-600, #0BBF63); background: #fff; }
.major-input input { min-width: 0; flex: 1; height: 86rpx; color: #191B1F; font-size: 27rpx; }
.major-clear { flex: none; color: #08A152; font-size: 23rpx; font-weight: 700; }
.major-placeholder { color: #AEB3BB; }
.major-dropdown { position: absolute; z-index: 50; top: calc(100% + 8rpx); left: 0; right: 0; max-height: 440rpx; overflow-y: auto; box-sizing: border-box; padding: 10rpx; border: 1rpx solid #E3E8E4; border-radius: 18rpx; background: #fff; box-shadow: 0 12rpx 32rpx rgba(23, 26, 23, .12); }
.major-list { display: flex; flex-direction: column; }
.major-item { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; min-height: 76rpx; padding: 14rpx 16rpx; border-radius: 14rpx; }
.major-item:active { background: #F4FDF8; }
.major-item-name { min-width: 0; flex: 1; overflow: hidden; color: #191B1F; font-size: 26rpx; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.major-item-cat { flex: none; padding: 4rpx 12rpx; border-radius: 999rpx; background: #E7F9EF; color: #08A152; font-size: 20rpx; font-weight: 700; }
.major-state { padding: 24rpx 16rpx; color: #767C85; font-size: 23rpx; text-align: center; }
.major-state-empty { line-height: 1.5; }
</style>
