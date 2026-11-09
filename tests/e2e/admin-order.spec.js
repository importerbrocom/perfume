// Brief steps 14-18: order created -> admin receives it -> admin updates status + tracking ->
// customer receives the update by email -> customer tracks delivery. Also invoice, dashboard and CSV.
// Needs ADMIN_USER, ADMIN_PASSWORD and MAILPIT_URL (set by .github/workflows/e2e.yml / docs/STAGING-CLONE.md).
const { test, expect } = require('@playwright/test');
const { P, MAILPIT, placeCodOrder, waitForMail } = require('./helpers');

const ADMIN_USER = process.env.ADMIN_USER;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD;
test.skip(!ADMIN_USER || !ADMIN_PASSWORD || !MAILPIT, 'ADMIN_USER, ADMIN_PASSWORD and MAILPIT_URL are required');
test.describe.configure({ mode: 'serial' });

const EMAIL = `e2e-admin+${Date.now()}@example.test`;
let orderId;

test('customer places a COD order and receives the order email', async ({ page, request }) => {
  orderId = await placeCodOrder(page, EMAIL);
  const mail = await waitForMail(request, EMAIL, /order/i);
  expect(mail.subject).toBeTruthy();
});

test('admin sees the order, sets Shipped + tracking, invoice prints', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await page.click('#wp-submit');
  await expect(page).toHaveURL(/wp-admin/);

  // HPOS first, then legacy post-based orders (live clones may use either).
  await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
  if (!(await page.locator('#order_status').count())) await page.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
  await expect(page.locator('#order_status')).toHaveCount(1);
  await expect(page.locator('body')).toContainText(EMAIL);

  await page.fill('input[name="pm_carrier"]', 'Aramex');
  await page.fill('input[name="pm_tracking_number"]', 'TRK-E2E-123');
  await page.evaluate(() => {
    const s = document.querySelector('#order_status');
    s.value = 'wc-pm-shipped';
    if (window.jQuery) window.jQuery(s).trigger('change'); else s.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.locator('button[name="save"]').first().click();
  await expect(page.locator('#order_status')).toHaveValue('wc-pm-shipped');
  await expect(page.locator('input[name="pm_tracking_number"]')).toHaveValue('TRK-E2E-123');

  const href = await page.locator('#pm_order_tools a', { hasText: 'Print invoice' }).getAttribute('href');
  await page.goto(href);
  await expect(page.locator('h1')).toContainText(`Invoice #${orderId}`);
  await expect(page.locator('body')).toContainText('[TEST]');
});

test('customer receives the Shipped email with tracking number', async ({ request }) => {
  const mail = await waitForMail(request, EMAIL, /Shipped/);
  expect(mail.html + mail.text).toContain('TRK-E2E-123');
});

test('customer tracks the order: timeline at Shipped with tracking number', async ({ page }) => {
  await page.goto(P.track);
  await page.fill('input[name="order"]', orderId);
  await page.fill('input[name="email"]', EMAIL);
  await page.click('.pm-track button');
  await expect(page.locator('.pm-timeline li.is-current')).toContainText('Shipped');
  await expect(page.locator('.pm-track__result')).toContainText('TRK-E2E-123');
});

test('admin dashboard loads and CSV report contains the order', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await page.click('#wp-submit');
  await page.goto('/wp-admin/admin.php?page=pm-dashboard');
  await expect(page.locator('h1')).toContainText('Store Dashboard');
  await expect(page.locator('.pmc', { hasText: 'Recent orders' })).toContainText(`#${orderId}`);
  const today = new Date().toISOString().slice(0, 10);
  const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
  await page.fill('input[name="from"]', yesterday);
  await page.fill('input[name="to"]', today);
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('text=Download CSV')]);
  expect(dl.suggestedFilename()).toMatch(/\.csv$/);
  const fs = require('fs');
  const body = fs.readFileSync(await dl.path(), 'utf8');
  expect(body.split('\n')[0]).toContain('Order,Date,Status');
  expect(body).toContain(EMAIL);
});
