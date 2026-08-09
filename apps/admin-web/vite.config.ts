import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  base: process.env.VITE_BASE_PATH || '/',
  plugins: [react()],
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          const path = id.replaceAll('\\', '/')
          if (path.includes('node_modules/@ant-design/icons')) return 'vendor-icons'
          const antdComponent = path.match(/node_modules\/antd\/es\/([^/]+)/)?.[1]
          if (antdComponent) return `antd-${antdComponent}`
          const rcComponent = path.match(/node_modules\/@rc-component\/([^/]+)/)?.[1]
          if (rcComponent) return `rc-${rcComponent}`
          const legacyRc = path.match(/node_modules\/(rc-[^/]+)/)?.[1]
          if (legacyRc) return legacyRc
          if (path.includes('node_modules/@ant-design')) return 'vendor-ant-core'
          if (path.includes('node_modules/react') || path.includes('node_modules/scheduler')) return 'vendor-react'
          return undefined
        },
      },
    },
  },
  server: {
    port: 5174,
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
