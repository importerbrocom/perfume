import { expect, test as setup } from '@playwright/test';

for (const [role, email, password] of [['admin', 'admin@example.test', 'E2eAdmin12345'], ['manager', 'manager@example.test', 'E2eManager12345']]) {
  setup(`log in as ${role}`, async ({ page }) => {
    await page.goto('/admin');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await page.context().storageState({ path: `e2e/.auth/${role}.json` });
  });
}
