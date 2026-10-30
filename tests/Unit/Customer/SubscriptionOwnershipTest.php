<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Customer;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\SubscriptionOwnership;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;

final class SubscriptionOwnershipTest extends TestCase
{
    public function testOwnCustomerRowIsOwned(): void
    {
        $anna = $this->customer(1, 'anna@example.com');

        self::assertTrue(SubscriptionOwnership::isOwnedBy($this->subscription($anna, 'anna@example.com'), $anna));
    }

    public function testSameCustomerIdOnAnotherInstanceIsOwned(): void
    {
        self::assertTrue(SubscriptionOwnership::isOwnedBy(
            $this->subscription($this->customer(1, 'anna@example.com'), 'anna@example.com'),
            $this->customer(1, 'anna@example.com'),
        ));
    }

    public function testAnotherCustomersRowIsNotOwnedEvenWithTheSameEmail(): void
    {
        self::assertFalse(SubscriptionOwnership::isOwnedBy(
            $this->subscription($this->customer(2, 'other@example.com'), 'anna@example.com'),
            $this->customer(1, 'anna@example.com'),
        ));
    }

    public function testGuestRowWithMyCanonicalEmailIsOwned(): void
    {
        self::assertTrue(SubscriptionOwnership::isOwnedBy($this->subscription(null, 'anna@example.com'), $this->customer(1, 'anna@example.com')));
    }

    public function testGuestRowWithAnotherEmailIsNotOwned(): void
    {
        self::assertFalse(SubscriptionOwnership::isOwnedBy($this->subscription(null, 'bob@example.com'), $this->customer(1, 'anna@example.com')));
    }

    public function testNothingIsOwnedWithoutACustomerOrCanonicalEmail(): void
    {
        $noEmail = new Customer();
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($noEmail, 1);

        self::assertFalse(SubscriptionOwnership::isOwnedBy($this->subscription(null, 'anna@example.com'), null));
        self::assertFalse(SubscriptionOwnership::isOwnedBy($this->subscription(null, ''), $noEmail));
    }

    private function customer(int $id, string $email): Customer
    {
        $customer = new Customer();
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, $id);
        $customer->setEmail($email);
        $customer->setEmailCanonical($email);

        return $customer;
    }

    private function subscription(?Customer $customer, string $emailCanonical): Subscription
    {
        $subscription = new Subscription();
        $subscription->setCustomer($customer);
        $subscription->setEmailCanonical($emailCanonical);

        return $subscription;
    }
}
