<template>
  <view
    class="home-v4"
    :class="{ 'is-authenticated': auth.isAuthenticated, 'is-guest': !auth.isAuthenticated }"
    :style="pageStyle"
  >
    <view class="home-v4-safe" />

    <view v-if="auth.isAuthenticated" class="home-v10-auth-shell">
      <view class="home-v10-hero">
        <swiper
          class="home-v10-hero-swiper"
          :autoplay="home.carousel.autoplay"
          :interval="home.carousel.interval_ms"
          :current="activeHeroIndex"
          circular
          @change="handleHeroChange"
        >
          <swiper-item v-for="slide in home.carousel.items" :key="`v10-${slide.id}`">
            <view class="home-v10-hero-slide" @click="navigateHomeLink(slide.link_type)">
              <image
                class="home-v10-hero-image"
                :src="heroImageSource(slide)"
                mode="aspectFill"
                @error="handleHeroImageError(slide)"
              />
              <view class="home-v10-hero-veil" />
            </view>
          </swiper-item>
        </swiper>

        <view class="home-v10-hero-brand">
          <text class="home-v10-hero-title">{{ home.brand.name || '任务商城' }}</text>
          <text class="home-v10-hero-subtitle">完成任务，兑换好生活</text>
        </view>

        <view class="home-v10-hero-actions">
          <view class="home-v10-hero-action" role="button" aria-label="打开消息中心" @click="openNotifications">
            <view class="home-v10-bell"><view /></view>
            <view class="home-v10-notice-dot" />
            <text>消息</text>
          </view>
          <view
            v-if="home.floating_support.enabled"
            class="home-v10-hero-action"
            role="button"
            aria-label="联系客服"
            @click="openFloatingSupport"
          >
            <image
              v-if="home.floating_support.icon_url"
              class="home-v10-action-image"
              :src="home.floating_support.icon_url"
              mode="aspectFit"
            />
            <view v-else class="home-v10-headset"><view>••</view></view>
            <text>{{ supportShortLabel }}</text>
          </view>
        </view>

        <view v-if="home.carousel.items.length > 1" class="home-v10-hero-dots">
          <view
            v-for="(_slide, index) in home.carousel.items"
            :key="`v10-dot-${index}`"
            :class="['home-v10-hero-dot', { active: index === activeHeroIndex }]"
          />
        </view>
      </view>

      <view class="home-v10-main">
        <view class="home-v10-quick-card" :style="backgroundStyle(home.modules.quick_grid_background_url)">
          <view
            v-for="item in quickEntries"
            :key="`v10-quick-${item.id}`"
            class="home-v10-quick-item"
            @click="item.action"
          >
            <view :class="['home-v10-quick-icon', `tone-${item.tone}`]">
              <image v-if="item.icon_url" :src="item.icon_url" mode="aspectFit" />
              <text v-else>{{ quickArtSymbol(item.id, item.link_type) }}</text>
            </view>
            <text class="home-v10-quick-label">{{ item.label }}</text>
          </view>
        </view>

        <view
          v-if="home.announcement.enabled"
          class="home-v10-announcement"
          role="button"
          aria-label="查看校园公告"
          @click="navigateHomeLink(home.announcement.link_type)"
        >
          <view class="home-v10-announcement-icon">
            <image v-if="home.announcement.icon_url" :src="home.announcement.icon_url" mode="aspectFit" />
            <text v-else>◖</text>
          </view>
          <text class="home-v10-announcement-label">校园公告</text>
          <text class="home-v10-announcement-content">{{ announcementSummary }}</text>
          <text class="home-v10-announcement-arrow">›</text>
        </view>

        <view v-if="homeError" class="home-v10-error" @click="loadHome">
          首页内容使用默认配置，点击重新加载
        </view>

        <view class="home-v10-section-head">
          <text class="home-v10-section-title">为你推荐</text>
          <text class="home-v10-section-link" @click="openTasks">查看全部任务 ›</text>
        </view>

        <view v-if="recommendedTask" class="home-v10-task-card" @click="openRecommendedTask">
          <view class="home-v10-task-cover tone-green">
            <image v-if="recommendedTask?.cover_image_url" :src="recommendedTask.cover_image_url" mode="aspectFill" />
            <view v-else class="home-v10-task-fallback">✓</view>
          </view>
          <view class="home-v10-task-copy">
            <text class="home-v10-task-title">{{ recommendedTask?.title || home.modules.recommendation.title }}</text>
            <text class="home-v10-task-description">{{ recommendedDescriptionText }}</text>
            <text class="home-v10-task-meta">剩余名额 {{ recommendedTask?.remaining_reward_slots ?? 0 }} 人 ｜ 审核时限 {{ recommendedReviewTimeText }}</text>
          </view>
          <view class="home-v10-task-side">
            <text class="home-v10-task-reward">{{ recommendedRewardText }}</text>
            <button class="home-v10-task-button" @click.stop="openRecommendedTask">去参与</button>
          </view>
        </view>

        <view v-else class="home-v10-task-empty" @click="openTasks">
          <view class="home-v10-task-empty-icon">任</view>
          <view class="home-v10-task-empty-copy">
            <text>新的校园任务正在准备中</text>
            <text>先去任务大厅看看其他可参与内容</text>
          </view>
          <text class="home-v10-task-empty-link">去看看 ›</text>
        </view>

        <view v-if="activeClaim" class="home-v10-continue-section">
          <view class="home-v10-continue-head">
            <text>继续使用任务</text>
            <text @click="openTasks">查看全部任务 ›</text>
          </view>
          <view class="home-v10-task-card home-v10-continue-card" @click="openActiveClaim">
            <view class="home-v10-task-cover tone-blue">
              <view class="home-v10-task-fallback">续</view>
            </view>
            <view class="home-v10-task-copy">
              <text class="home-v10-task-title">{{ activeClaim.title }}</text>
              <text class="home-v10-task-description">{{ activeClaimDescriptionText }}</text>
              <text class="home-v10-task-meta">已完成 {{ activeClaimPercent }}% ｜ {{ activeClaim.execution_status_label || '等待继续提交' }}</text>
            </view>
            <view class="home-v10-task-side">
              <text class="home-v10-task-reward">{{ activeClaimRewardText }}</text>
              <button class="home-v10-task-button" @click.stop="openActiveClaim">去完成</button>
            </view>
          </view>
        </view>

        <view class="home-v10-mall-section">
          <view class="home-v10-mall-head">
            <text>积分商城精选</text>
            <text @click="openProducts">全部商品 ›</text>
          </view>
          <view class="home-v10-products">
            <view
              v-for="(product, index) in registeredProducts"
              :key="`v10-product-${product.id}`"
              class="home-v10-product"
              @click="openProduct(product.id)"
            >
              <view :class="['home-v10-product-image', `tone-${index % 4}`]">
                <image v-if="productImage(product)" :src="productImage(product)" mode="aspectFill" />
                <text v-else>{{ product.name.slice(0, 1) }}</text>
              </view>
              <text class="home-v10-product-name">{{ product.name }}</text>
              <text class="home-v10-product-price">{{ productPriceText(product) }}</text>
            </view>
          </view>
        </view>

        <view class="home-v10-trust" @click="openAssets">
          <view class="home-v10-trust-shield">✓</view>
          <view>
            <text>安心保障</text>
            <text>平台对任务发布与交易全程保障，权益无忧</text>
          </view>
          <text>›</text>
        </view>
      </view>
    </view>

    <template v-else>
    <view class="home-v4-header">
      <view class="home-v4-brand">
        <image
          v-if="home.brand.logo_url"
          class="home-v4-brand-logo"
          :src="home.brand.logo_url"
          mode="aspectFit"
        />
        <view v-else class="home-v4-brand-mark">{{ home.brand.logo_text || 'TM' }}</view>
        <view class="home-v4-brand-copy">
          <text class="home-v4-brand-name">{{ home.brand.name }}</text>
          <text class="home-v4-brand-caption">{{ home.brand.caption }}</text>
        </view>
      </view>

      <view class="home-v4-header-actions">
        <view
          class="home-v4-header-action"
          role="button"
          aria-label="打开消息中心"
          @click="openNotifications"
        >
          <image
            v-if="home.modules.notification_icon_url"
            class="home-v4-header-action-image"
            :src="home.modules.notification_icon_url"
            mode="aspectFit"
          />
          <view v-else class="home-v4-bell-glyph"><view class="home-v4-bell-clapper" /></view>
        </view>
        <view
          v-if="home.floating_support.enabled"
          class="home-v4-support-action"
          role="button"
          aria-label="联系客服"
          @click="openFloatingSupport"
        >
          <image
            v-if="home.floating_support.icon_url"
            class="home-v4-support-image"
            :src="home.floating_support.icon_url"
            mode="aspectFit"
          />
          <view v-else class="home-v4-support-glyph">
            <view class="home-v4-support-headset" />
            <view class="home-v4-support-face">••</view>
          </view>
          <text v-if="home.floating_support.show_label" class="home-v4-support-label">
            {{ supportShortLabel }}
          </text>
        </view>
      </view>
    </view>

    <view
      v-if="homeLoading && !homeLoaded"
      class="home-v4-state home-v4-state-loading"
      role="status"
      aria-live="polite"
    >
      <view class="home-v4-state-spinner" aria-hidden="true" />
      <text>正在加载校园首页…</text>
    </view>

    <view
      v-else-if="homeError"
      class="home-v4-state home-v4-state-error"
      role="alert"
    >
      <view>
        <text class="home-v4-state-title">首页配置暂时不可用</text>
        <text class="home-v4-state-copy">已为你显示默认内容，稍后可重新加载。</text>
      </view>
      <button class="home-v4-state-action" @click="loadHome">重新加载</button>
    </view>

    <view class="home-v4-content">
      <swiper
        class="home-v4-hero-swiper"
        :autoplay="home.carousel.autoplay"
        :interval="home.carousel.interval_ms"
        :current="activeHeroIndex"
        circular
        @change="handleHeroChange"
      >
        <swiper-item v-for="slide in home.carousel.items" :key="slide.id">
          <view class="home-v4-hero">
            <image
              class="home-v4-hero-image"
              :src="heroImageSource(slide)"
              mode="aspectFill"
              @error="handleHeroImageError(slide)"
            />
            <view class="home-v4-hero-wash" />
            <view class="home-v4-hero-copy">
              <text class="home-v4-hero-kicker">{{ slide.kicker }}</text>
              <view class="home-v4-hero-title">
                <text>{{ titleParts(slide.title).primary }}</text>
                <view
                  v-if="titleParts(slide.title).secondary || titleParts(slide.title).accent"
                  class="home-v4-hero-title-line"
                >
                  <text>{{ titleParts(slide.title).secondary }}</text>
                  <text v-if="titleParts(slide.title).accent" class="home-v4-hero-title-accent">{{ titleParts(slide.title).accent }}</text>
                </view>
              </view>
              <text class="home-v4-hero-description">{{ slide.description }}</text>
              <button class="home-v4-hero-button" @click.stop="navigateHomeLink(slide.link_type)">
                {{ slide.button_text }}<text class="home-v4-hero-button-arrow">›</text>
              </button>
            </view>
            <view v-if="home.carousel.items.length > 1" class="home-v4-hero-dots">
              <view
                v-for="(_item, index) in home.carousel.items"
                :key="index"
                class="home-v4-hero-dot"
                :class="{ active: index === activeHeroIndex }"
              />
            </view>
          </view>
        </swiper-item>
      </swiper>

      <view class="home-v4-quick-card" :style="backgroundStyle(home.modules.quick_grid_background_url)">
        <view
          v-for="(item, index) in quickEntries"
          :key="item.id"
          class="home-v4-quick-item"
          :class="{ 'with-divider': index > 0 }"
          @click="item.action"
        >
          <view :class="['home-v4-quick-icon', `tone-${item.tone}`]">
            <image v-if="item.icon_url" class="home-v4-quick-image" :src="item.icon_url" mode="aspectFit" />
            <view v-else :class="['home-v4-quick-art', `art-${quickArtType(item.id, item.link_type)}`]">
              <view class="home-v4-quick-art-body">
                <text class="home-v4-quick-art-symbol">{{ quickArtSymbol(item.id, item.link_type) }}</text>
              </view>
            </view>
          </view>
          <text class="home-v4-quick-label">{{ item.label }}</text>
          <text class="home-v4-quick-note">{{ item.note }}</text>
        </view>
      </view>

      <view class="home-v09-auth">
        <view
          class="home-v09-notice"
          role="button"
          aria-label="查看平台公告"
          @click="navigateHomeLink(home.announcement.link_type)"
        >
          <view class="home-v09-notice-icon">
            <image v-if="home.announcement.icon_url" :src="home.announcement.icon_url" mode="aspectFit" />
            <text v-else>公</text>
          </view>
          <view class="home-v09-notice-copy">
            <text class="home-v09-notice-title">{{ home.announcement.title || '平台公告' }}</text>
            <rich-text class="home-v09-notice-content" :nodes="compactRichTextContent(home.announcement.content)" />
          </view>
          <text class="home-v09-arrow">›</text>
        </view>

        <view class="home-v09-section-head">
          <view>
            <text class="home-v09-section-title">今日推荐</text>
            <text class="home-v09-section-note">从一件简单的小事开始</text>
          </view>
          <text class="home-v09-section-link" @click="openTasks">查看全部 ›</text>
        </view>

        <view class="home-v09-task-card" @click="openRecommendedTask">
          <view class="home-v09-task-visual">
            <image
              v-if="recommendedTask?.cover_image_url"
              class="home-v09-task-cover"
              :src="recommendedTask.cover_image_url"
              mode="aspectFill"
            />
            <view v-else class="home-v09-task-art">
              <view class="home-v09-task-clipboard"><view class="home-v09-task-lines" /></view>
              <text>★</text>
            </view>
          </view>
          <view class="home-v09-task-copy">
            <view class="home-v09-task-title-row">
              <text class="home-v09-task-title">{{ recommendedTask?.title || home.modules.recommendation.title }}</text>
              <text class="home-v09-task-level">{{ taskDifficultyLabel(recommendedTask) }}</text>
            </view>
            <text class="home-v09-task-description">{{ recommendedTask?.description_text || recommendedTask?.description || home.modules.recommendation.description }}</text>
            <view class="home-v09-task-meta">
              <text class="home-v09-task-reward">{{ recommendedRewardText }}</text>
              <text class="home-v09-task-time">◷ {{ recommendedTimeText }}</text>
            </view>
          </view>
          <view class="home-v09-circle-arrow">›</view>
        </view>

        <view v-if="activeClaim" class="home-v09-progress-card" @click="openActiveClaim">
          <view class="home-v09-progress-main">
            <view class="home-v09-progress-badge">续</view>
            <view class="home-v09-progress-copy">
              <text class="home-v09-progress-kicker">继续上次任务</text>
              <text class="home-v09-progress-title">{{ activeClaim.title }}</text>
              <text class="home-v09-progress-note">{{ activeClaim.execution_status_label || '已领取，等待提交任务材料' }}</text>
            </view>
            <text class="home-v09-progress-action">继续完成</text>
          </view>
          <view class="home-v09-progress-track"><view :style="{ width: `${activeClaimPercent}%` }" /></view>
        </view>

        <view class="home-v09-section-head home-v09-mall-head">
          <view>
            <text class="home-v09-section-title">积分商城</text>
            <text class="home-v09-section-note">积分、现金与组合支付都可选</text>
          </view>
          <text class="home-v09-section-link" @click="openProducts">逛商城 ›</text>
        </view>

        <scroll-view class="home-v09-product-scroll" scroll-x :show-scrollbar="false">
          <view class="home-v09-product-row">
            <view
              v-for="(product, index) in registeredProducts"
              :key="product.id"
              class="home-v09-product-card"
              @click="openProduct(product.id)"
            >
              <view :class="['home-v09-product-picture', `tone-${index % 4}`]">
                <image v-if="productImage(product)" :src="productImage(product)" mode="aspectFill" />
                <text v-else>{{ product.name.slice(0, 1) }}</text>
              </view>
              <text class="home-v09-product-title">{{ product.name }}</text>
              <text class="home-v09-product-price">{{ productPriceText(product) }}</text>
            </view>
          </view>
        </scroll-view>

        <view class="home-v09-trust" @click="openAssets">
          <view class="home-v09-trust-icon">✓</view>
          <view class="home-v09-trust-copy">
            <text>每一笔资产都有记录</text>
            <text>积分来源、有效期、退款和余额变动清晰可查</text>
          </view>
          <text class="home-v09-arrow">›</text>
        </view>
      </view>

      <view class="home-v4-campus-card">
        <view class="home-v4-campus-head">
          <view>
            <text class="home-v4-campus-kicker">CAMPUS NOW · 校园速览</text>
            <text class="home-v4-campus-title">今天需要关注的事</text>
          </view>
          <text class="home-v4-campus-more" role="button" aria-label="查看消息详情" @click="openNotifications">查看详情 ›</text>
        </view>

        <view class="home-v4-campus-tabs">
          <view
            v-for="tab in infoTabs"
            :key="tab.id"
            class="home-v4-campus-tab"
            :class="{ active: activeInfoTab === tab.id }"
            @click="activeInfoTab = tab.id"
          >
            {{ tab.label }}
          </view>
        </view>

        <view v-if="activeInfoTab === 'announcement'" class="home-v4-info-panel">
          <view
            v-if="home.announcement.enabled"
            class="home-v4-announcement"
            role="button"
            aria-label="查看平台公告"
            @click="navigateHomeLink(home.announcement.link_type)"
          >
            <view class="home-v4-announcement-icon">
              <image
                v-if="home.announcement.icon_url"
                class="home-v4-announcement-image"
                :src="home.announcement.icon_url"
                mode="aspectFit"
              />
              <view v-else class="home-v4-megaphone"><view class="home-v4-megaphone-handle" /></view>
            </view>
            <view class="home-v4-announcement-copy">
              <text class="home-v4-announcement-title">{{ home.announcement.title }}</text>
              <rich-text
                class="home-v4-announcement-content"
                :nodes="compactRichTextContent(home.announcement.content)"
              />
            </view>
            <text v-if="home.announcement.link_type !== 'none'" class="home-v4-card-arrow">›</text>
          </view>
          <view v-else class="home-v4-info-empty">暂无平台公告</view>
        </view>

        <view v-else-if="activeInfoTab === 'messages'" class="home-v4-info-panel">
          <view class="home-v4-message-panel" role="button" aria-label="查看个人消息" @click="openNotifications">
            <view class="home-v4-message-icon">信</view>
            <view class="home-v4-message-copy">
              <text class="home-v4-message-title">{{ auth.isAuthenticated ? '查看个人消息' : '登录后接收个人消息' }}</text>
              <text class="home-v4-message-note">任务进度、订单和资产变动都会在这里提醒</text>
            </view>
            <text class="home-v4-card-arrow">›</text>
          </view>
        </view>

        <view v-else class="home-v4-info-panel">
          <view class="home-v4-message-panel" role="button" aria-label="查看资产动态" @click="openAssets">
            <view class="home-v4-message-icon asset">资</view>
            <view class="home-v4-message-copy">
              <text class="home-v4-message-title">资产动态</text>
              <text class="home-v4-message-note">积分、余额、优惠券和有效期变化统一查询</text>
            </view>
            <text class="home-v4-card-arrow">›</text>
          </view>
        </view>

        <view class="home-v4-priority-grid">
          <view class="home-v4-priority-card asset" role="button" aria-label="打开资产中心" @click="openAssets">
            <view class="home-v4-priority-icon">
              <image v-if="priorityIcon('assets')" :src="priorityIcon('assets')" mode="aspectFit" />
              <view v-else class="home-v4-wallet-art"><view class="home-v4-wallet-clasp" /></view>
            </view>
            <view class="home-v4-priority-copy">
              <text class="home-v4-priority-title">资产中心</text>
              <text class="home-v4-priority-note">登录后查看资产</text>
            </view>
            <text class="home-v4-card-arrow">›</text>
          </view>
          <view class="home-v4-priority-card referral" role="button" aria-label="打开邀请成长" @click="openReferrals">
            <view class="home-v4-priority-icon">
              <image v-if="priorityIcon('referrals')" :src="priorityIcon('referrals')" mode="aspectFit" />
              <view v-else class="home-v4-people-art"><view class="home-v4-people-art-small" /></view>
            </view>
            <view class="home-v4-priority-copy">
              <text class="home-v4-priority-title">邀请成长</text>
              <text class="home-v4-priority-note">邀请好友一起成长</text>
            </view>
            <text class="home-v4-card-arrow">›</text>
          </view>
        </view>
      </view>

      <view class="home-v4-section-head">
        <view>
          <text class="home-v4-section-title">{{ home.modules.recommendation.section_title }}</text>
          <text class="home-v4-section-subtitle">{{ home.modules.recommendation.section_subtitle }}</text>
        </view>
        <text class="home-v4-section-link" role="button" aria-label="查看全部任务" @click="openTasks">查看全部 ›</text>
      </view>

      <view
        class="home-v4-recommend"
        :style="backgroundStyle(home.modules.recommendation.background_url)"
        @click="navigateHomeLink(home.modules.recommendation.link_type)"
      >
        <view class="home-v4-recommend-icon">
          <image
            v-if="home.modules.recommendation.icon_url"
            class="home-v4-recommend-image"
            :src="home.modules.recommendation.icon_url"
            mode="aspectFit"
          />
          <view v-else class="home-v4-recommend-art">
            <view class="home-v4-recommend-board"><view class="home-v4-recommend-lines" /></view>
            <view class="home-v4-recommend-star">★</view>
          </view>
        </view>
        <view class="home-v4-recommend-copy">
          <view class="home-v4-recommend-title-row">
            <text class="home-v4-recommend-title">{{ home.modules.recommendation.title }}</text>
            <text class="home-v4-recommend-tag">{{ home.modules.recommendation.tag }}</text>
          </view>
          <text class="home-v4-recommend-description">{{ home.modules.recommendation.description }}</text>
          <view class="home-v4-recommend-meta">
            <text class="home-v4-recommend-reward">{{ home.modules.recommendation.reward_text }}</text>
            <text class="home-v4-recommend-time">◷ {{ home.modules.recommendation.time_text }}</text>
          </view>
        </view>
        <view class="home-v4-recommend-arrow">›</view>
      </view>

      <view class="home-v4-section-head home-v4-mall-head">
        <view>
          <text class="home-v4-section-title">{{ home.modules.product_section.section_title }}</text>
          <text class="home-v4-section-subtitle">{{ home.modules.product_section.section_subtitle }}</text>
        </view>
        <text class="home-v4-section-link" role="button" aria-label="打开精选商城" @click="openProducts">逛商城 ›</text>
      </view>

      <view class="home-v4-products">
        <view
          v-for="card in home.modules.product_section.cards"
          :key="card.id"
          :class="['home-v4-product', `tone-${card.tone}`]"
          :style="backgroundStyle(card.background_url)"
          @click="navigateHomeLink(card.link_type)"
        >
          <view>
            <text class="home-v4-product-eyebrow">{{ card.eyebrow }}</text>
            <text class="home-v4-product-title">{{ card.title }}</text>
            <text class="home-v4-product-price">{{ card.price_text }}</text>
          </view>
          <image v-if="card.image_url" class="home-v4-product-image" :src="card.image_url" mode="aspectFit" />
          <view v-else class="home-v4-product-fallback">★</view>
        </view>
      </view>

      <view
        class="home-v4-trust"
        :style="backgroundStyle(home.modules.trust.background_url)"
        @click="navigateHomeLink(home.modules.trust.link_type)"
      >
        <view class="home-v4-trust-icon">
          <image v-if="home.modules.trust.icon_url" :src="home.modules.trust.icon_url" mode="aspectFit" />
          <text v-else>✓</text>
        </view>
        <view class="home-v4-trust-copy">
          <text class="home-v4-trust-title">{{ home.modules.trust.title }}</text>
          <text class="home-v4-trust-note">{{ home.modules.trust.description }}</text>
        </view>
        <text class="home-v4-card-arrow">›</text>
      </view>

      <button v-if="!auth.isAuthenticated" class="home-v4-login" @click="openLogin">
        登录后领取任务与奖励
      </button>
    </view>
    </template>

    <view v-if="popupVisible" class="home-v4-popup-mask" role="presentation" @click.self="closePopup">
      <view :class="['home-v4-popup', `mode-${home.popup.mode}`]">
        <view class="home-v4-popup-close" role="button" aria-label="关闭欢迎提示" @click="closePopup">×</view>
        <image v-if="home.popup.image_url" class="home-v4-popup-image" :src="home.popup.image_url" mode="widthFix" />
        <view class="home-v4-popup-body">
          <text class="home-v4-popup-title">{{ home.popup.title }}</text>
          <text v-if="home.popup.content" class="home-v4-popup-content">{{ home.popup.content }}</text>
          <button class="home-v4-popup-button" @click="handlePopupAction">{{ home.popup.button_text }}</button>
          <text class="home-v4-popup-muted" role="button" @click="disablePopup">不再提醒</text>
        </view>
      </view>
    </view>

    <AppTabBar current-target="home" variant="flat" />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { loadHomeContent, type HomeCarouselItem, type HomeContent, type HomeLinkType } from '@/api/home'
