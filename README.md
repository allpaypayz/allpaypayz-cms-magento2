# Allpaypayz for Magento 2

**[⬇ Download the latest version](https://github.com/allpaypayz/allpaypayz-cms-magento2/archive/refs/heads/main.zip)** · [Browse the code](https://github.com/allpaypayz/allpaypayz-cms-magento2) · [MIT](LICENSE)

<sub>The archive is a snapshot of `main` — the current state of the plugin. Tagged releases will appear on the Releases page once the code leaves alpha.</sub>


Magento 2 module that adds **Allpaypayz** as a payment method, with refund
support and signed webhook handling. Wraps
[`allpaypayz/sdk`](https://github.com/allpaypayz/allpaypayz-sdk-php) so every Allpaypayz-side call goes through the same
audited code path as the other Allpaypayz integrations.

> Status: **alpha** (v0.1.0). Targets Magento 2.4+ on PHP 8.1+.

## Install

```bash
composer require allpaypayz/cms-magento2
bin/magento module:enable Allpaypayz_Magento2
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Then under **Stores → Configuration → Sales → Payment Methods → Allpaypayz**,
fill in:

- **Enabled** — toggle the method on.
- **API key** — `sk_...` token (stored encrypted via Magento's
  `core_config_data` encryption backend).
- **Webhook sign key** — symmetric secret used to verify deliveries.
- **API environment** — Production / Staging.
- **Payment method** — `card`, `redirect`, etc.
- **New order status** — defaults to *pending payment*.

Register the webhook URL with Allpaypayz:
`https://your-shop.example.com/allpaypayz/webhook`.

## How it works

- `Model/Payment/Allpaypayz.php` extends `AbstractMethod`, supports `refund` and
  `capture`. `getOrderPlaceRedirectUrl()` returns `allpaypayz/redirect/`.
- `Controller/Redirect/Index.php` loads the just-placed order from the
  checkout session, calls `client->payments->createRedirect(...)` with
  `merchant_reference` of the form `M2-<incrementId>`, stores the Allpaypayz
  payment id in `Order::ext_order_id`, then 302s the customer to the
  returned `checkout_url`.
- `Controller/Webhook/Index.php` verifies the `Callback-Signature` header via
  `Allpaypayz\Webhooks::verify`, then maps v4 `event.type` to a Magento order
  transition (state, status, comment).
- Refunds are wired through Magento's native refund UI thanks to
  `_canRefund = true`; `Allpaypayz::refund()` calls
  `client->payments->createRefund(...)`.

## Event-to-status mapping

| v4 `event.type` | Magento action |
|---|---|
| `payment.succeeded`, `order.completed` | `STATE_PROCESSING` + comment |
| `payment.failed`, `payment.cancelled`, `order.cancelled`, `order.expired` | `$order->cancel()` + comment |
| `payment.refunded`, `payment.partially_refunded`, `refund.succeeded` | order comment (operator follow-up) |

## Files

```
cms-magento2/
├── composer.json
├── registration.php
├── etc/
│   ├── module.xml
│   ├── config.xml
│   ├── payment.xml
│   ├── adminhtml/system.xml
│   └── frontend/routes.xml
├── Model/
│   ├── Config/Source/BaseUrl.php
│   └── Payment/Allpaypayz.php
├── Controller/
│   ├── Redirect/Index.php
│   └── Webhook/Index.php
└── i18n/{en_US,ru_RU}.csv
```

## License

MIT
