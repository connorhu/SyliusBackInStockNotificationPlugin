<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Claim;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Claim\ClaimLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\ClaimSession;

final class ClaimLinkGeneratorTest extends TestCase
{
    public function testClaimLinkIsSignedAndValidUntilTheHoldEnds(): void
    {
        $generator = $this->generator();
        $until = new \DateTimeImmutable('2026-10-31 12:00:00');
        $url = $generator->generate($this->subscription($until));

        self::assertStringStartsWith('https://shop.test/hu_HU/back-in-stock-notification/claim/abc123?', $url);
        $request = Request::create($url);
        self::assertTrue($generator->verify($request, 'abc123', $until->modify('-1 second')));
        self::assertFalse($generator->verify($request, 'abc123', $until));
        self::assertFalse($generator->verify($request, 'other-hash', $until->modify('-1 hour')));
        self::assertFalse($generator->verify(Request::create(preg_replace('/expires=\d+/', 'expires=' . ($until->getTimestamp() + 3600), $url) ?? ''), 'abc123', $until->modify('-1 hour')));
    }

    public function testWithoutHoldTheLinkIsTheProductPage(): void
    {
        self::assertSame('https://shop.test/hu_HU/products/the-book', $this->generator()->generate($this->subscription(null)));
    }

    public function testClaimSessionReadsWithoutStartingASession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $claims = new ClaimSession($stack);

        self::assertSame([], $claims->claimedIds());
        self::assertFalse($session->isStarted());

        $claims->claim(5);
        $claims->claim(5);
        $claims->claim(9);
        self::assertSame([5, 9], $claims->claimedIds());
    }

    private function generator(): ClaimLinkGenerator
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $name, array $parameters): string {
            $locale = $parameters['_locale'];
            unset($parameters['_locale']);
            if ('sylius_shop_product_show' === $name) {
                return sprintf('https://shop.test/%s/products/%s', $locale, $parameters['slug']);
            }
            $hash = $parameters['hash'];
            unset($parameters['hash']);

            return sprintf('https://shop.test/%s/back-in-stock-notification/claim/%s?%s', $locale, $hash, http_build_query($parameters));
        });

        return new ClaimLinkGenerator($router, new UriSigner('secret'));
    }

    private function subscription(?\DateTimeImmutable $holdUntil): Subscription
    {
        $product = new Product();
        $product->setCurrentLocale('hu_HU');
        $product->setFallbackLocale('hu_HU');
        $product->setSlug('the-book');
        $variant = new ProductVariant();
        $product->addVariant($variant);
        $subscription = new Subscription();
        $subscription->setHash('abc123');
        $subscription->setProduct($product);
        $subscription->setLocaleCode('hu_HU');
        if (null !== $holdUntil) {
            $subscription->markNotified($holdUntil->modify('-24 hours'), $variant, $holdUntil);
        }

        return $subscription;
    }
}
