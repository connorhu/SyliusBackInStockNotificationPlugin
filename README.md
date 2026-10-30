<p align="center">
    <a href="https://sylius.com" target="_blank">
        <img src="https://demo.sylius.com/assets/shop/img/logo.png" />
    </a>
</p>

<h1 align="center">Back in Stock Notification Plugin</h1>

<p align="center">Plugin that add the possibility to receive an email notification when a product return in stock</p>

<p align="center"><a href="https://github.com/webgriffe/SyliusBackInStockNotificationPlugin/actions"><img src="https://github.com/webgriffe/SyliusBackInStockNotificationPlugin/workflows/Build/badge.svg" alt="Build Status" /></a></p>

## Features

### Subscribe to get a Notification when a product return in stock

![Subscription for simple product](docs/product.png)

### View your subscriptions in the apposite section of your account

![See the apposite section in the my account](docs/my_account_section.png)

### View your client subscriptions in the apposite admin section

![See the apposite section in the admin account](docs/admin_account_section.png)

## Requirements

* PHP `^8.2`
* Sylius `^2.0`

## Installation

1. Run `composer require webgriffe/sylius-back-in-stock-notification-plugin --no-scripts`.

2. Add the plugin to the `config/bundles.php` file:

```php
Webgriffe\SyliusBackInStockNotificationPlugin\WebgriffeSyliusBackInStockNotificationPlugin::class => ['all' => true],
```

3. Import the plugin routes by creating a file in `config/routes/webgriffe_sylius_back_in_stock_notification_plugin.yaml`. The shop
   routes (product-page actions, account page, manage and claim links) live under the locale prefix:

```yaml
webgriffe_back_in_stock_notification_admin:
    resource: "@WebgriffeSyliusBackInStockNotificationPlugin/config/routing/admin_routing.yaml"
    prefix: '/%sylius_admin.path_name%'

webgriffe_back_in_stock_notification_shop:
    resource: "@WebgriffeSyliusBackInStockNotificationPlugin/config/routing/shop_routing.yaml"
    prefix: /{_locale}
    requirements:
        _locale: ^[A-Za-z]{2,4}(_([A-Za-z]{4}|[0-9]{3}))?(_([A-Za-z]{2}|[0-9]{3}))?$
```

4. Import required packages config in your `config/packages/webgriffe_sylius_back_in_stock_notification_plugin.yaml` file:

```yaml
imports:
   - { resource: "@WebgriffeSyliusBackInStockNotificationPlugin/config/packages.yaml" }
```

5. Update the database schema and install assets:

```bash
bin/console doctrine:migrations:migrate
bin/console assets:install
bin/console sylius:theme:assets:install
```

6. Clear cache:

```bash
bin/console cache:clear
```

## Pre-orderability (optional)

Whether the notification/pre-order block shows on a product page is decided per product, per category (taxon) and per channel:
a product's own setting (`inherit`, `allowed`, `forbidden`) wins unless it inherits; then the product's categories decide (each
category's own setting or its nearest ancestor's; any `forbidden` beats `allowed`); if everything inherits, the channel default applies
(enabled by default). Entities that do not implement the interfaces below simply count as `inherit` / the channel defaults.

To get the admin fields, make your entities implement the interfaces and use the traits (the traits carry Doctrine ORM attributes, so
the entities must be attribute-mapped), then run the plugin migrations:

```php
class Product extends BaseProduct implements PreorderabilityAwareInterface
{
    use ProductPreorderabilityTrait;
}

class Taxon extends BaseTaxon implements PreorderabilityAwareInterface
{
    use TaxonPreorderabilityTrait;
}

class Channel extends BaseChannel implements PreorderChannelSettingsInterface
{
    use PreorderChannelSettingsTrait;
}
```

(all in `Webgriffe\SyliusBackInStockNotificationPlugin\Entity`). If your entities are mapped in XML, map the columns yourself instead of using
the traits' attributes, e.g. for the product (the trait provides the property and accessors; leave the attributes out by implementing the
property yourself):

```xml
<field name="preorderability" column="preorderability" type="string" length="16" enum-type="Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\Preorderability">
    <options><option name="default">inherit</option></options>
</field>
```

and for the channel `preorder_enabled_by_default` (boolean, default `true`), `preorder_mode` (string 16, default `queue`),
`preorder_hold_hours` (integer, default `24`), `preorder_rate_limit_seconds` (integer, default `120`).

## Pre-orders, queue and holds (fork)

Besides the stock alert this fork turns the subscription into a pre-order with a lifecycle (`waiting`, `notified`, `fulfilled`,
`cancelled`), a per-channel restock mode (`queue`: the first in line gets a copy held for the channel's hold hours; `fastest`: everyone
is notified), guest pre-orders (name and e-mail, honeypot, rate limits) with signed manage links, and a neutral UI:

* **Product page**: one block for every state of the visitor (can pre-order, pre-ordered with queue position and cancel, held for you
  until a date, held for somebody else, nothing when the item can be bought). A logged-in customer pre-orders with one click; a guest
  fills in name and e-mail; a guest cancels through the link in the e-mail.
