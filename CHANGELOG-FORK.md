# Fork changelog

This file tracks the rounds of the pre-order work done in this fork of
`webgriffe/SyliusBackInStockNotificationPlugin` (see `docs/preorder-design.md`). One line per round.

- Round 1: PostgreSQL-safe migrations (platform branch in both existing migrations), PostgreSQL 17 CI job, unit-test suite (`phpunit.unit.xml.dist`), design document, test application excludes the Messenger table from the schema check (`schema_filter`).
- Round 2: subscriptions get a `product` relation, a configurable `product`/`variant` target (`TargetResolver`), a lifecycle `status` (waiting/notified/fulfilled/cancelled, replaces `notify`), canonical e-mail and name, hold/dismiss fields; migration `Version20261030100000` (PostgreSQL + MySQL), UPGRADE-6.0.md.
- Round 3: pre-orderability: `Preorderability` enum, `PreorderabilityResolver` (product, taxon ancestry, channel default), host-entity traits/interfaces for product, taxon and channel settings, admin form fields via twig hooks, product-page block gated; migration `Version20261030110000` (PostgreSQL + MySQL).
