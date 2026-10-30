<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Behat\Page\Shop\Account\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPageInterface;

interface IndexPageInterface extends SymfonyPageInterface
{
    public function cancelFirstSubscription(): void;

    public function hasUndoBar(): bool;

    public function countSubscriptions(): int;

    public function isPresentNoSubscriptionInfoMessage(): bool;
}
