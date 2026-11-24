import { expect, test } from '@playwright/test';

const adminState = 'e2e/.auth/admin.json';

async function noOverflow(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
}

const card = (page, label) => page.locator('.fx-card').filter({ has: page.getByLabel(`Select ${label}`, { exact: true }) });

test('Fixnote page: home, regions and the full catalogue with search and brand filter', async ({ page }) => {
  await page.goto('/shop-fixnote');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Find Your Signature Scent.');
  await expect(page.getByText('Explore Our Collections')).toBeVisible();
  for (const r of ['uae', 'india', 'saudi', 'gcc']) await expect(page.getByTestId(`fx-region-${r}`)).toBeVisible();
  await noOverflow(page);

  await page.getByTestId('fx-region-uae').click();
  await expect(page).toHaveURL(/\/shop-fixnote\/uae$/);
  await expect(page.getByTestId('fx-offers')).toContainText('5+ PIECES = FREE DELIVERY');
  await expect(page.getByTestId('fx-quick')).toContainText('100 ML59 AED');
  expect(await page.locator('.fx-card').count()).toBeGreaterThanOrEqual(378);
  await expect(page.getByRole('heading', { name: 'Louis Vuitton', level: 2 })).toBeVisible();
  await noOverflow(page);

  await page.getByLabel('Search perfumes').fill('black tea');
  await expect(page.locator('.fx-card')).toHaveCount(1);
  await expect(page.locator('.fx-card')).toContainText('F01');
  await page.getByLabel('Search perfumes').fill('');
  await page.getByLabel('Inspired by brand').selectOption('Creed');
  await expect(page.locator('.fx-group')).toHaveText(['Creed']);
  await expect(card(page, 'F132 Aventus')).toBeVisible();
});

