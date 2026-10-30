<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Command;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\RouterInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Claim\ClaimLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Command\AlertCommand;
use Webgriffe\SyliusBackInStockNotificationPlugin\Mailer\SafeSender;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\RestockProcessor;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\SellableUnits;
use Webgriffe\SyliusBackInStockNotificationPlugin\Target\TargetResolver;

final class AlertCommandTest extends TestCase
{
    public function testRunsOfOtherInstallationsOnTheSameHostDoNotBlockEachOther(): void
    {
        $inner = $this->command('/srv/shop/prod', 'prod', static fn (): null => null);
        $innerOutput = null;
        $outer = $this->command('/srv/shop/staging', 'staging', static function () use ($inner, &$innerOutput): void {
            $tester = new CommandTester($inner);
            $tester->execute([]);
            $innerOutput = $tester->getDisplay();
        });

        (new CommandTester($outer))->execute([]);

        self::assertIsString($innerOutput);
        self::assertStringNotContainsString('already running', $innerOutput);
    }

    public function testOverlappingRunOfTheSameInstallationExits(): void
    {
        $inner = $this->command('/srv/shop/prod', 'prod', static fn (): null => null);
        $innerOutput = null;
        $outer = $this->command('/srv/shop/prod', 'prod', static function () use ($inner, &$innerOutput): void {
            $tester = new CommandTester($inner);
            $tester->execute([]);
            $innerOutput = $tester->getDisplay();
        });

        (new CommandTester($outer))->execute([]);

        self::assertIsString($innerOutput);
        self::assertStringContainsString('already running', $innerOutput);
    }

    /**
     * @param \Closure(): void|\Closure(): null $duringRun
     */
    private function command(string $projectDir, string $environment, \Closure $duringRun): AlertCommand
    {
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('findRestockTargets')->willReturnCallback(static function () use ($duringRun): array {
            $duringRun();

            return [];
        });
        $router = $this->createMock(RouterInterface::class);
        $processor = new RestockProcessor(
            $repository,
            $this->createMock(ObjectManager::class),
            new TargetResolver('product', $repository),
            new SellableUnits(new AvailabilityChecker()),
            new SafeSender($this->createMock(SenderInterface::class), new NullLogger()),
            $router,
            new NullLogger(),
            new ClaimLinkGenerator($router, new UriSigner('secret')),
        );

        return new AlertCommand($processor, projectDir: $projectDir, environment: $environment);
    }
}
