<script setup lang="ts">
import { MODULE_REGISTRY, type ModuleCode } from '@/platform/modules'

const props = defineProps<{ code: ModuleCode }>()
const def = MODULE_REGISTRY[props.code]

function goHome() {
  uni.reLaunch({ url: '/pages/index/index' })
}
</script>

<template>
  <view class="module-placeholder">
    <view class="mp-icon-wrap">
      <text class="mp-icon">{{ def.icon }}</text>
    </view>
    <text class="mp-title">功能升级中</text>
    <text class="mp-desc">{{ def.name }}正在升级优化，稍作等待，精彩马上回来。</text>
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
  font-size: 36px;
  color: var(--tm-brand-700);
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
