<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Twig\Component;

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
use Webgriffe\SyliusBackInStockNotificationPlugin\Form\SubscriptionType;
use Webgriffe\SyliusBackInStockNotificationPlugin\Preorderability\PreorderabilityResolverInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\ProcessResult;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\SubscriptionProcessorInterface;
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

    #[LiveProp]
    public bool $isCustomerLoggedIn = false;

    public function __construct(
        protected readonly FormFactoryInterface $formFactory,
        protected readonly ProductVariantResolverInterface $productVariantResolver,
        protected readonly CustomerContextInterface $customerContext,
        protected readonly SubscriptionProcessorInterface $subscriptionProcessor,
        ProductRepositoryInterface $productRepository,
        ProductVariantRepositoryInterface $productVariantRepository,
        protected readonly PreorderabilityResolverInterface $preorderabilityResolver,
        protected readonly ChannelContextInterface $channelContext,
        protected readonly RequestStack $requestStack,
    ) {
        $this->initializeProduct($productRepository);
        $this->initializeProductVariant($productVariantRepository);
    }

    /**
     * Whether new subscriptions are accepted for the product in the current channel (the block renders nothing otherwise).
     */
    public function isPreorderable(): bool
    {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);
        Assert::notNull($this->product);

        return $this->preorderabilityResolver->isPreorderable($this->product, $channel);
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
        $this->formValues['product_variant_code'] = $this->variant?->getCode();
        $this->submitForm();

        Assert::notNull($this->product);

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

        if (ProcessResult::NotPreorderable === $result) {
            $this->getForm()->addError(new FormError('webgriffe_bisn.subscription.not_preorderable'));

            return;
        }
        if (ProcessResult::RateLimited === $result) {
            $this->getForm()->addError(new FormError('webgriffe_bisn.subscription.rate_limited'));

            return;
        }

        // An already pre-ordered address gets the same answer, so the form never reveals who has pre-ordered.
        $this->notificationSent = true;
        $this->resetForm();
    }
}
