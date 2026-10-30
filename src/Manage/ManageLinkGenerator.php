<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Manage;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds and checks the signed link that opens the guest manage page. The link carries the canonical e-mail and an
 * explicit `expires` timestamp, both covered by the signature; there is no token table.
 */
final class ManageLinkGenerator
{
    public const ROUTE = 'webgriffe_back_in_stock_notification_manage';

    public const TTL = '+24 hours';

    public const EMAIL_PARAMETER = 'email';

    public const EXPIRES_PARAMETER = 'expires';

    public function __construct(
        private UrlGeneratorInterface $router,
        private UriSigner $uriSigner,
    ) {
    }

    public function generate(string $emailCanonical, \DateTimeImmutable $now, ?string $localeCode = null): string
    {
        $parameters = [
            self::EMAIL_PARAMETER => $emailCanonical,
            self::EXPIRES_PARAMETER => $now->modify(self::TTL)->getTimestamp(),
        ];
        if (null !== $localeCode) {
            $parameters['_locale'] = $localeCode;
        }

        return $this->uriSigner->sign($this->router->generate(self::ROUTE, $parameters, UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /**
     * The e-mail of a valid, unexpired signed link; null when the request is not one.
     */
    public function verify(Request $request, ?\DateTimeImmutable $now = null): ?string
    {
        $expiresAt = $this->expiresAt($request);
        if (null === $expiresAt || !$this->uriSigner->checkRequest($request)) {
            return null;
        }

        if ($expiresAt <= ($now ?? new \DateTimeImmutable())) {
            return null;
        }

        $email = $request->query->get(self::EMAIL_PARAMETER);

        return is_string($email) && '' !== $email ? $email : null;
    }

    /**
     * Only meaningful once verify() accepted the request.
     */
    public function expiresAt(Request $request): ?\DateTimeImmutable
    {
        $expires = $request->query->get(self::EXPIRES_PARAMETER);
        if (!is_string($expires) || !ctype_digit($expires)) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $expires);
    }
}
