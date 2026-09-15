<?php

namespace hexa_package_article_campaigns\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final readonly class CampaignRunReservation
{
    public CarbonImmutable $reservedAt;

    public CarbonImmutable $nextRunAt;

    public function __construct(DateTimeInterface $reservedAt, DateTimeInterface $nextRunAt)
    {
        $this->reservedAt = CarbonImmutable::instance($reservedAt);
        $this->nextRunAt = CarbonImmutable::instance($nextRunAt);
    }

    /**
     * @return array{last_run_at: CarbonImmutable, next_run_at: CarbonImmutable}
     */
    public function campaignAttributes(): array
    {
        return [
            'last_run_at' => $this->reservedAt,
            'next_run_at' => $this->nextRunAt,
        ];
    }
}
