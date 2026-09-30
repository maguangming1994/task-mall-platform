<script setup lang="ts">
import { computed } from 'vue'
import { MODULE_REGISTRY, type ModuleCode } from '@/platform/modules'
import { useModulesStore } from '@/stores/modules'

const props = defineProps<{ code: ModuleCode }>()
const def = MODULE_REGISTRY[props.code]
const modules = useModulesStore()

const copy = computed(() => modules.placeholder(props.code))

function goHome() {
  uni.reLaunch({ url: '/pages/index/index' })
}
</script>

<template>
  <view class="module-placeholder">
    <view class="mp-icon-wrap">
      <image class="mp-icon" :src="def.icon" mode="aspectFit" />
    </view>
    <text class="mp-title">{{ copy.title }}</text>
    <text class="mp-desc">{{ copy.desc }}</text>
    <button class="tm-secondary-button mp-home" @click="goHome">返回首页</button>
  </view>
</template>

<style scoped>
.module-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 62vh;
  padding: 40rpx 48rpx calc(160rpx + env(safe-area-inset-bottom));
  box-sizing: border-box;
  text-align: center;
}

.mp-icon-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 144rpx;
  height: 144rpx;
  border-radius: 36rpx;
  background: var(--tm-brand-100);
  box-shadow: var(--tm-shadow-card);
  margin-bottom: 32rpx;
}

.mp-icon {
  width: 72rpx;
  height: 72rpx;
  display: block;
}

.mp-title {
  font-size: 18px;
  font-weight: 900;
  color: var(--tm-ink-900);
  margin-bottom: 12rpx;
}

.mp-desc {
  font-size: 13px;
  color: var(--tm-ink-500);
  line-height: 1.65;
  max-width: 480rpx;
  margin-bottom: 56rpx;
}

.mp-home {
  width: 320rpx;
}
</style>
