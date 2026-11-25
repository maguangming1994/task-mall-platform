import { defineConfig } from "vite";
import uni from "@dcloudio/vite-plugin-uni";

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [uni()],
  vue: {
    template: {
      compilerOptions: {
        isCustomElement: (tag) => tag === 'wx-open-subscribe',
      },
    },
  },
  server: {
    host: '127.0.0.1',
    proxy: {
      '/api': {
        target: 'https://rwsc.jxgxnxs.cn',
        changeOrigin: true,
      },
      '/content': {
        target: 'https://rwsc.jxgxnxs.cn',
        changeOrigin: true,
      },
      '/storage': {
        target: 'https://rwsc.jxgxnxs.cn',
        changeOrigin: true,
      },
    },
  },
});
