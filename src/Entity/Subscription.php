<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Resource\Model\TimestampableTrait;

/**
 * @psalm-suppress ClassMustBeFinal
 */
class Subscription implements SubscriptionInterface
{
    use TimestampableTrait;

    private ?int $id = null;

    private ?string $hash = null;

    private ?string $email = null;

    private ?string $emailCanonical = null;

    private ?string $name = null;

    private ?CustomerInterface $customer = null;

    private ?ProductInterface $product = null;

    private ?ProductVariantInterface $productVariant = null;

    private ?ChannelInterface $channel = null;

    private ?string $localeCode = null;

    private string $status = SubscriptionStatus::WAITING;

    private ?\DateTimeImmutable $notifiedAt = null;

    private ?\DateTimeImmutable $holdUntil = null;

    private ?ProductVariantInterface $heldVariant = null;

    private ?\DateTimeImmutable $fulfilledAt = null;

    private ?\DateTimeImmutable $cancelledAt = null;

    private ?\DateTimeImmutable $dismissedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(string $hash): void
    {
        $this->hash = $hash;
    }

    public function getLocaleCode(): ?string
    {
        return $this->localeCode;
    }

    public function setLocaleCode(string $localeCode): void
    {
        $this->localeCode = $localeCode;
    }

    public function getChannel(): ?ChannelInterface
    {
        return $this->channel;
    }

    public function setChannel(?ChannelInterface $channel): void
    {
        $this->channel = $channel;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

    public function getProductVariant(): ?ProductVariantInterface
    {
        return $this->productVariant;
    }

    public function setProductVariant(?ProductVariantInterface $productVariant): void
    {
        $this->productVariant = $productVariant;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getEmailCanonical(): ?string
    {
        return $this->emailCanonical;
    }

    public function setEmailCanonical(?string $emailCanonical): void
    {
        $this->emailCanonical = $emailCanonical;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getProduct(): ?ProductInterface
    {
        return $this->product;
    }

    public function setProduct(ProductInterface $product): void
    {
        $this->product = $product;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return in_array($this->status, SubscriptionStatus::ACTIVE, true);
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function getHoldUntil(): ?\DateTimeImmutable
    {
        return $this->holdUntil;
    }

    public function getFulfilledAt(): ?\DateTimeImmutable
    {
        return $this->fulfilledAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getDismissedAt(): ?\DateTimeImmutable
    {
        return $this->dismissedAt;
    }

    public function getHeldVariant(): ?ProductVariantInterface
    {
        return $this->heldVariant;
    }

    public function markNotified(\DateTimeImmutable $now, ?ProductVariantInterface $heldVariant, ?\DateTimeImmutable $holdUntil): void
    {
        $this->assertStatus(SubscriptionStatus::WAITING, 'notified');
        $this->status = SubscriptionStatus::NOTIFIED;
        $this->notifiedAt = $now;
        $this->heldVariant = $heldVariant;
        $this->holdUntil = $holdUntil;
    }

    public function releaseHold(): void
    {
        $this->heldVariant = null;
        $this->holdUntil = null;
    }

    public function markWaiting(): void
    {
        $this->assertStatus(SubscriptionStatus::NOTIFIED, 'moved back to waiting');
        $this->status = SubscriptionStatus::WAITING;
        $this->notifiedAt = null;
        $this->releaseHold();
    }

    public function markFulfilled(\DateTimeImmutable $now): void
    {
        $this->assertStatus(SubscriptionStatus::ACTIVE, 'fulfilled');
        $this->status = SubscriptionStatus::FULFILLED;
        $this->fulfilledAt = $now;
        $this->releaseHold();
    }

    public function markCancelled(\DateTimeImmutable $now): void
    {
        $this->assertStatus(SubscriptionStatus::ACTIVE, 'cancelled');
        $this->status = SubscriptionStatus::CANCELLED;
        $this->cancelledAt = $now;
        $this->releaseHold();
    }

    public function restore(): void
    {
        if (SubscriptionStatus::CANCELLED !== $this->status) {
            throw new \LogicException('Only a cancelled subscription can be restored.');
        }

        $this->status = SubscriptionStatus::WAITING;
        $this->cancelledAt = null;
        $this->notifiedAt = null;
        $this->releaseHold();
    }

    public function dismiss(\DateTimeImmutable $now): void
    {
        if (SubscriptionStatus::FULFILLED !== $this->status) {
            throw new \LogicException('Only a fulfilled subscription can be dismissed.');
        }

        $this->dismissedAt = $now;
    }

    public function isDismissed(): bool
    {
        return null !== $this->dismissedAt;
    }

    /**
     * @param string|list<string> $allowed
     */
    private function assertStatus(string|array $allowed, string $action): void
    {
        if (!in_array($this->status, (array) $allowed, true)) {
            throw new \LogicException(sprintf('A subscription with status "%s" cannot be %s.', $this->status, $action));
        }
    }
}
