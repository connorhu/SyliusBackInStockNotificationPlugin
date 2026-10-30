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

## Restock processing (BC break)

- Run `webgriffe:back-in-stock-notification:alert` **every 5 minutes** instead of once a day (`*/5 * * * *`): it now also expires holds,
  whose length is set in hours per channel. Overlapping runs exit at once (`LockableTrait`), which requires `symfony/lock` (now a dependency).
- `AlertCommand` takes only the new `RestockProcessor` service (and the optional name); its old constructor arguments (logger, sender,
  availability checker, repository, router) are gone. Its logic lives in `Webgriffe\SyliusBackInStockNotificationPlugin\Restock\RestockProcessor::process(\DateTimeImmutable $now): RestockReport`.
  The command no longer deletes subscriptions without a variant (a product-target pre-order has none).
- Per target (channel + product, or + variant for the `variant` target), in this order: a hold with `holdUntil <= now` is released and the
  holder gets the new `webgriffe_back_in_stock_notification_hold_expired` mail (they stay `notified` and may still buy); a `notified` pre-order
  without an active hold whose target has no sellable unit goes back to `waiting` (keeping its `createdAt`); then free units (sellable units
  minus active holds) go to `waiting` pre-orders: `fastest` notifies all of them without a hold, `queue` notifies one per free unit in
  `createdAt` order and holds that unit for the channel's hold hours, counted from the run that hands it over.
- Sellable units (`SellableUnits`) use Sylius' plain `AvailabilityChecker` (an own instance, so a decorated `sylius.checker.inventory.availability`
  does not apply), and count only enabled variants of enabled products that are in the channel and have a price there.
- Each target's state is flushed before its mails are sent. A failing target is logged (`webgriffe_bisn.restock.target_failed`) and skipped;
  a failing flush is logged (`webgriffe_bisn.restock.flush_failed`) and stops the run (exit code 1).
- The `alert` mail gets the variables `mode`, `holdUntil` and `variant` and the keys `webgriffe_bisn.alert_email.held_until` / `.fastest`;
  the alert and hold-expired templates read the product name and slug in the subscription's locale (`product.translation(localeCode)`),
  so the links work from the console; the product name is no longer printed `|raw`. `SubscriptionRepositoryInterface` gains
  `findRestockTargets()` and `countActiveHoldsByVariant()`.
- Active holds are counted per variant across **all channels** (stock is shared), so a unit held in one channel is not free in another.
- Rows that do not match the configured target (a variant set under the `product` target, or no variant under the `variant` target, e.g.
  after switching the target) are skipped with the warning `webgriffe_bisn.restock.target_mismatch`; migrate or cancel them by hand.

## Hold enforcement, claim links and fulfilment

- `Webgriffe\SyliusBackInStockNotificationPlugin\Hold\HoldAwareAvailabilityChecker` **decorates `sylius.checker.inventory.availability`**.
  In the shop section (Sylius `SectionProviderInterface`, `ShopSection` and its sub-sections) units under an active hold (`notified`,
  `holdUntil > now`, counted per variant across all channels) are not available to a visitor who may not buy them:
  `isStockSufficient($variant, $q)` = the decorated answer and `onHand - onHold - holds >= $q`. The holder and anyone whose pre-order of the
  variant's target is already `notified` may buy held units. Admin, API, CLI and the restock run see the decorated checker's plain answer
  (`SellableUnits` keeps its own undecorated instance). If you decorate the checker yourself, mind the decoration priority.
- The visitor (`HoldVisitor`) is the logged-in customer (pre-orders with that customer, or without a customer and with its canonical e-mail)
  plus the pre-orders claimed in this session (`ClaimSession`, key `webgriffe_bisn_claims`; reading never starts a session). Both services
  memoise per request and are tagged `kernel.reset`: the checker loads every active hold count with one query
  (`SubscriptionRepositoryInterface::countAllActiveHolds()`) and the visitor's pre-orders with one more, however many variants a page
  checks. A claim stays in the session after the hold ends; while that pre-order is still `notified` its holder keeps the right every
  notified pre-order of the target has (buying held units).
- New route `webgriffe_back_in_stock_notification_claim` (`GET /back-in-stock-notification/claim/{hash}`, shop routing): the signed link
  (`ClaimLinkGenerator`, hash + `expires` = the end of the hold, `UriSigner`) marks the pre-order as claimed in the session and redirects to
  the product page; an invalid or expired link redirects there with the info flash `webgriffe_bisn.claim.expired` (new `flashes` domain).
- The `alert` mail gets the variable `claimUrl` (the claim link while a unit is held, the product page otherwise) and the key
  `webgriffe_bisn.alert_email.claim_hint`; the buy button uses `claimUrl`. `RestockProcessor` takes the `ClaimLinkGenerator` as a new last
  constructor argument.
- `OrderCompletedListener` listens to **`sylius.order.post_complete`**: the active pre-orders of the order's customer (or, without a
  customer, of its canonical e-mail) whose target contains an ordered variant become `fulfilled` (hold released). A failure is logged as
  `webgriffe_bisn.order_fulfilment_failed` and never breaks the checkout (the order is already committed; a failing flush closes the shared
  entity manager, which later `post_complete` listeners would notice). Orders completed through the API do not dispatch this event.
- `PreorderStateResolver::resolve($product, $variant, $channel)` (`webgriffe.resolver.preorder_state`) returns a `PreorderView`
  (`state`: `PreorderState` enum `NotPreorderable`, `CanPreorder`, `Preordered`, `HeldForYou`, `HeldForOthers`, `Sellable`; `subscription`,
  `holdUntil`, `position`). A guest's own pre-orders of this session (`GuestPreorderSession`) resolve as `Preordered`.
- `SubscriptionRepositoryInterface` gains `countAllActiveHolds()`, `findActiveByVisitor()`, `queuePosition()` (1 + earlier waiting pre-orders of the same target and
  channel, rule in `State\QueuePosition`), `activeHoldDeadlinesByProduct()` (latest active hold per product, one query) and
  `accountSummary()` (active count and whether any is actionable).
