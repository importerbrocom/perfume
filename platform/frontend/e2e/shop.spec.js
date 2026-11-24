import { expect, test } from '@playwright/test';

/** The page must never be wider than the screen (no sideways scrolling on phones). */
async function expectNoHorizontalOverflow(page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(1);
}

test('homepage shows exactly one SHOP button per brand', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Shop Perfumarathi' })).toHaveCount(1);
  await expect(page.getByRole('link', { name: 'Shop Fixnote' })).toHaveCount(1);
  await expect(page.getByText(/ENTER/)).toHaveCount(0);
  await expectNoHorizontalOverflow(page);
  await page.getByRole('link', { name: 'Shop Fixnote' }).click();
  await expect(page).toHaveURL(/\/shop-fixnote$/);
  await expect(page.getByTestId('product-card').filter({ hasText: 'Fixnote Aqua' })).toHaveCount(1);
  await expect(page.getByTestId('product-card').filter({ hasNotText: 'Fixnote' })).toHaveCount(0); // brand shop: only Fixnote
});

test('brand shop lists only that brand and search filters it', async ({ page }) => {
  await page.goto('/shop-perfumarathi');
  const cards = page.getByTestId('product-card');
  await expect(cards).toHaveCount(3);
  await expect(page.getByText('Fixnote Aqua')).toHaveCount(0);
  await page.locator('#pm-search').fill('night');
  await expect(cards).toHaveCount(1);
  await expect(cards.first()).toContainText('Amber Night');
  await expectNoHorizontalOverflow(page);
});

const buyBox = (page) => page.locator('.pp-summary');

test('product page: choose size, add to cart, buy now, place COD order, view and track it', async ({ page }) => {
  await page.goto('/product/kausar');
  await expect(page.getByRole('heading', { level: 1, name: 'Kausar' })).toBeVisible();
  await expectNoHorizontalOverflow(page);

  await buyBox(page).getByRole('button', { name: '100 ml' }).click();
  await expect(page.getByTestId('stock')).toContainText(/in stock/i);
  await buyBox(page).getByRole('button', { name: 'Add to Cart' }).click();
  await expect(page.getByText('Added to your bag').first()).toBeVisible();

  await page.goto('/cart');
  await expect(page.locator('.pc-summary').getByTestId('subtotal')).toContainText('129.00');

  await page.goto('/product/kausar');
  await buyBox(page).getByRole('button', { name: '50 ml' }).click();
  await buyBox(page).getByRole('button', { name: 'Buy Now' }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await expect(page.locator('.pc-summary').getByTestId('subtotal')).toContainText('224.00'); // 129 + 95

  await page.getByLabel('Full name').fill('Aisha Khan');
  await page.getByLabel('Email').fill('aisha@example.test');
  await page.getByLabel('Mobile number').fill('+971 50 123 4567');
  await page.getByLabel('City').fill('Dubai');
  await page.getByLabel(/Complete address/).fill('Villa 12, Al Barsha 1');
  await page.getByRole('radio', { name: /cash on delivery/i }).check();
  await page.getByRole('checkbox').check();
  await expectNoHorizontalOverflow(page);
  await page.getByTestId('place-order').click();

  await expect(page).toHaveURL(/\/order\/PM\d+\?token=/);
  await expect(page.getByRole('heading', { name: /Thank you, Aisha/ })).toBeVisible();
  await expect(page.getByTestId('order-status')).toHaveText('Order placed');
  const number = (await page.getByTestId('order-number').textContent()).trim();
  await expectNoHorizontalOverflow(page);

  // The bag is empty after ordering
  await page.goto('/cart');
  await expect(page.getByRole('heading', { name: 'Your bag is empty' })).toBeVisible();

  // Track with number + email; a wrong email is refused
  await page.goto('/track-order');
  await page.getByLabel('Order number').fill(number.toLowerCase());
  await page.getByLabel('Email').fill('someone@else.test');
  await page.getByRole('button', { name: 'Track' }).click();
  await expect(page.getByRole('alert')).toContainText('could not find');
  await page.getByLabel('Email').fill('AISHA@example.test');
  await page.getByRole('button', { name: 'Track' }).click();
  await expect(page.getByTestId('order-number')).toHaveText(number);
  await expectNoHorizontalOverflow(page);
});

test('out-of-stock and price-on-request products cannot be ordered', async ({ page }) => {
  await page.goto('/product/royal-oud');
  await expect(buyBox(page).getByTestId('stock')).toHaveText('Out of stock');
  await expect(buyBox(page).getByRole('button', { name: /Add to Cart|Buy Now/ })).toHaveCount(0);
  await page.goto('/product/fixnote-rose');
  await expect(buyBox(page).getByText('Price on request')).toBeVisible();
  await expect(buyBox(page).getByRole('button', { name: /Add to Cart|Buy Now/ })).toHaveCount(0);
});

test('order link with a wrong token shows nothing private', async ({ page }) => {
  await page.goto('/order/PM100001?token=wrong');
  await expect(page.getByRole('heading', { name: 'Order not found' })).toBeVisible();
  await expect(page.getByTestId('order')).toHaveCount(0);
});

test('unknown pages show the 404 screen', async ({ page }) => {
  await page.goto('/no-such-page');
  await expect(page.getByRole('heading', { name: 'Page not found' })).toBeVisible();
});

test('wishlist keeps saved products', async ({ page }) => {
  await page.goto('/product/amber-night');
  await buyBox(page).getByRole('button', { name: /wishlist/i }).click();
  await page.goto('/wishlist');
  await expect(page.getByTestId('product-card')).toHaveCount(1);
  await expect(page.getByTestId('product-card')).toContainText('Amber Night');
});
