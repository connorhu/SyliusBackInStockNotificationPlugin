<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

use Sylius\Component\Channel\Model\ChannelAwareInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerAwareInterface;
use Sylius\Component\Resource\Model\ResourceInterface;
use Sylius\Component\Resource\Model\TimestampableInterface;

interface SubscriptionInterface extends ResourceInterface, CustomerAwareInterface, ChannelAwareInterface, TimestampableInterface, ProductVariantAwareInterface
{
    public function getHash(): ?string;

    public function setHash(string $hash): void;

    public function getEmail(): ?string;

    public function setEmail(string $email): void;

    public function getLocaleCode(): ?string;

    public function setLocaleCode(string $localeCode): void;

    public function getEmailCanonical(): ?string;

    public function setEmailCanonical(?string $emailCanonical): void;

    public function getName(): ?string;

    public function setName(?string $name): void;

    public function getProduct(): ?ProductInterface;

    public function setProduct(ProductInterface $product): void;

    /** One of the SubscriptionStatus constants. */
    public function getStatus(): string;

    public function isActive(): bool;

    public function getNotifiedAt(): ?\DateTimeImmutable;

    public function getHoldUntil(): ?\DateTimeImmutable;

    public function getFulfilledAt(): ?\DateTimeImmutable;

    public function getCancelledAt(): ?\DateTimeImmutable;

    public function getDismissedAt(): ?\DateTimeImmutable;

    public function getHeldVariant(): ?ProductVariantInterface;

    public function markNotified(\DateTimeImmutable $now, ?ProductVariantInterface $heldVariant, ?\DateTimeImmutable $holdUntil): void;

    public function releaseHold(): void;

    public function markWaiting(): void;

    public function markFulfilled(\DateTimeImmutable $now): void;

    public function markCancelled(\DateTimeImmutable $now): void;

    /** Cancelled -> waiting, keeps the original createdAt (queue position). */
    public function restore(): void;

    /** Hides a fulfilled subscription from the customer's list. */
    public function dismiss(\DateTimeImmutable $now): void;

    public function isDismissed(): bool;
}
