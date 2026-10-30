<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use RuntimeException;
use Sylius\Behat\NotificationType;
use Sylius\Behat\Service\Checker\EmailCheckerInterface;
use Sylius\Behat\Service\NotificationCheckerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Behat\Element\Product\ShowPage\SubscriptionFormElementInterface;
use Webmozart\Assert\Assert;

final class ProductInventoryContext implements Context
{
    public function __construct(private NotificationCheckerInterface $notificationChecker, private EmailCheckerInterface $emailChecker, private TranslatorInterface $translator, private SubscriptionFormElementInterface $subscriptionFormElement)
    {
    }

    /**
     * @When I subscribe to the alert list for the product :product
     * @When I subscribe to the alert list for the product :product with the email :email
     */
    public function iSubscribeToTheAlertListForThisProduct(ProductInterface $product, string $email = null): void
    {
        $productCode = $product->getCode();
        if ($productCode === null) {
            throw new RuntimeException('Could not load the product properly');
        }

        if ($email !== null) {
            $this->subscriptionFormElement->submitFormAsAGuest($productCode, $email);
        } else {
            $this->subscriptionFormElement->submitFormAsALoggedCustomer($productCode);
        }
    }

    /**
     * @Then I should be notified that the email is subscribed correctly
     */
    public function iShouldBeNotifiedThatTheEmailIsSubscribedCorrectly(): void
    {
        $this->notificationChecker->checkNotification(
            $this->translator->trans('webgriffe_bisn.form_submission.subscription_successfully'),
            NotificationType::success(),
        );
    }

    /**
     * A logged-in customer sees the pre-ordered state of the product block instead of a flash message.
     *
     * @Then I should see that I have pre-ordered the product
     */
    public function iShouldSeeThatIHavePreorderedTheProduct(): void
    {
        Assert::true($this->subscriptionFormElement->isPreordered(), 'The product page does not show the pre-ordered state.');
    }

    /**
     * @Then an email with a success message should be sent to :email
     */
    public function anEmailWithASuccessMessageShouldBeSentTo(string $email): void
    {
        Assert::true($this->emailChecker->hasMessageTo(
            $this->translator->trans('webgriffe_bisn.subscription_mail.subscription_title'),
            $email,
        ));
    }

    /**
     * A guest sees the same success state for an already pre-ordered address, so the page never reveals who has pre-ordered.
     *
     * @Then I should be notified that the email :email is already subscribed
     */
    public function iShouldBeNotifiedThatTheEmailIsAlreadySubscribed(string $email): void
    {
        $this->notificationChecker->checkNotification(
            $this->translator->trans('webgriffe_bisn.form_submission.subscription_successfully'),
            NotificationType::success(),
        );
    }
}
