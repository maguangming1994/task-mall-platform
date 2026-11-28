<template>
  <view class="webview-page">
    <web-view v-if="targetUrl" :src="targetUrl" />
    <view v-else class="webview-empty">
      <text class="webview-empty-text">链接无效</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'

const targetUrl = ref('')

onLoad((query) => {
  const raw = typeof query?.url === 'string' ? query.url : ''
  if (raw) {
    try {
      targetUrl.value = decodeURIComponent(raw)
    } catch {
      targetUrl.value = raw
    }
  }
})
</script>

<style scoped>
.webview-page { width: 100%; height: 100vh; }
.webview-empty { display: flex; align-items: center; justify-content: center; height: 60vh; }
.webview-empty-text { font-size: 28rpx; color: #9aa5b8; }
</style>
