<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Processor;

use PHPUnit\Framework\TestCase;
use Webgriffe\SyliusBackInStockNotificationPlugin\Processor\ProcessResult;

final class ProcessResultTest extends TestCase
{
    public function testGuestDuplicateLooksExactlyLikeASuccess(): void
    {
        self::assertNull(ProcessResult::Created->errorKey(true));
        self::assertSame(ProcessResult::Created->errorKey(true), ProcessResult::AlreadySubscribed->errorKey(true));
    }

    public function testLoggedInCustomerIsToldAboutTheirOwnDuplicate(): void
    {
        self::assertNull(ProcessResult::Created->errorKey(false));
        self::assertSame('webgriffe_bisn.subscription.already_saved', ProcessResult::AlreadySubscribed->errorKey(false));
    }

    public function testRefusalsAreErrorsForEveryone(): void
    {
        self::assertSame('webgriffe_bisn.subscription.not_preorderable', ProcessResult::NotPreorderable->errorKey(true));
        self::assertSame('webgriffe_bisn.subscription.rate_limited', ProcessResult::RateLimited->errorKey(false));
    }
}
