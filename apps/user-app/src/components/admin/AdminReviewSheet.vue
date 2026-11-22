<template>
  <view v-if="visible" class="mask" @click="close">
    <view class="sheet" @click.stop>
      <text class="sheet-title">{{ title }}</text>

      <view class="decisions">
        <text
          v-for="option in decisions"
          :key="option.key"
          :class="['decision', option.tone || 'blue', { on: decision === option.key }]"
          @click="decision = option.key"
        >{{ option.label }}</text>
      </view>

      <textarea
        v-model="note"
        class="note"
        :placeholder="notePlaceholder"
        placeholder-style="color:#AEB3BB"
        :maxlength="500"
      />
      <text class="count">{{ note.length }}/500</text>

      <view class="actions">
        <button class="cancel" @click="close">取消</button>
        <button class="confirm" :disabled="submitting || !canConfirm" @click="confirm">
          {{ submitting ? '提交中…' : '确认提交' }}
        </button>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

interface DecisionOption {
  key: string
  label: string
  tone?: 'green' | 'red' | 'orange' | 'blue' | 'purple'
}

const props = withDefaults(defineProps<{
  visible: boolean
  title: string
  decisions: DecisionOption[]
  requireNote?: boolean
  submitting?: boolean
  notePlaceholder?: string
}>(), {
  requireNote: false,
  submitting: false,
  notePlaceholder: '请填写处理说明（可选）',
})

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void
  (event: 'confirm', payload: { decision: string; note: string }): void
}>()

const decision = ref(props.decisions[0]?.key || '')
const note = ref('')

watch(() => props.visible, (visible) => {
  if (visible) {
    decision.value = props.decisions[0]?.key || ''
    note.value = ''
  }
})

const canConfirm = computed(() => !props.requireNote || note.value.trim().length > 0)

function close() {
  emit('update:visible', false)
}

function confirm() {
  if (!canConfirm.value) {
    uni.showToast({ title: '请填写处理说明', icon: 'none' })
    return
  }
  emit('confirm', { decision: decision.value, note: note.value.trim() })
}
</script>

<style scoped>
.mask{position:fixed;inset:0;z-index:90;display:flex;align-items:flex-end;background:rgba(17,20,18,.45)}
.sheet{width:100%;padding:36rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));border-radius:36rpx 36rpx 0 0;background:#fff;box-sizing:border-box}
.sheet-title{display:block;margin-bottom:28rpx;color:#191B1F;font-size:32rpx;font-weight:800;text-align:center}
.decisions{display:flex;gap:16rpx;margin-bottom:24rpx}
.decision{flex:1;padding:22rpx 0;border-radius:18rpx;background:#F4F6F4;color:#767C85;font-size:26rpx;font-weight:600;text-align:center;border:2rpx solid transparent}
.decision.green.on{background:#E7F9EF;border-color:#08A152;color:#087A43}
.decision.red.on{background:#FFECEA;border-color:#FF3B30;color:#C0261D}
.decision.orange.on{background:#FFF2E5;border-color:#F2620A;color:#B74A05}
.decision.blue.on{background:#EAF2FF;border-color:#3B82F6;color:#1D4ED8}
.decision.purple.on{background:#F1ECFF;border-color:#6D4AC4;color:#54379E}
.note{width:100%;min-height:160rpx;padding:20rpx 24rpx;box-sizing:border-box;border-radius:18rpx;background:#F7F9F8;color:#191B1F;font-size:26rpx;line-height:1.6}
.count{display:block;margin-top:8rpx;color:#AEB3BB;font-size:20rpx;text-align:right}
.actions{display:flex;gap:20rpx;margin-top:28rpx}
.cancel{flex:1;height:88rpx;border-radius:44rpx;background:#F1F3F1;color:#767C85;font-size:28rpx;line-height:88rpx}
.confirm{flex:2;height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:88rpx}
.cancel::after,.confirm::after{border:0}
.confirm[disabled]{opacity:.55;color:#fff}
</style>
