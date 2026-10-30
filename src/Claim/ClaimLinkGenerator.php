<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Claim;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;

/**
 * Builds and checks the claim link of the "it arrived" mail: a signed URL carrying the pre-order's hash and an explicit
 * `expires` timestamp (the end of the hold), both covered by the signature. A pre-order without a hold gets the plain
 * product page.
 */
final class ClaimLinkGenerator
{
    public const ROUTE = 'webgriffe_back_in_stock_notification_claim';

    public const EXPIRES_PARAMETER = 'expires';

    public function __construct(
        private UrlGeneratorInterface $router,
        private UriSigner $uriSigner,
    ) {
    }

    public function generate(SubscriptionInterface $subscription): string
    {
        $localeCode = $subscription->getLocaleCode();
        $holdUntil = $subscription->getHoldUntil();
        $hash = $subscription->getHash();
        if (null === $holdUntil || null === $subscription->getHeldVariant() || null === $hash) {
            return $this->productUrl($subscription);
        }

        $parameters = ['hash' => $hash, self::EXPIRES_PARAMETER => $holdUntil->getTimestamp()];
        if (null !== $localeCode) {
            $parameters['_locale'] = $localeCode;
        }

        return $this->uriSigner->sign($this->router->generate(self::ROUTE, $parameters, UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /**
     * The product page in the subscription's locale.
     */
    public function productUrl(SubscriptionInterface $subscription): string
    {
        $localeCode = $subscription->getLocaleCode();
        $product = $subscription->getProduct();
        $parameters = ['slug' => (string) $product?->getTranslation($localeCode)->getSlug()];
        if (null !== $localeCode) {
            $parameters['_locale'] = $localeCode;
        }

        return $this->router->generate('sylius_shop_product_show', $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Whether the request is an untampered, unexpired claim link for the given hash.
     */
    public function verify(Request $request, string $hash, ?\DateTimeImmutable $now = null): bool
    {
        $expires = $request->query->get(self::EXPIRES_PARAMETER);
        if (!is_string($expires) || !ctype_digit($expires) || !$this->uriSigner->checkRequest($request)) {
            return false;
        }
        if (!str_ends_with(rawurldecode($request->getPathInfo()), '/' . $hash)) {
            return false;
        }

        return (int) $expires > ($now ?? new \DateTimeImmutable())->getTimestamp();
    }
}
