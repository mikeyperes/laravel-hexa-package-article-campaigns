<?php

namespace hexa_package_article_campaigns\Data;

final readonly class DeliveryResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public bool $successful,
        public int|string|null $externalKey = null,
        public array $metadata = [],
        public ?string $message = null,
    ) {}
}
