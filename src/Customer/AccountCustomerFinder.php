<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Customer;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;

/**
 * Finds the customer that owns a registered account (a shop user) for an e-mail address.
 */
final class AccountCustomerFinder
{
    public function __construct(private CustomerRepositoryInterface $customerRepository)
    {
    }

    public function find(string $emailCanonical): ?CustomerInterface
    {
        $customer = $this->customerRepository->findOneBy(['emailCanonical' => $emailCanonical]);
        if (!$customer instanceof CustomerInterface || null === $customer->getUser()) {
            return null;
        }

        return $customer;
    }
}
