// Shared helpers. Paths are configurable so the suite also runs against a clone of the live site.
const { expect } = require('@playwright/test');

const P = {
  perfumarathi: process.env.PM_PATH_PERFUMARATHI || '/shop-perfumarathi/',
  fixnote: process.env.PM_PATH_FIXNOTE || '/shop-fixnote/',
  checkout: process.env.PM_PATH_CHECKOUT || '/checkout/',
  account: process.env.PM_PATH_ACCOUNT || '/my-account/',
  track: process.env.PM_PATH_TRACK || '/track-order/',
  wishlist: process.env.PM_PATH_WISHLIST || '/wishlist/',
};
const MAILPIT = process.env.MAILPIT_URL || '';

async function addTestProduct(page, size = '30ml') {
  await page.goto(P.perfumarathi);
  await page.locator('.pm-card:not(.pm-skel) h3 a', { hasText: '[TEST]' }).first().click();
  await page.selectOption('select[name="attribute_size"]', size);
  await page.click('button.single_add_to_cart_button');
  await page.waitForLoadState('load');
}

/** Guest COD checkout. Supports classic shortcode and block checkout. Returns the order id. */
async function placeCodOrder(page, email) {
  await addTestProduct(page);
  await page.goto(P.checkout);
  const classic = page.locator('#billing_first_name');
  const block = page.locator('.wc-block-checkout');
  await expect(classic.or(block).first()).toBeVisible();
  if (await classic.count()) {
    await page.fill('#billing_first_name', 'E2E');
    await page.fill('#billing_last_name', 'Tester');
    await page.fill('#billing_address_1', '1 Test Street');
    await page.fill('#billing_city', 'Dubai');
    await page.fill('#billing_phone', '+971500000000');
    await page.fill('#billing_email', email);
    await page.check('input[value="cod"]');
    const terms = page.locator('#terms');
    if (await terms.count()) await terms.check();
    await page.click('#place_order');
  } else {
    // Block checkout (not exercised by CI's fresh install; used if the live site uses blocks).
    await page.fill('#email', email);
    await page.fill('#shipping-first_name', 'E2E');
    await page.fill('#shipping-last_name', 'Tester');
    await page.fill('#shipping-address_1', '1 Test Street');
    await page.fill('#shipping-city', 'Dubai');
    const phone = page.locator('#shipping-phone');
    if (await phone.count()) await phone.fill('+971500000000');
    await page.locator('input[value="cod"]').check();
    const terms = page.locator('.wc-block-checkout__terms input[type="checkbox"]');
    if (await terms.count()) await terms.check();
    await page.click('.wc-block-components-checkout-place-order-button');
  }
  await expect(page).toHaveURL(/order-received/, { timeout: 30000 });
  const id = (page.url().match(/order-received\/(\d+)/) || [])[1];
  expect(id).toMatch(/^\d+$/);
  return id;
}

/** Polls Mailpit for a message to `to` whose subject matches `re`. */
async function waitForMail(request, to, re, timeout = 30000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    const r = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent('to:"' + to + '"')}`);
    if (r.ok()) {
      const d = await r.json();
      const m = (d.messages || []).find((x) => re.test(x.Subject));
      if (m) {
        const full = await (await request.get(`${MAILPIT}/api/v1/message/${m.ID}`)).json();
        return { subject: m.Subject, text: full.Text || '', html: full.HTML || '' };
      }
    }
    await new Promise((res) => setTimeout(res, 1000));
  }
  throw new Error(`No email to ${to} matching ${re} within ${timeout}ms`);
}

module.exports = { P, MAILPIT, addTestProduct, placeCodOrder, waitForMail };
