<?php

namespace hexa_package_article_campaigns\Policies;

/** Shared evidence and output floors for manifest-backed campaign articles. */
final class CampaignArticleLengthPolicy
{
    private const DEFAULT_ARTICLE_MINIMUM = 400;

    private const MINIMUM_ARTICLE_FLOOR = 350;

    private const MAXIMUM_ARTICLE_FLOOR = 450;

    private const MAXIMUM_SINGLE_SOURCE_FLOOR = 400;

    private const ACCEPTANCE_TOLERANCE_PERCENT = 5;

    private const GENERATION_TARGET_BUFFER_WORDS = 50;

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

    /**
     * Accept a draft that is materially at the requested length without
     * treating a few words of provider/tokenization variance as a hard
     * publication failure. The global evidence floor remains absolute.
     */
    public function minimumAcceptedArticleWords(int $configuredMinimum = 0): int
    {
        $target = $this->minimumArticleWords($configuredMinimum);
        $tolerance = (int) ceil($target * (self::ACCEPTANCE_TOLERANCE_PERCENT / 100));

        return max(self::MINIMUM_ARTICLE_FLOOR, $target - $tolerance);
    }

    /**
     * Give generation providers a bounded cushion above the publication
     * target so cleanup and tokenization do not turn an otherwise usable
     * response into a paid near-boundary rejection.
     */
    public function generationTargetMinimumWords(int $configuredMinimum = 0, int $configuredMaximum = 0): int
    {
        $publicationTarget = $this->minimumArticleWords($configuredMinimum);
        $target = $publicationTarget + self::GENERATION_TARGET_BUFFER_WORDS;
        if ($configuredMaximum > 0) {
            $target = min($target, max($publicationTarget, $configuredMaximum));
        }

        return max($publicationTarget, $target);
    }
}
