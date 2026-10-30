<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Behat\Page\Shop\Account\Subscription\IndexPageInterface;
use Webmozart\Assert\Assert;

final class AccountContext implements Context
{
    public function __construct(private IndexPageInterface $subscriptionIndexPage)
    {
    }

    /**
     * @When /^I browse to my product subscriptions$/
     */
    public function iBrowseToMyProductSubscriptions(): void
    {
        $this->subscriptionIndexPage->open();
    }

    /**
     * @When /^I cancel the first subscription$/
     */
    public function iCancelTheFirstSubscription(): void
    {
        $this->subscriptionIndexPage->cancelFirstSubscription();
    }

    /**
     * @Then /^I should be able to undo the cancellation$/
     */
    public function iShouldBeAbleToUndoTheCancellation(): void
    {
        Assert::true($this->subscriptionIndexPage->hasUndoBar());
    }

    /**
     * @Then /^there should be no subscriptions$/
     */
    public function thereShouldBeNoSubscriptions(): void
    {
        Assert::true($this->subscriptionIndexPage->isPresentNoSubscriptionInfoMessage());
    }

    /**
     * @Then /^I should see only one subscription$/
     */
    public function iShouldSeeOnlyOneSubscription(): void
    {
        Assert::same($this->subscriptionIndexPage->countSubscriptions(), 1);
    }
}
