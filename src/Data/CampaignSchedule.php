<?php

namespace hexa_package_article_campaigns\Data;

final readonly class CampaignSchedule
{
    public function __construct(
        public int|string $campaignKey,
        public string $timezone = 'America/New_York',
        public string $intervalUnit = 'daily',
        public string $runAtTime = '09:00',
        public int $articlesPerInterval = 1,
        public int $dripIntervalMinutes = 60,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            campaignKey: $values['campaign_key'] ?? $values['id'] ?? 0,
            timezone: trim((string) ($values['timezone'] ?? '')) ?: 'America/New_York',
            intervalUnit: trim((string) ($values['interval_unit'] ?? '')) ?: 'daily',
            runAtTime: trim((string) ($values['run_at_time'] ?? '')) ?: '09:00',
            articlesPerInterval: max(1, (int) ($values['articles_per_interval'] ?? 1)),
            dripIntervalMinutes: max(1, (int) ($values['drip_interval_minutes'] ?? 60)),
        );
    }
}
