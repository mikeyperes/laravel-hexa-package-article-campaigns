<?php

namespace hexa_package_article_campaigns\Data;

/** Application-neutral state passed between workflow phases. */
final readonly class CampaignWorkflowState
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public mixed $payload = null,
        public array $metadata = [],
    ) {}
}
