import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  workers: 1, // tests share one database and change stock
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: { baseURL: 'http://127.0.0.1:8099', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [
    // Logs in once per staff role (login is rate limited) and saves the session for the admin tests.
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    { name: 'desktop', use: { ...devices['Desktop Chrome'] }, dependencies: ['setup'] },
    { name: 'mobile', use: { ...devices['Pixel 7'] }, dependencies: ['setup'] },
  ],
  webServer: {
    command: 'bash e2e/serve.sh',
    url: 'http://127.0.0.1:8099/up',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
