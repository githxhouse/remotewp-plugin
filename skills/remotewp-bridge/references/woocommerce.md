# RemoteWP utility: WooCommerce changes

Use this playbook for product pages, cart/checkout behavior, order flows and
WooCommerce template customizations.

## Workflow

1. Confirm WooCommerce is active and read `/wp/info` plus the relevant plugin/theme context.
2. List and read the current template or hook owner before proposing a change.
3. Prefer a child-theme override or a custom plugin over editing WooCommerce or parent-theme files.
4. Check compatibility with the installed WooCommerce/WordPress versions and existing payment, shipping and cache integrations.
5. Show a narrow diff and request approval before mutation.
6. Write with the reviewed hash, idempotency key and backup/operation tracking.
7. Clear cache and verify product, cart and checkout behavior without creating real orders or sending customer emails.

## Guardrails

- Never alter prices, stock, orders, payment settings or customer data without explicit scope.
- Use test products/orders and staging for transactional changes.
- Preserve tax, shipping, currency, multilingual and privacy behavior.
- If a checkout or payment flow cannot be safely tested, stop at code review and report the limitation.
