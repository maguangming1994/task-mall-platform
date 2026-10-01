<script setup lang="ts">
import { onLaunch, onShow, onHide } from "@dcloudio/uni-app";
import { useModulesStore } from '@/stores/modules'
import { useIconConfigStore } from '@/stores/icons'

onLaunch(() => {
  console.info("Task Mall launched");
  const modules = useModulesStore()
  modules.hydrate()
  void modules.load(true)
  const icons = useIconConfigStore()
  icons.hydrate()
  void icons.load(true)
});
onShow(() => {
  // App lifecycle hook reserved for channel-specific refresh work.
});
onHide(() => {
  // Sensitive drafts are persisted by their owning pages before backgrounding.
});
</script>
<style>
page {
  /* V09: one soft campus system. Keep legacy names as semantic aliases only. */
  --tm-brand-900: #10213a;
  --tm-brand-700: #2455c3;
  --tm-brand-600: #2f67df;
  --tm-brand-500: #6e95e9;
  --tm-brand-100: #eaf1ff;
  --tm-brand-50: #f3f7ff;
  --tm-growth-700: #0f8a61;
  --tm-growth-600: #12a66a;
  --tm-growth-100: #e4f8ef;
  --tm-campus-yellow: #ffdb4d;
  --tm-campus-yellow-soft: #fff5bf;
  --tm-campus-lime: #76c968;
  --tm-campus-lime-soft: #e8f7df;
  --tm-campus-coral: #e45d63;
  --tm-campus-coral-soft: #fff0ef;
  --tm-campus-cyan: #1aaabd;
  --tm-campus-cyan-soft: #dcf7fa;
  --tm-campus-purple: #7957c8;
  --tm-campus-purple-soft: #f0eaff;
  --tm-reward-600: #e56a16;
  --tm-reward-100: #fff0df;
  --tm-ink-900: #10213a;
  --tm-ink-700: #42526a;
  --tm-ink-500: #748099;
  --tm-line: #e2e9f0;
  --tm-surface: #ffffff;
  --tm-surface-soft: #f8fafc;
  --tm-canvas: #f6f9fc;
  --tm-success: #15966a;
  --tm-warning: #b77b12;
  --tm-danger: #d14343;
  --tm-info: #2475ca;
  --tm-radius-sm: 12rpx;
  --tm-radius-md: 18rpx;
  --tm-radius-lg: 28rpx;
  --tm-shadow-sm: 0 8rpx 22rpx rgba(31, 56, 92, .06);
  --tm-shadow-card: 0 14rpx 38rpx rgba(31, 56, 92, .08);
  --tm-shadow-float: 0 24rpx 64rpx rgba(31, 56, 92, .14);
  --tm-motion-fast: 160ms;
  --tm-motion-base: 260ms;
  --tm-ease: cubic-bezier(.2, .75, .25, 1);
  --tm-space-page: 28rpx;
  --tm-border-strong: 1rpx solid rgba(7, 26, 51, .11);
  --tm-shadow-pop: 0 16rpx 42rpx rgba(7, 26, 51, .10);
  --tm-z-sticky: 20;
  --tm-z-bottom-action: 900;
  --tm-z-bottom-nav: 950;
  --tm-z-float: 980;
  --tm-z-overlay: 1200;
  --tm-bottom-clearance: 0rpx;
  background:
    radial-gradient(circle at 12% 5%, rgba(255, 219, 77, .20) 0 88rpx, transparent 90rpx),
    radial-gradient(circle at 92% 18%, rgba(25, 198, 209, .10) 0 120rpx, transparent 122rpx),
    linear-gradient(180deg, #fffef9 0%, var(--tm-canvas) 48%, #f3f7ff 100%);
  color: var(--tm-ink-900);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
  /* Typography uses physical CSS pixels so phone width changes do not resize text. */
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
}

button {
  font-family: inherit;
}

button::after {
  border: 0;
}

button,
.tm-card,
.tm-card-strong,
.tm-primary-button,
.tm-secondary-button,
.tabbar-item {
  transition: transform var(--tm-motion-fast) var(--tm-ease), box-shadow var(--tm-motion-base) var(--tm-ease), border-color var(--tm-motion-fast) ease, background-color var(--tm-motion-fast) ease, opacity var(--tm-motion-fast) ease;
}

button:active,
.tm-card:active,
.tm-card-strong:active,
.tm-primary-button:active,
.tm-secondary-button:active,
.tabbar-item:active {
  transform: scale(.975);
}

.tm-page-shell,
.tm-campus-page {
  animation: tm-page-enter 380ms var(--tm-ease) both;
}

.tm-skeleton {
  position: relative;
  overflow: hidden;
  background: #edf0f5;
}

.tm-skeleton::after {
  content: '';
  position: absolute;
  inset: 0;
  transform: translateX(-100%);
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.72), transparent);
  animation: tm-shimmer 1.35s ease-in-out infinite;
}

