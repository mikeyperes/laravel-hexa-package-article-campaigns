<?php

namespace hexa_package_article_campaigns\Data;

final readonly class GeneratedArticle
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $title,
        public string $body,
        public array $metadata = [],
    ) {}
}
