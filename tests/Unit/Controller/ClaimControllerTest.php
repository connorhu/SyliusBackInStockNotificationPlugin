<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Claim\ClaimLinkGenerator;
use Webgriffe\SyliusBackInStockNotificationPlugin\Controller\ClaimController;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\Subscription;
use Webgriffe\SyliusBackInStockNotificationPlugin\Hold\ClaimSession;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

final class ClaimControllerTest extends TestCase
{
    private ?Subscription $subscription = null;

    private Session $session;

    private RequestStack $requestStack;

    private ClaimLinkGenerator $generator;

    private ClaimController $controller;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $name, array $parameters): string {
            if ('sylius_shop_product_show' === $name) {
                return sprintf('/%s/products/%s', $parameters['_locale'], $parameters['slug']);
            }

            return sprintf('https://shop.test/%s/back-in-stock-notification/claim/%s?expires=%s', $parameters['_locale'], $parameters['hash'], $parameters['expires']);
        });
        $this->generator = new ClaimLinkGenerator($router, new UriSigner('secret'));
        $this->controller = new ClaimController($router, $this->repository(), $this->generator, new ClaimSession($this->requestStack));
    }

    public function testValidLinkClaimsAndOpensTheProductPage(): void
    {
        $subscription = $this->subscription(new \DateTimeImmutable('+5 hours'));

        $response = $this->open($this->generator->generate($subscription));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/hu_HU/products/the-book', $response->getTargetUrl());
        self::assertSame([42], (new ClaimSession($this->requestStack))->claimedIds());
        self::assertSame([], $this->session->getFlashBag()->peekAll());
    }

    public function testExpiredLinkOpensTheProductPageWithAFlash(): void
    {
        $subscription = $this->subscription(new \DateTimeImmutable('+5 hours'));
        $url = $this->generator->generate($subscription);
        $url = preg_replace('/expires=\d+/', 'expires=' . (time() - 10), $url) ?? '';

        $response = $this->open($url);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/hu_HU/products/the-book', $response->getTargetUrl());
        self::assertSame([], (new ClaimSession($this->requestStack))->claimedIds());
        self::assertSame(['info' => [ClaimController::EXPIRED_FLASH]], $this->session->getFlashBag()->peekAll());
    }

    public function testUnknownHashIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->open('https://shop.test/hu_HU/back-in-stock-notification/claim/nope?expires=1');
    }

    private function open(string $url): Response
    {
        $request = Request::create($url);
        $request->setSession($this->session);
        $this->requestStack->push($request);
        $hash = basename((string) parse_url($url, \PHP_URL_PATH));

        return $this->controller->claimAction($request, $hash);
    }

    private function repository(): SubscriptionRepositoryInterface
    {
        $repository = $this->createMock(SubscriptionRepositoryInterface::class);
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?Subscription => null !== $this->subscription && $criteria === ['hash' => $this->subscription->getHash()] ? $this->subscription : null,
        );

        return $repository;
    }

    private function subscription(\DateTimeImmutable $holdUntil): Subscription
    {
        $product = new Product();
        $product->setCurrentLocale('hu_HU');
        $product->setFallbackLocale('hu_HU');
        $product->setSlug('the-book');
        $variant = new ProductVariant();
        $product->addVariant($variant);
        $subscription = new Subscription();
        (new \ReflectionProperty(Subscription::class, 'id'))->setValue($subscription, 42);
        $subscription->setHash('abc123');
        $subscription->setProduct($product);
        $subscription->setLocaleCode('hu_HU');
        $subscription->markNotified(new \DateTimeImmutable('-1 hour'), $variant, $holdUntil);

        return $this->subscription = $subscription;
    }
}
