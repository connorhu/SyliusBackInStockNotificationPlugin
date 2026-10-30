<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

trait PreorderChannelSettingsTrait
{
    #[ORM\Column(name: 'preorder_enabled_by_default', type: 'boolean', options: ['default' => true])]
    protected bool $preorderEnabledByDefault = PreorderChannelSettingsInterface::DEFAULT_ENABLED_BY_DEFAULT;

    #[ORM\Column(name: 'preorder_mode', type: 'string', length: 16, options: ['default' => 'queue'])]
    protected string $preorderMode = PreorderChannelSettingsInterface::DEFAULT_MODE;

    #[ORM\Column(name: 'preorder_hold_hours', type: 'integer', options: ['default' => 24])]
    protected int $preorderHoldHours = PreorderChannelSettingsInterface::DEFAULT_HOLD_HOURS;

    #[ORM\Column(name: 'preorder_rate_limit_seconds', type: 'integer', options: ['default' => 120])]
    protected int $preorderRateLimitSeconds = PreorderChannelSettingsInterface::DEFAULT_RATE_LIMIT_SECONDS;

    public function isPreorderEnabledByDefault(): bool
    {
        return $this->preorderEnabledByDefault;
    }

    public function setPreorderEnabledByDefault(bool $preorderEnabledByDefault): void
    {
        $this->preorderEnabledByDefault = $preorderEnabledByDefault;
    }

    public function getPreorderMode(): string
    {
        return $this->preorderMode;
    }

    public function setPreorderMode(string $preorderMode): void
    {
        $this->preorderMode = $preorderMode;
    }

    public function getPreorderHoldHours(): int
    {
        return $this->preorderHoldHours;
    }

    public function setPreorderHoldHours(int $preorderHoldHours): void
    {
        $this->preorderHoldHours = $preorderHoldHours;
    }

    public function getPreorderRateLimitSeconds(): int
    {
        return $this->preorderRateLimitSeconds;
    }

    public function setPreorderRateLimitSeconds(int $preorderRateLimitSeconds): void
    {
        $this->preorderRateLimitSeconds = $preorderRateLimitSeconds;
    }
}
