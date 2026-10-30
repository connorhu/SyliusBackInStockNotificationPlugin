<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Behat\Page\Shop\Account\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

final class IndexPage extends SymfonyPage implements IndexPageInterface
{
    public function getRouteName(): string
    {
        return 'webgriffe_back_in_stock_notification_account_list_subscriptions';
    }

    public function cancelFirstSubscription(): void
    {
        $this->getElement('first_subscription')->click();
    }

    public function countSubscriptions(): int
    {
        return count($this->getDocument()->findAll('css', '[data-test-grid-table-body] [data-test-row]'));
    }

    public function isPresentNoSubscriptionInfoMessage(): bool
    {
        return !$this->hasElement('table');
    }

    public function hasUndoBar(): bool
    {
        return $this->hasElement('undo_bar');
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'first_subscription' => '[data-test-grid-table-body] [data-test-row]:first-child [data-test-button-cancel]',
            'table' => '[data-test-grid-table-body]',
            'undo_bar' => '[data-test-undo-bar]',
        ]);
    }
}
