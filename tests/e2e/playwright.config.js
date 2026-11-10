// Run against STAGING only:
//   BASE_URL=http://localhost:8080 ADMIN_USER=admin ADMIN_PASSWORD=... MAILPIT_URL=http://localhost:8025 npx playwright test
const { defineConfig, devices } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  timeout: 60000,
  retries: 0,
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    // cPanel staging (perfume.nokkoo.in) is behind a staging key gate: send it on every request.
    extraHTTPHeaders: process.env.PM_GATE ? { 'X-PM-Gate': process.env.PM_GATE } : {},
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] }, testMatch: /shopping\.spec\.js/ },
    { name: 'mobile', use: { ...devices['Pixel 7'] }, testMatch: /shopping\.spec\.js/ },
    { name: 'admin', use: { ...devices['Desktop Chrome'], acceptDownloads: true }, testMatch: /admin-order\.spec\.js/ },
    // Last, so its 12 requests cannot rate-limit the real tracking tests (all from the same IP).
    { name: 'ratelimit', testMatch: /ratelimit\.spec\.js/, dependencies: ['desktop', 'mobile', 'admin'] },
  ],
});