import { loadPendingLegalDocuments } from '@/api/legal'
import { openPrimaryNavigation } from '@/api/navigation'
import { listProducts, type MallProduct } from '@/api/products'
import { listHallTasks, listMyTaskClaims, type HallTask, type TaskClaim, type TaskReward } from '@/api/tasks'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { compactRichTextContent } from '@/utils/richText'

type InfoTab = 'announcement' | 'messages' | 'assets'
type RegisteredProductCard = { id: string; name: string; image_url: string; price_text: string; live: boolean }

const fallbackHeroImage = '/static/home/hero-campus-reward.png'
const auth = useAuthStore()
const popupVisible = ref(false)
const homeLoading = ref(false)
const homeLoaded = ref(false)
const homeError = ref('')
const activeHeroIndex = ref(0)
const activeInfoTab = ref<InfoTab>('announcement')
const failedHeroImages = ref<Record<string, boolean>>({})
const hallTasks = ref<HallTask[]>([])
const myClaims = ref<TaskClaim[]>([])
const mallProducts = ref<MallProduct[]>([])
const infoTabs: Array<{ id: InfoTab; label: string }> = [
  { id: 'announcement', label: '公告' },
  { id: 'messages', label: '个人消息' },
  { id: 'assets', label: '资产动态' },
]

