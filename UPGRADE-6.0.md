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
  `GuestPreorderSession`, router, canonicalizer, logger); if you redefined the service, copy the new arguments from `config/services.yaml`.
- The confirmation e-mail (`webgriffe_back_in_stock_notification_success_subscription`) gets the variables `subscription`, `product`, `mode`,
  `holdHours`, `manageUrl` and `accountUrl`; the translation key `subscription_unsub` is replaced by `not_you`. Mails go through `SafeSender`,
  which logs `webgriffe_bisn.mail_failed` instead of throwing.
- `SubscriptionType` gains the `name` field and an unmapped honeypot `website`; the `ask_identity` option (default `true`) turns the `name` and
  `email` fields off for a logged-in customer. `AddNotificationComponent` takes the request stack as last constructor argument.
- The `SubscriptionUnique` constraint, its validator and the service are removed: a form error for an already pre-ordered address would
  reveal who has pre-ordered. Duplicates are detected in the processor (`ProcessResult::AlreadySubscribed`); a guest sees the same success
  state as for a new pre-order (nothing stored, no second mail), a logged-in customer sees the `webgriffe_bisn.subscription.already_saved` error.
- `GuestPreorderSession` remembers the ids of the pre-orders a guest created (session key `webgriffe_bisn_guest_preorders`).

## Manage page replaces the GET delete (BC break)

- The route `webgriffe_back_in_stock_notification_delete_subscription` (GET delete by hash), `SubscriptionController` and the account grid's
  `delete` field with its template are removed: a state-changing GET link in a mail can be triggered by anyone holding it. Guests manage their
  pre-orders on `webgriffe_back_in_stock_notification_manage`, opened from a signed link (e-mail + `expires`, valid 24 hours, no token table);
  deleting is a CSRF-protected POST that cancels softly (`markCancelled`) and can be undone for 10 minutes (`CancelUndoSession`).
- New routes: `..._manage`, `..._manage_request`, `..._manage_link_sent`, `..._manage_delete`, `..._manage_delete_all`, `..._manage_restore`;
  new mail code `webgriffe_back_in_stock_notification_manage_link`.
- `symfony/rate-limiter` is now required. The bundle prepends the limiters `webgriffe_bisn_manage_link_email` (1 per 60 s) and
  `webgriffe_bisn_manage_link_ip` (10 per hour) to `framework.rate_limiter`.
- `SubscriptionProcessor` takes the `ManageLinkGenerator` as a new constructor argument (after the router); the confirmation mail's
  `manageUrl` is now filled for guest pre-orders.
- `SubscriptionRepositoryInterface` gains `findManageableByEmail()`, `findActiveByEmail()`, `findOneByHashAndEmail()` and `findCancelledByIds()`.
- The "new link" mail is sent on `kernel.terminate` by `ManageLinkMailer` (lookup included), so the request's timing does not reveal whether an
  address has pre-orders. `findOneByHashAndEmail()` and `findCancelledByIds()` take the channel as an extra argument.
