<template>
  <view class="bind-page" :style="topSafeAreaStyle">
    <view class="bind-head">
      <text class="bind-kicker">CAMPUS</text>
      <text class="bind-title">绑定你的学校</text>
      <text class="bind-sub">完成任务后即可查看本校任务、闲置与社区内容</text>
    </view>

    <view class="bind-steps">
      <view v-for="(item, index) in stepItems" :key="item.key" class="bind-step">
        <view :class="['bind-step-bar', { done: step > index + 1, active: step >= index + 1 }]" />
        <view class="bind-step-foot">
          <view :class="['bind-step-dot', { done: step > index + 1, active: step === index + 1 }]">
            <text>{{ step > index + 1 ? '✓' : index + 1 }}</text>
          </view>
          <text :class="['bind-step-label', { active: step === index + 1 }]">{{ item.label }}</text>
        </view>
      </view>
    </view>

    <!-- ① 选择学校 -->
    <view v-if="step === 1" class="bind-card">
      <text class="bind-card-title">选择学校</text>
      <text class="bind-card-note">搜索学校名称、城市或地区</text>

      <view class="bind-search">
        <view class="bind-search-icon" />
        <input
          v-model="keyword"
          confirm-type="search"
          placeholder="搜索学校"
          placeholder-class="bind-placeholder"
          @confirm="searchCampus"
        />
        <text v-if="keyword" class="bind-search-clear" @click="clearKeyword">清除</text>
      </view>

      <view class="bind-selected" v-if="selectedCampus">
        <view class="bind-selected-mark">当</view>
        <view class="bind-selected-copy">
          <text class="bind-selected-name">{{ selectedCampus.name }}</text>
          <text class="bind-selected-note">{{ locationText(selectedCampus) }}</text>
        </view>
        <text class="bind-selected-tag">已选</text>
      </view>

      <scroll-view class="bind-campus-scroll" scroll-y>
        <view v-if="campus.loading" class="bind-state">正在加载学校…</view>
        <view v-else-if="campus.error" class="bind-state">
          <text class="bind-state-title">学校列表加载失败</text>
          <text class="bind-state-copy">{{ campus.error }}</text>
          <button class="bind-state-btn" @click="loadCampus">重新加载</button>
        </view>
        <view v-else-if="!campus.items.length" class="bind-state">
          <text class="bind-state-title">没有找到匹配的学校</text>
          <text class="bind-state-copy">试试输入学校全称、城市或地区名称。</text>
        </view>
        <view v-else class="bind-campus-list">
          <view
            v-for="item in campus.items"
            :key="item.id"
            :class="['bind-campus', { active: selectedCampus?.id === item.id }]"
            @click="pickCampus(item)"
          >
            <view class="bind-campus-mark">{{ initials(item.name) }}</view>
            <view class="bind-campus-copy">
              <text class="bind-campus-name">{{ item.name }}</text>
              <text class="bind-campus-loc">{{ locationText(item) }}</text>
            </view>
            <view v-if="selectedCampus?.id === item.id" class="bind-campus-check">✓</view>
          </view>
        </view>
      </scroll-view>
    </view>

    <!-- ② 选择年级 -->
    <view v-else-if="step === 2" class="bind-card">
      <text class="bind-card-title">选择年级</text>
      <text class="bind-card-note">用于匹配适合你的校园任务</text>

      <view class="bind-years">
        <view
          v-for="option in yearOptions"
          :key="String(option.value)"
          :class="['bind-year', { active: gradeChoice === option.value }]"
          @click="pickYear(option.value)"
        >
          <text class="bind-year-main">{{ option.label }}</text>
          <text v-if="option.note" class="bind-year-note">{{ option.note }}</text>
        </view>
      </view>

      <view v-if="gradeChoice === 'other'" class="bind-custom-year">
        <text class="bind-field-label">其他年份</text>
        <input
          v-model="customYear"
          type="number"
          maxlength="4"
          class="bind-input"
          placeholder="如 2019"
          placeholder-class="bind-placeholder"
        />
        <text class="bind-field-hint">请填写入学年份，1900 ~ {{ currentYear }}</text>
      </view>
    </view>

    <!-- ③ 填写专业 -->
    <view v-else class="bind-card">
      <text class="bind-card-title">完善校园档案</text>
      <text class="bind-card-note">专业将展示在你的校园名片上</text>

      <view class="bind-field">
        <view class="bind-field-head">
          <text class="bind-field-label">专业<text class="bind-required">*</text></text>
          <text class="bind-field-count">{{ major.length }}/30</text>
        </view>
        <input
          v-model="major"
          maxlength="30"
          class="bind-input"
          placeholder="如 计算机科学与技术"
          placeholder-class="bind-placeholder"
        />
      </view>

      <view class="bind-field">
        <view class="bind-field-head">
          <text class="bind-field-label">学院<text class="bind-optional">（选填）</text></text>
          <text class="bind-field-count">{{ college.length }}/60</text>
        </view>
        <input
          v-model="college"
          maxlength="60"
          class="bind-input"
          placeholder="如 计算机学院"
          placeholder-class="bind-placeholder"
        />
      </view>

      <view class="bind-summary">
        <view class="bind-summary-row"><text>学校</text><text>{{ selectedCampus?.name || '未选择' }}</text></view>
        <view class="bind-summary-row"><text>年级</text><text>{{ gradeYearText }}</text></view>
      </view>
    </view>

    <view class="bind-footer">
      <button v-if="step > 1" class="bind-secondary" :disabled="submitting" @click="prevStep">上一步</button>
      <button v-if="step < 3" class="bind-primary" :disabled="!canNext" @click="nextStep">下一步</button>
      <button v-else class="bind-primary" :loading="submitting" :disabled="submitting || !canSubmit" @click="submit">
        完成绑定
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import { bindAccountCampus, type Campus } from '@/api/campuses'
import { useAuthStore } from '@/stores/auth'
import { useCampusStore } from '@/stores/campus'
import { useCampusProfileStore } from '@/stores/campusProfile'
import { useModulesStore } from '@/stores/modules'
import { formatDateTime } from '@/utils/dateTime'
import { navigateToLogin } from '@/utils/authRedirect'
import { createTopSafeAreaStyle } from '@/utils/safeArea'