function createDefaultHome(): HomeContent {
  return {
    brand: { logo_url: '', logo_text: 'TM', name: '任务商城', caption: 'TASK · REWARD · LIFE' },
    carousel: {
      autoplay: true,
      interval_ms: 4500,
      items: [{
        id: 'mall-flexible-pay',
        kicker: '积分与现金灵活选',
        title: '三种购买方式，价格各端一致',
        description: '积分兑换、现金购买或现金加积分，按商品配置自由选择。',
        button_text: '去积分商城',
        link_type: 'products',
        image_url: '',
      }],
    },
    announcement: {
      enabled: true,
      icon_text: '公',
      icon_url: '',
      title: '平台公告',
      content: '欢迎来到任务商城，完成任务即可领取奖励。',
      link_type: 'notifications',
    },
    popup: {
      // 即使装修接口暂时不可用，也保留游客首页的首次欢迎提示；远端配置仍然优先。
      enabled: true,
      display_once: true,
      content_id: 'default',
      mode: 'announcement',
      title: '欢迎来到任务商城',
      content: '新用户可以先完成新手任务，熟悉流程并领取第一份积分奖励。',
      image_url: '',
      button_text: '我知道了',
      link_type: 'none',
    },
    floating_support: {
      enabled: true,
      show_label: true,
      icon_text: '服',
      icon_url: '',
      label: '客服',
      subtitle: '扫码联系校园客服',
      qr_code_url: '',
      qr_code_tip: '长按识别客服二维码',
      position: 'right',
      bottom_offset_rpx: 180,
    },
    modules: {
      page_background_url: '',
      notification_icon_url: '',
      quick_grid_background_url: '',
      quick_entries: [
        { id: 'tasks', label: '任务大厅', note: '领取奖励', mark: '任', tone: 'green', icon_url: '', link_type: 'tasks' },
        { id: 'products', label: '积分商城', note: '兑换好物', mark: '兑', tone: 'blue', icon_url: '', link_type: 'products' },
        { id: 'community', label: '社区成长', note: '分享交流', mark: '聊', tone: 'orange', icon_url: '', link_type: 'community' },
        { id: 'orders', label: '我的订单', note: '查看进度', mark: '单', tone: 'violet', icon_url: '', link_type: 'orders' },
      ],
      priority_entries: [
        { id: 'assets', mark: '资', icon_url: '' },
        { id: 'referrals', mark: '邀', icon_url: '' },
      ],
      recommendation: {
        section_title: '今日推荐',
        section_subtitle: '从一件简单的小事开始',
        title: '完成新手任务',
        description: '完善资料、熟悉流程，开启你的第一份奖励',
        tag: '简单',
        reward_text: '最高 +300 积分',
        time_text: '约 3 分钟',
        icon_text: '新',
        icon_url: '',
        background_url: '',
        link_type: 'tasks',
      },
      product_section: {
        section_title: '精选兑换',
        section_subtitle: '三种购买方式，价格各端一致',
        cards: [
          { id: 'points-choice', eyebrow: '积分好物', title: '日常精选', price_text: '积分兑换', tone: 'blue', image_url: '', background_url: '', link_type: 'products' },
          { id: 'life-choice', eyebrow: '限时推荐', title: '生活补给', price_text: '现金 / 积分', tone: 'warm', image_url: '', background_url: '', link_type: 'products' },
        ],
      },
      trust: {
        title: '每一笔资产都有记录',
        description: '积分来源、有效期、退款和余额变动清晰可查',
        icon_url: '',
        background_url: '',
        link_type: 'assets',
      },
    },
    versions: {},
  }
}

const home = ref<HomeContent>(createDefaultHome())
const quickEntries = computed(() => home.value.modules.quick_entries.slice(0, 4).map((item) => ({
  ...item,
  action: () => navigateHomeLink(item.link_type),
})))
const pageStyle = computed(() => backgroundStyle(home.value.modules.page_background_url))
const supportShortLabel = computed(() => {
  const value = String(home.value.floating_support.label || '客服').trim()
  if (value.includes('客服')) return '客服'
  return Array.from(value).slice(0, 2).join('') || '客服'
})
const recommendedTask = computed(() => hallTasks.value.find((task) => task.can_claim) || hallTasks.value[0] || null)
const activeClaim = computed(() => myClaims.value.find((claim) => !['approved', 'cancelled', 'expired', 'closed'].includes(claim.status)) || null)
const activeClaimPercent = computed(() => {
  const claim = activeClaim.value
  if (!claim) return 0
  if (typeof claim.progress?.percent === 'number') return Math.max(8, Math.min(100, claim.progress.percent))
  if (['submitted', 'reviewing', 'appealed', 'appeal_reviewing'].includes(claim.status)) return 76
  return Math.max(18, Math.min(62, (claim.submission_count || 0) * 28 + 22))
})
const recommendedRewardText = computed(() => {
  const rewards = recommendedTask.value?.rewards || []
  return rewards.length ? rewards.map(rewardLabel).join(' + ') : home.value.modules.recommendation.reward_text
})
const recommendedTimeText = computed(() => {
  const minutes = recommendedTask.value?.average_completion_minutes
  return minutes && minutes > 0 ? `约 ${Math.max(1, Math.round(minutes))} 分钟` : home.value.modules.recommendation.time_text
})
const recommendedReviewTimeText = computed(() => {
  const hours = recommendedTask.value?.review_deadline_hours
  return hours && hours > 0 ? `${Math.max(1, Math.round(hours))}h` : '24h'
})
const announcementSummary = computed(() => plainText(home.value.announcement.content) || '暂无新的校园公告')
const recommendedDescriptionText = computed(() => plainText(
  recommendedTask.value?.description_text
    || recommendedTask.value?.description
    || home.value.modules.recommendation.description,
))
const activeClaimDescriptionText = computed(() => plainText(
  activeClaim.value?.description_text
    || activeClaim.value?.description
    || activeClaim.value?.execution_status_label
    || '已领取，等待继续完成',
))
const activeClaimRewardText = computed(() => {
  const rewards = activeClaim.value?.rewards || []
  return rewards.length ? rewards.map(rewardLabel).join(' + ') : '任务奖励'
})
const registeredProducts = computed<RegisteredProductCard[]>(() => {
  const live = mallProducts.value.slice(0, 4).map((product) => ({
    id: product.id,
    name: product.name,
    image_url: product.gallery_images?.[0]?.url || '',
    price_text: mallProductPrice(product),
    live: true,
  }))
  if (live.length >= 4) return live
  const fallbacks = home.value.modules.product_section.cards.map((card, index) => ({
    id: `fallback-${card.id || index}`,
    name: card.title,
    image_url: card.image_url || '',
    price_text: card.price_text,
    live: false,
  }))
  const presets: RegisteredProductCard[] = [
    { id: 'fallback-campus-kit', name: '校园成长礼包', image_url: '', price_text: '800 积分', live: false },
    { id: 'fallback-study-card', name: '学习权益卡', image_url: '', price_text: '1,200 积分', live: false },
  ]
  return [...live, ...fallbacks, ...presets].slice(0, 4)
})

onShow(async () => {
  await loadHome()
  if (auth.accessToken) await auth.fetchCurrentUser()
  await loadRegisteredHomeData()
  if (!auth.accessToken) return
  try {
    const pending = await loadPendingLegalDocuments(auth.accessToken, 'account')
    if (pending.length > 0) uni.navigateTo({ url: '/pages/legal-consent/index?next=home' })
  } catch {
    // 服务端仍会执行协议校验，此处仅用于提前提示。
  }
})

async function loadRegisteredHomeData() {
  const [tasksResult, claimsResult, productsResult] = await Promise.allSettled([
    listHallTasks({ claimable_only: false }),
    auth.accessToken ? listMyTaskClaims(auth.accessToken) : Promise.resolve({ items: [] as TaskClaim[] }),
    listProducts(),
  ])
  if (tasksResult.status === 'fulfilled') hallTasks.value = tasksResult.value.items
  if (claimsResult.status === 'fulfilled') myClaims.value = claimsResult.value.items
  if (productsResult.status === 'fulfilled') mallProducts.value = productsResult.value.items
}

