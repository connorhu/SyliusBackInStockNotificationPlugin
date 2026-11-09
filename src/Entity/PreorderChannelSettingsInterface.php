<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

/**
 * Implemented by the host's Channel entity (see PreorderChannelSettingsTrait).
 */
interface PreorderChannelSettingsInterface
{
    public const MODE_QUEUE = 'queue';

    public const MODE_FASTEST = 'fastest';

    public const DEFAULT_ENABLED_BY_DEFAULT = true;

    public const DEFAULT_MODE = self::MODE_QUEUE;

    public const DEFAULT_HOLD_HOURS = 24;

    public const DEFAULT_RATE_LIMIT_SECONDS = 120;

    public function isPreorderEnabledByDefault(): bool;

    public function setPreorderEnabledByDefault(bool $preorderEnabledByDefault): void;

    public function getPreorderMode(): string;

    public function setPreorderMode(string $preorderMode): void;

    public function getPreorderHoldHours(): int;

    public function setPreorderHoldHours(int $preorderHoldHours): void;

    public function getPreorderRateLimitSeconds(): int;

    public function setPreorderRateLimitSeconds(int $preorderRateLimitSeconds): void;
}
