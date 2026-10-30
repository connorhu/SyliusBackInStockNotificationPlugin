# UPGRADE 5.x TO 6.0

## Subscription lifecycle (BC break)

- The boolean `notify` flag is replaced by a `status` string: `waiting`, `notified`, `fulfilled`, `cancelled`
  (constants in `Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionStatus`).
  `SubscriptionInterface::isNotify()` / `setNotify()` are removed; use `getStatus()`, `isActive()`, `markNotified()`,
  `markWaiting()`, `markFulfilled()`, `markCancelled()`, `restore()`, `dismiss()`.
  `AlertCommand` now selects subscriptions with status `waiting` and calls `markNotified($now, null, null)` after sending.
- `SubscriptionFactoryInterface::createWithData()` has a new signature:
  `createWithData(ChannelInterface $channel, ProductInterface $product, ?ProductVariantInterface $variant, string $email, ?string $name, string $localeCode, ?CustomerInterface $customer = null)`.
  The factory constructor takes the `sylius.canonicalizer` as second argument; if you redefined the factory service, add it.
- `SubscriptionInterface` gains `getProduct()` / `setProduct()`, `getName()` / `setName()`, `getEmailCanonical()` and the
  lifecycle/hold getters. `getProductVariant()` is now nullable in the database as well (product target).
- Grid fields/filters named `notify` are now `status`; the templates `.../grid/field/notify.html.twig` are renamed to `status.html.twig`.
- `SubscriptionUniqueValidator` takes the canonicalizer as 4th constructor argument and only counts active subscriptions.

## Target

New bundle config `webgriffe_sylius_back_in_stock_notification_plugin.target` (`variant`, the default, or `product`),
exposed through `TargetResolverInterface` (`webgriffe.resolver.target`). Behaviour for `variant` is unchanged.

## Database

Run the migrations: `Version20261030100000` adds `product_id` (NOT NULL, back-filled from the variant), `name`,
`email_canonical` (back-filled `LOWER(TRIM(email))`), `status` (back-filled from `notify`), the notification/hold/fulfil/cancel/dismiss
timestamps and `held_variant_id`, makes `product_variant_id` nullable, and drops `notify`.
The `down()` removes subscriptions without a variant, since the old schema cannot hold them.
