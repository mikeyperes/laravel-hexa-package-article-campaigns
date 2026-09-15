<?php

namespace hexa_package_article_campaigns\Data;

final readonly class ConcurrencyLimits
{
    public function __construct(
        public int $global,
        public int $publication,
    ) {}
}
