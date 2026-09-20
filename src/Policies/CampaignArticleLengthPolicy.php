<?php

namespace hexa_package_article_campaigns\Policies;

/** Shared evidence and output floors for manifest-backed campaign articles. */
final class CampaignArticleLengthPolicy
{
    private const DEFAULT_ARTICLE_MINIMUM = 400;

    private const MINIMUM_ARTICLE_FLOOR = 350;

    private const MAXIMUM_ARTICLE_FLOOR = 450;

    private const MAXIMUM_SINGLE_SOURCE_FLOOR = 400;

    public function minimumArticleWords(int $configuredMinimum = 0): int
    {
        if ($configuredMinimum <= 0) {
            return self::DEFAULT_ARTICLE_MINIMUM;
        }

        return min(max($configuredMinimum, self::MINIMUM_ARTICLE_FLOOR), self::MAXIMUM_ARTICLE_FLOOR);
    }

    public function minimumSourceWords(int $configuredMinimum = 0): int
    {
        return min($this->minimumArticleWords($configuredMinimum), self::MAXIMUM_SINGLE_SOURCE_FLOOR);
    }
}