@keyframes tm-page-enter {
  from { opacity: 0; transform: translateY(12rpx); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes tm-shimmer {
  to { transform: translateX(100%); }
}

@media (hover: hover) {
  .tm-card:hover,
  .tm-card-strong:hover,
  .tm-primary-button:hover,
  .tm-secondary-button:hover {
    transform: translateY(-3rpx);
    box-shadow: var(--tm-shadow-card);
  }
}

@media (prefers-reduced-motion: reduce) {
  .tm-page-shell,
  .tm-campus-page,
  .tm-skeleton::after { animation: none; }

  button,
  .tm-card,
  .tm-card-strong,
  .tm-primary-button,
  .tm-secondary-button,
  .tabbar-item { transition: none; }
}

text,
view {
  box-sizing: border-box;
}

.rich-content { display: block; overflow-wrap: anywhere; line-height: 1.75; }

.tm-page-shell {
  min-height: 100vh;
  padding: 24rpx 28rpx 64rpx;
  background: var(--tm-canvas);
  box-sizing: border-box;
}

.tm-panel {
  border: 1rpx solid rgba(7, 26, 51, .08);
  border-radius: var(--tm-radius-lg);
  background: rgba(255, 255, 255, .96);
  box-shadow: var(--tm-shadow-card);
}

.tm-campus-grid {
  background-image: linear-gradient(rgba(7,26,51,.035) 1rpx, transparent 1rpx), linear-gradient(90deg, rgba(7,26,51,.035) 1rpx, transparent 1rpx);
  background-size: 34rpx 34rpx;
}

.tm-section-kicker {
  display: inline-flex;
  align-items: center;
  min-height: 34rpx;
  padding: 0 12rpx;
  border: 1rpx solid rgba(7,26,51,.10);
  border-radius: 999rpx;
  background: var(--tm-campus-yellow);
  color: var(--tm-ink-900);
  font-size: 12px;
  font-weight: 900;
  letter-spacing: .08em;
}

.tm-card-strong {
  border: var(--tm-border-strong);
  border-radius: 30rpx;
  background: #fff;
  box-shadow: var(--tm-shadow-pop);
}

.tm-primary-button {
  height: 88rpx;
  border-radius: 18rpx;
  background: var(--tm-brand-600);
  box-shadow: 0 12rpx 28rpx rgba(23,105,255,.22);
  color: #fff;
  font-size: 17px;
  font-weight: 800;
  line-height: 88rpx;
}

.tm-secondary-button {
  height: 88rpx;
  border: 1rpx solid var(--tm-line);
  border-radius: 18rpx;
  background: #fff;
  color: var(--tm-ink-900);
  font-size: 17px;
  font-weight: 800;
  line-height: 86rpx;
}

.tm-money { color: var(--tm-reward-600); font-weight: 850; }

@media screen and (min-width: 768px) {
  .tm-page-shell { width: 750rpx; margin: 0 auto; }
}
</style>
<style src="./styles/campus-flow-v3.css"></style>
