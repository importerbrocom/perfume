// Covers the customer workflow from the brief against a staging site seeded with dev/seed-staging.php.
const { test, expect, request } = require('@playwright/test');
const { P, addTestProduct, placeCodOrder } = require('./helpers');
const EMAIL = `e2e+${Date.now()}@example.test`;
let orderNumber;


test('brand shop lists products and search works', async ({ page }) => {
  await page.goto(P.perfumarathi);
  await expect(page.locator('.pm-card').first()).toBeVisible();
  await expect(page.locator('.pm-card:not(.pm-skel) h3', { hasText: '[TEST]' }).first()).toContainText('Perfumarathi');
  await page.fill('.pm-search', 'Oud');
  await expect(page.locator('.pm-card').first()).toContainText('Oud');
  await page.fill('.pm-search', 'zzzz-no-match');
  await expect(page.locator('.pm-shop .pm-empty')).toContainText('No perfumes found');
});

test('fixnote shop is separate from perfumarathi', async ({ page }) => {
  await page.goto(P.fixnote);
  await expect(page.locator('.pm-card:not(.pm-skel) h3', { hasText: '[TEST]' }).first()).toContainText('Fixnote');
  await expect(page.locator('.pm-card__brand', { hasText: 'Perfumarathi' })).toHaveCount(0);
});

test('product page: choose size, add to cart, drawer quantity, remove', async ({ page }) => {
  await addTestProduct(page, '50ml');
  await page.goto(P.perfumarathi);
  await expect(page.locator('.pm-bag:visible [data-pm-count]')).toHaveText('1');
  await page.locator('.pm-bag:visible').click();
  await expect(page.locator('#pm-drawer.is-open')).toBeVisible();
  await page.click('.pm-line [data-qty="1"]');
  await expect(page.locator('.pm-line .pm-qty span')).toHaveText('2');
  await page.click('.pm-line [data-remove]');
  await expect(page.locator('#pm-drawer .pm-empty')).toContainText('Your bag is empty');
});

test('product page: Buy Now goes straight to checkout with the chosen size and quantity', async ({ page }) => {
  await page.goto(P.perfumarathi);
  await page.locator('.pm-card:not(.pm-skel) h3 a', { hasText: '[TEST]' }).first().click();
  const buy = page.locator('button.pm-buy-now');
  await expect(buy).toBeDisabled(); // until a size is chosen
  await page.selectOption('select[name="attribute_size"]', '100ml');
  await expect(buy).toBeEnabled({ timeout: 15000 });
  await page.fill('input.qty', '2');
  await Promise.all([page.waitForURL(/\/checkout\//, { timeout: 30000 }), buy.click()]);
  const review = page.locator('.woocommerce-checkout-review-order-table');
  await expect(review).toContainText('[TEST]');
  await expect(review).toContainText('100ml');
  await expect(review.locator('.product-quantity')).toContainText('2');
});

test('invalid coupon is rejected by the server', async ({ page }) => {
  await addTestProduct(page);
  await page.goto(P.perfumarathi);
  await page.locator('.pm-bag:visible').click();
  await page.locator('#pm-coupon-in').fill('NOT-A-COUPON');
  await page.click('.pm-coupon button');
  await expect(page.locator('.pm-toast--error')).toBeVisible();
});

test.describe.serial('checkout then tracking', () => {
test('guest checkout with cash on delivery creates an order', async ({ page }) => {
  orderNumber = await placeCodOrder(page, EMAIL);
  // Order-received page shows the progress timeline and how to track the order.
  await expect(page.locator('.pm-thankyou-track .pm-timeline')).toBeVisible();
  await expect(page.locator('.pm-thankyou-track')).toContainText(orderNumber);
});

test('tracking: correct email shows timeline, wrong email is rejected', async ({ page }) => {
  await page.goto(P.track);
  await page.fill('input[name="order"]', orderNumber);
  await page.fill('input[name="email"]', 'wrong@example.test');
  await page.click('.pm-track button');
  await expect(page.locator('.pm-track__result')).toContainText('could not find');
  await page.fill('input[name="email"]', EMAIL);
  await page.click('.pm-track button');
  await expect(page.locator('.pm-timeline')).toBeVisible();
});
});

test('security: anonymous users cannot reach admin APIs or dashboard', async ({ baseURL }) => {
  const ctx = await request.newContext({ baseURL });
  expect((await ctx.get('/wp-json/wc/v3/orders')).status()).toBe(401);
  expect((await ctx.get('/wp-json/pm/v1/wishlist')).status()).toBe(401);
  const r = await ctx.get('/wp-admin/admin.php?page=pm-dashboard', { maxRedirects: 0 });
  expect([301, 302]).toContain(r.status());
  expect(r.headers()['location']).toContain('wp-login.php');
});

test('security: registered customer is kept out of wp-admin', async ({ page }) => {
  const user = `cust${Date.now()}`;
  await page.goto(P.account);
  await page.fill('#reg_email', `${user}@example.test`);
  if (await page.locator('#reg_password').count()) await page.fill('#reg_password', `Pw!${Date.now()}aZ`);
  await page.click('button[name="register"]');
  await page.goto('/wp-admin/');
  await expect(page).toHaveURL(/my-account/);
});

test('empty wishlist shows the empty state', async ({ page }) => {
  await page.goto(P.wishlist);
  await expect(page.locator('[data-pm-wishlist] .pm-empty')).toContainText('wishlist is empty');
});

test('reduced motion disables reveal animation', async ({ browser, baseURL }) => {
  const ctx = await browser.newContext({ baseURL, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  await page.goto(P.perfumarathi);
  const real = page.locator('.pm-card:not(.pm-skel)').first();
  await expect(real).toBeVisible();
  const t = await real.evaluate((el) => getComputedStyle(el).transitionDuration);
  expect(t).toBe('0s');
  await ctx.close();
});
