/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    sourcemap: false,
  },
  server: {
    port: 5173,
  },
  test: {
    // happy-dom rather than jsdom: the tests here need a DOM (focus traps,
    // aria-live announcements, <table> alternatives to the charts) but nothing
    // jsdom offers beyond it, and it starts in a fraction of the time.
    environment: 'happy-dom',
    include: ['src/**/*.test.{ts,tsx}'],
    setupFiles: ['src/test/setup.ts'],
    restoreMocks: true,
  },
})
