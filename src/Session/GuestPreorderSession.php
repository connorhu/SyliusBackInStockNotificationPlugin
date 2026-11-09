<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Session;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Remembers the ids of the pre-orders a guest created in this browser session, so the product page can show them as
 * "pre-ordered". Reading never starts a session; remembering does.
 */
final class GuestPreorderSession
{
    public const KEY = 'webgriffe_bisn_guest_preorders';

    private const MAX = 100;

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function remember(int $id): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $ids = $this->read($session);
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }

        $session->set(self::KEY, array_slice($ids, -self::MAX));
    }

    /**
     * @return list<int>
     */
    public function ids(): array
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