async function loadHome() {
  if (homeLoading.value) return
  homeLoading.value = true
  homeError.value = ''
  failedHeroImages.value = {}
  try {
    home.value = normalizeHomeContent(await loadHomeContent())
    const preferredHeroIndex = home.value.carousel.items.findIndex((item) => item.link_type === 'products')
    activeHeroIndex.value = preferredHeroIndex >= 0 ? preferredHeroIndex : 0
    showPopupWhenNeeded()
  } catch {
    // 首页装修接口不可用时保留内置内容，保证首页仍可使用。
    homeError.value = '首页配置暂时不可用'
    showPopupWhenNeeded()
  } finally {
    homeLoaded.value = true
    homeLoading.value = false
  }
}

function normalizeHomeContent(remote: HomeContent): HomeContent {
  const fallback = createDefaultHome()
  const modules = remote?.modules || fallback.modules
  return {
    ...fallback,
    ...remote,
    brand: { ...fallback.brand, ...(remote?.brand || {}) },
    carousel: {
      ...fallback.carousel,
      ...(remote?.carousel || {}),
      items: remote?.carousel?.items?.length ? remote.carousel.items : fallback.carousel.items,
    },
    announcement: { ...fallback.announcement, ...(remote?.announcement || {}) },
    popup: { ...fallback.popup, ...(remote?.popup || {}) },
    floating_support: { ...fallback.floating_support, ...(remote?.floating_support || {}) },
    modules: {
      ...fallback.modules,
      ...modules,
      quick_entries: modules.quick_entries?.length ? modules.quick_entries : fallback.modules.quick_entries,
      priority_entries: modules.priority_entries?.length ? modules.priority_entries : fallback.modules.priority_entries,
      recommendation: { ...fallback.modules.recommendation, ...(modules.recommendation || {}) },
      product_section: {
        ...fallback.modules.product_section,
        ...(modules.product_section || {}),
        cards: modules.product_section?.cards?.length
          ? modules.product_section.cards
          : fallback.modules.product_section.cards,
      },
      trust: { ...fallback.modules.trust, ...(modules.trust || {}) },
    },
    versions: remote?.versions || {},
  }
}

function titleParts(title: string) {
  const raw = String(title || '').trim()
  const match = raw.match(/^(.+?[，,])\s*(.+)$/)
  if (!match) return { primary: raw, secondary: '', accent: '' }

  const accentMatch = match[2].match(/^(.*?)(一致)$/)
  return {
    primary: match[1],
    secondary: accentMatch ? accentMatch[1] : match[2],
    accent: accentMatch ? accentMatch[2] : '',
  }
}

