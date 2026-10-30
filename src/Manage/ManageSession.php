<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Manage;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Remembers, for the length of a signed link's life, which e-mail's pre-orders this browser may manage.
 */
final class ManageSession
{
    public const KEY = 'webgriffe_bisn_manage';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function grant(string $emailCanonical, \DateTimeImmutable $until): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $request->getSession()->set(self::KEY, ['email' => $emailCanonical, 'until' => $until->getTimestamp()]);
    }

    public function grantedEmail(\DateTimeImmutable $now): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        if (!$session->isStarted() && !$request->hasPreviousSession()) {
            return null;
        }

        /** @var mixed $stored */
        $stored = $session->get(self::KEY);
        if (!is_array($stored) || !isset($stored['email'], $stored['until']) || !is_string($stored['email']) || !is_int($stored['until'])) {
            return null;
        }

        return $stored['until'] > $now->getTimestamp() ? $stored['email'] : null;
    }
}
