import { expect, test } from '@playwright/test';

const adminState = 'e2e/.auth/admin.json';
const managerState = 'e2e/.auth/manager.json';

async function noOverflow(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
}

/** Phones show the menu behind the ☰ button; desktop shows it all the time. */
async function openMenu(page, isMobile) {
  if (isMobile) await page.getByRole('button', { name: 'Open menu' }).click();
}

/** Places a COD order through the public API, like a customer would. */
async function placeOrder(request, slug, email) {
  const product = await (await request.get(`/api/products/${slug}`)).json();
  const variant = product.product?.variants?.[0] || product.variants?.[0];
  const res = await request.post('/api/checkout', {
    headers: { Accept: 'application/json' },
    data: {
      items: [{ variant_id: variant.id, quantity: 1 }], name: 'Noor Test', email, phone: '+971 50 111 2222',
      country: 'AE', city: 'Sharjah', address: 'Flat 4, Al Majaz 2', payment_method: 'cod', accept_terms: true,
    },
  });
  expect(res.status(), await res.text()).toBe(201);
  return res.json();
}

test.describe('login', () => {
  test('wrong password is refused, staff can log in and out', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'login is rate limited; one run is enough');
    await page.goto('/admin/orders');
    await page.getByLabel('Email').fill('admin@example.test');
    await page.getByLabel('Password').fill('not-the-password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('alert')).toContainText('Wrong email or password');
    await page.getByLabel('Password').fill('E2eAdmin12345');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('heading', { name: 'Orders', level: 1 })).toBeVisible();
    await page.getByRole('button', { name: 'Log out' }).click();
    await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible();
  });
});

