<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Restock;

/**
 * What one restock run did.
 */
final class RestockReport
{
    private int $notified = 0;

    private int $held = 0;

    private int $expired = 0;

    private int $rewaited = 0;

    private int $failedTargets = 0;

    private bool $aborted = false;

    public function addNotified(bool $held): void
    {
        ++$this->notified;
        if ($held) {
            ++$this->held;
        }
    }

    public function addExpired(int $count = 1): void
    {
        $this->expired += $count;
    }

    public function addRewaited(int $count = 1): void
    {
        $this->rewaited += $count;
    }

    public function addFailedTarget(): void
    {
        ++$this->failedTargets;
    }

    public function abort(): void
    {
        $this->aborted = true;
    }

    /** Pre-orders that got the "it arrived" mail in this run. */
    public function getNotified(): int
    {
        return $this->notified;
    }

    /** Of the notified ones, those that got a unit held for them (queue mode). */
    public function getHeld(): int
    {
        return $this->held;
    }

    /** Holds that ran out in this run. */
    public function getExpired(): int
    {
        return $this->expired;
    }

    /** Notified pre-orders moved back to waiting because their target sold out again. */
    public function getRewaited(): int
    {
        return $this->rewaited;
    }

    /** Targets skipped because their evaluation failed (logged). */
    public function getFailedTargets(): int
    {
        return $this->failedTargets;
    }

    /** True when saving failed and the run stopped early. */
    public function isAborted(): bool
    {
        return $this->aborted;
    }
}
