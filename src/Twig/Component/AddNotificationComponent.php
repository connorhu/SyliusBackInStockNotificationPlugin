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
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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
        return $this->formFactory->create(SubscriptionType::class);
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
        if ($this->isCustomerLoggedIn) {
            $this->formValues['email'] = $this->customerContext->getCustomer()?->getEmail();
        }
        $this->submitForm();

        Assert::isInstanceOf($this->variant, ProductVariantInterface::class);

        $formData = $this->getForm()->getData();
        Assert::isArray($formData);
        $email = $formData['email'] ?? null;
        Assert::stringNotEmpty($email);

        $this->subscriptionProcessor->process(
            $this->variant,
            $email,
            $this->customerContext->getCustomer(),
        );

        $this->notificationSent = true;
        $this->resetForm();
    }
}
