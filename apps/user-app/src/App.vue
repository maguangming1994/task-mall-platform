<script setup lang="ts">
import { onLaunch, onShow, onHide } from "@dcloudio/uni-app";
onLaunch(() => {
  console.info("Task Mall launched");
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
  --tm-brand-900: #10295f;
  --tm-brand-700: #2455c3;
  --tm-brand-600: #2f67df;
  --tm-brand-500: #3f78f3;
  --tm-brand-100: #eaf1ff;
  --tm-brand-50: #f4f7ff;
  --tm-reward-600: #e56a16;
  --tm-reward-100: #fff0df;
  --tm-ink-900: #172033;
  --tm-ink-700: #3c4961;
  --tm-ink-500: #748099;
  --tm-line: #e7ebf2;
  --tm-surface: #ffffff;
  --tm-canvas: #f6f8fc;
  --tm-success: #15966a;
  --tm-danger: #d14343;
  background: var(--tm-canvas);
  color: var(--tm-ink-900);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
  font-size: 28rpx;
  -webkit-font-smoothing: antialiased;
}

button {
  font-family: inherit;
}

button::after {
  border: 0;
}

text,
view {
  box-sizing: border-box;
}

.rich-content { display: block; overflow-wrap: anywhere; line-height: 1.75; }
</style>