function plainText(value: string | null | undefined) {
  return String(value || '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;|&#160;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/\s+/g, ' ')
    .trim()
}

function quickArtType(id: string, linkType: HomeLinkType) {
  const value = id || linkType
  if (value === 'products') return 'products'
  if (value === 'community') return 'community'
  if (value === 'orders') return 'orders'
  return 'tasks'
}

function quickArtSymbol(id: string, linkType: HomeLinkType) {
  const type = quickArtType(id, linkType)
  if (type === 'products') return '★'
  if (type === 'community') return '•••'
  if (type === 'orders') return '≡'
  return '✓'
}

function heroImageSource(slide: HomeCarouselItem) {
  if (slide.image_url && !failedHeroImages.value[slide.id]) return slide.image_url
  return fallbackHeroImage
}

function handleHeroImageError(slide: HomeCarouselItem) {
  if (!slide.image_url || failedHeroImages.value[slide.id]) return
  failedHeroImages.value = { ...failedHeroImages.value, [slide.id]: true }
}

function handleHeroChange(event: { detail?: { current?: number } }) {
  activeHeroIndex.value = Number(event.detail?.current || 0)
}

function priorityIcon(id: 'assets' | 'referrals') {
  return home.value.modules.priority_entries?.find((entry) => entry.id === id)?.icon_url || ''
}

function rewardLabel(reward: TaskReward) {
  if (reward.type === 'wallet_balance') return `+¥${Number(reward.balance_amount || 0).toFixed(2)}`
  if (reward.type === 'coupon') return `+${reward.quantity || 1}张券`
  return `+${Number(reward.points || 0).toLocaleString()} 积分`
}

function taskDifficultyLabel(task: HallTask | null) {
  if (!task) return home.value.modules.recommendation.tag || '简单'
  if (task.average_completion_minutes && task.average_completion_minutes > 30) return '进阶'
  if (task.steps.length >= 4) return '进阶'
  return '简单'
}

function mallProductPrice(product: MallProduct) {
  const options = product.skus.flatMap((sku) => sku.purchase_options.filter((option) => option.enabled))
  const points = options.filter((option) => option.purchase_mode === 'points').sort((a, b) => a.points - b.points)[0]
  const cash = options.filter((option) => option.purchase_mode === 'cash').sort((a, b) => a.cash_amount - b.cash_amount)[0]
  const mixed = options.filter((option) => option.purchase_mode === 'cash_points').sort((a, b) => a.cash_amount - b.cash_amount)[0]
  if (points) return `${points.points.toLocaleString()} 积分`
  if (cash) return `¥${(cash.cash_amount / 100).toFixed(2)}`
  if (mixed) return `¥${(mixed.cash_amount / 100).toFixed(2)} + 积分`
  return '查看详情'
}

function productImage(product: RegisteredProductCard) { return product.image_url }
function productPriceText(product: RegisteredProductCard) { return product.price_text }
function openRecommendedTask() {
  if (recommendedTask.value) return uni.navigateTo({ url: `/pages/task-detail/index?id=${recommendedTask.value.id}` })
  openTasks()
}
function openActiveClaim() {
  if (activeClaim.value) return uni.navigateTo({ url: `/pages/task-claim/index?id=${activeClaim.value.id}` })
  openTasks()
}
function openProduct(id: string) {
  if (id.startsWith('fallback-')) return openProducts()
  uni.navigateTo({ url: `/pages/product-detail/index?id=${id}` })
}

function openLogin() { navigateToLogin() }
function openTasks() { openPrimaryNavigation('tasks') }
function openProducts() { openPrimaryNavigation('products') }
function openCommunity() { openPrimaryNavigation('community') }
function openOrders() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/orders/index' }) : openLogin() }
function openNotifications() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/notifications/index' }) : openLogin() }
function openAssets() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/assets/index' }) : openLogin() }
function openReferrals() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/referrals/index' }) : openLogin() }
function openFloatingSupport() { uni.navigateTo({ url: '/pages/support/index' }) }

function navigateHomeLink(link: HomeLinkType) {
  if (link === 'tasks') return openTasks()
  if (link === 'products') return openProducts()
  if (link === 'community') return openCommunity()
  if (link === 'notifications') return openNotifications()
  if (link === 'orders') return openOrders()
  if (link === 'assets') return openAssets()
}

function backgroundStyle(url: string) {
  return url ? { backgroundImage: `url(${url})`, backgroundPosition: 'center', backgroundSize: 'cover' } : undefined
}

function popupStorageKey() { return `task_mall_home_popup_seen_${home.value.popup.content_id}` }
function popupMutedStorageKey() { return `task_mall_home_popup_muted_${home.value.popup.content_id}` }
function showPopupWhenNeeded() {
  const popup = home.value.popup
  if (auth.isAuthenticated) {
    popupVisible.value = false
    return
  }
  if (!popup.enabled) return
  if (uni.getStorageSync(popupMutedStorageKey())) return
  if (popup.display_once && uni.getStorageSync(popupStorageKey())) return
  popupVisible.value = true
}
function closePopup() {
  if (home.value.popup.display_once) uni.setStorageSync(popupStorageKey(), true)
  popupVisible.value = false
}
function disablePopup() {
  uni.setStorageSync(popupMutedStorageKey(), true)
  uni.setStorageSync(popupStorageKey(), true)
  popupVisible.value = false
}
function handlePopupAction() {
  const link = home.value.popup.link_type
  closePopup()
  navigateHomeLink(link)
}
</script>

<style scoped>
.home-v4{min-height:100vh;background:radial-gradient(circle at 96% 3%,rgba(69,207,212,.17),transparent 240rpx),linear-gradient(180deg,#fff 0,#fbfffd 58%,#f7fbff 100%);color:#0b253d}
.home-v4-safe{height:calc(env(safe-area-inset-top) + 14rpx)}
.home-v4.is-authenticated{background:#fff;color:#122338}
.home-v4.is-authenticated>.home-v4-header,.home-v4.is-authenticated>.home-v4-state,.home-v4.is-authenticated>.home-v4-content{display:none}
.home-v10-auth-shell{min-height:100vh;padding-bottom:18rpx;background:linear-gradient(180deg,#f7fbff 0,#fff 31%,#fff 100%)}
.home-v10-hero{position:relative;height:390rpx;overflow:hidden;background:#ccecff}
.home-v10-hero-swiper,.home-v10-hero-slide,.home-v10-hero-image,.home-v10-hero-veil{position:absolute;inset:0;width:100%;height:100%}
.home-v10-hero-image{display:block}.home-v10-hero-veil{background:linear-gradient(90deg,rgba(255,255,255,.98) 0%,rgba(255,255,255,.92) 27%,rgba(255,255,255,.3) 55%,rgba(255,255,255,.02) 76%)}
.home-v10-hero-brand{position:absolute;z-index:2;top:31rpx;left:31rpx;display:flex;max-width:370rpx;flex-direction:column}
.home-v10-hero-title{max-width:100%;overflow:hidden;color:#101923!important;font-size:49rpx;font-weight:950;letter-spacing:-1.5rpx;line-height:1.06;text-overflow:ellipsis;text-shadow:0 2rpx 7rpx rgba(255,255,255,.72);white-space:nowrap}
.home-v10-hero-subtitle{margin-top:16rpx;color:#4d6076!important;font-size:25rpx;font-weight:650;letter-spacing:.02em}
.home-v10-hero-actions{position:absolute;z-index:3;top:23rpx;right:21rpx;display:flex;gap:18rpx}
.home-v10-hero-action{position:relative;display:flex;width:69rpx;align-items:center;flex-direction:column;color:#172536}
.home-v10-hero-action>text{margin-top:6rpx;color:#172536!important;font-size:18rpx;font-weight:750;line-height:1}
.home-v10-action-image{width:43rpx;height:43rpx}.home-v10-notice-dot{position:absolute;top:-2rpx;right:9rpx;width:13rpx;height:13rpx;border:3rpx solid #fff;border-radius:50%;background:#ff3e24}
.home-v10-bell{position:relative;width:32rpx;height:35rpx;border:4rpx solid #152235;border-bottom:0;border-radius:18rpx 18rpx 5rpx 5rpx}.home-v10-bell::before{content:'';position:absolute;right:-7rpx;bottom:-5rpx;left:-7rpx;height:4rpx;border-radius:4rpx;background:#152235}.home-v10-bell::after{content:'';position:absolute;bottom:-12rpx;left:9rpx;width:13rpx;height:7rpx;border-radius:0 0 8rpx 8rpx;background:#152235}
.home-v10-headset{position:relative;width:39rpx;height:35rpx;border:4rpx solid #152235;border-bottom-color:transparent;border-radius:50%}.home-v10-headset::before,.home-v10-headset::after{content:'';position:absolute;top:18rpx;width:7rpx;height:14rpx;border-radius:4rpx;background:#152235}.home-v10-headset::before{left:-6rpx}.home-v10-headset::after{right:-6rpx}.home-v10-headset>view{position:absolute;top:10rpx;left:8rpx;color:#152235;font-size:15rpx;font-weight:950;letter-spacing:2rpx}
.home-v10-hero-dots{position:absolute;z-index:3;right:35rpx;bottom:21rpx;display:flex;gap:9rpx}.home-v10-hero-dot{width:10rpx;height:10rpx;border-radius:50%;background:rgba(255,255,255,.78);box-shadow:0 1rpx 5rpx rgba(23,58,67,.2)}.home-v10-hero-dot.active{width:28rpx;border-radius:999rpx;background:#08a96a}
.home-v10-main{position:relative;z-index:4;margin-top:-58rpx;padding:0 27rpx 30rpx}
.home-v10-quick-card{display:grid;gap:0;padding:29rpx 17rpx 26rpx;border:1rpx solid #e3e9ef;border-radius:31rpx;background:rgba(255,255,255,.97);box-shadow:0 16rpx 38rpx rgba(41,73,101,.1);grid-template-columns:repeat(4,1fr)}
.home-v10-quick-item{position:relative;display:flex;min-width:0;align-items:center;flex-direction:column}.home-v10-quick-item+.home-v10-quick-item::before{content:'';position:absolute;top:13rpx;bottom:13rpx;left:0;width:1rpx;background:#edf0f3}
.home-v10-quick-icon{display:flex;width:76rpx;height:76rpx;align-items:center;justify-content:center;border-radius:23rpx;box-shadow:inset 0 0 0 1rpx rgba(255,255,255,.46),0 9rpx 18rpx rgba(31,65,81,.12);color:#fff}
.home-v10-quick-icon.tone-green{background:linear-gradient(145deg,#39d68a,#079e61)}.home-v10-quick-icon.tone-blue{background:linear-gradient(145deg,#73b8ff,#2479e9)}.home-v10-quick-icon.tone-orange{background:linear-gradient(145deg,#ffcb4e,#ff921c)}.home-v10-quick-icon.tone-violet{background:linear-gradient(145deg,#b583ff,#7145e9)}
.home-v10-quick-icon image{width:62rpx;height:62rpx}.home-v10-quick-icon>text{color:#fff!important;font-size:31rpx;font-weight:950;text-shadow:0 2rpx 4rpx rgba(0,0,0,.12)}
.home-v10-quick-label{max-width:100%;margin-top:13rpx;overflow:hidden;color:#142438!important;font-size:22rpx;font-weight:850;text-overflow:ellipsis;white-space:nowrap}
.home-v10-announcement{display:flex;min-height:77rpx;box-sizing:border-box;align-items:center;gap:12rpx;margin-top:20rpx;padding:12rpx 20rpx;border:1rpx solid #dfe7ee;border-radius:22rpx;background:#fff;box-shadow:0 9rpx 22rpx rgba(39,72,98,.055)}
.home-v10-announcement-icon{display:flex;width:45rpx;height:45rpx;flex:none;align-items:center;justify-content:center;border-radius:14rpx;background:#e2f8ec;color:#08a166;font-size:30rpx;font-weight:950}.home-v10-announcement-icon image{width:38rpx;height:38rpx}.home-v10-announcement-label{flex:none;color:#079e61!important;font-size:23rpx;font-weight:950}.home-v10-announcement-content{min-width:0;overflow:hidden;flex:1;color:#657388!important;font-size:20rpx;text-overflow:ellipsis;white-space:nowrap}.home-v10-announcement-arrow{flex:none;color:#53657a!important;font-size:33rpx}
.home-v10-error{margin-top:14rpx;padding:14rpx 18rpx;border-radius:15rpx;background:#fff5df;color:#a56c08;font-size:18rpx;text-align:center}
.home-v10-section-head{display:flex;align-items:center;justify-content:space-between;margin:28rpx 3rpx 13rpx}.home-v10-section-title{color:#132338!important;font-size:30rpx;font-weight:950}.home-v10-section-link{color:#586a80!important;font-size:19rpx;font-weight:700}
.home-v10-task-card{display:flex;align-items:center;gap:16rpx;padding:19rpx;border:1rpx solid #dce7ef;border-radius:25rpx;background:#fff;box-shadow:0 10rpx 26rpx rgba(29,66,99,.055)}
.home-v10-task-empty{display:flex;min-height:112rpx;box-sizing:border-box;align-items:center;gap:16rpx;padding:20rpx;border:1rpx solid #dce7ef;border-radius:25rpx;background:linear-gradient(90deg,#f7fbff,#fff);box-shadow:0 10rpx 26rpx rgba(29,66,99,.045)}.home-v10-task-empty-icon{display:flex;width:68rpx;height:68rpx;flex:none;align-items:center;justify-content:center;border-radius:21rpx;background:#e3f7ee;color:#089961;font-size:27rpx;font-weight:950}.home-v10-task-empty-copy{min-width:0;flex:1}.home-v10-task-empty-copy>text{display:block}.home-v10-task-empty-copy>text:first-child{color:#17283c!important;font-size:22rpx;font-weight:900}.home-v10-task-empty-copy>text:last-child{margin-top:7rpx;color:#6c7c8e!important;font-size:18rpx}.home-v10-task-empty-link{flex:none;color:#078e61!important;font-size:19rpx;font-weight:850}
.home-v10-task-cover{display:flex;width:105rpx;height:105rpx;overflow:hidden;flex:none;align-items:center;justify-content:center;border-radius:24rpx}.home-v10-task-cover.tone-green{background:linear-gradient(145deg,#e1f9ed,#b4edd1)}.home-v10-task-cover.tone-blue{background:linear-gradient(145deg,#e5f3ff,#b8dcff)}.home-v10-task-cover image{width:100%;height:100%}.home-v10-task-fallback{display:flex;width:68rpx;height:68rpx;align-items:center;justify-content:center;border-radius:21rpx;background:linear-gradient(145deg,#35cd83,#079a60);box-shadow:0 8rpx 17rpx rgba(8,154,96,.2);color:#fff;font-size:28rpx;font-weight:950}.tone-blue .home-v10-task-fallback{background:linear-gradient(145deg,#59b7ff,#1f77e2)}
.home-v10-task-copy{min-width:0;flex:1}.home-v10-task-title,.home-v10-task-description,.home-v10-task-meta{display:block}.home-v10-task-title{overflow:hidden;color:#112337!important;font-size:25rpx;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.home-v10-task-description{display:-webkit-box;margin-top:7rpx;overflow:hidden;color:#647388!important;font-size:19rpx;line-height:1.42;-webkit-box-orient:vertical;-webkit-line-clamp:2}.home-v10-task-meta{margin-top:10rpx;overflow:hidden;color:#738095!important;font-size:17rpx;text-overflow:ellipsis;white-space:nowrap}
.home-v10-task-side{display:flex;max-width:155rpx;flex:none;align-items:flex-end;flex-direction:column}.home-v10-task-reward{max-width:155rpx;overflow:hidden;color:#ff4b19!important;font-size:23rpx;font-weight:950;text-align:right;text-overflow:ellipsis;white-space:nowrap}.home-v10-task-button{height:57rpx;margin:14rpx 0 0;padding:0 26rpx;border:0;border-radius:999rpx;background:linear-gradient(110deg,#08aa67,#08c17d);box-shadow:0 9rpx 18rpx rgba(8,169,104,.2);color:#fff;font-size:20rpx;font-weight:900;line-height:57rpx}.home-v10-task-button::after{display:none}
.home-v10-continue-section{overflow:hidden;margin-top:25rpx;border:1rpx solid #bcd9fb;border-radius:28rpx;background:linear-gradient(180deg,#eff7ff,#fff 49%)}.home-v10-continue-head{display:flex;align-items:center;justify-content:space-between;padding:22rpx 25rpx 15rpx}.home-v10-continue-head text:first-child{color:#1458a5!important;font-size:27rpx;font-weight:950}.home-v10-continue-head text:last-child{color:#586d86!important;font-size:19rpx}.home-v10-continue-card{border:0;border-top:1rpx solid #e0ebf7;border-radius:0;background:#fff;box-shadow:none}
.home-v10-mall-section{margin-top:27rpx;padding:21rpx 18rpx 22rpx;border:1rpx solid #f0d69d;border-radius:28rpx;background:linear-gradient(180deg,#fffaf0 0,#fff 54%)}.home-v10-mall-head{display:flex;align-items:center;justify-content:space-between;padding:0 3rpx 16rpx}.home-v10-mall-head text:first-child{color:#8e5b07!important;font-size:28rpx;font-weight:950}.home-v10-mall-head text:last-child{color:#53677f!important;font-size:19rpx}
.home-v10-products{display:grid;gap:12rpx;grid-template-columns:repeat(4,minmax(0,1fr))}.home-v10-product{min-width:0}.home-v10-product-image{display:flex;height:116rpx;overflow:hidden;align-items:center;justify-content:center;border-radius:18rpx;color:#0a8c61;font-size:34rpx;font-weight:950}.home-v10-product-image.tone-0{background:#e8f8ef}.home-v10-product-image.tone-1{background:#fff1db}.home-v10-product-image.tone-2{background:#eaf2ff}.home-v10-product-image.tone-3{background:#edf7f4}.home-v10-product-image image{width:100%;height:100%}.home-v10-product-name,.home-v10-product-price{display:block;overflow:hidden;text-align:center;text-overflow:ellipsis;white-space:nowrap}.home-v10-product-name{margin-top:10rpx;color:#243246!important;font-size:18rpx;font-weight:750}.home-v10-product-price{margin-top:6rpx;color:#0b9a61!important;font-size:17rpx;font-weight:900}.home-v10-product:nth-child(2n) .home-v10-product-price{color:#e56624!important}
.home-v10-trust{display:flex;align-items:center;gap:15rpx;margin-top:25rpx;padding:18rpx 20rpx;border:1rpx solid #dbe8f5;border-radius:22rpx;background:linear-gradient(90deg,#eef6ff,#f8fbff)}.home-v10-trust-shield{display:flex;width:57rpx;height:62rpx;flex:none;align-items:center;justify-content:center;border-radius:17rpx 17rpx 23rpx 23rpx;background:linear-gradient(145deg,#5cb2ff,#2678e6);color:#fff;font-size:27rpx;font-weight:950;clip-path:polygon(50% 0,94% 17%,88% 74%,50% 100%,12% 74%,6% 17%)}.home-v10-trust>view:nth-child(2){min-width:0;flex:1}.home-v10-trust>view:nth-child(2)>text{display:block}.home-v10-trust>view:nth-child(2)>text:first-child{color:#17283c!important;font-size:22rpx;font-weight:950}.home-v10-trust>view:nth-child(2)>text:last-child{margin-top:4rpx;color:#66758a!important;font-size:17rpx}.home-v10-trust>text:last-child{color:#50647b!important;font-size:34rpx}
.home-v4-header{display:flex;align-items:center;justify-content:space-between;padding:18rpx 30rpx 28rpx}
.home-v4-brand,.home-v4-header-actions{display:flex;align-items:center}.home-v4-brand{min-width:0;gap:18rpx}.home-v4-header-actions{flex:none;gap:14rpx}
.home-v4-brand-mark,.home-v4-brand-logo{width:84rpx;height:84rpx;flex:none;border-radius:24rpx}
.home-v4-brand-mark{display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#02a965,#10c68d);box-shadow:0 14rpx 28rpx rgba(7,167,111,.22);color:#fff;font-size:31rpx;font-weight:950}
.home-v4-brand-copy{min-width:0}.home-v4-brand-name,.home-v4-brand-caption{display:block}.home-v4-brand-name{overflow:hidden;color:#081f37;font-size:40rpx;font-weight:950;letter-spacing:.015em;line-height:1.14;text-overflow:ellipsis;white-space:nowrap}.home-v4-brand-caption{margin-top:6rpx;color:#687183;font-size:17rpx;font-weight:800;letter-spacing:.14em}
.home-v4-header-action,.home-v4-support-action{display:flex;align-items:center;justify-content:center;box-sizing:border-box;border:1rpx solid #dce6eb;background:rgba(255,255,255,.9);box-shadow:0 10rpx 24rpx rgba(20,53,73,.08)}
.home-v4-header-action{width:78rpx;height:78rpx;border-radius:27rpx}.home-v4-support-action{position:relative;width:82rpx;min-width:82rpx;height:82rpx;flex-direction:column;padding:5rpx;border-color:#c9e970;border-radius:50%;background:linear-gradient(145deg,#e5ff87,#a9e943);box-shadow:0 12rpx 25rpx rgba(105,167,36,.19)}
.home-v4-header-action-image{width:39rpx;height:39rpx}.home-v4-bell-glyph{position:relative;width:25rpx;height:29rpx;border:4rpx solid #152b3f;border-bottom:0;border-radius:16rpx 16rpx 4rpx 4rpx}.home-v4-bell-glyph::after{content:'';position:absolute;right:-7rpx;bottom:-5rpx;left:-7rpx;height:4rpx;border-radius:4rpx;background:#152b3f}.home-v4-bell-clapper{position:absolute;bottom:-14rpx;left:7rpx;width:10rpx;height:7rpx;border-radius:0 0 9rpx 9rpx;background:#152b3f}
.home-v4-support-image{width:37rpx;height:37rpx}.home-v4-support-glyph{position:relative;width:37rpx;height:32rpx}.home-v4-support-headset{position:absolute;left:3rpx;top:0;width:29rpx;height:25rpx;border:4rpx solid #17211a;border-bottom-color:transparent;border-radius:50%}.home-v4-support-face{position:absolute;left:10rpx;top:10rpx;color:#17211a;font-size:15rpx;font-weight:900;letter-spacing:1rpx}.home-v4-support-label{max-width:58rpx;margin-top:0;overflow:hidden;color:#17211a;font-size:18rpx;font-weight:950;line-height:1;text-overflow:clip;white-space:nowrap}
.home-v4-content{padding:0 26rpx}.home-v4-hero-swiper{height:466rpx;border-radius:36rpx}.home-v4-hero{position:relative;overflow:hidden;height:100%;border-radius:36rpx;background:#c9f8ef;box-shadow:0 22rpx 48rpx rgba(25,110,91,.14)}
.home-v4-hero-image,.home-v4-hero-wash{position:absolute;inset:0;width:100%;height:100%}.home-v4-hero-wash{background:linear-gradient(90deg,rgba(224,255,247,.97) 0%,rgba(224,255,247,.88) 38%,rgba(224,255,247,.28) 58%,transparent 72%)}
.home-v4-hero-copy{position:relative;z-index:2;display:flex;width:58%;height:100%;box-sizing:border-box;flex-direction:column;align-items:flex-start;padding:34rpx 0 31rpx 33rpx}
.home-v4-hero-kicker{display:inline-flex;max-width:100%;padding:8rpx 17rpx;border-radius:999rpx;background:linear-gradient(90deg,#ffe437,#ffcf31);box-shadow:0 7rpx 14rpx rgba(231,170,16,.18);color:#24310d;font-size:19rpx;font-weight:900;line-height:1.2}
.home-v4-hero-title{display:flex;margin-top:19rpx;color:#071c22;font-size:50rpx;font-weight:950;letter-spacing:-.01em;line-height:1.18;flex-direction:column}.home-v4-hero-title-line{display:flex;align-items:baseline;white-space:nowrap}.home-v4-hero-title-accent{color:#07945f}
.home-v4-hero-description{display:-webkit-box;margin-top:15rpx;overflow:hidden;color:#415b64;font-size:22rpx;font-weight:650;line-height:1.55;-webkit-box-orient:vertical;-webkit-line-clamp:3}
.home-v4-hero-button{height:76rpx;margin:auto 0 0;padding:0 34rpx;border:0;border-radius:999rpx;background:linear-gradient(90deg,#08a75f,#08bd78);box-shadow:0 14rpx 27rpx rgba(5,151,99,.24);color:#fff;font-size:25rpx;font-weight:950;line-height:76rpx}.home-v4-hero-button::after{display:none}.home-v4-hero-button-arrow{padding-left:11rpx;font-size:33rpx;line-height:1}
.home-v4-hero-dots{position:absolute;z-index:3;bottom:18rpx;left:50%;display:flex;gap:8rpx;transform:translateX(-50%)}.home-v4-hero-dot{width:12rpx;height:12rpx;border-radius:999rpx;background:rgba(255,255,255,.7);transition:width .2s}.home-v4-hero-dot.active{width:31rpx;background:#08ad71}
.home-v4-quick-card{display:grid;margin-top:27rpx;padding:28rpx 9rpx 27rpx;border:1rpx solid rgba(220,232,232,.9);border-radius:32rpx;background:rgba(255,255,255,.97);box-shadow:0 15rpx 36rpx rgba(31,70,78,.075);grid-template-columns:repeat(4,1fr)}
.home-v4-quick-item{position:relative;display:flex;min-width:0;align-items:center;flex-direction:column;text-align:center}.home-v4-quick-item.with-divider::before{content:'';position:absolute;top:13rpx;bottom:9rpx;left:0;width:1rpx;background:#e5ecec}
.home-v4-quick-icon{display:flex;width:96rpx;height:96rpx;align-items:center;justify-content:center;margin-bottom:13rpx;border-radius:31rpx;background:transparent!important}.home-v4-quick-icon.tone-green,.home-v4-quick-icon.tone-blue,.home-v4-quick-icon.tone-orange,.home-v4-quick-icon.tone-violet{background:transparent!important}
.home-v4-quick-image{width:88rpx;height:88rpx}.home-v4-quick-art{position:relative;width:88rpx;height:88rpx}.home-v4-quick-art-body{position:absolute;inset:8rpx 9rpx 7rpx;display:flex;align-items:center;justify-content:center;border-radius:23rpx;box-shadow:0 9rpx 17rpx rgba(22,62,72,.16)}.home-v4-quick-art-symbol{position:relative;z-index:2;color:#fff;font-size:30rpx;font-weight:950;line-height:1}.home-v4-quick-art.art-tasks .home-v4-quick-art-body{border:3rpx solid #078e5b;background:linear-gradient(145deg,#25c67f,#079d64)}.home-v4-quick-art.art-tasks .home-v4-quick-art-body::before{content:'';position:absolute;top:-9rpx;left:18rpx;width:30rpx;height:17rpx;border:3rpx solid #078e5b;border-radius:8rpx;background:#9df0bf}.home-v4-quick-art.art-tasks .home-v4-quick-art-body::after{content:'';position:absolute;right:-5rpx;bottom:-5rpx;width:28rpx;height:28rpx;border:4rpx solid #fff;border-radius:50%;background:#ffe743}.home-v4-quick-art.art-products .home-v4-quick-art-body{inset:16rpx 4rpx 4rpx;border-radius:14rpx 14rpx 24rpx 24rpx;background:linear-gradient(145deg,#3ab2ff,#0877df)}.home-v4-quick-art.art-products .home-v4-quick-art-body::before{content:'';position:absolute;top:-21rpx;left:18rpx;width:31rpx;height:29rpx;border:7rpx solid #1188eb;border-bottom:0;border-radius:25rpx 25rpx 0 0}.home-v4-quick-art.art-products .home-v4-quick-art-symbol{color:#ffe846;font-size:35rpx;text-shadow:0 2rpx 0 rgba(97,74,0,.14)}.home-v4-quick-art.art-community::before{content:'';position:absolute;top:7rpx;left:2rpx;width:55rpx;height:51rpx;border-radius:50%;background:#ffc55e;box-shadow:0 8rpx 15rpx rgba(204,114,4,.13)}.home-v4-quick-art.art-community .home-v4-quick-art-body{inset:24rpx 0 3rpx 20rpx;border-radius:50%;background:linear-gradient(145deg,#ffab40,#ff721e)}.home-v4-quick-art.art-community .home-v4-quick-art-body::after{content:'';position:absolute;right:7rpx;bottom:-7rpx;border-width:11rpx 4rpx 0 13rpx;border-style:solid;border-color:#ff721e transparent transparent transparent;transform:rotate(8deg)}.home-v4-quick-art.art-community .home-v4-quick-art-symbol{font-size:20rpx;letter-spacing:3rpx}.home-v4-quick-art.art-orders .home-v4-quick-art-body{border:3rpx solid #6941d7;background:linear-gradient(145deg,#9c78ff,#6841dd)}.home-v4-quick-art.art-orders .home-v4-quick-art-body::before{content:'';position:absolute;top:-10rpx;left:19rpx;width:30rpx;height:17rpx;border:3rpx solid #6941d7;border-radius:8rpx;background:#d5c6ff}.home-v4-quick-art.art-orders .home-v4-quick-art-symbol{font-size:40rpx;transform:scaleX(1.25)}.home-v4-quick-label,.home-v4-quick-note{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.home-v4-quick-label{color:#0d2438;font-size:24rpx;font-weight:950}.home-v4-quick-note{margin-top:5rpx;color:#6c7484;font-size:18rpx}
.home-v4-campus-card{margin-top:25rpx;padding:25rpx 22rpx 22rpx;border:1rpx solid #e3ecec;border-radius:31rpx;background:rgba(255,255,255,.96);box-shadow:0 14rpx 35rpx rgba(31,70,78,.065)}
.home-v4-campus-head,.home-v4-section-head{display:flex;align-items:flex-end;justify-content:space-between}.home-v4-campus-kicker,.home-v4-campus-title{display:block}.home-v4-campus-kicker{display:inline-flex;padding:6rpx 13rpx;border-radius:999rpx;background:linear-gradient(90deg,#dfff4e,#b8ed37);color:#183d20;font-size:16rpx;font-weight:900;letter-spacing:.08em}.home-v4-campus-title{margin-top:12rpx;color:#0a2033;font-size:31rpx;font-weight:950}.home-v4-campus-more{padding:8rpx 0;color:#172d3f;font-size:21rpx;font-weight:800}
.home-v4-campus-tabs{display:grid;margin-top:20rpx;overflow:hidden;border:1rpx solid #d9e4e6;border-radius:999rpx;background:#fbfdfd;grid-template-columns:repeat(3,1fr)}.home-v4-campus-tab{position:relative;display:flex;height:64rpx;align-items:center;justify-content:center;color:#707988;font-size:22rpx;font-weight:800}.home-v4-campus-tab+.home-v4-campus-tab::before{content:'';position:absolute;top:15rpx;bottom:15rpx;left:0;width:1rpx;background:#e3e8e8}.home-v4-campus-tab.active{border-radius:999rpx;background:linear-gradient(90deg,#08a864,#08bc78);box-shadow:0 7rpx 16rpx rgba(8,169,104,.18);color:#fff}
.home-v4-info-panel{margin-top:15rpx}.home-v4-announcement,.home-v4-message-panel{display:flex;align-items:center;gap:15rpx;min-height:94rpx;box-sizing:border-box;padding:16rpx 17rpx;border-radius:22rpx}.home-v4-announcement{background:linear-gradient(90deg,#f3ffe6,#fbfff1);border:1rpx solid #e7f1d3}.home-v4-message-panel{border:1rpx solid #e2eaed;background:#f7fbfd}.home-v4-announcement-icon,.home-v4-message-icon{display:flex;width:61rpx;height:61rpx;flex:none;align-items:center;justify-content:center;border-radius:19rpx;background:linear-gradient(145deg,#c8ff61,#8fe850);color:#1f692d;font-size:22rpx;font-weight:950}.home-v4-message-icon{background:#e4f2ff;color:#1780d4}.home-v4-message-icon.asset{background:#e3faf0;color:#078b59}.home-v4-announcement-image{width:49rpx;height:49rpx}.home-v4-announcement-copy,.home-v4-message-copy{min-width:0;flex:1}.home-v4-announcement-title,.home-v4-message-title{display:block;color:#142b3e;font-size:23rpx;font-weight:900}.home-v4-announcement-content,.home-v4-message-note{display:-webkit-box;max-height:56rpx;margin-top:4rpx;overflow:hidden;color:#687584;font-size:19rpx;line-height:1.45;-webkit-box-orient:vertical;-webkit-line-clamp:2}.home-v4-card-arrow{flex:none;color:#637587;font-size:37rpx;font-weight:350}.home-v4-info-empty{padding:29rpx;border-radius:20rpx;background:#f7fafb;color:#8a96a1;font-size:21rpx;text-align:center}
.home-v4-megaphone{position:relative;width:37rpx;height:29rpx;border-radius:8rpx 3rpx 3rpx 8rpx;background:#25b863}.home-v4-megaphone::before{content:'';position:absolute;top:-7rpx;right:-11rpx;border-width:21rpx 0 21rpx 17rpx;border-style:solid;border-color:transparent transparent transparent #25b863}.home-v4-megaphone::after{content:'';position:absolute;top:8rpx;left:-9rpx;width:12rpx;height:14rpx;border-radius:4rpx;background:#fff}.home-v4-megaphone-handle{position:absolute;bottom:-16rpx;left:8rpx;width:11rpx;height:19rpx;border-radius:0 0 7rpx 7rpx;background:#14934a;transform:rotate(-10deg)}
.home-v4-priority-grid{display:grid;gap:13rpx;margin-top:14rpx;grid-template-columns:1fr 1fr}.home-v4-priority-card{display:flex;min-width:0;align-items:center;gap:11rpx;padding:16rpx 14rpx;border:1rpx solid #dfebf0;border-radius:20rpx}.home-v4-priority-card.asset{background:linear-gradient(135deg,#eaf7ff,#f6fbff)}.home-v4-priority-card.referral{background:linear-gradient(135deg,#fff5e8,#fffaf3)}.home-v4-priority-icon{display:flex;width:54rpx;height:54rpx;flex:none;align-items:center;justify-content:center;border-radius:17rpx;background:#ccecff;color:#087dcc;font-size:19rpx;font-weight:950}.home-v4-priority-card.referral .home-v4-priority-icon{background:#ffe2b4;color:#e5730d}.home-v4-priority-icon image{width:43rpx;height:43rpx}.home-v4-priority-copy{min-width:0;flex:1}.home-v4-priority-title,.home-v4-priority-note{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.home-v4-priority-title{color:#132a3e;font-size:22rpx;font-weight:900}.home-v4-priority-note{margin-top:3rpx;color:#6f7a88;font-size:17rpx}
.home-v4-wallet-art{position:relative;width:35rpx;height:28rpx;border-radius:7rpx;background:linear-gradient(145deg,#3eb5ff,#147dde);box-shadow:inset 0 -3rpx 0 rgba(0,70,155,.15)}.home-v4-wallet-art::before{content:'';position:absolute;top:-7rpx;left:5rpx;width:22rpx;height:9rpx;border-radius:6rpx 6rpx 2rpx 2rpx;background:#8ed9ff}.home-v4-wallet-clasp{position:absolute;right:-5rpx;top:8rpx;width:14rpx;height:11rpx;border-radius:6rpx;background:#fff}.home-v4-people-art{position:relative;width:38rpx;height:31rpx}.home-v4-people-art::before,.home-v4-people-art-small::before{content:'';position:absolute;border-radius:50%;background:#ff971e}.home-v4-people-art::before{top:0;left:6rpx;width:16rpx;height:16rpx}.home-v4-people-art::after,.home-v4-people-art-small::after{content:'';position:absolute;border-radius:18rpx 18rpx 7rpx 7rpx;background:#ff971e}.home-v4-people-art::after{left:1rpx;bottom:0;width:27rpx;height:17rpx}.home-v4-people-art-small::before{top:5rpx;right:0;width:13rpx;height:13rpx;background:#ffbd50}.home-v4-people-art-small::after{right:-4rpx;bottom:1rpx;width:21rpx;height:14rpx;background:#ffbd50}
.home-v4-section-head{margin:35rpx 4rpx 16rpx}.home-v4-section-title,.home-v4-section-subtitle{display:block}.home-v4-section-title{position:relative;color:#0b2134;font-size:32rpx;font-weight:950}.home-v4-section-title::after{content:'';position:absolute;bottom:-8rpx;left:0;width:55rpx;height:5rpx;border-radius:999rpx;background:linear-gradient(90deg,#a9e83f,#48cd83)}.home-v4-section-subtitle{margin-top:12rpx;color:#7b8391;font-size:20rpx}.home-v4-section-link{padding:8rpx 0;color:#152c3e;font-size:21rpx;font-weight:850}
.home-v4-recommend{display:flex;align-items:center;gap:18rpx;padding:20rpx;border:1rpx solid #dceae5;border-radius:26rpx;background:linear-gradient(90deg,#effff7,#fcfff7);box-shadow:0 10rpx 28rpx rgba(26,82,60,.06)}.home-v4-recommend-icon{display:flex;width:102rpx;height:102rpx;flex:none;align-items:center;justify-content:center;border-radius:29rpx;background:linear-gradient(145deg,#d8f9e8,#9ce5bb);box-shadow:inset 0 0 0 9rpx rgba(255,255,255,.48),0 8rpx 17rpx rgba(32,121,80,.12);color:#087d52;font-size:30rpx;font-weight:950}.home-v4-recommend-image{width:88rpx;height:88rpx}.home-v4-recommend-art{position:relative;width:82rpx;height:82rpx}.home-v4-recommend-board{position:absolute;inset:8rpx 13rpx 7rpx 7rpx;border:3rpx solid #078f5b;border-radius:13rpx;background:linear-gradient(145deg,#39ce85,#0ca767)}.home-v4-recommend-board::before{content:'';position:absolute;top:-10rpx;left:13rpx;width:26rpx;height:17rpx;border:3rpx solid #078f5b;border-radius:7rpx;background:#a6f1c3}.home-v4-recommend-lines{position:absolute;top:22rpx;left:13rpx;width:27rpx;height:4rpx;border-radius:4rpx;background:#fff;box-shadow:0 12rpx 0 #fff,0 24rpx 0 #fff}.home-v4-recommend-star{position:absolute;right:0;bottom:0;z-index:2;display:flex;width:34rpx;height:34rpx;align-items:center;justify-content:center;border:4rpx solid #fff;border-radius:50%;background:#ffe43d;color:#0b8a54;font-size:21rpx}.home-v4-recommend-copy{min-width:0;flex:1}.home-v4-recommend-title-row{display:flex;align-items:center;gap:10rpx}.home-v4-recommend-title{overflow:hidden;color:#0e2639;font-size:27rpx;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.home-v4-recommend-tag{padding:4rpx 10rpx;border-radius:999rpx;background:#fff235;color:#36400a;font-size:17rpx;font-weight:900}.home-v4-recommend-description{display:block;margin-top:6rpx;overflow:hidden;color:#6b7682;font-size:19rpx;text-overflow:ellipsis;white-space:nowrap}.home-v4-recommend-meta{display:flex;gap:18rpx;margin-top:8rpx}.home-v4-recommend-reward{color:#ff4d21;font-size:21rpx;font-weight:900}.home-v4-recommend-time{color:#68727d;font-size:18rpx}.home-v4-recommend-arrow{display:flex;width:56rpx;height:56rpx;flex:none;align-items:center;justify-content:center;border:1rpx solid #e1e8e6;border-radius:50%;background:#fff;color:#1b3344;font-size:37rpx}
.home-v4-mall-head{margin-top:36rpx}.home-v4-products{display:grid;gap:16rpx;grid-template-columns:1fr 1fr}.home-v4-product{position:relative;display:flex;overflow:hidden;min-height:178rpx;box-sizing:border-box;align-items:flex-start;justify-content:space-between;padding:22rpx;border-radius:25rpx}.home-v4-product.tone-blue{background:linear-gradient(145deg,#eaf6ff,#dcedff)}.home-v4-product.tone-warm{background:linear-gradient(145deg,#fff6e9,#ffe6c3)}.home-v4-product-eyebrow,.home-v4-product-title,.home-v4-product-price{display:block}.home-v4-product-eyebrow{color:#667783;font-size:17rpx}.home-v4-product-title{margin-top:5rpx;color:#132a3d;font-size:25rpx;font-weight:950}.home-v4-product-price{margin-top:8rpx;color:#09845a;font-size:18rpx;font-weight:850}.home-v4-product.tone-warm .home-v4-product-price{color:#d46e11}.home-v4-product-image{position:absolute;right:11rpx;bottom:8rpx;width:88rpx;height:88rpx}.home-v4-product-fallback{position:absolute;right:16rpx;bottom:13rpx;display:flex;width:73rpx;height:73rpx;align-items:center;justify-content:center;border-radius:23rpx;background:rgba(255,255,255,.62);color:#ffb616;font-size:36rpx}
.home-v4-trust{display:flex;align-items:center;gap:15rpx;margin-top:20rpx;padding:20rpx;border:1rpx solid #dbe9ed;border-radius:23rpx;background:#f2f8fb}.home-v4-trust-icon{display:flex;width:58rpx;height:58rpx;flex:none;align-items:center;justify-content:center;border-radius:18rpx;background:#fff;color:#0ba56d;font-size:27rpx;font-weight:950}.home-v4-trust-icon image{width:47rpx;height:47rpx}.home-v4-trust-copy{min-width:0;flex:1}.home-v4-trust-title,.home-v4-trust-note{display:block}.home-v4-trust-title{color:#142a3d;font-size:22rpx;font-weight:900}.home-v4-trust-note{margin-top:3rpx;color:#6b7784;font-size:18rpx;line-height:1.4}.home-v4-login{height:82rpx;margin:22rpx 0 0;border:0;border-radius:999rpx;background:linear-gradient(90deg,#08a663,#09c385);color:#fff;font-size:24rpx;font-weight:900;line-height:82rpx}.home-v4-login::after{display:none}
.home-v4-campus-card,.home-v4-content>.home-v4-section-head,.home-v4-recommend,.home-v4-content>.home-v4-products,.home-v4-content>.home-v4-trust{display:none}
.home-v09-auth{padding-bottom:210rpx}
.home-v09-notice{display:flex;min-height:92rpx;align-items:center;gap:15rpx;margin-top:25rpx;padding:14rpx 17rpx;border:1rpx solid #dbe9df;border-radius:25rpx;background:linear-gradient(90deg,#f5ffea 0%,#fbfff4 100%);box-shadow:0 10rpx 24rpx rgba(43,94,63,.055)}
.home-v09-notice-icon{display:flex;width:61rpx;height:61rpx;flex:none;align-items:center;justify-content:center;border-radius:20rpx;background:linear-gradient(145deg,#dfff70,#a7e849);color:#176d3d;font-size:22rpx;font-weight:950}.home-v09-notice-icon image{width:45rpx;height:45rpx}.home-v09-notice-copy{min-width:0;flex:1}.home-v09-notice-title,.home-v09-notice-content{display:block}.home-v09-notice-title{color:#10293c;font-size:23rpx;font-weight:950}.home-v09-notice-content{display:-webkit-box;margin-top:3rpx;overflow:hidden;color:#667581;font-size:18rpx;line-height:1.45;-webkit-box-orient:vertical;-webkit-line-clamp:1}.home-v09-arrow{flex:none;color:#52677a;font-size:35rpx;line-height:1}
.home-v09-section-head{display:flex;align-items:flex-end;justify-content:space-between;margin:34rpx 3rpx 15rpx}.home-v09-section-title,.home-v09-section-note{display:block}.home-v09-section-title{position:relative;color:#0c2438;font-size:32rpx;font-weight:950;line-height:1.2}.home-v09-section-title::after{content:'';position:absolute;bottom:-8rpx;left:0;width:58rpx;height:5rpx;border-radius:999rpx;background:linear-gradient(90deg,#b3e846,#32c582)}.home-v09-section-note{margin-top:13rpx;color:#7a8492;font-size:20rpx}.home-v09-section-link{padding:8rpx 0;color:#132e42;font-size:21rpx;font-weight:850}
.home-v09-task-card{display:flex;align-items:center;gap:17rpx;padding:19rpx;border:1rpx solid #dbe9e3;border-radius:27rpx;background:linear-gradient(90deg,#effff7,#fbfff5);box-shadow:0 11rpx 26rpx rgba(32,83,61,.06)}.home-v09-task-visual{display:flex;width:104rpx;height:104rpx;overflow:hidden;flex:none;align-items:center;justify-content:center;border-radius:29rpx;background:linear-gradient(145deg,#d7f8e7,#9ee4bd)}.home-v09-task-cover{width:100%;height:100%}.home-v09-task-art{position:relative;width:80rpx;height:80rpx}.home-v09-task-clipboard{position:absolute;inset:7rpx 13rpx 7rpx 6rpx;border:3rpx solid #078e5a;border-radius:13rpx;background:linear-gradient(145deg,#3bd086,#0ba565)}.home-v09-task-clipboard::before{content:'';position:absolute;top:-10rpx;left:14rpx;width:26rpx;height:16rpx;border:3rpx solid #078e5a;border-radius:7rpx;background:#a7efc4}.home-v09-task-lines{position:absolute;top:22rpx;left:13rpx;width:28rpx;height:4rpx;border-radius:4rpx;background:#fff;box-shadow:0 12rpx 0 #fff,0 24rpx 0 #fff}.home-v09-task-art>text{position:absolute;right:0;bottom:0;display:flex;width:34rpx;height:34rpx;align-items:center;justify-content:center;border:4rpx solid #fff;border-radius:50%;background:#ffe83b;color:#087d50;font-size:20rpx}.home-v09-task-copy{min-width:0;flex:1}.home-v09-task-title-row{display:flex;align-items:center;gap:9rpx}.home-v09-task-title{overflow:hidden;color:#10293c;font-size:26rpx;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.home-v09-task-level{flex:none;padding:4rpx 10rpx;border-radius:999rpx;background:#fff235;color:#344109;font-size:16rpx;font-weight:900}.home-v09-task-description{display:-webkit-box;margin-top:6rpx;overflow:hidden;color:#667581;font-size:18rpx;line-height:1.45;-webkit-box-orient:vertical;-webkit-line-clamp:1}.home-v09-task-meta{display:flex;align-items:center;gap:17rpx;margin-top:9rpx}.home-v09-task-reward{color:#ff4a1d;font-size:22rpx;font-weight:950}.home-v09-task-time{color:#697681;font-size:18rpx}.home-v09-circle-arrow{display:flex;width:55rpx;height:55rpx;flex:none;align-items:center;justify-content:center;border:1rpx solid #e0e8e5;border-radius:50%;background:#fff;color:#193447;font-size:36rpx}
.home-v09-progress-card{margin-top:16rpx;padding:18rpx 20rpx;border:1rpx solid #d8e9f3;border-radius:26rpx;background:linear-gradient(100deg,#edf8ff,#f8fdff);box-shadow:0 9rpx 22rpx rgba(37,94,128,.05)}.home-v09-progress-main{display:flex;align-items:center;gap:14rpx}.home-v09-progress-badge{display:flex;width:56rpx;height:56rpx;flex:none;align-items:center;justify-content:center;border-radius:18rpx;background:linear-gradient(145deg,#59b7ff,#187fdc);color:#fff;font-size:21rpx;font-weight:950}.home-v09-progress-copy{min-width:0;flex:1}.home-v09-progress-kicker,.home-v09-progress-title,.home-v09-progress-note{display:block}.home-v09-progress-kicker{color:#1980c4;font-size:16rpx;font-weight:900;letter-spacing:.08em}.home-v09-progress-title{margin-top:2rpx;overflow:hidden;color:#112b3e;font-size:23rpx;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.home-v09-progress-note{margin-top:2rpx;overflow:hidden;color:#71808d;font-size:17rpx;text-overflow:ellipsis;white-space:nowrap}.home-v09-progress-action{flex:none;padding:13rpx 19rpx;border-radius:999rpx;background:#fff;color:#147bbd;font-size:18rpx;font-weight:900}.home-v09-progress-track{height:7rpx;margin-top:14rpx;overflow:hidden;border-radius:999rpx;background:#dceaf2}.home-v09-progress-track>view{height:100%;border-radius:inherit;background:linear-gradient(90deg,#25a9ed,#2ac7b5)}
.home-v09-mall-head{margin-top:35rpx}.home-v09-product-scroll{width:100%}.home-v09-product-row{display:flex;gap:14rpx;padding:1rpx 2rpx 8rpx}.home-v09-product-card{width:205rpx;flex:none;padding:13rpx;border:1rpx solid #e1eaeb;border-radius:23rpx;background:#fff;box-shadow:0 8rpx 20rpx rgba(29,69,78,.055)}.home-v09-product-picture{display:flex;width:100%;height:153rpx;overflow:hidden;align-items:center;justify-content:center;border-radius:17rpx;color:#087d55;font-size:43rpx;font-weight:950}.home-v09-product-picture.tone-0{background:linear-gradient(145deg,#e1fbef,#bcefd4)}.home-v09-product-picture.tone-1{background:linear-gradient(145deg,#fff4d8,#ffe1a5)}.home-v09-product-picture.tone-2{background:linear-gradient(145deg,#e5f3ff,#cfe6ff)}.home-v09-product-picture.tone-3{background:linear-gradient(145deg,#f1eaff,#dfd1ff)}.home-v09-product-picture image{width:100%;height:100%}.home-v09-product-title,.home-v09-product-price{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.home-v09-product-title{margin-top:11rpx;color:#132a3d;font-size:21rpx;font-weight:900}.home-v09-product-price{margin-top:5rpx;color:#0a9360;font-size:19rpx;font-weight:900}
.home-v09-trust{display:flex;align-items:center;gap:14rpx;margin-top:16rpx;padding:18rpx;border:1rpx solid #dce8ed;border-radius:23rpx;background:#f3f8fb}.home-v09-trust-icon{display:flex;width:56rpx;height:56rpx;flex:none;align-items:center;justify-content:center;border-radius:18rpx;background:#fff;color:#0aa36c;font-size:25rpx;font-weight:950}.home-v09-trust-copy{min-width:0;flex:1}.home-v09-trust-copy text{display:block}.home-v09-trust-copy text:first-child{color:#142b3e;font-size:22rpx;font-weight:900}.home-v09-trust-copy text:last-child{margin-top:3rpx;color:#6b7784;font-size:17rpx;line-height:1.4}
.home-v4-popup-mask{position:fixed;z-index:999;inset:0;display:flex;align-items:center;justify-content:center;padding:44rpx;background:rgba(12,31,40,.68)}.home-v4-popup{position:relative;overflow:hidden;width:100%;max-width:620rpx;border-radius:31rpx;background:#fff;box-shadow:0 30rpx 90rpx rgba(0,0,0,.25)}.home-v4-popup-close{position:absolute;z-index:2;top:17rpx;right:17rpx;display:flex;width:53rpx;height:53rpx;align-items:center;justify-content:center;border-radius:50%;background:rgba(8,31,38,.63);color:#fff;font-size:35rpx}.home-v4-popup-image{display:block;width:100%}.home-v4-popup-body{padding:34rpx;text-align:center}.home-v4-popup-title,.home-v4-popup-content{display:block}.home-v4-popup-title{color:#10283a;font-size:32rpx;font-weight:950}.home-v4-popup-content{margin-top:13rpx;color:#6b7682;font-size:22rpx;line-height:1.65}.home-v4-popup-button{height:76rpx;margin-top:24rpx;border:0;border-radius:999rpx;background:linear-gradient(90deg,#08a663,#09c385);color:#fff;font-size:23rpx;font-weight:900;line-height:76rpx}.home-v4-popup-button::after{display:none}
@media screen and (max-width:360px){.home-v4-brand-name{font-size:35rpx}.home-v4-brand-caption{font-size:14rpx}.home-v4-hero-title{font-size:43rpx}.home-v4-hero-copy{width:61%;padding-left:27rpx}.home-v4-hero-description{font-size:19rpx}.home-v4-quick-icon{width:86rpx;height:86rpx}.home-v4-quick-art{transform:scale(.92)}.home-v4-quick-label{font-size:21rpx}.home-v4-quick-note{font-size:16rpx}.home-v4-campus-title{font-size:29rpx}}
@media screen and (min-width:768px){.home-v4{width:750rpx;margin:0 auto;box-shadow:0 0 70rpx rgba(24,62,67,.08)}.home-v4-safe{height:24rpx}}
/* V09 游客首页：欢迎弹层与设计稿保持暖白、湖蓝、校园绿的层次。 */
.home-v4-popup-mask{background:rgba(16,40,58,.48);backdrop-filter:blur(6rpx)}
.home-v4-popup{border:1rpx solid rgba(217,231,237,.95);border-radius:32rpx;box-shadow:0 28rpx 70rpx rgba(23,63,82,.25)}
.home-v4-popup-close{top:18rpx;right:18rpx;background:#edf1f2;color:#536474;font-size:34rpx}
.home-v4-popup-body{padding:44rpx 38rpx 36rpx}
.home-v4-popup-title{font-size:34rpx}
.home-v4-popup-content{color:#5f7184;font-size:23rpx;line-height:1.65}
.home-v4-popup-button{height:82rpx;margin-top:26rpx;background:linear-gradient(110deg,#08ac69,#0ac383);box-shadow:0 12rpx 26rpx rgba(7,170,105,.2);font-size:26rpx;line-height:82rpx}
.home-v4-popup-muted{display:block;margin-top:20rpx;color:#71818c;font-size:20rpx;line-height:1.4;text-align:center;text-decoration:underline;text-underline-offset:4rpx}
.home-v4-state{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin:0 26rpx 18rpx;padding:18rpx 20rpx;border:1rpx solid #d9e9e6;border-radius:20rpx;background:#fff;color:#315366;font-size:20rpx;box-shadow:0 10rpx 24rpx rgba(30,75,82,.06)}
.home-v4-state-loading{justify-content:center;min-height:78rpx;color:#5c7380}
.home-v4-state-spinner{width:28rpx;height:28rpx;border:4rpx solid #cdece1;border-top-color:#08ab6a;border-radius:50%;animation:home-v4-spin .9s linear infinite}
.home-v4-state-title,.home-v4-state-copy{display:block}.home-v4-state-title{color:#17394c;font-weight:850}.home-v4-state-copy{margin-top:4rpx;color:#768893;font-size:17rpx}
.home-v4-state-action{height:54rpx;flex:none;margin:0;padding:0 18rpx;border:1rpx solid #08a96a;border-radius:14rpx;background:#ecfbf4;color:#078a59;font-size:18rpx;line-height:52rpx}.home-v4-state-action::after{border:0}
@keyframes home-v4-spin{to{transform:rotate(360deg)}}
</style>
