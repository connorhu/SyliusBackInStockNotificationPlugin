<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Manage;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Session\CancelUndoSession;

final class ManageSessionTest extends TestCase
{
    public function testGrantExpires(): void
    {
        $manage = new ManageSession($this->stack());
        $now = new \DateTimeImmutable('2026-10-30 10:00:00');

        self::assertNull($manage->grantedEmail($now));
        $manage->grant('a@b.hu', $now->modify('+1 hour'));

        self::assertSame('a@b.hu', $manage->grantedEmail($now->modify('+59 minutes')));
        self::assertNull($manage->grantedEmail($now->modify('+61 minutes')));
    }

    public function testUndoMemoryLastsTenMinutesAndIsTakenOnce(): void
    {
        $undo = new CancelUndoSession($this->stack());
        $now = new \DateTimeImmutable('2026-10-30 10:00:00');
        $undo->remember([1, 2], $now);

        self::assertSame([1, 2], $undo->peek($now->modify('+9 minutes')));
        self::assertSame([1, 2], $undo->take($now->modify('+9 minutes')));
        self::assertSame([], $undo->take($now->modify('+9 minutes')));

        $undo->remember([3], $now);
        self::assertSame([], $undo->take($now->modify('+11 minutes')));
    }

    private function stack(): RequestStack
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
