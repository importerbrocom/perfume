// Runs once, after desktop+mobile, so it cannot rate-limit the real tracking test (same client IP).
const { test, expect, request } = require('@playwright/test');

test('tracking endpoint is rate limited', async ({ baseURL }) => {
  const ctx = await request.newContext({ baseURL });
  let last;
  for (let i = 0; i < 12; i++) last = await ctx.post('/wp-json/pm/v1/track', { data: { order: '1', email: 'x@example.test' } });
  expect(last.status()).toBe(429);
});