* **Account**: "Pre-orders" page (Sylius account layout, menu entry) listing the customer's own pre-orders and those a guest made with
  the customer's e-mail, with cancel (undo for 10 minutes), remove for fulfilled ones and "held for you until ..." with a buy link.
* **Admin**: the subscription grid shows product, variant, name, e-mail, status, hold deadline and date, filters by status, product code,
  channel and e-mail, and has a cancel action. Products, categories and channels get the pre-order settings.

### Target

```yaml
# config/packages/webgriffe_sylius_back_in_stock_notification_plugin.yaml
webgriffe_sylius_back_in_stock_notification:
    target: variant  # or `product`: a pre-order waits for any variant of the product
    display_timezone: ~  # e.g. Europe/Budapest; null prints dates in the PHP default time zone
```

Dates in the shop (hold deadlines, the account list) are printed with Twig's `format_datetime` (`twig/intl-extra`) in the request
locale; `display_timezone` selects the time zone, which matters when PHP runs in UTC but the shop does not.

### Twig helpers for themes

`webgriffe_bisn_view(product, variant = null)` returns the visitor's `PreorderView` (`state`: a `PreorderState`, `subscription`,
`holdUntil`, `position`); `webgriffe_bisn_account_summary()` returns `{active, actionable}` of the logged-in customer (null for a guest;
for a header badge); `webgriffe_bisn_hold_deadlines(products)` returns the latest active hold deadline per product id in one query;
`webgriffe_bisn_timezone()` returns `display_timezone`.

## Configuration

This module sends mail using a Symfony Command. Unfortunately, the command line context does not know about your VirtualHost or domain name. To fix this, the Command loads the hostname from the Subscription's channel, if nothing set it defaults to `localhost`. In console commands, URLs use http by default. You can change this globally with these configuration parameters:

1. Edit the `config/services.yml` file by adding the following content:

```yaml
parameters:
    router.request_context.scheme: https
```

2. As said early this module provides a command that checks the stock of the pre-ordered products, notifies the waiting
   pre-orders (by the channel's restock mode, `fastest` or `queue`), passes expired holds on and moves pre-orders of a
   sold-out product back to waiting. Holds are measured in hours, so run it every 5 minutes (overlapping runs exit at once):

```bash
*/5 * * * * <absolute-php-path> <absolute-path-to-sylius-dir>/bin/console webgriffe:back-in-stock-notification:alert
```

## Optional Configuration

If you want to use our Behat defined steps you have to include our Behat class in your autoloader-dev. To achieve this adds the following line to your `composer.json`:

```json
"autoload-dev": {
    "psr-4": {
      "Tests\\Webgriffe\\SyliusBackInStockNotificationPlugin\\": "vendor/webgriffe/sylius-back-in-stock-notification-plugin/tests/"
    }
},
```

## Contributing

To contribute to this plugin clone this repository, create a branch for your feature or bugfix, do your changes and then make sure al tests are passing.

```bash
composer install
(cd vendor/sylius/test-application && yarn install)
(cd vendor/sylius/test-application && yarn build)
vendor/bin/console assets:install

docker-compose up -d # only if you haven't mysql and mailhog installed locally 

vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
# Optionally load data fixtures
vendor/bin/console sylius:fixtures:load -n

symfony server:ca:install
symfony server:start -d
```

### Running plugin tests

#### PHPUnit

```bash
vendor/bin/phpunit
```

#### Behat (non-JS scenarios)

```bash
vendor/bin/behat --strict --tags="~@javascript"
```

#### Behat (JS scenarios)

1. [Install Symfony CLI command](https://symfony.com/download).
    
2. Start Headless Chrome:

```bash
google-chrome-stable --enable-automation --disable-background-networking --no-default-browser-check --no-first-run --disable-popup-blocking --disable-default-apps --allow-insecure-localhost --disable-translate --disable-extensions --no-sandbox --enable-features=Metal --headless --remote-debugging-port=9222 --window-size=2880,1800 --proxy-server='direct://' --proxy-bypass-list='*' http://127.0.0.1
```

3. Install SSL certificates (only once needed) and run test application's webserver on `127.0.0.1:8080`:

```bash
symfony server:ca:install
APP_ENV=test symfony server:start --port=8080 --dir=tests/Application/public --daemon
```

4. Run Behat:

```bash
vendor/bin/behat --strict --tags="@javascript"
```
  
#### Static Analysis

##### Psalm

```bash
vendor/bin/psalm
```

##### PHPStan

```bash
vendor/bin/phpstan analyse -c phpstan.neon
```

##### Coding Standard

```bash
vendor/bin/ecs check
```

### Opening Sylius with your plugin

- Using `test` environment:

```bash
(cd tests/Application && APP_ENV=test bin/console sylius:fixtures:load)
APP_ENV=test symfony server:start --port=8080 --dir=tests/Application/public --daemon
```

- Using `dev` environment:

```bash
(cd tests/Application && APP_ENV=dev bin/console sylius:fixtures:load)
APP_ENV=dev symfony server:start --dir=tests/Application/public --daemon
```

## License

This plugin is under the MIT license. See the complete license in the LICENSE file.

## Credits

Developed by [Webgriffe®](http://www.webgriffe.com/).
