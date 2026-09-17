<?php

namespace hexa_package_article_campaigns\Data;

final readonly class CampaignStageResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public bool $successful,
        public CampaignWorkflowState $state,
        public ?string $failureCode = null,
        public ?string $message = null,
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function continued(CampaignWorkflowState $state, array $metadata = []): self
    {
        return new self(true, $state, metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function failed(
        CampaignWorkflowState $state,
        string $failureCode,
        string $message,
        array $metadata = [],
    ): self {
        return new self(false, $state, $failureCode, $message, $metadata);
    }
}
