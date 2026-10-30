<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Manage;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Manage\ManageLinkGenerator;

final class ManageLinkGeneratorTest extends TestCase
{
    public function testSignedLinkExpiresAfter24Hours(): void
    {
        $generator = $this->generator();
        $issued = new \DateTimeImmutable('2026-10-30 10:00:00');
        $request = Request::create($generator->generate('a@b.hu', $issued, 'hu_HU'));

        self::assertSame('a@b.hu', $generator->verify($request, $issued->modify('+23 hours 59 minutes')));
        self::assertNull($generator->verify($request, $issued->modify('+24 hours 1 second')));
        self::assertSame($issued->modify('+24 hours')->getTimestamp(), $generator->expiresAt($request)?->getTimestamp());
    }

    public function testTamperedLinkIsRejected(): void
    {
        $generator = $this->generator();
        $now = new \DateTimeImmutable('2026-10-30 10:00:00');
        $url = $generator->generate('a@b.hu', $now, 'hu_HU');

        self::assertNull($generator->verify(Request::create(str_replace('a%40b.hu', 'c%40d.hu', $url)), $now));
        self::assertNull($generator->verify(Request::create(preg_replace('/expires=\d+/', 'expires=' . ($now->getTimestamp() + 999999), $url) ?? ''), $now));
        self::assertNull($generator->verify(Request::create('https://shop.test/hu/back-in-stock-notification/manage?email=a%40b.hu'), $now));
    }

    private function generator(): ManageLinkGenerator
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters): string => 'https://shop.test/hu/back-in-stock-notification/manage?' . http_build_query($parameters),
        );

        return new ManageLinkGenerator($router, new UriSigner('secret'));
    }
}
