<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Session;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Remembers the ids of the last cancelled pre-orders for ten minutes, so the cancel can be undone.
 */
final class CancelUndoSession
{
    public const KEY = 'webgriffe_bisn_cancel_undo';

    public const TTL = '+10 minutes';

    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * @param list<int> $ids
     */
    public function remember(array $ids, \DateTimeImmutable $now): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession() || [] === $ids) {
            return;
        }

        $request->getSession()->set(self::KEY, ['ids' => $ids, 'until' => $now->modify(self::TTL)->getTimestamp()]);
    }

    /**
     * @return list<int>
     */
    public function peek(\DateTimeImmutable $now): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return [];
        }

        $session = $request->getSession();
        if (!$session->isStarted() && !$request->hasPreviousSession()) {
            return [];
        }

        /** @var mixed $stored */
        $stored = $session->get(self::KEY);
        if (!is_array($stored) || !isset($stored['ids'], $stored['until']) || !is_array($stored['ids']) || !is_int($stored['until'])) {
            return [];
        }

        if ($stored['until'] <= $now->getTimestamp()) {
            $session->remove(self::KEY);

            return [];
        }

        return array_values(array_filter($stored['ids'], 'is_int'));
    }

    /**
     * @return list<int>
     */
    public function take(\DateTimeImmutable $now): array
    {
        $ids = $this->peek($now);
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request && $request->hasSession()) {
            $request->getSession()->remove(self::KEY);
        }

        return $ids;
    }
}
