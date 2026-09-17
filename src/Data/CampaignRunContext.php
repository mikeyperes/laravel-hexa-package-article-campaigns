<?php

namespace hexa_package_article_campaigns\Data;

final readonly class CampaignRunContext
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public int|string $campaignKey,
        public int|string|null $publicationKey,
        public array $settings = [],
        public mixed $runtime = null,
    ) {}
}
