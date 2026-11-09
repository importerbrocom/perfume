# Payment gateway setup

No gateway is hard-coded. The store is UAE-based, so choose a provider whose **merchant account you are approved for**
(commonly Stripe UAE, Telr, PayTabs, Network International/N-Genius, Checkout.com; Tabby/Tamara for instalments).
Check current fees, supported cards, AED settlement and onboarding requirements with each provider yourself.
If you sell to India from a UAE entity, confirm whether the provider can process INR/Indian cards; some Indian gateways require an Indian business entity.

## Rules
- Use the provider's **official WooCommerce plugin** (hosted payment page or tokenised fields). Card numbers must never reach this server.
- Keys: enter in WooCommerce > Settings > Payments, or define in `wp-config.php` from environment variables (`.env.example`). Never commit keys.
- Webhooks must be **signature-verified**. Use only plugins that verify the signing secret.
- Never mark an order paid from the browser return URL alone; the order should stay `Pending payment` until the webhook/server verification confirms it.
- Do not use any "simulate payment" or dummy gateway on the live site.

## Steps
1. Install and activate the provider plugin; enable **test mode**; enter test keys.
2. Copy the webhook URL the plugin shows into the provider dashboard; paste the webhook signing secret back into the plugin.
3. Enable **Cash on Delivery** (Settings > Payments) and, if needed, limit it by shipping zone.
4. Run these test payments and record the results:
   | Case | Expected |
   |---|---|
   | Successful test card | Order moves from Pending payment to Processing; order-received page shown; confirmation email sent |
   | Declined card | Checkout shows an error; order stays Pending/Failed; cart is kept |
   | Customer cancels on the hosted page | Returns to the cart/pay page; order not paid |
   | Webhook with bad signature (replay with curl) | Rejected (4xx); order unchanged |
   | Webhook delivered twice | Order is not double-processed |
   | Refund from the order screen | Provider refunded; status becomes Refunded; customer emailed |
5. Switch to live keys and do one real low-value payment and refund before announcing.