const topSafeAreaStyle = createTopSafeAreaStyle()

const auth = useAuthStore()
const campus = useCampusStore()
const campusProfile = useCampusProfileStore()

const stepItems = [
  { key: 'campus', label: '选学校' },
  { key: 'grade', label: '选年级' },
  { key: 'profile', label: '填专业' },
]
const step = ref(1)
const keyword = ref('')
const selectedCampus = ref<Campus | null>(null)
const gradeChoice = ref<number | 'other' | null>(null)
const customYear = ref('')
const major = ref('')
const college = ref('')
const submitting = ref(false)

const currentYear = new Date().getFullYear()
const yearOptions = computed(() => {
  const years = Array.from({ length: 6 }, (_, index) => currentYear - index)
  return [
    ...years.map((year, index) => ({
      value: year as number | 'other',
      label: `${year} 级`,
      note: index === 0 ? '今年' : '',
    })),
    { value: 'other' as number | 'other', label: '其他', note: '更早年份' },
  ]
})

const gradeYear = computed(() => {
  if (gradeChoice.value === 'other') {
    const parsed = Number(customYear.value)
    return Number.isInteger(parsed) ? parsed : null
  }
  return typeof gradeChoice.value === 'number' ? gradeChoice.value : null
})
const gradeYearText = computed(() => (gradeYear.value ? `${gradeYear.value} 级` : '未选择'))

const canNext = computed(() => {
  if (step.value === 1) return Boolean(selectedCampus.value)
  if (step.value === 2) return gradeYear.value !== null && gradeYear.value >= 1900 && gradeYear.value <= currentYear
  return true
})
const canSubmit = computed(() => Boolean(selectedCampus.value) && Boolean(gradeYear.value) && major.value.trim().length > 0)

onLoad(async () => {
  if (!auth.accessToken) {
    navigateToLogin()
    return
  }
  await campusProfile.load(true).catch(() => {})
  const fallback = campusProfile.campus
  selectedCampus.value = campus.selected
    || (fallback ? { id: fallback.id, name: fallback.name } as Campus : null)
  if (campusProfile.profile?.major) major.value = campusProfile.profile.major
  if (campusProfile.profile?.college) college.value = campusProfile.profile.college
  if (campusProfile.profile?.grade_year) gradeChoice.value = campusProfile.profile.grade_year
  await loadCampus()
})

async function loadCampus() {
  await campus.load({ limit: 30 }).catch(() => {
    uni.showToast({ title: '学校列表加载失败，请重试', icon: 'none' })
  })
}

