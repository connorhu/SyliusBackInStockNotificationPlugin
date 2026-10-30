<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Twig;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\State\PreorderStateResolver;
use Webgriffe\SyliusBackInStockNotificationPlugin\Twig\PreorderExtension;

final class PreorderExtensionTest extends TestCase
{
    private SubscriptionRepositoryInterface&MockObject $repository;

    private CustomerContextInterface&MockObject $customerContext;

    private Channel $channel;

    private PreorderExtension $extension;

    protected function setUp(): void
    {
        $this->channel = new Channel();
        $this->repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->customerContext = $this->createMock(CustomerContextInterface::class);
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($this->channel);

        $this->extension = new PreorderExtension(
            (new \ReflectionClass(PreorderStateResolver::class))->newInstanceWithoutConstructor(),
            $this->repository,
            $channelContext,
            $this->customerContext,
            'Europe/Budapest',
        );
    }

    public function testHoldDeadlinesAsksOnceForAllProductIds(): void
    {
        $one = new Product();
        (new \ReflectionProperty(Product::class, 'id'))->setValue($one, 4);
        $two = new Product();
        (new \ReflectionProperty(Product::class, 'id'))->setValue($two, 9);
        $deadline = new \DateTimeImmutable('+1 day');
        $this->repository->expects(self::once())->method('activeHoldDeadlinesByProduct')->with([4, 9], $this->channel)->willReturn([9 => $deadline]);

        self::assertSame([9 => $deadline], $this->extension->holdDeadlines([$one, $two]));
    }

    public function testAccountSummaryIsNullForAGuest(): void
    {
        $this->customerContext->method('getCustomer')->willReturn(null);
        $this->repository->expects(self::never())->method('accountSummary');

        self::assertNull($this->extension->accountSummary());
    }

    public function testAccountSummaryOfTheLoggedInCustomer(): void
    {
        $customer = new Customer();
        $this->customerContext->method('getCustomer')->willReturn($customer);
        $this->repository->method('accountSummary')->with($customer, $this->channel)->willReturn(['active' => 2, 'actionable' => true]);

        self::assertSame(['active' => 2, 'actionable' => true], $this->extension->accountSummary());
    }

    public function testTimezoneIsConfigurable(): void
    {
        self::assertSame('Europe/Budapest', $this->extension->timezone());
    }
}
