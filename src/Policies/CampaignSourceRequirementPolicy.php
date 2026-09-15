<?php

namespace hexa_package_article_campaigns\Policies;

use hexa_package_article_campaigns\Discovery\HomepageCategoryPoolDefinition;

final class CampaignSourceRequirementPolicy
{
    public function replacementPoolExhausted(array $discovery): bool
    {
        $details = is_array($discovery['details'] ?? null) ? $discovery['details'] : [];

        return ($details['search_backend'] ?? '') === HomepageCategoryPoolDefinition::TYPE
            && empty($discovery['urls'])
            && ($details['outcome']['status'] ?? '') === 'candidate_pool_exhausted'
            && ($details['outcome']['retryable'] ?? null) === false;
    }

    public function replacementAttemptLimit(array $resolved): int
    {
        return ($resolved['discovery_process'] ?? '') === HomepageCategoryPoolDefinition::TYPE ? 12 : 3;
    }

    /**
     * Return the minimum number of publication-ready primary sources required
     * before an article may enter generation.
     *
     * Routine editorial news may be based on one complete, relevant source;
     * investigative and analytical formats retain a multi-source requirement.
     * Every source still passes provenance, relevance, negative-topic, factual-
     * claim, citation, and pre-publication quality checks downstream.
     *
     * @param  array<string, mixed>  $resolved
     */
    public function minimumPrimarySources(array $resolved): int
    {
        $configured = (int) ($resolved['minimum_primary_source_count'] ?? 0);
        if ($configured > 0) {
            return max(1, min(3, $configured));
        }

        $articleType = strtolower(trim((string) ($resolved['article_type'] ?? '')));

        return in_array($articleType, [
            'analysis',
            'reportage',
            'investigative',
            'investigative-report',
            'expert-article',
        ], true) ? 2 : 1;
    }
}
