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

## Pre-orderability

- Implement `PreorderabilityAwareInterface` on your `Product` and `Taxon` entities and `PreorderChannelSettingsInterface` on your `Channel`
  entity, and use `ProductPreorderabilityTrait` / `TaxonPreorderabilityTrait` / `PreorderChannelSettingsTrait` (see the README for the
  XML-mapping alternative). Without them every product counts as `inherit` and every channel uses the defaults (pre-orders enabled).
- Run the migration `Version20261030110000`: it adds `sylius_product.preorderability` and `sylius_taxon.preorderability`
  (`inherit`), and `sylius_channel.preorder_enabled_by_default` (`true`), `preorder_mode` (`queue`), `preorder_hold_hours` (`24`) and
  `preorder_rate_limit_seconds` (`120`). The columns exist whether or not you use the traits; map them in your entities
  (the traits do it), otherwise `doctrine:schema:validate` reports them as unmapped.
- `AddNotificationComponent` takes two more constructor arguments (the `PreorderabilityResolverInterface` and the channel context);
  the block renders nothing when the product is not pre-orderable in the current channel.

## Guest pre-orders and the processor (BC break)

- `SubscriptionProcessorInterface::process()` is now
  `process(ProductInterface $product, ?ProductVariantInterface $variant, string $email, ?string $name, ?CustomerInterface $customer, ?string $clientIp = null): ProcessResult`.
  `ProcessResult` is an enum: `Created`, `AlreadySubscribed`, `NotPreorderable`, `RateLimited`. The processor refuses products that are
  not pre-orderable in the current channel, treats e-mail addresses case-insensitively, and attaches the pre-order to the customer when the
  address belongs to a registered account (a customer with a shop user). A logged-in customer's own e-mail wins over the typed one.
  `SubscriptionProcessor` takes new constructor arguments (SafeSender, target resolver, pre-orderability resolver, `AccountCustomerFinder`,
  `GuestPreorderSession`, router, canonicalizer); if you redefined the service, copy the new arguments from `config/services.yaml`.
- The confirmation e-mail (`webgriffe_back_in_stock_notification_success_subscription`) gets the variables `subscription`, `product`, `mode`,
  `holdHours`, `manageUrl` and `accountUrl`; the translation key `subscription_unsub` is replaced by `not_you`. Mails go through `SafeSender`,
  which logs `webgriffe_bisn.mail_failed` instead of throwing.
- `SubscriptionType` gains the `name` field and an unmapped honeypot `website`; the `ask_identity` option (default `true`) turns the `name` and
  `email` fields off for a logged-in customer. `AddNotificationComponent` takes the request stack as last constructor argument.
- `SubscriptionUniqueValidator` now takes the target resolver (instead of the repository) first and the channel context last.
- `GuestPreorderSession` remembers the ids of the pre-orders a guest created (session key `webgriffe_bisn_guest_preorders`).