test.describe('as admin', () => {
  test.use({ storageState: adminState });

  test('dashboard and menu work on this screen size', async ({ page, isMobile }) => {
    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText('Sales this month')).toBeVisible();
    await noOverflow(page);
    await openMenu(page, isMobile);
    await page.getByRole('link', { name: 'Stock & Prices' }).click();
    await expect(page.getByRole('heading', { name: 'Stock & Prices' })).toBeVisible();
    await noOverflow(page);
  });

  test('add a product with two sizes; it appears in the Fixnote shop with its prices', async ({ page }, info) => {
    const name = `Fixnote Musk ${info.project.name}`;
    await page.goto('/admin/products/new');
    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
    await page.getByLabel('Brand').selectOption({ label: 'Fixnote' });
    await page.getByRole('checkbox', { name: "Men's Perfumes", exact: true }).check();
    const rows = page.getByTestId('variant-row');
    await rows.nth(0).getByLabel('Size name').fill('50 ml');
    await rows.nth(0).getByLabel('Price', { exact: true }).fill('70');
    await rows.nth(0).getByLabel('Stock (units)').fill('9');
    await page.getByRole('button', { name: '+ Add size' }).click();
    await rows.nth(1).getByLabel('Size name').fill('100 ml');
    await rows.nth(1).getByLabel('Price', { exact: true }).fill('110');
    await rows.nth(1).getByLabel('Sale price').fill('120'); // higher than price: refused
    await rows.nth(1).getByLabel('Stock (units)').fill('4');
    await page.getByTestId('save-product').click();
    await expect(rows.nth(1).getByText('Sale price must be lower than the regular price.')).toBeVisible();
    await rows.nth(1).getByLabel('Sale price').fill('99');
    await page.getByTestId('save-product').click();
    await expect(page.getByText('Product created. Now add photos.')).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/products\/\d+$/);
    await expect(page.getByText('All changes saved')).toBeVisible();
    await noOverflow(page);

    await page.goto('/shop-fixnote/uae');
    await page.getByLabel('Search perfumes').fill(name);
    const card = page.locator('.fx-card').filter({ hasText: name });
    await expect(card).toHaveCount(1);
    await expect(card.locator('.fx-price')).toContainText('99 AED'); // 100 ml first, on sale
    await expect(card.locator('.fx-price del')).toHaveText('110 AED');
    await card.getByRole('combobox').selectOption({ label: '50 ml - 70 AED' });
    await expect(card.locator('.fx-price')).toHaveText('70 AED');
  });

  test('Stock & Prices: change a price and stock, the shop shows it', async ({ page }) => {
    await page.goto('/admin/stock?search=Amber');
    const row = page.getByTestId('stock-row').filter({ hasText: 'Amber Night' });
    await expect(row).toHaveCount(1);
    await row.getByLabel(/^Price for Amber Night/).fill('175');
    await row.getByLabel(/^Stock for Amber Night/).fill('6');
    await expect(page.getByText('1 size changed')).toBeVisible();
    await page.getByTestId('save-stock').click();
    await expect(page.getByText('Saved 1 size.')).toBeVisible();
    await row.getByRole('button', { name: '+/− stock' }).click();
    await page.getByLabel(/Units to add/).fill('3');
    await page.getByLabel(/Reason/).fill('New delivery');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(row.getByLabel(/^Stock for Amber Night/)).toHaveValue('9');
    await row.getByRole('button', { name: 'History' }).click();
    await expect(page.getByRole('dialog')).toContainText('New delivery');
    await page.getByRole('button', { name: 'Close' }).click();

    await page.goto('/product/amber-night');
    await expect(page.locator('.pp-price')).toContainText('175.00');
  });

  test('order handling: confirm, add tracking, ship; the customer sees it when tracking', async ({ page, request }, info) => {
    const email = `noor.${info.project.name}@example.test`;
    const placed = await placeOrder(request, 'fixnote-aqua', email);
    await page.goto('/admin/orders');
    await page.getByLabel('Search orders').fill(placed.number);
    await page.getByRole('link', { name: placed.number }).click();
    await expect(page.getByRole('heading', { name: `Order ${placed.number}` })).toBeVisible();
    await noOverflow(page);
    await page.getByRole('button', { name: 'Confirm order' }).click();
    await expect(page.getByTestId('admin-order-status')).toContainText('Confirmed');
    await page.getByLabel('Courier').fill('Aramex');
    await page.getByLabel('Tracking number').fill('ARX123456');
    await page.getByRole('button', { name: 'Save tracking' }).click();
    await expect(page.getByText('Tracking saved.')).toBeVisible();
    await page.getByLabel('Note for the customer (optional)').fill('Packed with care');
    await page.getByRole('button', { name: 'Mark as shipped' }).click();
    await expect(page.getByTestId('admin-order-status')).toContainText('Shipped');
    await expect(page.getByRole('button', { name: 'Mark as delivered' })).toBeVisible();

    await page.goto('/track-order');
    await page.getByLabel('Order number').fill(placed.number);
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: 'Track' }).click();
    await expect(page.getByTestId('order-status')).toHaveText('Shipped');
    await expect(page.getByText('ARX123456')).toBeVisible();
    await expect(page.getByText('Packed with care')).toBeVisible();
  });

  test('cancelling an order puts the stock back', async ({ page, request }, info) => {
    const before = (await (await request.get('/api/products/kausar')).json());
    const v = (before.product || before).variants[0];
    const placed = await placeOrder(request, 'kausar', `cancel.${info.project.name}@example.test`);
    await page.goto('/admin/orders?search=' + placed.number);
    await page.getByRole('link', { name: placed.number }).click();
    await page.getByRole('button', { name: 'Cancel order' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel order' }).click();
    await expect(page.getByTestId('admin-order-status')).toContainText('Cancelled');
    const after = (await (await request.get('/api/products/kausar')).json());
    expect((after.product || after).variants[0].available).toBe(v.available);
  });

  test('coupon created in admin works in the bag', async ({ page }, info) => {
    const code = `SAVE${info.project.name.toUpperCase()}`;
    await page.goto('/admin/coupons');
    await page.getByRole('button', { name: '+ Add coupon' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Code').fill(code);
    await dialog.getByLabel('Amount').fill('10');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByTestId('coupons-table')).toContainText(code);

    await page.goto('/product/fixnote-aqua');
    await page.locator('.pp-summary').getByRole('button', { name: '50 ml' }).click();
    await page.locator('.pp-summary').getByRole('button', { name: 'Add to Cart' }).click();
    await page.goto('/cart');
    await page.locator('.pc-summary').getByRole('textbox').fill(code.toLowerCase());
    await page.locator('.pc-summary').getByRole('button', { name: /apply/i }).click();
    await expect(page.locator('.pc-summary')).toContainText('Discount');
    await expect(page.locator('.pc-summary').getByTestId('total')).toContainText('63.00'); // 60 − 10% + 9 Fixnote UAE delivery (50 ml)
  });

  test('store settings and payment method changes reach the shop', async ({ page, request }, info) => {
    test.skip(info.project.name !== 'desktop', 'changes shared store settings');
    await page.goto('/admin/settings');
    await page.getByRole('tab', { name: 'Homepage' }).click();
    await page.getByLabel('Title').first().fill('THE ART OF OUD');
    await page.getByTestId('save-settings').click();
    await expect(page.getByText(/Settings saved/)).toBeVisible();

    await page.goto('/admin/payments');
    const cod = page.getByTestId('method-cod');
    await cod.getByLabel('Name at checkout').fill('Pay cash on delivery');
    await cod.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Pay cash on delivery saved.')).toBeVisible();
    const card = page.getByTestId('method-card');
    await card.getByRole('switch').check();
    await card.getByRole('button', { name: 'Save' }).click();
    await expect(card.getByRole('alert')).toContainText('Enter the Stripe publishable key');
    await card.getByLabel('Secret key').fill('sk_' + 'live_abc123'); // live keys are refused on this site
    await card.getByLabel('Publishable key').fill('pk_' + 'live_abc123');
    await card.getByRole('button', { name: 'Save' }).click();
    await expect(card.getByRole('alert')).toContainText('Live Stripe keys are switched off');

    // Browsers may keep /api/store for up to a minute; a fresh request must show the new text at once.
    expect((await (await request.get('/api/store')).json()).settings.home_title).toBe('THE ART OF OUD');
    await page.goto('/product/amber-night');
    await page.locator('.pp-summary').getByRole('button', { name: 'Buy Now' }).click();
    await expect(page.getByRole('radio', { name: /Pay cash on delivery/ })).toBeVisible();
    await expect(page.getByRole('radio', { name: /Card/ })).toHaveCount(0);
  });

  test('content screens open and list their items', async ({ page, isMobile }) => {
    for (const [path, heading, text] of [
      ['/admin/brands', 'Brands', 'Fixnote'], ['/admin/categories', 'Categories & collections', 'Oud Collection'],
      ['/admin/home-sections', 'Homepage sections', 'Best Sellers'], ['/admin/customers', 'Customers', null],
      ['/admin/reviews', 'Reviews', null], ['/admin/staff', 'Staff accounts', 'manager@example.test'],
      ['/admin/banners', 'Banners', null], ['/admin/announcements', 'Announcement bar', null], ['/admin/account', 'My account', null],
    ]) {
      await page.goto(path);
      await expect(page.getByRole('heading', { name: heading, level: 1 })).toBeVisible();
      if (text) await expect(page.locator('.ad-main')).toContainText(text);
      if (isMobile) await noOverflow(page);
    }
  });
});

test.describe('as manager', () => {
  test.use({ storageState: managerState });

  test('manager handles products but cannot open settings, payments or staff', async ({ page, isMobile }) => {
    await page.goto('/admin');
    await openMenu(page, isMobile);
    await expect(page.getByRole('link', { name: 'Products', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Store settings' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Payment methods' })).toHaveCount(0);
    await page.goto('/admin/payments');
    await expect(page.getByText('Only an admin can open this page.')).toBeVisible();
    const res = await page.request.get('/api/admin/payment-methods', { headers: { Accept: 'application/json', Referer: 'http://127.0.0.1:8099/admin' } });
    expect(res.status()).toBe(403);
  });
});