async function searchCampus() {
  const query = keyword.value.trim()
  await campus.load(query ? { keyword: query, limit: 30 } : { limit: 30 }).catch(() => {
    uni.showToast({ title: '搜索失败，请重试', icon: 'none' })
  })
}

function clearKeyword() {
  keyword.value = ''
  void searchCampus()
}

function pickCampus(item: Campus) {
  selectedCampus.value = item
}

function pickYear(value: number | 'other') {
  gradeChoice.value = value
  if (value !== 'other') customYear.value = ''
}

function nextStep() {
  if (!canNext.value) {
    uni.showToast({ title: step.value === 1 ? '请先选择学校' : '请选择年级', icon: 'none' })
    return
  }
  step.value += 1
}

function prevStep() {
  if (step.value > 1) step.value -= 1
}

async function submit() {
  if (submitting.value || !auth.accessToken) return
  if (!selectedCampus.value) {
    step.value = 1
    return
  }
  const year = gradeYear.value
  if (!year || year < 1900 || year > currentYear) {
    step.value = 2
    uni.showToast({ title: '请选择或填写正确的入学年份', icon: 'none' })
    return
  }
  const majorText = major.value.trim()
  if (!majorText) {
    uni.showToast({ title: '请填写专业', icon: 'none' })
    return
  }

  submitting.value = true
  try {
    const result = await bindAccountCampus({
      campus_id: selectedCampus.value.id,
      grade_year: year,
      major: majorText.slice(0, 30),
      ...(college.value.trim() ? { college: college.value.trim().slice(0, 60) } : {}),
    }, auth.accessToken)

    campus.setLocal(result)
    // 清理校区相关的业务缓存并刷新账号校区状态
    uni.removeStorageSync('task_mall_module_flags')
    useModulesStore().reloadForCampus()
    await campusProfile.load(true).catch(() => {})
    await auth.fetchCurrentUser()

    uni.showToast({ title: '学校绑定成功', icon: 'success' })
    setTimeout(() => {
      uni.reLaunch({ url: '/pages/index/index' })
    }, 600)
  } catch (error) {
    showBindError(error)
  } finally {
    submitting.value = false
  }
}

function showBindError(error: unknown) {
  if (error instanceof ApiError) {
    if (error.code === 'CAMPUS_CHANGE_TOO_FREQUENT') {
      const nextAt = typeof error.details?.next_available_at === 'string' ? error.details.next_available_at : null
      const days = daysUntil(nextAt)
      uni.showModal({
        title: '暂时无法更换学校',
        content: `${days > 0 ? `${days} 天后可更换学校。` : ''}下次可修改时间：${formatDateTime(nextAt)}`,
        showCancel: false,
        confirmText: '我知道了',
      })
      return
    }
    if (error.code === 'CAMPUS_CHANGE_BLOCKED_ORDERS') {
      uni.showModal({
        title: '存在进行中的闲置订单',
        content: `${ordersText(error.details?.orders)}履约完成后即可更换学校。`,
        showCancel: false,
        confirmText: '我知道了',
      })
      return
    }
    uni.showToast({ title: error.message || '绑定失败，请重试', icon: 'none' })
    return
  }
  uni.showToast({ title: '绑定失败，请检查网络后重试', icon: 'none' })
}

function daysUntil(value: string | null) {
  if (!value) return 0
  const time = new Date(value).getTime()
  if (!Number.isFinite(time)) return 0
  return Math.max(0, Math.ceil((time - Date.now()) / 86400000))
}

const orderStatusLabels: Record<string, string> = {
  pending: '待付款',
  paid: '待发货',
  shipped: '待收货',
}

function ordersText(value: unknown) {
  const orders = Array.isArray(value) ? value as Array<Record<string, unknown>> : []
  if (!orders.length) return ''
  const lines = orders.slice(0, 5).map((order) => {
    const id = typeof order.id === 'string' ? order.id : ''
    const status = typeof order.status === 'string' ? order.status : ''
    const createdAt = typeof order.created_at === 'string' ? order.created_at : null
    return `订单 ${id.slice(-8)}（${orderStatusLabels[status] || status}，${formatDateTime(createdAt)}）`
  })
  return `${lines.join('；')}。`
}

const separators = /[\s\-_.·/]+/

function initials(name: string) {
  const normalized = name.trim()
  if (!normalized) return '校'
  return Array.from(normalized.split(separators).join('')).slice(0, 2).join('').toUpperCase()
}

