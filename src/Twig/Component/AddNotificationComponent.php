<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Twig\Component;

use Psr\Log\LoggerInterface;
use Sylius\Bundle\ShopBundle\Twig\Component\Product\AddToCartFormComponent;
use Sylius\Bundle\ShopBundle\Twig\Component\Product\Trait\ProductLivePropTrait;
use Sylius\Bundle\ShopBundle\Twig\Component\Product\Trait\ProductVariantLivePropTrait;
use Sylius\Bundle\UiBundle\Twig\Component\TemplatePropTrait;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Sylius\TwigHooks\LiveComponent\HookableLiveComponentTrait;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Webgriffe\SyliusBackInStockNotificationPlugin\Customer\SubscriptionOwnership;
use Webgriffe\SyliusBackInStockNotificationPlugin\Form\SubscriptionType;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolverInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\ProcessResult;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\SubscriptionProcessorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\RateLimit\PreorderRateLimiter;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\CancelUndoSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderStateResolver;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderView;
use Webmozart\Assert\Assert;

#[AsLiveComponent]
final class AddNotificationComponent
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    use HookableLiveComponentTrait;
    use ProductLivePropTrait;
    use ProductVariantLivePropTrait;
    use TemplatePropTrait;

    #[LiveProp]
    public bool $notificationSent = false;

    /** Minutes the visitor has to wait after a rate-limited attempt; null when the last attempt was not limited. */
    #[LiveProp]
    public ?int $rateLimitedMinutes = null;

    #[LiveProp]
    public bool $isCustomerLoggedIn = false;

    /** Whether the visitor just cancelled their pre-order here and can still undo it (the toast with the undo button). */
    #[LiveProp]
    public bool $cancelled = false;

    private ?PreorderView $view = null;

    public function __construct(
        protected readonly FormFactoryInterface $formFactory,
        protected readonly ProductVariantResolverInterface $productVariantResolver,
        protected readonly CustomerContextInterface $customerContext,
        protected readonly SubscriptionProcessorInterface $subscriptionProcessor,
        ProductRepositoryInterface $productRepository,
        ProductVariantRepositoryInterface $productVariantRepository,
        protected readonly PreorderStateResolver $stateResolver,
        protected readonly PreorderabilityResolverInterface $preorderabilityResolver,
        protected readonly SubscriptionRepositoryInterface $subscriptionRepository,
        protected readonly CancelUndoSession $cancelUndoSession,
        protected readonly ChannelContextInterface $channelContext,
        protected readonly RequestStack $requestStack,
        protected readonly LoggerInterface $logger,
    ) {
        $this->initializeProduct($productRepository);
        $this->initializeProductVariant($productVariantRepository);
    }

    /**
     * The visitor's pre-order state for the product (or selected variant) in the current channel; the template renders
     * nothing for a sellable or not pre-orderable target.
     */
    public function getView(): PreorderView
    {
        if (null === $this->view) {
            $channel = $this->channelContext->getChannel();
            Assert::isInstanceOf($channel, ChannelInterface::class);
            Assert::notNull($this->product);

            $this->view = $this->stateResolver->resolve($this->product, $this->variant, $channel);
        }

        return $this->view;
    }

    /**
     * Whether new pre-orders are accepted for the product in the current channel (the held-for-others state still
     * needs the answer; the resolver only checks it for a target without units).
     */
    public function isPreorderable(): bool
    {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);
        Assert::notNull($this->product);

        return $this->preorderabilityResolver->isPreorderable($this->product, $channel);
    }

    /**
     * The end of the latest active hold on the product, shown to a visitor who cannot buy the held copies.
     */
    public function getHoldDeadline(): ?\DateTimeImmutable
    {
        $channel = $this->channelContext->getChannel();
        $id = $this->product?->getId();
        if (!$channel instanceof ChannelInterface || !is_int($id)) {
            return null;
        }

        return $this->subscriptionRepository->activeHoldDeadlinesByProduct([$id], $channel)[$id] ?? null;
    }

    /**
     * Whether the visitor may cancel the pre-order of the current view here: a logged-in customer's own; a guest cancels
     * through the manage link of the e-mails.
     */
    public function canCancel(): bool
    {
        $subscription = $this->getView()->subscription;
        $customer = $this->customerContext->getCustomer();

        return null !== $subscription && null !== $customer && $subscription->isActive() && SubscriptionOwnership::isOwnedBy($subscription, $customer);
    }

    protected function instantiateForm(): FormInterface
    {
        $customer = $this->customerContext->getCustomer();

        return $this->formFactory->create(SubscriptionType::class, null, [
            'ask_identity' => null === $customer || null === $customer->getEmail(),
        ]);
    }

    #[PostMount]
    public function postMount(): void
    {
        Assert::notNull($this->product);

        /** @var ProductVariantInterface|null $variant * */
        $variant = $this->productVariantResolver->getVariant($this->product);

        $this->variant = $variant;

        $customer = $this->customerContext->getCustomer();
        if ($customer !== null && $customer->getEmail() !== null) {
            $this->isCustomerLoggedIn = true;
        }
    }

    #[LiveListener(AddToCartFormComponent::SYLIUS_SHOP_VARIANT_CHANGED)]
    public function updateProductVariant(#[LiveArg] mixed $variantId): void
    {
        if (null === $variantId) {
            $this->variant = null;

            return;
        }

        $this->variant = $this->productVariantRepository->find($variantId);
    }

    #[LiveAction]
    public function addNotification(): void
    {
        $this->notificationSent = false;
        $this->rateLimitedMinutes = null;
        $this->cancelled = false;
        $this->view = null;
        $this->formValues['product_variant_code'] = $this->variant?->getCode();
        $this->submitForm();

        Assert::notNull($this->product);

        // Honeypot: a bot that fills the hidden field gets the guest success state; nothing is stored or mailed.
        /** @psalm-suppress MixedAssignment */
        $honeypot = $this->getForm()->get('website')->getData();
        if (is_string($honeypot) && '' !== trim($honeypot)) {
            $this->logger->info('webgriffe_bisn.honeypot_filled');
            $this->notificationSent = true;
            $this->resetForm();

            return;
        }

        $formData = $this->getForm()->getData();
        Assert::isArray($formData);
        $customer = $this->customerContext->getCustomer();
        $email = $formData['email'] ?? $customer?->getEmail();
        Assert::stringNotEmpty($email);
        $result = $this->subscriptionProcessor->process(
            $this->product,
            $this->variant,
            $email,
            isset($formData['name']) && is_string($formData['name']) ? $formData['name'] : null,
            $customer,
            $this->requestStack->getCurrentRequest()?->getClientIp(),
        );

        if (ProcessResult::RateLimited === $result) {
            // The form keeps its data so the visitor can simply try again a little later.
            $channel = $this->channelContext->getChannel();
            Assert::isInstanceOf($channel, ChannelInterface::class);
            $this->rateLimitedMinutes = max(1, (int) ceil(PreorderRateLimiter::intervalSeconds($channel) / 60));

            return;
        }

        $errorKey = $result->errorKey(null === $customer);
        if (null !== $errorKey) {
            $this->getForm()->addError(new FormError($errorKey));

            return;
        }

        // A guest gets the same answer whether or not the address already had a pre-order.
        $this->notificationSent = true;
        $this->resetForm();
    }

    #[LiveAction]
    public function cancel(): void
    {
        $this->view = null;
        $subscription = $this->getView()->subscription;
        $customer = $this->customerContext->getCustomer();
        if (null !== $subscription && null !== $customer && $subscription->isActive() && SubscriptionOwnership::isOwnedBy($subscription, $customer)) {
            $now = new \DateTimeImmutable();
            $subscription->markCancelled($now);
            $this->subscriptionRepository->add($subscription);
            /** @psalm-suppress MixedAssignment */
            $id = $subscription->getId();
            if (is_int($id)) {
                $this->cancelUndoSession->remember([$id], $now);
                $this->cancelled = true;
            }
        }
        $this->view = null;
    }

    #[LiveAction]
    public function restore(): void
    {
        $customer = $this->customerContext->getCustomer();
        $channel = $this->channelContext->getChannel();
        if (null !== $customer && $channel instanceof ChannelInterface) {
            $subscriptions = $this->subscriptionRepository->findCancelledByIds(
                $this->cancelUndoSession->take(new \DateTimeImmutable()),
                (string) $customer->getEmailCanonical(),
                $channel,
            );
            foreach ($subscriptions as $subscription) {
                if (SubscriptionOwnership::isOwnedBy($subscription, $customer)) {
                    $subscription->restore();
                    $this->subscriptionRepository->add($subscription);
                }
            }
        }
        $this->cancelled = false;
        $this->view = null;
    }
}
