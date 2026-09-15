<?php

namespace hexa_package_article_campaigns\Data;

final readonly class CampaignRunResult
{
    public function __construct(
        public bool $successful,
        public string $state,
        public ?string $failureCode = null,
        public ?string $message = null,
        public ?GeneratedArticle $article = null,
        public ?DeliveryResult $delivery = null,
    ) {}
}
