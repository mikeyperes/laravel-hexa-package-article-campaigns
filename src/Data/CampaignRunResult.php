<?php

namespace hexa_package_article_campaigns\Data;

final readonly class CampaignRunResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public bool $successful,
        public string $state,
        public ?string $failureCode = null,
        public ?string $message = null,
        public array $metadata = [],
    ) {}
}
