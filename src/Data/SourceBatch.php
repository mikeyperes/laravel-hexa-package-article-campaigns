<?php

namespace hexa_package_article_campaigns\Data;

final readonly class SourceBatch
{
    /** @param array<int, array<string, mixed>> $sources */
    /** @param array<int, array<string, mixed>> $rejections */
    public function __construct(
        public array $sources,
        public array $rejections = [],
        public array $metadata = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }
}
