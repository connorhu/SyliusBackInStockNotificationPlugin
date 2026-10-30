# Pre-order plugin (#48) — design

Issue: #48 (etnobook), design issue for the etnobook look: #79 — done, saved as `docs/design/2026-10-30-elojegyzes.{html,css,js}` and `docs/design/2026-10-30-emailek.js` (group 7 = pre-order mails; supersedes `2026-10-29-emailek.js`). The etnobook theme override is a later spec; this one only takes from the design what the plugin must provide.
Base: fork of `webgriffe/sylius-back-in-stock-notification-plugin` (MIT, v5.0.2, Sylius ^2.0) —
https://github.com/webgriffe/SyliusBackInStockNotificationPlugin. Milestone 1.

## Intent

A visitor who wants a sold-out book can pre-order ("előjegyzés") it and gets an e-mail when a copy
arrives. Two shops need it — etnobook.hu and the egyházzene Sylius shop — so it is built **once, as a
shared plugin with a neutral look**, and each shop adds its own **theme override**. This spec covers
the plugin (the "backend" plus its neutral UI) and the etnobook wiring that does not depend on the
design; the etnobook look is a later spec on top of #79.

The upstream plugin is a plain back-in-stock subscription (per variant, e-mail only, daily cron, no
confirmation page, no ordering). We keep its skeleton (entity, sender, admin grid, account page, cron
command, live component) and extend it in **scoped rounds**.

## Decided (by the owner)

- **Fork, then customize in rounds.** Fork under `connorhu`. Every customization round is one
  well-scoped PR into our fork, so each change and its reason can be followed step by step. **Every
  GitHub artifact of the fork — issues, PRs, commit messages — is in English.** (etnobook-side
  issues/PRs/commits stay Hungarian as usual.)
- **Two layers:** the plugin with a universal look; per-shop theme overrides. The egyházzene shop
  integrates it in its own session (that session has been told to use the fork).
- **Guest pre-order:** name + e-mail. The pre-order is active immediately. A confirmation e-mail goes
  out with a link to a page listing **all** pre-orders of that e-mail address, where each can be
  deleted. The e-mail must clearly say: if it wasn't you, delete it on that page.
- **E-mail that belongs to an account:** the pre-order is attached to that account.
- **Logged-in pre-order:** one click, no data asked; the list lives in the account.
- **Rate limit:** one pre-order per 2 minutes, so nobody can pre-order the whole stock in half a
  minute and block other buyers.
- **On restock, configurable:**
  - **Fastest wins:** everyone waiting is notified at once; whoever buys first gets it (even someone
    who never pre-ordered).
  - **Queue order:** the first in line is notified and the copy is **held** for them for the
    configured hours (**default 24**); when that runs out the next one is notified and the copy is
    held for them, and so on. Somebody notified earlier may still buy after their own hold ran out
    while the copy is there (with three waiting, the first one waking up on day 3 can take it from the
    third).
- **Pre-orderability** is decided per **store (channel) setting** = the base state, editable in admin;
  everything inherits from it. **Taxon** (category) and **product** each have a tri-state
  *allowed / forbidden / inherit*. The sheet's existing "Előjegyzés" column (5 rows) is **not**
  imported for now.

## My calls (flagged for review)