test('Fixnote UAE: choose perfumes and sizes, UAE delivery charge, COD order shows under Fixnote orders', async ({ page, browser }) => {
  await page.goto('/shop-fixnote/uae');
  const f01 = card(page, 'F01 Imagination');
  await page.getByLabel('Select F01 Imagination').check();
  await expect(f01.locator('.fx-price')).toHaveText('59 AED'); // 100 ML is chosen first
  await expect(page.getByTestId('fx-count')).toHaveText('1');
  await expect(page.getByTestId('fx-delivery')).toHaveText('Delivery 4 AED • add 4 more pieces for FREE delivery');
  await page.getByLabel('Size for F01 Imagination').selectOption({ label: '30 ML - 29 AED' });
  await expect(f01.locator('.fx-price')).toHaveText('29 AED');
  await expect(page.getByTestId('fx-delivery')).toContainText('Delivery 14 AED');
  await page.getByLabel('Select F18 Sauvage').check();
  await expect(page.getByTestId('fx-count')).toHaveText('2');
  await noOverflow(page);

  await page.getByRole('button', { name: 'CHECKOUT' }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  const summary = page.locator('.pc-summary');
  await expect(summary.getByTestId('subtotal')).toContainText('88.00'); // 29 + 59
  await expect(summary.getByTestId('total')).toContainText('102.00'); // + 14 delivery (a 30 ML in the order)
  await page.getByLabel('Full name').fill('Fatima Ali');
  await page.getByLabel('Email').fill('fatima@example.test');
  await page.getByLabel('Mobile number').fill('+971 50 765 4321');
  await page.getByLabel('City').fill('Ajman');
  await page.getByLabel(/Complete address/).fill('Tower 3, Al Nuaimiya');
  await page.getByRole('radio', { name: /cash on delivery/i }).check();
  await page.getByRole('checkbox').check();
  await page.getByTestId('place-order').click();
  await expect(page).toHaveURL(/\/order\/PM\d+\?token=/);
  const number = (await page.getByTestId('order-number').textContent()).trim();

  const admin = await browser.newContext({ storageState: adminState });
  const ap = await admin.newPage();
  await ap.goto('/admin/fixnote/orders');
  await expect(ap.getByRole('heading', { name: 'Fixnote orders', level: 1 })).toBeVisible();
  await expect(ap.getByTestId('orders-table')).toContainText(number);
  await ap.getByRole('link', { name: number }).click();
  await expect(ap.getByText('F01-30')).toBeVisible(); // code + size on the order line
  await admin.close();
});

test('Fixnote India: own prices, selection goes to WhatsApp, online checkout refuses Fixnote for India', async ({ page }) => {
  await page.goto('/shop-fixnote/india');
  await expect(page.getByTestId('fx-quick')).toContainText('30 ML1,199 INR');
  await expect(page.getByRole('button', { name: 'CHECKOUT' })).toHaveCount(0);
  await page.getByLabel('Select F01 Imagination').check();
  await expect(card(page, 'F01 Imagination').locator('.fx-price')).toHaveText('1,999 INR');
  let sent = '';
  await page.route(/wa\.me/, (route) => {
    sent = decodeURIComponent(route.request().url());
    return route.fulfill({ status: 200, contentType: 'text/html', body: 'ok' });
  });
  await page.getByRole('button', { name: 'SEND MY SELECTION ON WHATSAPP' }).click();
  await expect.poll(() => sent).toContain('F01 – Imagination – EXTRAIT PERFUME – 100 ML – 1,999 INR');
  expect(sent).toContain('Region: INDIA');
  expect(sent).toContain('Total: 1,999 INR');

  // A Fixnote item in the bag cannot be ordered online to India
  await page.goto('/shop-fixnote/uae');
  await page.getByLabel('Select F02 Ombre Nomade').check();
  await page.goto('/checkout');
  await page.locator('select[name="country"]').selectOption('IN');
  await expect(page.locator('.pc-summary')).toContainText('taken on WhatsApp');
  await expect(page.getByTestId('place-order')).toBeDisabled();
});

test.describe('Fixnote admin', () => {
  test.use({ storageState: adminState });

  test('prices for all sizes, page texts and a new perfume reach the Fixnote page', async ({ page, browser, isMobile }, info) => {
    await page.goto('/admin');
    if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Fixnote page', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Fixnote page', level: 1 })).toBeVisible();
    await noOverflow(page);

    const visit = async (path, fn) => { // fresh browser: no cached catalogue
      const ctx = await browser.newContext();
      const p = await ctx.newPage();
      await p.goto(path);
      await fn(p);
      await ctx.close();
    };
    const setPrice30 = async (value) => {
      await page.getByTestId('fx-price-30 ML').fill(value);
      await page.getByTestId('fx-save-prices').click();
      await page.getByRole('button', { name: 'Change all prices' }).click();
      await expect(page.getByText(/Prices saved: \d+ sizes changed/)).toBeVisible();
    };

    // Price for every 30 ML
    await setPrice30('31');
    await visit('/shop-fixnote/uae', async (p) => {
      await expect(p.getByTestId('fx-quick')).toContainText('30 ML31 AED');
      await p.getByLabel('Select F05 Pacific Chill').check();
      await p.getByLabel('Size for F05 Pacific Chill').selectOption({ label: '30 ML - 31 AED' });
    });
    await setPrice30('29');

    // Page text
    await page.getByRole('tab', { name: 'Page texts' }).click();
    await page.getByLabel('Title, orange part').fill('Your Fixnote Scent.');
    await page.getByTestId('fx-save').click();
    await expect(page.getByText('Fixnote page saved.')).toBeVisible();
    await visit('/shop-fixnote', async (p) => {
      await expect(p.getByRole('heading', { level: 1 })).toHaveText('Find Your Your Fixnote Scent.');
    });
    await page.getByLabel('Title, orange part').fill('Signature Scent.');
    await page.getByTestId('fx-save').click();
    await expect(page.getByText('Fixnote page saved.').first()).toBeVisible();

    // New perfume with the three sizes
    const code = `T-${info.project.name}`.toUpperCase();
    await page.goto('/admin/fixnote/products');
    await expect(page.getByRole('heading', { name: 'Fixnote perfumes', level: 1 })).toBeVisible();
    await page.getByRole('button', { name: '+ Add Fixnote perfume' }).click();
    await page.getByLabel('Code').fill(code);
    await page.getByRole('dialog').getByLabel('Name').fill('Test Oud');
    await page.getByLabel('Inspired by').fill('Test House');
    await page.getByRole('button', { name: 'Add perfume' }).click();
    await expect(page.getByText(`${code} Test Oud added with 3 sizes.`)).toBeVisible();
    await page.getByLabel('Search products').fill(code);
    const row = page.getByTestId('products-table').locator('tr').filter({ hasText: 'Test Oud' });
    await expect(row).toContainText('3 sizes');
    await expect(row).toContainText('Test House');
    await noOverflow(page);
    await visit('/shop-fixnote/uae', async (p) => {
      await p.getByLabel('Inspired by brand').selectOption('Test House');
      await p.getByLabel(`Select ${code} Test Oud`).check();
      await expect(card(p, `${code} Test Oud`).locator('.fx-price')).toHaveText('59 AED');
    });
  });
});