function locationText(item: Campus) {
  return [item.province, item.city, item.district].filter(Boolean).join(' · ') || item.address || '学校地址待完善'
}
</script>

<style scoped>
.bind-page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--line:#EDEFEF;display:flex;flex-direction:column;min-height:100vh;box-sizing:border-box;padding:calc(env(safe-area-inset-top) + 28rpx) 32rpx calc(40rpx + env(safe-area-inset-bottom));background:linear-gradient(180deg,#F7FFFB 0%,#F4F6F4 42%,#F4F6F4 100%)}
.bind-head{padding:16rpx 8rpx 0}
.bind-kicker{display:inline-flex;align-items:center;height:34rpx;padding:0 14rpx;border-radius:999rpx;background:var(--green-soft);color:var(--green-deep);font-size:20rpx;font-weight:900;letter-spacing:.14em}
.bind-title{display:block;margin-top:18rpx;color:var(--t1);font-size:44rpx;font-weight:900;line-height:1.25}
.bind-sub{display:block;margin-top:12rpx;color:var(--t2);font-size:24rpx;line-height:1.55}
.bind-steps{display:flex;gap:16rpx;margin:30rpx 8rpx 0}
.bind-step{flex:1;min-width:0}
.bind-step-bar{height:8rpx;border-radius:999rpx;background:#E3E8E4}
.bind-step-bar.active{background:linear-gradient(90deg,#12D978,#08A152)}
.bind-step-bar.done{background:var(--green-deep)}
.bind-step-foot{display:flex;align-items:center;gap:10rpx;margin-top:14rpx}
.bind-step-dot{display:flex;width:36rpx;height:36rpx;align-items:center;justify-content:center;flex:none;border-radius:50%;background:#E3E8E4;color:#8A919C;font-size:20rpx;font-weight:900}
.bind-step-dot.active{background:var(--green);color:#fff;box-shadow:0 6rpx 16rpx rgba(11,191,99,.28)}
.bind-step-dot.done{background:var(--green-deep);color:#fff}
.bind-step-label{overflow:hidden;color:var(--t3);font-size:22rpx;font-weight:600;text-overflow:ellipsis;white-space:nowrap}
.bind-step-label.active{color:var(--green-deep);font-weight:800}
.bind-card{display:flex;flex:1;flex-direction:column;margin-top:28rpx;padding:30rpx 28rpx;box-sizing:border-box;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 14rpx 40rpx rgba(23,26,23,.06)}
.bind-card-title{color:var(--t1);font-size:32rpx;font-weight:900}
.bind-card-note{display:block;margin-top:10rpx;color:var(--t3);font-size:23rpx}
.bind-search{display:flex;align-items:center;gap:14rpx;height:76rpx;margin-top:24rpx;padding:0 22rpx;border:1rpx solid var(--line);border-radius:38rpx;background:#F8FAF8}
.bind-search input{min-width:0;flex:1;height:74rpx;color:var(--t1);font-size:26rpx}
.bind-search-icon{width:24rpx;height:24rpx;flex:none;box-sizing:border-box;border:4rpx solid #8A919C;border-radius:50%}
.bind-search-icon::after{content:'';display:block;width:12rpx;height:4rpx;margin:18rpx 0 0 17rpx;border-radius:4rpx;background:#8A919C;transform:rotate(45deg)}
.bind-search-clear{flex:none;color:var(--green-deep);font-size:23rpx;font-weight:700}
.bind-placeholder{color:var(--t3)}
.bind-selected{display:flex;align-items:center;gap:16rpx;margin-top:20rpx;padding:18rpx 20rpx;border:1rpx solid #BFEBD5;border-radius:22rpx;background:var(--green-soft)}
.bind-selected-mark{display:flex;width:52rpx;height:52rpx;align-items:center;justify-content:center;flex:none;border-radius:14rpx;background:var(--green);color:#fff;font-size:24rpx;font-weight:900}
.bind-selected-copy{min-width:0;flex:1}
.bind-selected-name{display:block;overflow:hidden;color:#0B6B43;font-size:26rpx;font-weight:800;text-overflow:ellipsis;white-space:nowrap}
.bind-selected-note{display:block;margin-top:6rpx;overflow:hidden;color:#4E7F68;font-size:21rpx;text-overflow:ellipsis;white-space:nowrap}
.bind-selected-tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;background:#fff;color:var(--green-deep);font-size:20rpx;font-weight:800}
.bind-campus-scroll{flex:1;height:46vh;margin-top:20rpx;overflow:hidden}
.bind-campus-list{padding-bottom:4rpx}
.bind-campus{display:flex;align-items:center;gap:16rpx;margin-top:12rpx;padding:18rpx 20rpx;border:1rpx solid var(--line);border-radius:22rpx;background:#fff}
.bind-campus.active{border-color:var(--green);background:#F4FDF8}
.bind-campus-mark{display:flex;width:52rpx;height:52rpx;align-items:center;justify-content:center;flex:none;border-radius:14rpx;background:#EAF2FF;color:#2F6BDE;font-size:20rpx;font-weight:900}
.bind-campus-copy{min-width:0;flex:1}
.bind-campus-name{display:block;overflow:hidden;color:var(--t1);font-size:26rpx;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.bind-campus-loc{display:block;margin-top:6rpx;overflow:hidden;color:var(--t3);font-size:21rpx;text-overflow:ellipsis;white-space:nowrap}
.bind-campus-check{display:flex;width:40rpx;height:40rpx;align-items:center;justify-content:center;flex:none;border-radius:50%;background:var(--green);color:#fff;font-size:22rpx;font-weight:900}
.bind-state{display:flex;min-height:300rpx;align-items:center;justify-content:center;flex-direction:column;padding:40rpx 20rpx;text-align:center}
.bind-state-title{color:var(--t2);font-size:26rpx;font-weight:700}
.bind-state-copy{margin-top:10rpx;color:var(--t3);font-size:22rpx;line-height:1.55}
.bind-state-btn{height:64rpx;margin-top:20rpx;padding:0 32rpx;border-radius:32rpx;background:var(--green);color:#fff;font-size:24rpx;font-weight:700;line-height:64rpx}
.bind-state-btn::after{border:0}
.bind-years{display:grid;grid-template-columns:repeat(2,1fr);gap:16rpx;margin-top:26rpx}
.bind-year{display:flex;flex-direction:column;align-items:flex-start;justify-content:center;height:104rpx;padding:0 24rpx;box-sizing:border-box;border:1rpx solid var(--line);border-radius:22rpx;background:#F8FAF8}
.bind-year.active{border-color:var(--green);background:var(--green-soft);box-shadow:0 8rpx 22rpx rgba(11,191,99,.14)}
.bind-year-main{color:var(--t1);font-size:28rpx;font-weight:800}
.bind-year.active .bind-year-main{color:var(--green-deep)}
.bind-year-note{margin-top:4rpx;color:var(--t3);font-size:20rpx}
.bind-custom-year{margin-top:26rpx}
.bind-field{margin-top:28rpx}
.bind-field-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12rpx}
.bind-field-label{color:var(--t1);font-size:25rpx;font-weight:800}
.bind-required{margin-left:4rpx;color:#FF3B30}
.bind-optional{color:var(--t3);font-size:22rpx;font-weight:500}
.bind-field-count{color:var(--t3);font-size:21rpx}
.bind-input{height:88rpx;padding:0 24rpx;box-sizing:border-box;border:1rpx solid var(--line);border-radius:22rpx;background:#F8FAF8;color:var(--t1);font-size:27rpx}
.bind-field-hint{display:block;margin-top:10rpx;color:var(--t3);font-size:21rpx}
.bind-summary{margin-top:28rpx;padding:20rpx 24rpx;border-radius:22rpx;background:#F8FAF8}
.bind-summary-row{display:flex;align-items:center;justify-content:space-between;padding:10rpx 0;color:var(--t2);font-size:24rpx}
.bind-summary-row>text:last-child{color:var(--t1);font-weight:700}
.bind-footer{display:flex;gap:18rpx;margin-top:28rpx}
.bind-primary,.bind-secondary{display:flex;flex:1;align-items:center;justify-content:center;height:92rpx;margin:0;border-radius:24rpx;font-size:29rpx;font-weight:800;line-height:92rpx}
.bind-primary{background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 12rpx 28rpx rgba(11,191,99,.26);color:#fff}
.bind-primary[disabled]{background:#DFE3E0;box-shadow:none;color:#fff}
.bind-secondary{flex:0 0 210rpx;border:1rpx solid var(--line);background:#fff;color:var(--t1)}
.bind-primary::after,.bind-secondary::after{border:0}
@media screen and (min-width:768px){.bind-page{width:750rpx;margin:0 auto}}
</style>