1. **Package identity.** The fork keeps the upstream package name, namespace
   (`Webgriffe\SyliusBackInStockNotificationPlugin`) and table prefix, so every round stays a readable
   diff against upstream and fixes can be offered back. etnobook requires it from the VCS repository
   at `dev-master` (the fork's default branch is `master`). The spec is copied into the fork's `docs/` in round 1 so
   the egyházzene session reads the same document.
2. **Target: product or variant**, a bundle config option `target: product|variant` (default
   `variant` = upstream behaviour). etnobook uses `product` (one copy = one variant; a new copy is
   usually a new variant). Holds are always on **variant units** (that is what can be sold).
3. **Lifecycle** replaces upstream's `notify` boolean:
   `waiting → notified (holdUntil set in queue mode) → fulfilled | cancelled`.
   - `fulfilled` when an order of that customer / e-mail containing the target is **completed**.
   - A `notified` pre-order whose product runs out again before it was fulfilled goes back to
     `waiting`, keeping its original position (antiquarian copies come and go; the person still wants
     the book).
   - `cancelled` = deleted by the person or by an admin (soft state, kept for the admin list).
   - One active (waiting/notified) pre-order per e-mail + target; a duplicate attempt answers "already
     pre-ordered", not an error.
4. **Queue mechanics.** Queue order = `createdAt` of the pre-order. When *N* sellable units of a
   target appear, the first *N* waiting pre-orders are notified at once, each holding one unit. A hold
   that expires passes its unit to the next waiting one. When nobody is left in line, the unit is
   free. Who may buy a held unit: its holder, and anyone whose pre-order of that target is already
   `notified` (earlier holders). Everybody else (not yet notified, or no pre-order) cannot put it in
   the cart while it is held; a free unit is sellable to anyone.
5. **Detection is a cron command** (upstream's `alert` command, rewritten): run every 5 minutes; it
   notifies on restock, advances expired holds, and sends the "hold expired" mail. No event hooks into
   stock changes (stock changes come from imports, admin, cancelled orders — the cron sees all of them
   the same way). etnobook adds it to the crontab of staging/prod.
6. **Hold enforcement** decorates Sylius' `AvailabilityCheckerInterface`: for a visitor who may not
   buy a held unit, held units are subtracted from the available stock. This covers add-to-cart, cart
   quantity changes and Sylius' stock checks at checkout completion. A guest proves they are the
   holder through the **claim link** in the "it arrived" e-mail (signed, valid until the hold ends),
   which marks the pre-order as claimed in the session; a logged-in customer is recognised by the
   account. An order that was placed while the unit was free is never cancelled by the plugin.
7. **Manage page** (guest): a **signed URL** (Symfony `UriSigner` with expiry) carrying the e-mail,
   valid **24 hours** like every other link in the shop — no token table. Expired link → the page
   offers "send me a new link" by e-mail (no login, rate-limited, always answers "sent", so it never
   reveals whether an address has pre-orders). Deleting is a POST with CSRF (upstream's GET delete by
   hash is removed). "Delete all" exists. A logged-in customer's manage link goes to the account page.
8. **Account linking:** at pre-order time a guest e-mail matching a customer **with a shop user**
   attaches the pre-order to that customer; earlier guest pre-orders are matched by e-mail (call 23).
9. **Rate limit:**
   - Per e-mail / customer: at most one new pre-order per *interval*, a channel setting (default
     **120 s**), checked in the database (the newest pre-order of that e-mail), so it is
     admin-editable.
   - Per IP: Symfony rate limiter, sliding window **10 per hour** (bundle config), so a shared network
     (a library) is not blocked by one person.
   - Exceeded → a friendly message ("You can pre-order again in a few minutes."), nothing stored.
10. **Channel settings** (admin, an own "Pre-orders" section on the channel form): pre-orderable by
    default (bool, default **true**), restock mode (`fastest` | `queue`, default `queue`), hold hours
    (int, default **24**), rate-limit interval seconds (default **120**). The plugin ships a trait +
    interface for the shop's `Channel` entity (Sylius plugin convention), as for `Product` and
    `Taxon`.
11. **Pre-orderability resolution** (`PreorderabilityResolver`): product setting if not *inherit*;
    else, over the product's taxons, each taxon's own setting or the nearest ancestor's non-inherit
    setting; any *forbidden* wins over *allowed*; if all inherit, the channel default. Forbidding later
    keeps existing pre-orders (they are still notified); it only blocks new ones. The pre-order button
    renders only when the target is not sellable to this visitor **and** it is pre-orderable.
12. **E-mails** (Sylius mailer codes, neutral templates, English + Hungarian translations; each shop
    overrides the templates):
    - `confirmation` — what happens next (per mode), manage link, "not you? delete it here";
    - `arrived` — product card, mode text (held until … / fastest wins), claim/buy link;
    - `hold_expired` — passed on to the next one; buy link if still sellable;
    - `manage_link` — new manage link on request.
    Each mail is sent with the subscription's channel and locale; mailer failures are logged, never
    break the request (pattern of etnobook #58).
13. **Migrations:** upstream's migrations are MySQL-only and are prepended into the host app, so on
    PostgreSQL they fail. The fork's migrations must run on **PostgreSQL and MySQL** (platform branch in
    each migration); the new schema arrives as new migrations, upstream's are not rewritten.
14. **Tests:** PHPUnit unit tests for all new logic (the resolver, queue/hold allocation, lifecycle,
    rate limit, availability decorator, signed links); upstream's Behat suite is kept working where it
    is cheap, not extended. CI of the fork gets a PostgreSQL job.
15. **Neutral UI** (plugin templates via twig hooks, Bootstrap like Sylius' shop): product-page block
    (button, guest form, logged-in one-click, states: pre-ordered + cancel, held for you until …, held
    for someone else), account "Pre-orders" page (Sylius account layout + menu entry), guest manage
    page, expired-link page. No design work in the plugin; etnobook's override follows #79.
16. **Admin:** upstream grid extended with status, hold-until, name, filters (status, product,
    channel); cancel action. Product and taxon forms get the tri-state field; the channel form the
    settings section.

## Builder-check answers (my calls unless stated)

17. **Restock trigger without a snapshot.** No previous state is stored. On every cron run, for each
    target with active pre-orders: *free units* = sellable units (Sylius availability, undecorated)
    minus units under an active hold. Queue mode: while there are free units and `waiting`
    pre-orders, the next waiting one is notified and holds one unit. Fastest mode: if free units > 0,
    every `waiting` pre-order is notified (no holds). A new pre-order is only possible while the
    target is not sellable *to that visitor* (sold out, or every unit held for others), so "copy A on
    sale, someone pre-orders, copy B arrives" does not occur.
18. **A hold records its variant** (`heldVariant` + `holdUntil` on the pre-order). A lapsed hold is
    handed to the next waiting pre-order on the **same variant** (if it is still sellable); otherwise
    the unit is simply free.
19. **Decorator scope.** The hold-aware availability applies to **shop-section HTTP requests only**
    (Sylius `SectionProviderInterface`); admin, API, CLI and the cron see Sylius' plain availability.
    For a quantity check, held units the visitor may not buy are subtracted before comparing with the
    requested quantity. The visitor = the shop customer (logged in) plus the claims in the session.
20. **Hook.** The plugin keeps upstream's neutral block on Sylius' standard
    `sylius_shop.product.show.content.info.summary`; etnobook (which re-points the product page)
    disables it there and mounts it in its own `sylius_shop.product.show.content.info.main.preorder`
    hook.
21. **Fulfilment** on `sylius.order.post_complete`: the order's customer (or its e-mail,
    canonicalised by `sylius.canonicalizer`) has an active pre-order whose target contains any ordered
    variant → `fulfilled`, and its hold is released.
22. **Claim is session-only.** Opening the claim link marks the hold as claimed in that browser's
    session; on another device the link can simply be opened again (it is valid until the hold
    ends). A logged-in holder never needs it.
23. **Account linking by e-mail at read time** instead of a registration hook: the account list and
    the "is this mine" checks match `customer = me` **or** (`customer` null and canonical e-mail =
    mine). New pre-orders by a logged-in customer, or by a guest with an account e-mail, store the
    customer. This covers form and API registration alike (call 8's registration hook is dropped).
24. **Migrations:** round 1 makes the two upstream migrations PostgreSQL-safe in place (platform
    branch), so a PostgreSQL host runs the whole chain from zero; later schema changes are new
    migrations with both branches.
25. **Bot protection:** a hidden honeypot field on the guest form (silently "succeeds" without storing
    or mailing) in addition to the rate limits; no CAPTCHA.
26. **Mail templates:** the etnobook overrides of the four mails come with the #79 theme spec (the
    design covers the mails); until then etnobook sends the plugin's neutral Hungarian mails.

## What the #79 design needs from the plugin (added 2026-10-30)

27. **Queue position** of a `waiting` pre-order (account list "A sorban: 3." / "Te vagy a következő"):
    1 + the number of earlier active `waiting` pre-orders of the same target and channel.
28. **Guest "pre-ordered" state on the product page:** the ids a guest created in this session are
    remembered (session key), so the product page shows "Előjegyezve" for them; a guest cancels only
    through the manage link (design state `res-guest`).
29. **Undo of a cancel** (account list, product-page toast "Mégse", guest "Mindet törlöm"): the last
    cancelled id(s) are kept in the session for 10 minutes; restore puts them back to `waiting` with
    their original `createdAt` (original queue position).
30. **Fulfilled rows can be removed from the list** ("Eltávolítás"): `dismissedAt`; lists hide
    dismissed rows, the admin still sees them.
31. **Account summary for the header badge:** active count (waiting + notified) and whether any is
    actionable (notified with a hold for me, or notified in fastest mode while sellable) → the theme
    colours the badge okker.
32. **Confirmation mail, account variant:** when the pre-order belongs to a customer the button goes
    to the account page instead of the manage link (design `pre-confirm-acct`); the success screen is
    the same either way, so it never reveals whether an address has an account.
33. **Rate-limit copy** (neutral `hu` translation follows the design): "Pár perc múlva jegyezhetsz elő
    újra." + "Két percenként egy előjegyzést fogadunk el. Az adataid megmaradtak, próbáld újra kicsit
    később." (the seconds come from the channel setting).
34. Left to the **theme spec**: tile labels ("Elfogyott · előjegyezhető", "Félretéve · okt. 31-ig") —
    the plugin offers a batch query of active hold deadlines per product for it (Task 10), header and
    drawer rows (guest row → the manage-request page), account frame, all etnobook templates and mails.

## Rounds (one fork PR each, stacked, English)

| # | Round | Contents |
|---|---|---|
| 1 | Fork baseline | fork, composer constraints that resolve with Sylius 2.2.12 / Doctrine DBAL 3 / ORM 3 / PHP 8.5, PostgreSQL-safe migrations + PG CI job, spec copied to `docs/` |
| 2 | Target and lifecycle | `target: product|variant`, `product` relation, status + timestamps + `name`, uniqueness rule, upstream `notify` migrated |
| 3 | Pre-orderability | channel/product/taxon traits + interfaces, resolver, admin form fields, button gating |
| 4 | Guest flow and manage page | name field, account linking (pre-order + registration), signed manage link, manage page, delete/delete-all POST, new-link request, `confirmation`/`manage_link` mails, GET delete removed |
| 5 | Rate limit | channel interval check + IP limiter, messages |
| 6 | Restock processing | channel mode/hold settings, rewritten cron command, holds, `arrived`/`hold_expired` mails, claim link, back-to-waiting |
| 7 | Hold enforcement and fulfilment | availability checker decorator, claim session, order-completed → fulfilled |
| 8 | Neutral UI, admin grid, translations | product-page states, account page, admin grid columns/filters/cancel, `en` + `hu` translations |

Each round: unit tests, phpstan/psalm as the repo already runs them, CHANGELOG/UPGRADE note in the
fork. Rounds stack (each PR bases on the previous one); one merge run at the end.

## etnobook wiring (Hungarian PR in etnobook, after the fork rounds)

- `composer.json`: VCS repository + `webgriffe/sylius-back-in-stock-notification-plugin: dev-master`;
  bundle + routes registered; `target: product`.
- `App\Entity\Channel\Channel`, `Product\Product`, `Taxonomy\Taxon` use the plugin traits; one app
  migration if the plugin's migrations do not cover the app entity columns (builder decides with the
  plugin's convention).
- Hold-aware "purchasable": etnobook's own `PurchasableVariants` (cards, product page, wishlist) uses the
  plugin's decorated availability checker, so a held copy is not offered to others.
- Crontab entry for the alert command on staging/prod (`docs/ops/`).
- Until the #79 theme override, the product page shows the plugin's neutral block in the existing
  `sylius_shop.product.show.content.info.main.preorder` hook; the account frame row "Előjegyzéseim"
  and the header rows come with the theme override.

## Not in scope

- Importing the sheet's "Előjegyzés" column.
- The etnobook look (#79 → later spec), the egyházzene integration (its own session).
- SMS/other channels, price watching, sharing.
- Event-driven (instant) restock detection.

## Left to the builder

- Exact class layout inside the fork, how the cron command batches work for large tables, the claim
  session key format, how the admin grid filters are built, how the PG/MySQL migration branching is
  written.
