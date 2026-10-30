<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Hold;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The pre-orders whose claim link was opened in this browser session: their holder may buy the held unit here.
 * Reading never starts a session; claiming does.
 */
final class ClaimSession
{
    public const KEY = 'webgriffe_bisn_claims';

    private const MAX = 50;

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function claim(int $subscriptionId): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $ids = $this->read($session);
        if (!in_array($subscriptionId, $ids, true)) {
            $ids[] = $subscriptionId;
        }

        $session->set(self::KEY, array_slice($ids, -self::MAX));
    }

    /**
     * @return list<int>
     */
    public function claimedIds(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return [];
        }

        $session = $request->getSession();
        if (!$session->isStarted() && !$request->hasPreviousSession()) {
            return [];
        }

        return $this->read($session);
    }

    /**
     * @return list<int>
     */
    private function read(SessionInterface $session): array
    {
        $stored = $session->get(self::KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, 'is_int'));
    }
}
