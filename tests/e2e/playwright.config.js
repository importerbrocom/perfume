// Run against STAGING only:  BASE_URL=http://localhost:8080 ADMIN_PASSWORD=... npx playwright test
const { defineConfig, devices } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  timeout: 60000,
  retries: 0,
  use: { baseURL: process.env.BASE_URL || 'http://localhost:8080', trace: 'retain-on-failure' },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] }, testIgnore: /ratelimit/ },
    { name: 'mobile', use: { ...devices['Pixel 7'] }, testIgnore: /ratelimit/ },
    { name: 'ratelimit', testMatch: /ratelimit\.spec\.js/, dependencies: ['desktop', 'mobile'] },
  ],
});
