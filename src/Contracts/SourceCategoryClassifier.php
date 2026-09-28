<?php

namespace hexa_package_article_campaigns\Contracts;

use hexa_package_article_campaigns\Data\SourceClassification;

/**
 * Decides which manifest category one extracted source belongs to and whether
 * it fits the publication, before any paid article generation.
 *
 * Implementations own the model call, metering and caching. Returning null
 * means classification is unavailable (provider outage, disabled, invalid
 * answer); callers then fall back to their deterministic policy.
 */
interface SourceCategoryClassifier
{
    /**
     * @param  array<string, mixed>  $source  title, url and complete extracted text
     * @param  array<int, array<string, mixed>>  $categories  manifest categories (name, description)
     * @param  array<string, mixed>  $publication  name, homepage_url and optional focus label
     */
    public function classify(array $source, array $categories, array $publication = []): ?SourceClassification;

    /**
     * Screen search candidates by title and snippet before any page is
     * fetched. One result per candidate, same keys; null where unavailable.
     *
     * @param  array<int|string, array<string, mixed>>  $candidates  title, url and optional description
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<string, mixed>  $publication
     * @return array<int|string, SourceClassification|null>
     */
    public function screen(array $candidates, array $categories, array $publication = []): array;
}
