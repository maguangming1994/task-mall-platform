<template>
  <view v-if="visibleSections.length" class="deco" :style="pageStyle">
    <view v-if="page.title" class="deco-page-title">{{ page.title }}</view>
    <view v-for="section in visibleSections" :key="section.id" :style="sectionStyle(section)">
      <view v-if="section.type === 'banner'" class="deco-banner-wrap">
        <swiper
          class="deco-banner"
          :style="{ height: `${numberValue(section.props.height_rpx, 280)}rpx`, borderRadius: `${numberValue(section.props.radius_rpx, 24)}rpx` }"
          :autoplay="boolValue(section.props.autoplay, true)"
          :interval="numberValue(section.props.interval_ms, 4500)"
          :duration="numberValue(section.props.duration_ms, 300)"
          :current="bannerCurrent(section.id)"
          circular
          @change="onBannerChange(section.id, $event)"
        >
          <swiper-item v-for="(item, index) in bannerItems(section)" :key="index">
            <view class="deco-banner-item" @click="navigate(stringValue(item.link_type))">
              <image v-if="stringValue(item.image_url)" :src="assetUrl(stringValue(item.image_url))" mode="aspectFill" class="deco-banner-img" />
              <view v-else class="deco-banner-img deco-placeholder">轮播图</view>
              <view v-if="section.props.show_title !== false" class="deco-banner-mask">
                <text v-if="stringValue(item.title)" class="deco-banner-title">{{ stringValue(item.title) }}</text>
                <text v-if="stringValue(item.subtitle)" class="deco-banner-sub">{{ stringValue(item.subtitle) }}</text>
                <text v-if="stringValue(item.button_text)" class="deco-banner-btn">{{ stringValue(item.button_text) }}</text>
              </view>
            </view>
          </swiper-item>
        </swiper>
        <view
          v-if="stringValue(section.props.indicator) !== 'none'"
          class="deco-banner-indicator"
          :class="'pos-' + (stringValue(section.props.indicator_position) || 'bottom-right')"
        >
          <text v-if="stringValue(section.props.indicator) === 'numbers'" class="deco-banner-indicator-num">{{ bannerCurrent(section.id) + 1 }}/{{ Math.max(1, bannerItems(section).length) }}</text>
          <view
            v-else
            v-for="(item, index) in bannerItems(section)"
            :key="index"
            class="deco-banner-dot"
            :class="{ 'is-active': index === bannerCurrent(section.id), 'is-bar': stringValue(section.props.indicator) === 'bars' }"
          />
        </view>
      </view>

      <view v-else-if="section.type === 'nav_bar'" class="deco-nav" :class="{ 'is-border': section.props.show_border === true }" @click="navigate(stringValue(section.props.link_type))">
        <view class="deco-nav-main">
          <text class="deco-nav-title">{{ stringValue(section.props.title) || '页面标题' }}</text>
          <text v-if="stringValue(section.props.subtitle)" class="deco-nav-sub">{{ stringValue(section.props.subtitle) }}</text>
        </view>
        <text v-if="stringValue(section.props.link_type) && stringValue(section.props.link_type) !== 'none'" class="deco-nav-more">›</text>
      </view>

      <view v-else-if="section.type === 'search_box'" class="deco-search" @click="navigate(stringValue(section.props.link_type) || 'products')">
        <text class="deco-search-ico">⌕</text>
        <text class="deco-search-ph">{{ stringValue(section.props.placeholder) || '搜索任务、商品或帖子' }}</text>
      </view>

      <view v-else-if="section.type === 'announcement'" class="deco-announce" @click="navigate(stringValue(section.props.link_type))">
        <view class="deco-announce-icon" :style="{ width: `${numberValue(section.props.icon_size_rpx, 48)}rpx`, height: `${numberValue(section.props.icon_size_rpx, 48)}rpx` }">
          <image v-if="stringValue(section.props.icon_url)" :src="assetUrl(stringValue(section.props.icon_url))" mode="aspectFit" class="deco-announce-icon-img" />
          <text v-else>{{ stringValue(section.props.icon_text) || '公' }}</text>
        </view>
        <view class="deco-announce-main">
          <text class="deco-announce-title" :style="{ fontSize: `${numberValue(section.props.title_size_rpx, 28)}rpx` }">{{ stringValue(section.props.title) || '平台公告' }}</text>
          <view class="deco-announce-view">
            <view
              v-if="announcementNodes(section)"
              class="deco-announce-viewport"
            >
              <text
                v-if="section.props.scroll !== false"
                class="deco-announce-marquee"
                :style="{ color: stringValue(section.props.text_color) || '#8A6A1F', animationDuration: `${14 - numberValue(section.props.speed, 3) * 2}s` }"
              >{{ announcementNodes(section) }}　　{{ announcementNodes(section) }}</text>
              <text
                v-else
                class="deco-announce-content"
                :style="{ color: stringValue(section.props.text_color) || '#8A6A1F' }"
              >{{ announcementNodes(section) }}</text>
            </view>
          </view>
        </view>
        <text v-if="section.props.show_arrow !== false" class="deco-announce-arrow">›</text>
      </view>

      <view v-else-if="section.type === 'quick_grid'" class="deco-grid" :style="{ rowGap: `${numberValue(section.props.row_gap_rpx, 20)}rpx` }">
        <view
          v-for="(item, index) in gridEntries(section)"
          :key="index"
          class="deco-grid-item"
          :style="{ width: `${100 / numberValue(section.props.columns, 4)}%` }"
          @click="openCategory(item)"
        >
          <view
            class="deco-grid-icon-wrap"
            :style="{ width: `${numberValue(section.props.icon_size_rpx, 84)}rpx`, height: `${numberValue(section.props.icon_size_rpx, 84)}rpx` }"
          >
            <image
              v-if="stringValue(item.icon_url)"
              :src="assetUrl(stringValue(item.icon_url))"
              mode="aspectFit"
              class="deco-grid-icon"
              :style="{ borderRadius: stringValue(section.props.icon_shape) === 'circle' ? '50%' : '24rpx' }"
            />
            <view
              v-else
              class="deco-grid-icon deco-grid-mark"
              :style="gridMarkStyle(item)"
            >{{ stringValue(item.mark) || stringValue(item.label).slice(0, 1) }}</view>
            <view v-if="stringValue(item.badge_type) === 'dot'" class="deco-grid-badge-dot" />
            <text v-else-if="stringValue(item.badge_type) === 'text'" class="deco-grid-badge-text">{{ stringValue(item.badge_text) || '新' }}</text>
            <image
              v-else-if="stringValue(item.badge_type) === 'image' && stringValue(item.badge_image)"
              :src="assetUrl(stringValue(item.badge_image))"
              mode="aspectFit"
              class="deco-grid-badge-img"
            />
          </view>
          <text
            class="deco-grid-label"
            :style="{ fontSize: `${numberValue(section.props.label_size_rpx, 24)}rpx`, color: stringValue(section.props.label_color) || '#3C4961' }"
          >{{ stringValue(item.label) }}</text>
          <text
            v-if="stringValue(item.note)"
            class="deco-grid-note"
            :style="{ fontSize: `${Math.max(18, numberValue(section.props.label_size_rpx, 24) - 6)}rpx` }"
          >{{ stringValue(item.note) }}</text>
        </view>
      </view>

      <view
        v-else-if="section.type === 'image_banner'"
        class="deco-image-banner"
        :style="{ height: `${numberValue(section.props.height_rpx, 200)}rpx` }"
        @click="navigate(stringValue(section.props.link_type))"
      >
        <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFill" class="deco-image-banner-img" />
        <view v-else class="deco-image-banner-img deco-placeholder">横幅图片</view>
      </view>

      <view
        v-else-if="section.type === 'image_text'"
        class="deco-image-text"
        :class="{ 'is-right': stringValue(section.props.layout) === 'right' }"
        @click="navigate(stringValue(section.props.link_type))"
      >
        <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFill" class="deco-image-text-img" />
        <view v-else class="deco-image-text-img deco-placeholder">图片</view>
        <view class="deco-image-text-main">
          <text class="deco-image-text-title">{{ stringValue(section.props.title) }}</text>
          <text v-if="stringValue(section.props.description)" class="deco-image-text-desc">{{ stringValue(section.props.description) }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'category_nav'" class="deco-cats">
        <scroll-view scroll-x class="deco-cats-scroll">
          <view class="deco-cats-inner">
            <view
              v-for="(item, index) in listValue(section.props.items)"
              :key="index"
              class="deco-cat"
              :class="{ 'is-first': index === 0 }"
              @click="openCategory(item)"
            >{{ stringValue(item.label) || '分类' }}</view>
          </view>
        </scroll-view>
      </view>

      <view v-else-if="section.type === 'coupon'" class="deco-coupons">
        <text class="deco-coupons-title">{{ stringValue(section.props.title) || '优惠券' }}</text>
        <scroll-view scroll-x class="deco-coupons-scroll">
          <view class="deco-coupons-inner">
            <template v-if="stringValue(section.props.source) === 'center'">
              <view v-for="coupon in couponItems.slice(0, numberValue(section.props.limit, 3))" :key="coupon.id" class="deco-coupon">
                <text class="deco-coupon-value">{{ couponValueText(coupon) }}</text>
                <text class="deco-coupon-title">{{ coupon.name }}</text>
                <text class="deco-coupon-cond">{{ couponConditionText(coupon) }}</text>
                <text
                  class="deco-coupon-btn"
                  :class="{ 'is-disabled': coupon.claimed || coupon.remaining <= 0 }"
                  @click.stop="claimCouponItem(coupon)"
                >{{ coupon.claimed ? '已领取' : (coupon.remaining <= 0 ? '已抢光' : '立即领取') }}</text>
              </view>
              <view v-if="!couponItems.length" class="deco-coupon">
                <text class="deco-coupon-title">暂无可领取的优惠券</text>
              </view>
            </template>
            <template v-else>
              <view v-for="(item, index) in listValue(section.props.items)" :key="index" class="deco-coupon" @click="navigate(stringValue(item.link_type))">
                <text class="deco-coupon-value">{{ stringValue(item.value_text) || '¥10' }}</text>
                <text class="deco-coupon-title">{{ stringValue(item.title) || '优惠券' }}</text>
                <text class="deco-coupon-cond">{{ stringValue(item.condition) }}</text>
                <text class="deco-coupon-btn">{{ stringValue(item.button_text) || '立即领取' }}</text>
              </view>
            </template>
          </view>
        </scroll-view>
      </view>

      <view v-else-if="section.type === 'product_grid'" class="deco-list">
        <view class="deco-list-head">
          <text class="deco-list-title">{{ stringValue(section.props.title) || '精选商品' }}</text>
          <text class="deco-list-more" @click="navigate('products')">查看全部 ›</text>
        </view>
        <view class="deco-products" :style="{ gridTemplateColumns: `repeat(${numberValue(section.props.columns, 2)}, minmax(0,1fr))` }">
          <view v-for="product in productsFor(section).slice(0, numberValue(section.props.limit, 6))" :key="product.id" class="deco-product" @click="openProduct(product.id)">
            <image v-if="productCover(product)" :src="productCover(product)" mode="aspectFill" class="deco-product-img" />
            <view v-else class="deco-product-img deco-placeholder">{{ product.name.slice(0, 1) }}</view>
            <text class="deco-product-name">{{ product.name }}</text>
            <text v-if="section.props.show_subtitle !== false && product.subtitle" class="deco-product-sub">{{ product.subtitle }}</text>
            <view class="deco-product-meta">
              <text v-if="section.props.show_price !== false" class="deco-product-price">{{ productPriceText(product, stringValue(section.props.price_mode)) }}</text>
              <text v-if="section.props.show_category === true && product.category" class="deco-product-tag">{{ product.category.name }}</text>
            </view>
          </view>
        </view>
      </view>

      <view v-else-if="section.type === 'post_list'" class="deco-list">
        <view class="deco-list-head">
          <text class="deco-list-title">{{ stringValue(section.props.title) || '社区热帖' }}</text>
          <text class="deco-list-more" @click="navigate('community')">进入社区 ›</text>
        </view>
        <view
          v-for="post in postsFor(section).slice(0, numberValue(section.props.limit, 6))"
          :key="post.id"
          class="deco-post"
          :class="{ 'is-card': stringValue(section.props.layout) !== 'list' }"
          @click="openPost(post.id)"
        >
          <template v-if="section.props.show_avatar !== false">
            <image v-if="post.author?.avatar_url" :src="assetUrl(post.author.avatar_url)" mode="aspectFill" class="deco-post-avatar" />
            <view v-else class="deco-post-avatar deco-post-avatar-mark">{{ (post.author?.nickname || '同').slice(0, 1) }}</view>
          </template>
          <view class="deco-post-main">
            <text class="deco-post-title">{{ post.title }}</text>
            <text v-if="section.props.show_excerpt === true" class="deco-post-excerpt">{{ postExcerpt(post) }}</text>
            <text v-if="section.props.show_stats !== false" class="deco-post-meta">{{ post.author?.nickname || '同学' }} · {{ post.comment_count }} 条评论</text>
          </view>
        </view>
      </view>

      <view v-else-if="section.type === 'task_list'" class="deco-list">
        <view class="deco-list-head">
          <text class="deco-list-title">{{ stringValue(section.props.title) || '推荐任务' }}</text>
          <text class="deco-list-more" @click="navigate('tasks')">去任务大厅 ›</text>
        </view>
        <view
          v-for="task in tasksFor(section).slice(0, numberValue(section.props.limit, 5))"
          :key="`deco-task-${task.id}`"
          class="deco-task-card"
          @click="openTask(task.id)"
        >
          <view class="deco-task-head">
            <image v-if="task.publisher?.avatar_url" class="deco-task-avatar" :src="assetUrl(task.publisher.avatar_url)" mode="aspectFill" />
            <view v-else class="deco-task-avatar deco-task-avatar-fallback">{{ (task.publisher?.display_name || '同').slice(0, 1) }}</view>
            <view class="deco-task-head-main">
              <view class="deco-task-name-row">
                <text class="deco-task-name">{{ task.publisher?.display_name || '同学' }}</text>
                <text v-if="task.publisher?.publisher_badge" class="deco-task-badge">{{ task.publisher.publisher_badge }}</text>
              </view>
              <text class="deco-task-meta">{{ task.published_at ? String(task.published_at).slice(0, 10) : '' }}</text>
            </view>
          </view>
          <text class="deco-task-body">{{ task.description_text || task.description || task.title }}</text>
          <view class="deco-task-tags">
            <text v-if="task.bounty" class="deco-task-tag deco-task-tag-red">悬赏</text>
            <text v-if="task.urgent" class="deco-task-tag deco-task-tag-orange">加急</text>
            <text v-for="tag in (task.tags || []).slice(0, 3)" :key="tag" class="deco-task-tag deco-task-tag-gray">{{ tag }}</text>
          </view>
          <view v-if="task.gallery?.length" class="deco-task-gallery" :class="`g-${Math.min(task.gallery.length, 3)}`">
            <image v-for="(img, gi) in task.gallery.slice(0, 3)" :key="gi" class="deco-task-gallery-img" :src="assetUrl(img)" mode="aspectFill" />
          </view>
          <view class="deco-task-foot">
            <text class="deco-task-pts">{{ taskRewardText(task) }}</text>
            <text v-if="task.location_text" class="deco-task-loc">@ {{ task.location_text }}</text>
            <button class="deco-task-btn" :class="{ 'deco-task-btn--disabled': !task.can_claim }" :disabled="!task.can_claim" @click.stop="openTask(task.id)">{{ task.can_claim ? '去接单' : '暂不可领' }}</button>
          </view>
        </view>
        <view v-if="!tasksFor(section).length" class="deco-task-empty">暂无任务</view>
      </view>

      <view v-else-if="section.type === 'rich_text'" class="deco-rich">
        <rich-text :nodes="stringValue(section.props.html)" />
      </view>

      <view v-else-if="section.type === 'spacer'" :style="{ height: `${numberValue(section.props.height_rpx, 24)}rpx` }" />

      <view v-else-if="section.type === 'page_title'" class="deco-title" :class="{ 'is-center': stringValue(section.props.align) === 'center' }">
        <text class="deco-title-main">{{ stringValue(section.props.title) || '页面标题' }}</text>
        <text v-if="stringValue(section.props.subtitle)" class="deco-title-sub">{{ stringValue(section.props.subtitle) }}</text>
      </view>

      <view v-else-if="section.type === 'list_nav'" class="deco-listnav">
        <view v-for="(item, index) in listValue(section.props.items)" :key="index" class="deco-listnav-item" :class="{ 'is-first': index === 0 }" @click="navigate(stringValue(item.link_type))">
          <image v-if="stringValue(item.icon_url)" :src="assetUrl(stringValue(item.icon_url))" mode="aspectFill" class="deco-listnav-icon" />
          <view v-else class="deco-listnav-icon deco-listnav-mark">{{ stringValue(item.title).slice(0, 1) || '入' }}</view>
          <view class="deco-listnav-main">
            <text class="deco-listnav-title">{{ stringValue(item.title) || '入口' }}</text>
            <text v-if="stringValue(item.subtitle)" class="deco-listnav-sub">{{ stringValue(item.subtitle) }}</text>
          </view>
          <text class="deco-listnav-arrow">›</text>
        </view>
      </view>

      <view v-else-if="section.type === 'tag_group'" class="deco-tags">
        <text v-if="stringValue(section.props.title)" class="deco-tags-title">{{ stringValue(section.props.title) }}</text>
        <view class="deco-tags-wrap">
          <text v-for="(item, index) in listValue(section.props.items)" :key="index" class="deco-tag" @click="navigate(stringValue(item.link_type))">{{ stringValue(item.label) || '标签' }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'image_group'" class="deco-images" :style="{ gridTemplateColumns: `repeat(${numberValue(section.props.columns, 2)}, minmax(0,1fr))` }">
        <template v-for="(item, index) in listValue(section.props.items)" :key="index">
          <image
            v-if="stringValue(item.image_url)"
            :src="assetUrl(stringValue(item.image_url))"
            mode="aspectFill"
            class="deco-images-item"
            @click="navigate(stringValue(item.link_type))"
          />
          <view v-else class="deco-images-item deco-placeholder" @click="navigate(stringValue(item.link_type))">图片</view>
        </template>
      </view>

      <view v-else-if="section.type === 'button_group'" class="deco-buttons">
        <view
          v-for="(item, index) in listValue(section.props.items)"
          :key="index"
          class="deco-btn"
          :class="{ 'is-primary': index === 0 }"
          @click="navigate(stringValue(item.link_type))"
        >{{ stringValue(item.label) || '立即查看' }}</view>
      </view>

      <view v-else-if="section.type === 'divider'" class="deco-divider" :style="{ borderTopStyle: stringValue(section.props.line_style) === 'dashed' ? 'dashed' : 'solid', borderTopColor: stringValue(section.props.color) || '#E7EBF2' }" />

      <view v-else-if="section.type === 'activity_group'" class="deco-activities">
        <text class="deco-activities-title">{{ stringValue(section.props.title) || '校园活动' }}</text>
        <scroll-view scroll-x class="deco-activities-scroll">
          <view class="deco-activities-inner">
            <view v-for="(item, index) in listValue(section.props.items)" :key="index" class="deco-activity" @click="navigate(stringValue(item.link_type))">
              <image v-if="stringValue(item.image_url)" :src="assetUrl(stringValue(item.image_url))" mode="aspectFill" class="deco-activity-img" />
              <view v-else class="deco-activity-img deco-placeholder">活动</view>
              <text class="deco-activity-title">{{ stringValue(item.title) || '活动' }}</text>
              <text v-if="stringValue(item.subtitle)" class="deco-activity-sub">{{ stringValue(item.subtitle) }}</text>
            </view>
          </view>
        </scroll-view>
      </view>

      <view v-else-if="section.type === 'member_card'" class="deco-member" @click="navigate(stringValue(section.props.link_type))">
        <view class="deco-member-main">
          <text class="deco-member-title">{{ stringValue(section.props.title) || '会员中心' }}</text>
          <text v-if="stringValue(section.props.description)" class="deco-member-desc">{{ stringValue(section.props.description) }}</text>
        </view>
        <text class="deco-member-btn">{{ stringValue(section.props.button_text) || '去开通' }}</text>
      </view>

      <view v-else-if="section.type === 'cart_entry'" class="deco-cart" @click="openCart">
        <view class="deco-cart-main">
          <text class="deco-cart-title">{{ stringValue(section.props.title) || '购物车' }}</text>
          <text v-if="stringValue(section.props.description)" class="deco-cart-desc">{{ stringValue(section.props.description) }}</text>
        </view>
        <text class="deco-cart-btn">{{ stringValue(section.props.button_text) || '去结算' }}</text>
      </view>

      <view v-else-if="section.type === 'qr_code'" class="deco-qr">
        <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFit" class="deco-qr-img" />
        <view v-else class="deco-qr-img deco-placeholder">二维码</view>
        <view class="deco-qr-main">
          <text class="deco-qr-title">{{ stringValue(section.props.title) || '扫码加入' }}</text>
          <text v-if="stringValue(section.props.description)" class="deco-qr-desc">{{ stringValue(section.props.description) }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'location_search'" class="deco-loc">
        <view class="deco-loc-campus" @click="navigate('tasks')">
          <text class="deco-loc-pin">⌖</text>
          <text>{{ stringValue(section.props.campus_text) || '当前校区' }}</text>
        </view>
        <view class="deco-loc-search" @click="navigate(stringValue(section.props.link_type) || 'products')">
          <text class="deco-search-ico">⌕</text>
          <text class="deco-search-ph">{{ stringValue(section.props.placeholder) || '搜索任务、商品或帖子' }}</text>
        </view>
      </view>

      <view
        v-else-if="section.type === 'order_marquee'"
        class="deco-marquee"
        :style="{ background: stringValue(section.props.bg_color) || '#FFF8E8', opacity: numberValue(section.props.opacity, 100) / 100 }"
        @click="navigate(stringValue(section.props.link_type))"
      >
        <text class="deco-marquee-icon">{{ stringValue(section.props.icon_text) || '动' }}</text>
        <view class="deco-marquee-view">
          <view class="deco-marquee-inner" :style="{ animationDuration: `${16 - numberValue(section.props.speed, 3) * 2}s` }">
            <text class="deco-marquee-text" :style="{ color: stringValue(section.props.text_color) || '#8A6A1F' }">{{ marqueeText(section) }}</text>
          </view>
        </view>
      </view>

      <video
        v-else-if="section.type === 'video' && stringValue(section.props.video_url)"
        class="deco-video"
        :src="assetUrl(stringValue(section.props.video_url))"
        :poster="assetUrl(stringValue(section.props.poster_url))"
        :style="videoStyle(section)"
        controls
        object-fit="cover"
      />

      <view v-else-if="section.type === 'tab_nav'" class="deco-tabsnav" :class="{ 'is-sticky': section.props.sticky === true }">
        <view
          v-for="(item, index) in listValue(section.props.items)"
          :key="index"
          class="deco-tabsnav-item"
          :class="{ 'is-first': index === 0 }"
          :style="index === 0
            ? { background: stringValue(section.props.active_color) || '#0BBF63', color: '#fff' }
            : { background: stringValue(section.props.background_color) || '#F2F4F8' }"
          @click="navigate(stringValue(item.link_type))"
        >{{ stringValue(item.label) || '选项' }}</view>
      </view>

      <view v-else-if="section.type === 'ad_banner'" class="deco-ad" :style="{ height: `${numberValue(section.props.height_rpx, 160)}rpx` }" @click="navigate(stringValue(section.props.link_type))">
        <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFill" class="deco-ad-img" />
        <view v-else class="deco-ad-img deco-placeholder">广告位</view>
        <text v-if="section.props.show_badge !== false" class="deco-ad-badge">广告</text>
      </view>

      <view v-else-if="section.type === 'image_showcase'" class="deco-showcase" :class="{ 'is-right': stringValue(section.props.layout) === 'right' }">
        <view
          v-for="(item, index) in listValue(section.props.items).slice(0, 3)"
          :key="index"
          class="deco-showcase-item"
          :class="index === 0 ? 'is-big' : 'is-small'"
          @click="navigate(stringValue(item.link_type))"
        >
          <image v-if="stringValue(item.image_url)" :src="assetUrl(stringValue(item.image_url))" mode="aspectFill" class="deco-showcase-img" />
          <view v-else class="deco-showcase-img deco-placeholder">图</view>
          <text v-if="stringValue(item.title)" class="deco-showcase-label">{{ stringValue(item.title) }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'notice_bar'" class="deco-notice" :class="'tone-' + (stringValue(section.props.tone) || 'warning')" @click="navigate(stringValue(section.props.link_type))">
        <text v-if="section.props.show_icon !== false" class="deco-notice-icon">!</text>
        <view class="deco-notice-main">
          <text class="deco-notice-title" :style="{ fontSize: `${numberValue(section.props.title_size_rpx, 26)}rpx` }">{{ stringValue(section.props.title) || '温馨提示' }}</text>
          <text
            v-if="stringValue(section.props.content)"
            class="deco-notice-content"
            :style="{ fontSize: `${numberValue(section.props.content_size_rpx, 22)}rpx` }"
          >{{ stringValue(section.props.content) }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'hot_tags'" class="deco-tags">
        <text class="deco-tags-title">{{ stringValue(section.props.title) || '热门标签' }}</text>
        <view class="deco-tags-wrap">
          <view v-for="(item, index) in listValue(section.props.items)" :key="index" class="deco-hot-tag" @click="navigate(stringValue(item.link_type))">
            <text>{{ stringValue(item.label) || '标签' }}</text>
            <text v-if="item.hot === true" class="deco-hot-badge">HOT</text>
          </view>
        </view>
      </view>

      <view v-else-if="section.type === 'title_bar'" class="deco-titlebar" @click="navigate(stringValue(section.props.link_type))">
        <view class="deco-titlebar-main">
          <text
            class="deco-titlebar-title"
            :style="{ fontSize: `${numberValue(section.props.title_size_rpx, 32)}rpx`, color: stringValue(section.props.title_color) || '#1F2937' }"
          >{{ stringValue(section.props.title) || '标题' }}</text>
          <text v-if="stringValue(section.props.subtitle)" class="deco-titlebar-sub">{{ stringValue(section.props.subtitle) }}</text>
        </view>
        <text class="deco-titlebar-more" :style="{ color: stringValue(section.props.more_color) || '#0BBF63' }">{{ stringValue(section.props.more_text) || '更多' }} ›</text>
      </view>

      <view v-else-if="section.type === 'tile_ad'" class="deco-tiles" :class="'layout-' + (stringValue(section.props.layout) || 'big-left')" :style="{ gap: `${numberValue(section.props.gap_rpx, 8)}rpx` }">
        <view
          v-for="(item, index) in listValue(section.props.items).slice(0, 3)"
          :key="index"
          class="deco-tile"
          :style="tileStyle(section, index)"
          @click="navigate(stringValue(item.link_type))"
        >
          <image v-if="stringValue(item.image_url)" :src="assetUrl(stringValue(item.image_url))" mode="aspectFill" class="deco-tile-img" />
          <view class="deco-tile-mask">
            <text v-if="stringValue(item.tag)" class="deco-tile-tag" :style="{ background: stringValue(section.props.tag_bg) || '#0BBF63', color: stringValue(section.props.tag_color) || '#FFFFFF' }">{{ stringValue(item.tag) }}</text>
            <text class="deco-tile-title" :style="{ fontSize: `${numberValue(section.props.title_size_rpx, 30)}rpx`, color: stringValue(section.props.title_color) || '#FFFFFF' }">{{ stringValue(item.title) }}</text>
            <text v-if="stringValue(item.subtitle)" class="deco-tile-sub" :style="{ fontSize: `${numberValue(section.props.subtitle_size_rpx, 22)}rpx`, color: stringValue(section.props.subtitle_color) || '#F2F4F8' }">{{ stringValue(item.subtitle) }}</text>
          </view>
        </view>
      </view>

      <view v-else-if="section.type === 'popup' && popupVisible[section.id]" class="deco-popup-mask" @click="section.props.closable !== false ? closePopup(section) : null">
        <view class="deco-popup" :style="{ width: `${numberValue(section.props.width_rpx, 560)}rpx`, borderRadius: `${numberValue(section.props.radius_rpx, 20)}rpx` }" @click.stop>
          <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFill" class="deco-popup-img" />
          <view class="deco-popup-body">
            <text class="deco-popup-title">{{ stringValue(section.props.title) || '新人礼包' }}</text>
            <text v-if="stringValue(section.props.content)" class="deco-popup-content">{{ stringValue(section.props.content) }}</text>
            <view class="deco-popup-btn" @click="popupAction(section)">{{ stringValue(section.props.button_text) || '立即查看' }}</view>
          </view>
          <text v-if="section.props.closable !== false" class="deco-popup-close" @click="closePopup(section)">×</text>
        </view>
      </view>

      <view v-else-if="section.type === 'brand_card'" class="deco-brand" @click="navigate(stringValue(section.props.link_type))">
        <image v-if="stringValue(section.props.logo_url)" :src="assetUrl(stringValue(section.props.logo_url))" mode="aspectFit" class="deco-brand-logo-img" />
        <text v-else class="deco-brand-logo">{{ stringValue(section.props.logo_text) || 'TM' }}</text>
        <view class="deco-brand-main">
          <text class="deco-brand-name">{{ stringValue(section.props.name) || '任务商城' }}</text>
          <text v-if="stringValue(section.props.caption)" class="deco-brand-caption">{{ stringValue(section.props.caption) }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'point_card'" class="deco-point" @click="navigate(stringValue(section.props.link_type) || 'assets')">
        <view class="deco-point-top">
          <view class="deco-point-left">
            <text class="deco-point-label">{{ stringValue(section.props.points_label) || '我的积分' }} ›</text>
            <text class="deco-point-num">
              {{ pointAccount?.available_points?.toLocaleString() || '0' }}
              <text v-if="auth.isAuthenticated" class="deco-point-tip">今日已赚 +{{ pointAccount?.points_earned_today ?? 0 }}</text>
            </text>
          </view>
          <text class="deco-point-btn" :class="{ signed: checkedInToday }">
            {{ checkedInToday ? '今日已签' : (stringValue(section.props.sign_button_text) || '每日签到') }}
          </text>
        </view>
      </view>

      <view v-else-if="section.type === 'recommendation_card'" class="deco-reco" @click="navigate(stringValue(section.props.link_type))">
        <text v-if="stringValue(section.props.section_title)" class="deco-reco-section-title">{{ stringValue(section.props.section_title) }}</text>
        <text v-if="stringValue(section.props.section_subtitle)" class="deco-reco-section-subtitle">{{ stringValue(section.props.section_subtitle) }}</text>
        <view class="deco-reco-card">
          <image v-if="stringValue(section.props.background_url)" :src="assetUrl(stringValue(section.props.background_url))" mode="aspectFill" class="deco-reco-bg" />
          <image v-if="stringValue(section.props.icon_url)" :src="assetUrl(stringValue(section.props.icon_url))" mode="aspectFit" class="deco-reco-icon-img" />
          <text v-else-if="stringValue(section.props.icon_text)" class="deco-reco-icon">{{ stringValue(section.props.icon_text) }}</text>
          <view class="deco-reco-main">
            <view class="deco-reco-title-row">
              <text class="deco-reco-title">{{ stringValue(section.props.title) }}</text>
              <text v-if="stringValue(section.props.tag)" class="deco-reco-tag">{{ stringValue(section.props.tag) }}</text>
            </view>
            <text class="deco-reco-desc">{{ stringValue(section.props.description) }}</text>
            <view class="deco-reco-meta">
              <text v-if="stringValue(section.props.reward_text)" class="deco-reco-reward">{{ stringValue(section.props.reward_text) }}</text>
              <text v-if="stringValue(section.props.time_text)" class="deco-reco-time">{{ stringValue(section.props.time_text) }}</text>
            </view>
          </view>
        </view>
      </view>

      <view v-else-if="section.type === 'product_section'" class="deco-section">
        <view class="deco-section-head">
          <text class="deco-section-title">{{ stringValue(section.props.section_title) || '精选兑换' }}</text>
          <text v-if="stringValue(section.props.section_subtitle)" class="deco-section-subtitle">{{ stringValue(section.props.section_subtitle) }}</text>
        </view>
        <view class="deco-section-cards">
          <view
            v-for="(card, index) in (Array.isArray(section.props.cards) ? section.props.cards : [])"
            :key="`ps-${index}`"
            class="deco-section-card"
            :class="`tone-${card.tone || 'blue'}`"
            @click="navigate(stringValue(card.link_type))"
          >
            <image v-if="stringValue(card.image_url)" :src="assetUrl(stringValue(card.image_url))" mode="aspectFill" class="deco-section-card-img" />
            <text v-if="stringValue(card.eyebrow)" class="deco-section-card-eyebrow">{{ stringValue(card.eyebrow) }}</text>
            <text class="deco-section-card-title">{{ stringValue(card.title) }}</text>
            <text class="deco-section-card-price">{{ stringValue(card.price_text) }}</text>
          </view>
        </view>
      </view>

      <view v-else-if="section.type === 'trust_card'" class="deco-trust" @click="navigate(stringValue(section.props.link_type))">
        <image v-if="stringValue(section.props.background_url)" :src="assetUrl(stringValue(section.props.background_url))" mode="aspectFill" class="deco-trust-bg" />
        <image v-if="stringValue(section.props.icon_url)" :src="assetUrl(stringValue(section.props.icon_url))" mode="aspectFit" class="deco-trust-icon" />
        <view class="deco-trust-main">
          <text class="deco-trust-title">{{ stringValue(section.props.title) || '每一笔资产都有记录' }}</text>
          <text class="deco-trust-desc">{{ stringValue(section.props.description) }}</text>
        </view>
        <text class="deco-trust-arrow">›</text>
      </view>

      <view v-else-if="section.type === 'floating_box'" class="deco-float" :style="{ bottom: `${numberValue(section.props.bottom_rpx, 180)}rpx` }" @click="navigate(stringValue(section.props.link_type))">
        <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFill" class="deco-float-img" />
        <text v-else>{{ stringValue(section.props.icon_text) || '客' }}</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { resolveApiAssetUrl } from '@/api/http'
import { listProductCategories, listProducts, type MallProduct, type ProductCategory } from '@/api/products'
import { listCommunityPosts, type CommunityCategory, type CommunityPost } from '@/api/community'
import { listHallTasks, type HallTask } from '@/api/tasks'
import { claimCoupon, listCouponCenter, getPointAccount, type CouponCenterItem } from '@/api/assets'
import { getDailyCheckIn } from '@/api/growth'
import { listNotifications, type AppNotification } from '@/api/notifications'
import { loadHomeContent, type HomeCarouselItem } from '@/api/home'
import type { DecorationPageConfig, DecorationSection } from '@/api/decoration'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const KNOWN_TYPES = ['banner', 'announcement', 'quick_grid', 'image_banner', 'image_text', 'product_grid', 'post_list', 'task_list', 'rich_text', 'spacer', 'nav_bar', 'search_box', 'category_nav', 'coupon', 'floating_box', 'page_title', 'list_nav', 'tag_group', 'image_group', 'button_group', 'divider', 'activity_group', 'member_card', 'cart_entry', 'qr_code', 'location_search', 'order_marquee', 'video', 'tab_nav', 'ad_banner', 'image_showcase', 'notice_bar', 'hot_tags', 'title_bar', 'tile_ad', 'popup', 'brand_card', 'point_card', 'recommendation_card', 'product_section', 'trust_card']

let CURRENT_CHANNEL: 'h5' | 'miniapp' = 'h5'
// #ifdef MP-WEIXIN
CURRENT_CHANNEL = 'miniapp'
// #endif

const props = defineProps<{ page: DecorationPageConfig }>()
const emit = defineEmits<{ (event: 'navigate', link: string): void }>()

const auth = useAuthStore()
const pointAccount = ref<{ available_points: number; points_earned_today: number } | null>(null)
const checkedInToday = ref(false)
const needsPointCard = computed(() => visibleSections.value.some((section) => section.type === 'point_card'))
async function loadPointAccount() {
  if (!auth.accessToken) return
  try {
    const [account, checkIn] = await Promise.allSettled([getPointAccount(auth.accessToken), getDailyCheckIn(auth.accessToken)])
    if (account.status === 'fulfilled') {
      pointAccount.value = {
        available_points: account.value.available_points,
        points_earned_today: account.value.points_earned_today ?? 0,
      }
    }
    if (checkIn.status === 'fulfilled') checkedInToday.value = checkIn.value.checked_in_today ?? false
  } catch { /* 未登录或接口异常时保持 0 */ }
}
const productsByCategory = ref<Record<string, MallProduct[]>>({})
const postsByCategory = ref<Record<string, CommunityPost[]>>({})
const tasksByCategory = ref<Record<string, HallTask[]>>({})
const couponItems = ref<CouponCenterItem[]>([])
const productCategoriesList = ref<ProductCategory[]>([])
const platformSlides = ref<HomeCarouselItem[]>([])
const systemNotices = ref<AppNotification[]>([])
const popupVisible = ref<Record<string, boolean>>({})
const bannerIndex = ref<Record<string, number>>({})
function bannerCurrent(id: string): number {
  return bannerIndex.value[id] ?? 0
}
function onBannerChange(id: string, event: { detail?: { current?: number } }) {
  bannerIndex.value = { ...bannerIndex.value, [id]: Number(event.detail?.current || 0) }
}
const claimingCouponId = ref<string | null>(null)
const loadingProducts = new Set<string>()
const loadingPosts = new Set<string>()
const loadingTasks = new Set<string>()

function parseDateTime(value: string): number {
  if (!value) return 0
  const normalized = value.length === 16 ? `${value}:00` : value
  const timestamp = Date.parse(normalized)
  return Number.isNaN(timestamp) ? 0 : timestamp
}

function withinWindow(section: DecorationSection): boolean {
  const style = section.style
  if (!style) return true
  const now = Date.now()
  const start = parseDateTime(style.display_start)
  const end = parseDateTime(style.display_end)
  if (start && now < start) return false
  if (end && now > end) return false
  return true
}

function withinDailyWindow(section: DecorationSection): boolean {
  const style = section.style
  if (!style) return true
  const start = (style.display_daily_start ?? '').trim()
  const end = (style.display_daily_end ?? '').trim()
  if (!start && !end) return true
  const toMinutes = (value: string): number | null => {
    const [hour, minute] = value.split(':').map(Number)
    if (!Number.isFinite(hour) || !Number.isFinite(minute)) return null
    return hour * 60 + minute
  }
  const now = new Date()
  const current = now.getHours() * 60 + now.getMinutes()
  const from = start ? toMinutes(start) : 0
  const to = end ? toMinutes(end) : 24 * 60 - 1
  if (from === null || to === null) return true
  return from <= to ? current >= from && current <= to : current >= from || current <= to
}

const visibleSections = computed(() => props.page.sections.filter((section) => {
  if (section.enabled === false || !KNOWN_TYPES.includes(section.type)) return false
  const channels = section.style?.channels
  if (channels?.length && !channels.includes(CURRENT_CHANNEL)) return false
  if (!withinDailyWindow(section)) return false
  return withinWindow(section)
}))

const pageStyle = computed(() => {
  const style: Record<string, string> = {}
  if (props.page.background_color) style.backgroundColor = props.page.background_color
  if (props.page.background_url) {
    style.backgroundImage = `url(${resolveApiAssetUrl(props.page.background_url)})`
    style.backgroundSize = 'cover'
    style.backgroundPosition = 'center'
  }
  if (props.page.background_color || props.page.background_url) {
    style.borderRadius = '24rpx'
    style.padding = '16rpx'
  }
  return style
})

function sectionStyle(section: DecorationSection): Record<string, string> {
  const style = section.style
  if (!style) return {}
  const result: Record<string, string> = {}
  const rpx = (value: number) => `${value}rpx`
  if (style.margin.top) result.marginTop = rpx(style.margin.top)
  if (style.margin.right) result.marginRight = rpx(style.margin.right)
  if (style.margin.bottom) result.marginBottom = rpx(style.margin.bottom)
  if (style.margin.left) result.marginLeft = rpx(style.margin.left)
  if (style.padding.top) result.paddingTop = rpx(style.padding.top)
  if (style.padding.right) result.paddingRight = rpx(style.padding.right)
  if (style.padding.bottom) result.paddingBottom = rpx(style.padding.bottom)
  if (style.padding.left) result.paddingLeft = rpx(style.padding.left)
  if (style.corner_radius && style.radius_corners) {
    result.borderTopLeftRadius = rpx(style.radius_corners.top_left)
    result.borderTopRightRadius = rpx(style.radius_corners.top_right)
    result.borderBottomLeftRadius = rpx(style.radius_corners.bottom_left)
    result.borderBottomRightRadius = rpx(style.radius_corners.bottom_right)
  } else if (style.radius) {
    result.borderRadius = rpx(style.radius)
  }
  if (typeof style.opacity === 'number' && style.opacity < 100) result.opacity = `${Math.max(20, style.opacity) / 100}`
  if (style.border) {
    result.border = `1rpx solid ${style.border_color || '#E7EBF2'}`
  }
  if (style.shadow) result.boxShadow = '0 12rpx 32rpx rgba(15,25,20,.10)'
  if (style.background_type === 'color' && style.background_color) result.backgroundColor = style.background_color
  if (style.background_type === 'image' && style.background_image) {
    result.backgroundImage = `url(${resolveApiAssetUrl(style.background_image)})`
    result.backgroundSize = 'cover'
    result.backgroundPosition = 'center'
  }
  if (style.background_type === 'gradient' && style.background_gradient) result.backgroundImage = style.background_gradient
  return result
}

function videoStyle(section: DecorationSection): Record<string, string> {
  const ratio = stringValue(section.props.ratio)
  if (ratio === '16:9') return { width: '100%', height: '388rpx' }
  if (ratio === '4:3') return { width: '100%', height: '517rpx' }
  if (ratio === '1:1') return { width: '100%', height: '690rpx' }
  return { height: `${numberValue(section.props.height_rpx, 220)}rpx` }
}

function postExcerpt(post: CommunityPost): string {
  const source = post.latest_version?.content || post.content || ''
  return source.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 60)
}

function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function bannerItems(section: DecorationSection): Record<string, unknown>[] {
  if (stringValue(section.props.data_source) === 'platform' && platformSlides.value.length) {
    const limit = numberValue(section.props.limit, 5)
    return platformSlides.value.slice(0, limit).map((slide) => ({
      image_url: slide.image_url,
      title: slide.kicker || slide.title,
      subtitle: slide.description,
      button_text: slide.button_text,
      link_type: slide.link_type,
    }))
  }
  return listValue(section.props.items)
}

function announcementNodes(section: DecorationSection): string {
  if (stringValue(section.props.data_source) === 'system' && systemNotices.value.length) {
    const limit = Math.min(numberValue(section.props.limit, 3), systemNotices.value.length)
    return escapeHtml(systemNotices.value.slice(0, limit).map((notice) => `${notice.title}：${notice.body}`).join('　·　'))
  }
  return stringValue(section.props.content)
}

function gridEntries(section: DecorationSection): Record<string, unknown>[] {
  if (stringValue(section.props.data_source) === 'product_category' && productCategoriesList.value.length) {
    const limit = numberValue(section.props.limit, 8)
    return productCategoriesList.value.slice(0, limit).map((category) => ({
      label: category.name,
      mark: category.name.slice(0, 1),
      icon_url: '',
      enabled: true,
      category_id: category.id,
      link_type: 'products',
      badge_type: 'none',
    }))
  }
  return listValue(section.props.items).filter((entry) => entry.enabled !== false)
}

const GRID_TONE_MAP: Record<string, { bg: string; color: string }> = {
  green: { bg: '#E7F9EF', color: '#0BBF63' },
  blue: { bg: '#EAF2FF', color: '#3B82F6' },
  orange: { bg: '#FFF2E5', color: '#F2620A' },
  violet: { bg: '#F1EAFF', color: '#8B5CF6' },
  pink: { bg: '#FFEAF1', color: '#F43F7F' },
  cyan: { bg: '#E3F7F5', color: '#12A59B' },
  yellow: { bg: '#FFF6DC', color: '#D99400' },
  gray: { bg: '#F0F2F5', color: '#8A919C' },
  warm: { bg: '#FFF6DE', color: '#D99400' },
}
function gridMarkStyle(item: Record<string, unknown>): Record<string, string> {
  const tone = stringValue(item.tone) || 'green'
  const style = GRID_TONE_MAP[tone] || GRID_TONE_MAP.green
  return { background: style.bg, color: style.color }
}

function tileStyle(section: DecorationSection, index: number): Record<string, string> {
  const layout = stringValue(section.props.layout) || 'big-left'
  const radius = `${numberValue(section.props.radius_rpx, 12)}rpx`
  if (layout === 'three') return { borderRadius: radius, height: '200rpx' }
  if (index === 0) return { borderRadius: radius, gridRow: '1 / span 2', gridColumn: layout === 'big-right' ? '2' : '1' }
  return { borderRadius: radius, gridColumn: layout === 'big-right' ? '1' : '2', height: '160rpx' }
}

const needsProductCategories = computed(() => visibleSections.value.some((section) => section.type === 'quick_grid' && stringValue(section.props.data_source) === 'product_category'))
const needsSystemNotices = computed(() => visibleSections.value.some((section) => section.type === 'announcement' && stringValue(section.props.data_source) === 'system'))
const needsPlatformSlides = computed(() => visibleSections.value.some((section) => section.type === 'banner' && stringValue(section.props.data_source) === 'platform'))

async function loadPlatformSlides() {
  if (platformSlides.value.length) return
  try {
    const content = await loadHomeContent()
    platformSlides.value = content.carousel.items
  } catch {
    platformSlides.value = []
  }
}

async function loadProductCategories() {
  if (productCategoriesList.value.length) return
  try {
    const result = await listProductCategories()
    productCategoriesList.value = result.items
  } catch {
    productCategoriesList.value = []
  }
}

async function loadSystemNotices() {
  if (!auth.accessToken || systemNotices.value.length) return
  try {
    const result = await listNotifications(auth.accessToken, { category: 'system', page_size: 10 })
    systemNotices.value = result.items
  } catch {
    systemNotices.value = []
  }
}

function popupStorageKey(section: DecorationSection): string {
  return `decoration_popup_${section.id}`
}

function shouldShowPopup(section: DecorationSection): boolean {
  const frequency = stringValue(section.props.frequency) || 'daily'
  if (frequency === 'every') return true
  const stored = uni.getStorageSync(popupStorageKey(section))
  if (!stored) return true
  if (frequency === 'daily') return stored !== new Date().toISOString().slice(0, 10)
  return false
}

function initPopups() {
  const next: Record<string, boolean> = {}
  visibleSections.value.forEach((section) => {
    if (section.type === 'popup') next[section.id] = shouldShowPopup(section)
  })
  popupVisible.value = next
}

function closePopup(section: DecorationSection) {
  popupVisible.value = { ...popupVisible.value, [section.id]: false }
  const frequency = stringValue(section.props.frequency) || 'daily'
  if (frequency === 'once') uni.setStorageSync(popupStorageKey(section), '1')
  if (frequency === 'daily') uni.setStorageSync(popupStorageKey(section), new Date().toISOString().slice(0, 10))
}

function popupAction(section: DecorationSection) {
  closePopup(section)
  navigate(stringValue(section.props.link_type))
}

const productCategories = computed(() => Array.from(new Set(visibleSections.value.filter((section) => section.type === 'product_grid').map((section) => stringValue(section.props.category_id)))))
const postCategories = computed(() => Array.from(new Set(visibleSections.value.filter((section) => section.type === 'post_list').map((section) => stringValue(section.props.category_code)))))
const taskCategories = computed(() => Array.from(new Set(visibleSections.value.filter((section) => section.type === 'task_list').map((section) => stringValue(section.props.category)))))

async function loadProductsFor(category: string) {
  if (category in productsByCategory.value || loadingProducts.has(category)) return
  loadingProducts.add(category)
  try {
    const result = await listProducts(category || undefined)
    productsByCategory.value = { ...productsByCategory.value, [category]: result.items.slice(0, 20) }
  } catch {
    loadingProducts.delete(category)
  }
}
async function loadPostsFor(category: string) {
  if (category in postsByCategory.value || loadingPosts.has(category)) return
  loadingPosts.add(category)
  try {
    const result = await listCommunityPosts(1, 20, (category || undefined) as CommunityCategory['code'] | undefined)
    postsByCategory.value = { ...postsByCategory.value, [category]: result.items }
  } catch {
    loadingPosts.delete(category)
  }
}
async function loadTasksFor(category: string) {
  if (category in tasksByCategory.value || loadingTasks.has(category)) return
  loadingTasks.add(category)
  try {
    const result = await listHallTasks({ category: category || undefined })
    tasksByCategory.value = { ...tasksByCategory.value, [category]: result.items.slice(0, 10) }
  } catch {
    loadingTasks.delete(category)
  }
}

function productsFor(section: DecorationSection): MallProduct[] {
  return productsByCategory.value[stringValue(section.props.category_id)] ?? []
}
function postsFor(section: DecorationSection): CommunityPost[] {
  return postsByCategory.value[stringValue(section.props.category_code)] ?? []
}
function tasksFor(section: DecorationSection): HallTask[] {
  return tasksByCategory.value[stringValue(section.props.category)] ?? []
}
function taskRewardText(task: HallTask): string {
  const rewards = task.rewards || []
  if (!rewards.length) return '+0 积分'
  return rewards.map((reward) => {
    if (reward.type === 'wallet_balance') return `+¥${Number(reward.balance_amount || 0).toFixed(2)}`
    if (reward.type === 'coupon') return `+${reward.quantity || 1}张券`
    return `+${Number(reward.points || 0).toLocaleString()} 积分`
  }).join(' + ')
}

const needsCouponCenter = computed(() => visibleSections.value.some((section) => section.type === 'coupon' && stringValue(section.props.source) === 'center'))

async function loadCouponCenter() {
  if (!auth.accessToken) return
  try {
    const result = await listCouponCenter(auth.accessToken)
    couponItems.value = result.items
  } catch {
    couponItems.value = []
  }
}

watch(() => productCategories.value.join('\u0001'), () => { productCategories.value.forEach((category) => { void loadProductsFor(category) }) }, { immediate: true })
watch(() => postCategories.value.join('\u0001'), () => { postCategories.value.forEach((category) => { void loadPostsFor(category) }) }, { immediate: true })
watch(() => taskCategories.value.join('\u0001'), () => { taskCategories.value.forEach((category) => { void loadTasksFor(category) }) }, { immediate: true })
watch(needsCouponCenter, (value) => { if (value) void loadCouponCenter() }, { immediate: true })
watch(() => auth.accessToken, (token) => { if (token && needsCouponCenter.value) void loadCouponCenter() })
watch(() => [needsPointCard.value, auth.accessToken] as const, ([needs, token]) => { if (needs && token) void loadPointAccount() }, { immediate: true })
watch(needsProductCategories, (value) => { if (value) void loadProductCategories() }, { immediate: true })
watch(needsPlatformSlides, (value) => { if (value) void loadPlatformSlides() }, { immediate: true })
watch(() => [needsSystemNotices.value, auth.accessToken] as const, ([needs, token]) => { if (needs && token) void loadSystemNotices() }, { immediate: true })
watch(() => visibleSections.value.map((section) => section.id).join('|'), initPopups, { immediate: true })
onMounted(initPopups)

function stringValue(value: unknown): string {
  return typeof value === 'string' ? value : ''
}
function numberValue(value: unknown, fallback: number): number {
  return typeof value === 'number' ? value : fallback
}
function boolValue(value: unknown, fallback: boolean): boolean {
  return typeof value === 'boolean' ? value : fallback
}
function listValue(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? value as Record<string, unknown>[] : []
}
function marqueeText(section: DecorationSection): string {
  const texts = listValue(section.props.items)
    .map((item) => stringValue(item.text))
    .filter((text) => text !== '')
  return texts.length ? `${texts.join('　·　')}　·　` : '暂无动态'
}
function assetUrl(url: string): string {
  return resolveApiAssetUrl(url)
}
function productCover(product: MallProduct): string {
  const image = product.gallery_images?.[0]?.url
  return image ? resolveApiAssetUrl(image) : ''
}
function navigate(link: string) {
  if (!link || link === 'none') return
  emit('navigate', link)
}
function openProduct(id: string) { uni.navigateTo({ url: `/pages/product-detail/index?id=${encodeURIComponent(id)}` }) }
function openCart() { uni.navigateTo({ url: '/pages/cart/index' }) }
function openCategory(item: Record<string, unknown>) {
  const extType = stringValue(item.link_ext_type)
  if (extType === 'miniprogram') {
    const miniAppId = stringValue(item.mini_app_id)
    if (!miniAppId) {
      uni.showToast({ title: '未配置目标小程序 AppID', icon: 'none' })
      return
    }
    uni.navigateToMiniProgram({
      appId: miniAppId,
      path: stringValue(item.mini_path) || undefined,
      fail: () => uni.showToast({ title: '跳转失败，请确认已关联该小程序', icon: 'none' }),
    })
    return
  }
  if (extType === 'h5') {
    const h5Url = stringValue(item.h5_url)
    if (!h5Url) {
      uni.showToast({ title: '未配置 H5 链接', icon: 'none' })
      return
    }
    uni.navigateTo({ url: `/pages/webview/index?url=${encodeURIComponent(h5Url)}` })
    return
  }
  const categoryId = stringValue(item.category_id)
  if (categoryId) {
    uni.setStorageSync('task_mall_products_category', categoryId)
    uni.switchTab({ url: '/pages/products/index', fail: () => uni.navigateTo({ url: '/pages/products/index' }) })
    return
  }
  navigate(stringValue(item.link_type))
}
function productPriceText(product: MallProduct, mode: string): string {
  const options = product.skus.flatMap((sku) => sku.purchase_options.filter((option) => option.enabled))
  const cash = options.filter((option) => option.purchase_mode !== 'points' && option.cash_amount > 0).map((option) => option.cash_amount).sort((left, right) => left - right)[0]
  const points = options.filter((option) => option.purchase_mode !== 'cash' && option.points > 0).map((option) => option.points).sort((left, right) => left - right)[0]
  const cashText = cash ? `¥${(cash / 100).toFixed(2).replace(/\.00$/, '')}` : ''
  const pointsText = points ? `${points}积分` : ''
  if (mode === 'points') return pointsText
  if (mode === 'cash') return cashText
  if (mode === 'cash_points') return [cashText, pointsText].filter(Boolean).join(' + ')
  return cashText || pointsText
}
function couponValueText(coupon: CouponCenterItem): string {
  return coupon.cash_discount_amount > 0 ? `¥${(coupon.cash_discount_amount / 100).toFixed(0)}` : `${coupon.points_discount}积分`
}
function couponConditionText(coupon: CouponCenterItem): string {
  const rules: string[] = []
  if (coupon.minimum_cash_amount > 0) rules.push(`满¥${(coupon.minimum_cash_amount / 100).toFixed(0)}可用`)
  if (coupon.minimum_points > 0) rules.push(`满${coupon.minimum_points}积分可用`)
  rules.push(`${coupon.valid_days}天有效`)
  return rules.join(' · ')
}
async function claimCouponItem(coupon: CouponCenterItem) {
  if (coupon.claimed || coupon.remaining <= 0 || claimingCouponId.value) return
  if (!auth.accessToken) {
    navigateToLogin()
    return
  }
  claimingCouponId.value = coupon.id
  try {
    await claimCoupon(auth.accessToken, coupon.id)
    coupon.claimed = true
    coupon.remaining = Math.max(0, coupon.remaining - 1)
    uni.showToast({ title: '领取成功', icon: 'success' })
  } catch {
    uni.showToast({ title: '领取失败，请稍后重试', icon: 'none' })
  } finally {
    claimingCouponId.value = null
  }
}
function openPost(id: string) { uni.navigateTo({ url: `/pages/community-detail/index?id=${encodeURIComponent(id)}` }) }
function openTask(id: string) { uni.navigateTo({ url: `/pages/task-detail/index?id=${encodeURIComponent(id)}` }) }
</script>

<style scoped>
.deco { display: flex; flex-direction: column; gap: 0; margin: 20rpx 0; }
.deco-page-title { font-size: 32rpx; font-weight: 700; color: #1f2937; margin-bottom: 16rpx; }
.deco-placeholder { display: flex; align-items: center; justify-content: center; background: #eef2f9; color: #9aa6bc; font-size: 24rpx; }
.deco-banner-wrap { position: relative; width: 100%; }
.deco-banner { width: 100%; height: 280rpx; border-radius: 24rpx; overflow: hidden; position: relative; }
.deco-banner-item { position: relative; width: 100%; height: 100%; }
.deco-banner-img { width: 100%; height: 100%; }
.deco-banner-indicator { position: absolute; display: flex; align-items: center; gap: 8rpx; z-index: 2; }
.deco-banner-indicator.pos-top-left { top: 16rpx; left: 20rpx; }
.deco-banner-indicator.pos-top-center { top: 16rpx; left: 50%; transform: translateX(-50%); }
.deco-banner-indicator.pos-top-right { top: 16rpx; right: 20rpx; }
.deco-banner-indicator.pos-bottom-left { bottom: 16rpx; left: 20rpx; }
.deco-banner-indicator.pos-bottom-center { bottom: 16rpx; left: 50%; transform: translateX(-50%); }
.deco-banner-indicator.pos-bottom-right { bottom: 16rpx; right: 20rpx; }
.deco-banner-dot { width: 12rpx; height: 12rpx; border-radius: 999rpx; background: rgba(255,255,255,.5); }
.deco-banner-dot.is-bar { width: 24rpx; height: 6rpx; }
.deco-banner-dot.is-active { background: #fff; }
.deco-banner-indicator-num { padding: 2rpx 12rpx; border-radius: 999rpx; background: rgba(0,0,0,.4); color: #fff; font-size: 18rpx; }
.deco-banner-mask { position: absolute; left: 0; right: 0; bottom: 0; padding: 24rpx; background: linear-gradient(180deg, rgba(0,0,0,0) 30%, rgba(0,0,0,.55)); display: flex; flex-direction: column; gap: 6rpx; }
.deco-banner-title { color: #fff; font-size: 32rpx; font-weight: 700; }
.deco-banner-sub { color: rgba(255,255,255,.9); font-size: 24rpx; }
.deco-banner-btn { align-self: flex-start; margin-top: 8rpx; padding: 8rpx 24rpx; border-radius: 999rpx; background: #fff; color: #2f67df; font-size: 24rpx; font-weight: 600; }
.deco-nav { display: flex; align-items: center; justify-content: space-between; padding: 8rpx 4rpx 16rpx; }
.deco-nav.is-border { border-bottom: 1rpx solid #EDEFF3; }
.deco-nav-main { display: flex; flex-direction: column; gap: 4rpx; }
.deco-nav-title { font-size: 30rpx; font-weight: 700; color: #1f2937; }
.deco-nav-sub { font-size: 24rpx; color: #7a859b; }
.deco-nav-more { font-size: 32rpx; color: #b7c0cc; }
.deco-search { height: 68rpx; border-radius: 999rpx; background: #f2f4f8; display: flex; align-items: center; gap: 10rpx; padding: 0 24rpx; }
.deco-search-ico { color: #9aa6bc; font-size: 26rpx; }
.deco-search-ph { color: #9aa6bc; font-size: 24rpx; }
.deco-announce { display: flex; align-items: center; gap: 16rpx; padding: 20rpx 24rpx; border-radius: 20rpx; background: #fff8e8; }
.deco-announce-icon { width: 48rpx; height: 48rpx; border-radius: 12rpx; background: #ffd43b; color: #8a5c00; font-size: 24rpx; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.deco-announce-icon-img { width: 100%; height: 100%; border-radius: 12rpx; }
.deco-announce-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.deco-announce-title { font-size: 28rpx; font-weight: 600; color: #5c4300; }
.deco-announce-view { overflow: hidden; white-space: nowrap; flex: 1; }
.deco-announce-marquee { display: inline-block; white-space: nowrap; font-size: 24rpx; animation: deco-announce-scroll 10s linear infinite; padding-right: 40rpx; }
.deco-announce-content { display: inline-block; font-size: 24rpx; color: #8a6a1f; }
@keyframes deco-announce-scroll { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
.deco-announce-arrow { color: #b79a52; font-size: 32rpx; }
.deco-grid { display: flex; flex-wrap: wrap; padding: 20rpx 8rpx; background: #fff; border-radius: 24rpx; }
.deco-grid-item { display: flex; flex-direction: column; align-items: center; gap: 10rpx; padding: 10rpx 0; }
.deco-grid-icon-wrap { position: relative; }
.deco-grid-icon { width: 100%; height: 100%; border-radius: 24rpx; }
.deco-grid-mark { display: flex; align-items: center; justify-content: center; background: #eaf1ff; color: #2f67df; font-size: 32rpx; font-weight: 700; }
.deco-grid-label { font-size: 24rpx; color: #3c4961; }
.deco-grid-note { font-size: 18rpx; color: #9aa5b8; line-height: 1.2; margin-top: -6rpx; }
.deco-grid-badge-dot { position: absolute; top: 0; right: 0; width: 14rpx; height: 14rpx; border-radius: 999rpx; background: #ff5964; }
.deco-grid-badge-text { position: absolute; top: -8rpx; right: -10rpx; padding: 0 8rpx; border-radius: 999rpx; background: #ff5964; color: #fff; font-size: 18rpx; font-weight: 700; line-height: 1.5; }
.deco-grid-badge-img { position: absolute; top: -12rpx; right: -12rpx; width: 40rpx; height: 40rpx; }
.deco-image-banner { width: 100%; border-radius: 24rpx; overflow: hidden; }
.deco-image-banner-img { width: 100%; height: 100%; }
.deco-image-text { display: flex; gap: 20rpx; padding: 20rpx; background: #fff; border-radius: 24rpx; align-items: center; }
.deco-image-text.is-right { flex-direction: row-reverse; }
.deco-image-text-img { width: 160rpx; height: 160rpx; border-radius: 16rpx; flex-shrink: 0; }
.deco-image-text-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8rpx; }
.deco-image-text-title { font-size: 28rpx; font-weight: 600; color: #1f2937; }
.deco-image-text-desc { font-size: 24rpx; color: #7a859b; }
.deco-cats { width: 100%; }
.deco-cats-scroll { width: 100%; white-space: nowrap; }
.deco-cats-inner { display: inline-flex; gap: 16rpx; }
.deco-cat { flex-shrink: 0; padding: 12rpx 28rpx; border-radius: 999rpx; background: #f2f4f8; color: #3c4961; font-size: 24rpx; font-weight: 600; }
.deco-cat.is-first { background: #0bbf63; color: #fff; }
.deco-coupons { background: #fff; border-radius: 24rpx; padding: 20rpx; }
.deco-coupons-title { font-size: 28rpx; font-weight: 700; color: #1f2937; display: block; margin-bottom: 16rpx; }
.deco-coupons-scroll { width: 100%; white-space: nowrap; }
.deco-coupons-inner { display: inline-flex; gap: 16rpx; }
.deco-coupon { flex-shrink: 0; width: 300rpx; border-radius: 18rpx; background: #fff4ec; padding: 20rpx; display: flex; flex-direction: column; gap: 4rpx; }
.deco-coupon-value { font-size: 34rpx; font-weight: 800; color: #f2620a; }
.deco-coupon-title { font-size: 24rpx; font-weight: 600; color: #1f2937; }
.deco-coupon-cond { font-size: 20rpx; color: #9a7b67; }
.deco-coupon-btn { align-self: flex-start; margin-top: 8rpx; font-size: 20rpx; font-weight: 700; color: #f2620a; border: 1rpx solid #f2620a; border-radius: 999rpx; padding: 2rpx 16rpx; }
.deco-list { padding: 20rpx; background: #fff; border-radius: 24rpx; }
.deco-list-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16rpx; }
.deco-list-title { font-size: 30rpx; font-weight: 700; color: #1f2937; }
.deco-list-more { font-size: 24rpx; color: #7a859b; }
.deco-products { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16rpx; }
.deco-product { display: flex; flex-direction: column; gap: 8rpx; }
.deco-product-img { width: 100%; height: 200rpx; border-radius: 16rpx; }
.deco-product-name { font-size: 24rpx; color: #3c4961; }
.deco-product-sub { font-size: 22rpx; color: #8b95a6; }
.deco-product-meta { display: flex; align-items: center; gap: 12rpx; flex-wrap: wrap; }
.deco-product-price { font-size: 26rpx; font-weight: 700; color: #f2620a; }
.deco-product-tag { font-size: 18rpx; color: #2f67df; background: #eaf1ff; border-radius: 6rpx; padding: 2rpx 8rpx; }
.deco-coupon-btn.is-disabled { color: #b9a08f; border-color: #d9c8bb; }
.deco-post, .deco-task { display: flex; flex-direction: column; gap: 6rpx; padding: 16rpx 0; border-top: 1rpx solid #f0f2f7; }
.deco-post { flex-direction: row; align-items: flex-start; gap: 16rpx; }
.deco-post.is-card { background: #f8fafd; border-top: 0; border-radius: 16rpx; padding: 16rpx; margin-bottom: 12rpx; }
.deco-post-avatar { width: 56rpx; height: 56rpx; border-radius: 999rpx; flex-shrink: 0; }
.deco-post-avatar-mark { display: flex; align-items: center; justify-content: center; background: #eaf1ff; color: #2f67df; font-size: 24rpx; font-weight: 700; }
.deco-post-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6rpx; }
.deco-post-title, .deco-task-title { font-size: 28rpx; color: #1f2937; font-weight: 600; }
.deco-post-excerpt { font-size: 22rpx; color: #8b95a6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.deco-post-meta, .deco-task-desc { font-size: 24rpx; color: #7a859b; }
/* 首页任务流富卡片（装修 task_list 接管时与首页外观对齐） */
.deco-task-card { display: flex; flex-direction: column; gap: 12rpx; padding: 22rpx 0; border-top: 1rpx solid #f0f2f7; }
.deco-task-head { display: flex; align-items: center; gap: 16rpx; }
.deco-task-avatar { width: 64rpx; height: 64rpx; border-radius: 50%; flex-shrink: 0; background: #eef2f9; }
.deco-task-avatar-fallback { display: flex; align-items: center; justify-content: center; font-size: 26rpx; color: #7a859b; }
.deco-task-head-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.deco-task-name-row { display: flex; align-items: center; gap: 8rpx; }
.deco-task-name { font-size: 26rpx; font-weight: 700; color: #1f2937; }
.deco-task-badge { padding: 2rpx 10rpx; border-radius: 6rpx; background: #eaf7ef; color: #0a9d54; font-size: 18rpx; }
.deco-task-meta { font-size: 20rpx; color: #9aa5b8; }
.deco-task-body { font-size: 26rpx; color: #3c4961; line-height: 1.5; }
.deco-task-tags { display: flex; flex-wrap: wrap; gap: 8rpx; }
.deco-task-tag { padding: 2rpx 12rpx; border-radius: 8rpx; font-size: 20rpx; }
.deco-task-tag-red { background: #ffe9ea; color: #ff5964; }
.deco-task-tag-orange { background: #fff2e5; color: #f2620a; }
.deco-task-tag-gray { background: #f0f2f5; color: #7a859b; }
.deco-task-gallery { display: grid; gap: 8rpx; }
.deco-task-gallery.g-1 { grid-template-columns: 1fr; }
.deco-task-gallery.g-2 { grid-template-columns: 1fr 1fr; }
.deco-task-gallery.g-3 { grid-template-columns: 1fr 1fr 1fr; }
.deco-task-gallery-img { width: 100%; height: 160rpx; border-radius: 12rpx; }
.deco-task-foot { display: flex; align-items: center; gap: 16rpx; }
.deco-task-pts { font-size: 26rpx; font-weight: 700; color: #ff5964; }
.deco-task-loc { font-size: 20rpx; color: #9aa5b8; flex: 1; }
.deco-task-btn { margin-left: auto; padding: 8rpx 24rpx; border-radius: 999rpx; background: #0bbf63; color: #fff; font-size: 22rpx; font-weight: 600; border: 0; line-height: 1.6; }
.deco-task-btn--disabled { background: #e3e8ef; color: #9aa5b8; }
.deco-task-empty { padding: 40rpx 0; text-align: center; font-size: 24rpx; color: #9aa5b8; }
.deco-rich { padding: 20rpx; background: #fff; border-radius: 24rpx; font-size: 26rpx; color: #3c4961; line-height: 1.7; }
.deco-float { position: fixed; right: 24rpx; z-index: 30; width: 96rpx; height: 96rpx; border-radius: 50%; background: #0bbf63; color: #fff; font-size: 28rpx; font-weight: 700; display: flex; align-items: center; justify-content: center; box-shadow: 0 12rpx 28rpx rgba(11,191,99,.35); }
.deco-float-img { width: 96rpx; height: 96rpx; border-radius: 50%; }
.deco-title { display: flex; flex-direction: column; gap: 6rpx; }
.deco-title.is-center { align-items: center; text-align: center; }
.deco-title-main { font-size: 36rpx; font-weight: 800; color: #1f2937; }
.deco-title-sub { font-size: 24rpx; color: #7a859b; }
.deco-listnav { background: #fff; border-radius: 24rpx; padding: 0 24rpx; }
.deco-listnav-item { display: flex; align-items: center; gap: 20rpx; padding: 22rpx 0; border-top: 1rpx solid #f1f3f7; }
.deco-listnav-item.is-first { border-top: 0; }
.deco-listnav-icon { width: 56rpx; height: 56rpx; border-radius: 16rpx; flex-shrink: 0; }
.deco-listnav-mark { display: flex; align-items: center; justify-content: center; background: #eaf1ff; color: #2f67df; font-size: 24rpx; font-weight: 700; }
.deco-listnav-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.deco-listnav-title { font-size: 26rpx; font-weight: 600; color: #1f2937; }
.deco-listnav-sub { font-size: 22rpx; color: #8b95a6; }
.deco-listnav-arrow { color: #b7c0cc; font-size: 30rpx; }
.deco-tags { background: #fff; border-radius: 24rpx; padding: 24rpx; }
.deco-tags-title { font-size: 26rpx; font-weight: 700; color: #1f2937; display: block; margin-bottom: 16rpx; }
.deco-tags-wrap { display: flex; flex-wrap: wrap; gap: 12rpx; }
.deco-tag { padding: 8rpx 22rpx; border-radius: 999rpx; background: #f2f4f8; color: #3c4961; font-size: 22rpx; }
.deco-images { display: grid; gap: 12rpx; }
.deco-images-item { width: 100%; height: 180rpx; border-radius: 16rpx; background: #eef2f9; }
.deco-buttons { display: flex; gap: 16rpx; }
.deco-btn { flex: 1; text-align: center; padding: 16rpx 0; border-radius: 999rpx; background: #f2f4f8; color: #3c4961; font-size: 24rpx; font-weight: 600; }
.deco-btn.is-primary { background: #0bbf63; color: #fff; }
.deco-divider { width: 100%; border-top-width: 1rpx; border-top-style: solid; border-top-color: #e7ebf2; }
.deco-activities { background: #fff; border-radius: 24rpx; padding: 20rpx; }
.deco-activities-title { font-size: 28rpx; font-weight: 700; color: #1f2937; display: block; margin-bottom: 16rpx; }
.deco-activities-scroll { width: 100%; white-space: nowrap; }
.deco-activities-inner { display: inline-flex; gap: 16rpx; }
.deco-activity { flex-shrink: 0; width: 260rpx; display: flex; flex-direction: column; gap: 6rpx; }
.deco-activity-img { width: 260rpx; height: 150rpx; border-radius: 16rpx; }
.deco-activity-title { font-size: 24rpx; font-weight: 600; color: #1f2937; }
.deco-activity-sub { font-size: 20rpx; color: #8b95a6; }
.deco-member { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; padding: 24rpx 28rpx; border-radius: 24rpx; background: linear-gradient(135deg,#2a3142,#4b5563); }
.deco-member-main { display: flex; flex-direction: column; gap: 4rpx; min-width: 0; }
.deco-member-title { font-size: 28rpx; font-weight: 700; color: #fff; }
.deco-member-desc { font-size: 22rpx; color: rgba(255,255,255,.75); }
.deco-member-btn { flex-shrink: 0; padding: 10rpx 24rpx; border-radius: 999rpx; background: #ffd86b; color: #6b4a00; font-size: 22rpx; font-weight: 700; }
.deco-cart { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; padding: 22rpx 28rpx; border-radius: 24rpx; background: #eaf7ef; }
.deco-cart-main { display: flex; flex-direction: column; gap: 4rpx; min-width: 0; }
.deco-cart-title { font-size: 28rpx; font-weight: 700; color: #0a3d23; }
.deco-cart-desc { font-size: 22rpx; color: #4f7a63; }
.deco-cart-btn { flex-shrink: 0; padding: 10rpx 24rpx; border-radius: 999rpx; background: #0bbf63; color: #fff; font-size: 22rpx; font-weight: 700; }
.deco-qr { display: flex; align-items: center; gap: 24rpx; padding: 24rpx; background: #fff; border-radius: 24rpx; }
.deco-qr-img { width: 160rpx; height: 160rpx; border-radius: 16rpx; flex-shrink: 0; }
.deco-qr-main { display: flex; flex-direction: column; gap: 6rpx; min-width: 0; }
.deco-qr-title { font-size: 28rpx; font-weight: 700; color: #1f2937; }
.deco-qr-desc { font-size: 22rpx; color: #8b95a6; }
.deco-loc { display: flex; align-items: center; gap: 16rpx; }
.deco-loc-campus { flex-shrink: 0; display: flex; align-items: center; gap: 6rpx; padding: 12rpx 20rpx; border-radius: 999rpx; background: #eaf7ef; color: #0a3d23; font-size: 22rpx; font-weight: 600; }
.deco-loc-pin { color: #0bbf63; font-size: 24rpx; }
.deco-loc-search { flex: 1; height: 68rpx; border-radius: 999rpx; background: #f2f4f8; display: flex; align-items: center; gap: 10rpx; padding: 0 24rpx; min-width: 0; }
.deco-marquee { display: flex; align-items: center; gap: 12rpx; padding: 16rpx 20rpx; border-radius: 16rpx; background: #fff8e8; overflow: hidden; }
.deco-marquee-icon { width: 40rpx; height: 40rpx; border-radius: 12rpx; background: #ffd43b; color: #8a5c00; font-size: 20rpx; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.deco-marquee-view { flex: 1; overflow: hidden; white-space: nowrap; }
.deco-marquee-inner { display: inline-block; white-space: nowrap; animation: deco-marquee 14s linear infinite; }
.deco-marquee-text { font-size: 22rpx; color: #8a6a1f; }
@keyframes deco-marquee { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
.deco-video { width: 100%; border-radius: 20rpx; overflow: hidden; background: #1f2430; }
.deco-tabsnav { display: flex; gap: 12rpx; }
.deco-tabsnav.is-sticky { position: sticky; top: 0; z-index: 20; background: rgba(255,255,255,.96); padding: 12rpx 0; }
.deco-tabsnav-item { flex: 1; text-align: center; padding: 14rpx 0; border-radius: 14rpx; background: #f2f4f8; color: #3c4961; font-size: 24rpx; font-weight: 600; }
.deco-tabsnav-item.is-first { background: #0bbf63; color: #fff; }
.deco-ad { position: relative; width: 100%; border-radius: 20rpx; overflow: hidden; }
.deco-ad-img { width: 100%; height: 100%; }
.deco-ad-badge { position: absolute; right: 12rpx; bottom: 12rpx; padding: 2rpx 10rpx; border-radius: 8rpx; background: rgba(0,0,0,.45); color: #fff; font-size: 18rpx; }
.deco-showcase { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 144rpx 144rpx; gap: 12rpx; }
.deco-showcase-item { position: relative; border-radius: 16rpx; overflow: hidden; }
.deco-showcase-item.is-big { grid-row: 1 / span 2; }
.deco-showcase.is-right .is-big { grid-column: 2; grid-row: 1 / span 2; }
.deco-showcase-img { width: 100%; height: 100%; }
.deco-showcase-label { position: absolute; left: 12rpx; bottom: 12rpx; padding: 2rpx 10rpx; border-radius: 8rpx; background: rgba(0,0,0,.35); color: #fff; font-size: 20rpx; }
.deco-notice { display: flex; align-items: flex-start; gap: 14rpx; padding: 20rpx 24rpx; border-radius: 18rpx; }
.deco-notice.tone-warning { background: #fff2e5; color: #8a4b00; }
.deco-notice.tone-info { background: #eaf2ff; color: #1e3a8a; }
.deco-notice.tone-success { background: #eaf7ef; color: #0a3d23; }
.deco-notice.tone-normal { background: #f4f6fa; color: #3c4961; }
.deco-notice-icon { font-size: 24rpx; font-weight: 800; line-height: 1.4; }
.deco-notice-main { display: flex; flex-direction: column; gap: 4rpx; min-width: 0; }
.deco-notice-title { font-size: 26rpx; font-weight: 700; }
.deco-notice-content { font-size: 22rpx; opacity: .85; }
.deco-hot-tag { display: inline-flex; align-items: center; gap: 6rpx; padding: 8rpx 22rpx; border-radius: 999rpx; background: #f2f4f8; color: #3c4961; font-size: 22rpx; }
.deco-hot-badge { font-size: 16rpx; font-weight: 800; color: #fff; background: #ff5964; border-radius: 6rpx; padding: 0 6rpx; }
.deco-titlebar { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.deco-titlebar-main { display: flex; flex-direction: column; gap: 4rpx; min-width: 0; }
.deco-titlebar-title { font-size: 32rpx; font-weight: 800; color: #1f2937; }
.deco-titlebar-sub { font-size: 22rpx; color: #8b95a6; }
.deco-titlebar-more { flex-shrink: 0; font-size: 22rpx; color: #0bbf63; font-weight: 600; }
.deco-tiles { display: grid; grid-template-columns: 1fr 1fr; grid-auto-rows: 160rpx; }
.deco-tiles.layout-three { grid-template-columns: repeat(3, minmax(0, 1fr)); grid-auto-rows: auto; }
.deco-tile { position: relative; overflow: hidden; background: #eef2f9; }
.deco-tile-img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.deco-tile-mask { position: absolute; left: 0; right: 0; bottom: 0; padding: 16rpx; display: flex; flex-direction: column; gap: 4rpx; background: linear-gradient(180deg, rgba(0,0,0,0) 20%, rgba(0,0,0,.55)); }
.deco-tile-tag { align-self: flex-start; padding: 0 10rpx; border-radius: 8rpx; font-size: 18rpx; font-weight: 700; margin-bottom: 4rpx; }
.deco-tile-title { font-weight: 700; }
.deco-tile-sub { opacity: .95; }
.deco-popup-mask { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 999; background: rgba(10,20,15,.55); display: flex; align-items: center; justify-content: center; }
.deco-popup { position: relative; background: #fff; overflow: hidden; box-shadow: 0 24rpx 60rpx rgba(0,0,0,.2); }
.deco-popup-img { width: 100%; height: 320rpx; }
.deco-popup-body { padding: 28rpx 32rpx 32rpx; display: flex; flex-direction: column; align-items: center; gap: 12rpx; text-align: center; }
.deco-popup-title { font-size: 34rpx; font-weight: 800; color: #1f2937; }
.deco-popup-content { font-size: 26rpx; color: #7a859b; line-height: 1.6; }
.deco-popup-btn { margin-top: 8rpx; padding: 16rpx 56rpx; border-radius: 999rpx; background: #0bbf63; color: #fff; font-size: 28rpx; font-weight: 700; }
.deco-popup-close { position: absolute; top: 16rpx; right: 16rpx; width: 48rpx; height: 48rpx; border-radius: 999rpx; background: rgba(0,0,0,.35); color: #fff; font-size: 32rpx; line-height: 44rpx; text-align: center; }
/* 首页核心区块（brand/point/recommendation/product_section/trust） */
.deco-brand { display: flex; align-items: center; gap: 18rpx; padding: 8rpx 4rpx; }
.deco-brand-logo { width: 76rpx; height: 76rpx; border-radius: 20rpx; background: linear-gradient(135deg,#0bbf63,#0a9d54); color: #fff; font-size: 34rpx; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.deco-brand-logo-img { width: 76rpx; height: 76rpx; border-radius: 20rpx; flex-shrink: 0; }
.deco-brand-main { display: flex; flex-direction: column; gap: 4rpx; min-width: 0; }
.deco-brand-name { font-size: 34rpx; font-weight: 800; color: #1f2937; }
.deco-brand-caption { font-size: 20rpx; letter-spacing: 2rpx; color: #9aa5b8; }
.deco-point { background: linear-gradient(135deg,#0bbf63,#0a9d54); border-radius: 24rpx; padding: 28rpx 30rpx; color: #fff; }
.deco-point-top { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.deco-point-left { display: flex; flex-direction: column; gap: 6rpx; min-width: 0; }
.deco-point-label { font-size: 26rpx; font-weight: 700; }
.deco-point-tip { font-size: 20rpx; opacity: .85; }
.deco-point-num { font-size: 30rpx; font-weight: 800; margin-top: 4rpx; }
.deco-point-btn.signed { background: rgba(255,255,255,.12); opacity: .7; }
.deco-point-btn { flex-shrink: 0; padding: 12rpx 26rpx; border-radius: 999rpx; background: rgba(255,255,255,.22); font-size: 24rpx; font-weight: 600; }
.deco-reco { display: flex; flex-direction: column; gap: 10rpx; }
.deco-reco-section-title { font-size: 32rpx; font-weight: 800; color: #1f2937; }
.deco-reco-section-subtitle { font-size: 22rpx; color: #8b95a6; }
.deco-reco-card { position: relative; overflow: hidden; display: flex; gap: 18rpx; background: #fff; border-radius: 22rpx; padding: 24rpx; box-shadow: 0 6rpx 20rpx rgba(15,30,20,.06); }
.deco-reco-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: .25; }
.deco-reco-icon-img { width: 64rpx; height: 64rpx; border-radius: 18rpx; flex-shrink: 0; }
.deco-reco-icon { width: 64rpx; height: 64rpx; border-radius: 18rpx; background: #eaf7ef; color: #0a9d54; font-size: 30rpx; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.deco-reco-main { display: flex; flex-direction: column; gap: 8rpx; min-width: 0; flex: 1; }
.deco-reco-title-row { display: flex; align-items: center; gap: 10rpx; }
.deco-reco-title { font-size: 28rpx; font-weight: 700; color: #1f2937; }
.deco-reco-tag { padding: 2rpx 12rpx; border-radius: 8rpx; background: #fff2e5; color: #d96b1a; font-size: 20rpx; }
.deco-reco-desc { font-size: 22rpx; color: #7a859b; line-height: 1.5; }
.deco-reco-meta { display: flex; align-items: center; gap: 16rpx; }
.deco-reco-reward { font-size: 24rpx; font-weight: 700; color: #ff5964; }
.deco-reco-time { font-size: 20rpx; color: #9aa5b8; }
.deco-section { display: flex; flex-direction: column; gap: 14rpx; }
.deco-section-head { display: flex; flex-direction: column; gap: 4rpx; }
.deco-section-title { font-size: 32rpx; font-weight: 800; color: #1f2937; }
.deco-section-subtitle { font-size: 22rpx; color: #8b95a6; }
.deco-section-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 16rpx; }
.deco-section-card { position: relative; border-radius: 20rpx; padding: 24rpx; min-height: 160rpx; display: flex; flex-direction: column; justify-content: flex-end; gap: 6rpx; overflow: hidden; }
.deco-section-card.tone-blue { background: linear-gradient(135deg,#e8f1ff,#d4e4ff); }
.deco-section-card.tone-warm { background: linear-gradient(135deg,#fff3e6,#ffe4cc); }
.deco-section-card.tone-green { background: linear-gradient(135deg,#e7f9ef,#ccf0dd); }
.deco-section-card.tone-orange { background: linear-gradient(135deg,#fff2e5,#ffd9b8); }
.deco-section-card-img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: .35; }
.deco-section-card-eyebrow { font-size: 20rpx; color: #6b7893; }
.deco-section-card-title { font-size: 28rpx; font-weight: 700; color: #1f2937; }
.deco-section-card-price { font-size: 24rpx; font-weight: 700; color: #ff5964; }
.deco-trust { position: relative; overflow: hidden; display: flex; align-items: center; gap: 16rpx; background: #f4f6fa; border-radius: 20rpx; padding: 24rpx 26rpx; }
.deco-trust-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: .2; }
.deco-trust-icon { width: 56rpx; height: 56rpx; border-radius: 14rpx; flex-shrink: 0; }
.deco-trust-main { display: flex; flex-direction: column; gap: 6rpx; min-width: 0; flex: 1; }
.deco-trust-title { font-size: 26rpx; font-weight: 700; color: #1f2937; }
.deco-trust-desc { font-size: 22rpx; color: #7a859b; line-height: 1.5; }
.deco-trust-arrow { font-size: 32rpx; color: #9aa5b8; flex-shrink: 0; }
</style>
