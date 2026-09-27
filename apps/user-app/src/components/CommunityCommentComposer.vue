<template>
  <view v-if="visible" class="comment-composer-mask" @click.self="emit('close')">
    <view class="comment-composer-sheet" @click.stop>
      <view class="comment-composer-head">
        <view>
          <text class="comment-composer-kicker">COMMUNITY REPLY</text>
          <text class="comment-composer-title">{{ replyTo ? `回复 ${replyTo}` : '发表评论' }}</text>
        </view>
        <button class="comment-composer-close" aria-label="关闭评论输入" @click="emit('close')">×</button>
      </view>

      <textarea
        class="comment-textarea"
        :value="modelValue"
        :maxlength="maxLength"
        :cursor="cursor"
        :focus="inputFocused"
        :placeholder="replyTo ? `友善回复 ${replyTo}` : '保持友善，分享真实有用的信息'"
        confirm-type="send"
        fixed
        :disabled="submitting"
        @input="handleInput"
        @blur="trackCursor"
        @confirm="submit"
      />

      <view class="comment-composer-tools">
        <button :class="['emoji-toggle', { active: emojiVisible }]" aria-label="选择表情" @click="toggleEmoji">☺</button>
        <text class="comment-counter">至少 {{ minLength }} 个字符 · {{ unicodeLength(modelValue) }}/{{ maxLength }}</text>
        <button class="comment-submit" :disabled="submitting || unicodeLength(modelValue.trim()) < minLength || unicodeLength(modelValue.trim()) > maxLength" :loading="submitting" @click="submit">发布</button>
      </view>

      <EmojiPicker v-if="emojiVisible" @select="insertEmoji" />
      <text v-if="error" class="comment-error">{{ error }}</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import EmojiPicker from '@/components/EmojiPicker.vue'
import { insertTextAtCursor, unicodeLength } from '@/utils/textInput'

interface TextareaEvent {
  detail?: { value?: string; cursor?: number }
}

const props = withDefaults(defineProps<{
  modelValue: string
  visible: boolean
  replyTo?: string
  submitting?: boolean
  error?: string
  minLength?: number
  maxLength?: number
}>(), {
  replyTo: '',
  submitting: false,
  error: '',
  minLength: 2,
  maxLength: 500,
})

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void
  (event: 'close'): void
  (event: 'submit', content: string): void
}>()

const cursor = ref(0)
const inputFocused = ref(false)
const emojiVisible = ref(false)

watch(() => props.visible, (visible) => {
  if (!visible) {
    inputFocused.value = false
    emojiVisible.value = false
    return
  }
  cursor.value = props.modelValue.length
  nextTick(() => { inputFocused.value = true })
})

function textareaDetail(event: Event) {
  return (event as unknown as TextareaEvent).detail
}

function handleInput(event: Event) {
  emit('update:modelValue', textareaDetail(event)?.value ?? '')
  trackCursor(event)
}

function trackCursor(event: Event) {
  const nextCursor = Number(textareaDetail(event)?.cursor)
  if (Number.isFinite(nextCursor) && nextCursor >= 0) cursor.value = nextCursor
}

function toggleEmoji() {
  emojiVisible.value = !emojiVisible.value
  if (emojiVisible.value) {
    inputFocused.value = false
    uni.hideKeyboard()
  } else {
    nextTick(() => { inputFocused.value = true })
  }
}

function insertEmoji(emoji: string) {
  const result = insertTextAtCursor(props.modelValue, emoji, cursor.value, props.maxLength)
  if (!result.changed) {
    uni.showToast({ title: `最多输入 ${props.maxLength} 个字符`, icon: 'none' })
    return
  }
  cursor.value = result.cursor
  emit('update:modelValue', result.value)
}

function submit() {
  const content = props.modelValue.trim()
  const contentLength = unicodeLength(content)
  if (props.submitting) return
  if (contentLength < props.minLength) {
    uni.showToast({ title: `评论至少输入 ${props.minLength} 个字符`, icon: 'none' })
    return
  }
  if (contentLength > props.maxLength) {
    uni.showToast({ title: `评论最多输入 ${props.maxLength} 个字符`, icon: 'none' })
    return
  }
  emit('submit', content)
}
</script>

<style scoped>
.comment-composer-mask{position:fixed;z-index:1500;inset:0;display:flex;align-items:flex-end;justify-content:center;background:rgba(10,34,50,.48);backdrop-filter:blur(5px)}.comment-composer-sheet{width:100%;max-width:750rpx;padding:24rpx 24rpx calc(22rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:30rpx 30rpx 0 0;background:#fff;box-shadow:0 -18rpx 54rpx rgba(16,56,76,.2)}.comment-composer-head{display:flex;align-items:center;justify-content:space-between;gap:18rpx}.comment-composer-kicker,.comment-composer-title{display:block}.comment-composer-kicker{color:#1482a4;font-size:10px;font-weight:900;letter-spacing:2rpx}.comment-composer-title{margin-top:5rpx;color:#173c58;font-size:18px;font-weight:900}.comment-composer-close{display:flex;align-items:center;justify-content:center;width:58rpx;height:58rpx;flex:none;margin:0;padding:0;border:1rpx solid #d5e6ec;border-radius:50%;background:#f5fafc;color:#5c7483;font-size:21px;line-height:56rpx}.comment-composer-close::after{border:0}.comment-textarea{width:100%;height:190rpx;margin-top:20rpx;padding:19rpx;box-sizing:border-box;border:1rpx solid #c9dee7;border-radius:16rpx;background:#f9fcfd;color:#173c58;font-size:15px;line-height:1.6}.comment-composer-tools{display:flex;align-items:center;gap:14rpx;margin-top:14rpx}.emoji-toggle{display:flex;align-items:center;justify-content:center;width:66rpx;height:62rpx;margin:0;padding:0;border:1rpx solid #cbe1e8;border-radius:16rpx;background:#fff;color:#147b99;font-size:24px;line-height:60rpx}.emoji-toggle.active{border-color:#62c9ca;background:#e8f8f6}.emoji-toggle::after{border:0}.comment-counter{flex:1;color:#8295a1;font-size:12px}.comment-submit{width:150rpx;height:64rpx;margin:0;border:0;border-radius:16rpx;background:linear-gradient(135deg,#08a967,#0bbca5);color:#fff;font-size:14px;font-weight:850;line-height:64rpx;box-shadow:0 8rpx 18rpx rgba(8,169,103,.17)}.comment-submit::after{border:0}.comment-submit[disabled]{opacity:.48;box-shadow:none}.comment-composer-sheet :deep(.emoji-picker){margin:16rpx -24rpx -2rpx}.comment-error{display:block;margin-top:14rpx;padding:14rpx 16rpx;border:1rpx solid #f0c8c1;border-radius:14rpx;background:#fff4f1;color:#b9382d;font-size:13px;line-height:1.5}
</style>
