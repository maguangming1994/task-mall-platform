<template>
  <view class="emoji-picker">
    <view class="emoji-picker-head">
      <text>常用表情</text>
      <text>点击即可插入</text>
    </view>
    <scroll-view class="emoji-scroll" scroll-y :show-scrollbar="false">
      <view class="emoji-grid">
        <button
          v-for="emoji in emojis"
          :key="emoji"
          class="emoji-button"
          :aria-label="`插入表情 ${emoji}`"
          @click="emit('select', emoji)"
        >{{ emoji }}</button>
      </view>
    </scroll-view>
  </view>
</template>

<script setup lang="ts">
const emit = defineEmits<{ (event: 'select', emoji: string): void }>()

const emojis = [
  '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🙂',
  '🙃', '😉', '😊', '😍', '🥰', '😘', '😋', '😎',
  '🤩', '🥳', '😏', '😔', '😢', '😭', '😤', '😡',
  '🤔', '🤗', '🤭', '😴', '🤤', '😇', '🤠', '😌',
  '👍', '👎', '👏', '🙌', '👌', '✌', '🤝', '🙏',
  '💪', '👀', '💯', '🔥', '✨', '🎉', '🎁', '❤️',
  '💛', '💚', '💙', '💜', '💡', '📚', '☕', '🍉',
  '🌈', '⭐', '🌻', '🏆', '🚀', '✅', '❗', '❓',
]
</script>

<style scoped>
.emoji-picker{overflow:hidden;border-top:1rpx solid #dce9ef;background:#f7fbfd}.emoji-picker-head{display:flex;align-items:center;justify-content:space-between;padding:16rpx 18rpx 10rpx}.emoji-picker-head text:first-child{color:#173c58;font-size:13px;font-weight:850}.emoji-picker-head text:last-child{color:#78909f;font-size:11px}.emoji-scroll{height:276rpx}.emoji-grid{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:8rpx;padding:8rpx 14rpx 20rpx}.emoji-button{display:flex;align-items:center;justify-content:center;width:100%;height:64rpx;margin:0;padding:0;border:0;border-radius:14rpx;background:#fff;font-size:24px;line-height:64rpx}.emoji-button::after{border:0}.emoji-button:active{background:#e5f5f6;transform:scale(.94)}
</style>
