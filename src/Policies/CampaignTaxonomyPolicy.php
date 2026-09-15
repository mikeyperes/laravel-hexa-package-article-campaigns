<?php

namespace hexa_package_article_campaigns\Policies;

use Illuminate\Support\Str;
use hexa_package_article_campaigns\Discovery\HomepageCategoryPoolDefinition;

class CampaignTaxonomyPolicy
{
    public function limitIds(array $values, int $limit): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $values))));

        return array_slice($ids, 0, max(1, $limit));
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, string>
     */
    public function limitTerms(array $terms, int $limit): array
    {
        $selected = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            $key = $this->normalizeCampaignCategoryKey($term);
            if ($term === '' || $key === '' || isset($selected[$key])) {
                continue;
            }

            $selected[$key] = $term;
            if (count($selected) >= max(1, $limit)) {
                break;
            }
        }

        return array_values($selected);
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, string>
     */
    public function limitCategories(array $terms, int $limit): array
    {
        $selected = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            $key = $this->normalizeCampaignCategoryKey($term);
            if ($term === '' || $key === '' || $this->isDefaultCategoryName($term) || isset($selected[$key])) {
                continue;
            }

            $selected[$key] = $term;
            if (count($selected) >= max(1, $limit)) {
                break;
            }
        }

        return array_values($selected);
    }

    /**
     * @param  array<int, mixed>  $categories
     */
    public function containsDefaultCategory(array $categories): bool
    {
        foreach ($categories as $category) {
            if ($this->isDefaultCategoryName((string) $category)) {
                return true;
            }
        }

        return false;
    }

    public function isDefaultCategoryName(string $category): bool
    {
        return in_array($this->normalizeCampaignCategoryKey($category), ['uncategorized', 'uncategorised'], true);
    }

    /**
     * @param  array<int, mixed>  $generated
     * @param  array<int, mixed>  $allowed
     * @param  array<int, array<string, mixed>>  $sourceTexts
     * @return array<int, string>
     */
    public function enforceTags(array $generated, array $allowed, array $sourceTexts, string $html, string $title, int $limit): array
    {
        $allowed = array_values(array_filter(array_map(static fn ($tag): string => trim((string) $tag), $allowed)));
        if ($allowed === []) {
            return $this->limitTerms($generated, $limit);
        }

        $allowedMap = [];
        foreach ($allowed as $tag) {
            $key = $this->normalizeCampaignCategoryKey($tag);
            if ($key !== '' && ! isset($allowedMap[$key])) {
                $allowedMap[$key] = $tag;
            }
        }

        $selected = [];
        foreach ($generated as $tag) {
            $key = $this->normalizeCampaignCategoryKey((string) $tag);
            if ($key !== '' && isset($allowedMap[$key])) {
                $selected[$allowedMap[$key]] = true;
            } elseif ($key !== '') {
                foreach ($allowedMap as $allowedKey => $allowedName) {
                    if ($allowedKey !== '' && (str_contains($key, $allowedKey) || str_contains($allowedKey, $key))) {
                        $selected[$allowedName] = true;
                        break;
                    }
                }
            }

            if (count($selected) >= max(1, $limit)) {
                break;
            }
        }

        if ($selected === []) {
            foreach ($this->fallbackAllowedCampaignTags($allowed, $sourceTexts, $html, $title, $limit) as $tag) {
                $selected[$tag] = true;
            }
        }

        if ($selected === []) {
            $selected[$allowed[0]] = true;
        }

        return array_slice(array_keys($selected), 0, max(1, $limit));
    }

    /**
     * @param  array<int, string>  $allowed
     * @param  array<int, array<string, mixed>>  $sourceTexts
     * @return array<int, string>
     */
    private function fallbackAllowedCampaignTags(array $allowed, array $sourceTexts, string $html, string $title, int $limit): array
    {
        return $this->fallbackAllowedCampaignTerms($allowed, $sourceTexts, $html, $title, $limit);
    }

    /**
     * @param  array<int, string>  $allowed
     * @param  array<int, array<string, mixed>>  $sourceTexts
     * @return array<int, string>
     */
    private function fallbackAllowedCampaignCategories(array $allowed, array $sourceTexts, string $html, string $title, int $limit): array
    {
        return $this->fallbackAllowedCampaignTerms($allowed, $sourceTexts, $html, $title, $limit);
    }

    /**
     * @param  array<int, string>  $allowed
     * @param  array<int, array<string, mixed>>  $sourceTexts
     * @return array<int, string>
     */
    private function fallbackAllowedCampaignTerms(array $allowed, array $sourceTexts, string $html, string $title, int $limit): array
    {
        $sourceText = implode(' ', array_map(static fn ($source): string => trim((string) (($source['title'] ?? '').' '.($source['text'] ?? ''))), $sourceTexts));
        $surface = Str::lower(strip_tags($title.' '.$html.' '.$sourceText));
        $scores = [];

        foreach ($allowed as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }

            $needle = Str::lower($tag);
            $key = $this->normalizeCampaignCategoryKey($tag);
            $score = 0;
            if ($needle !== '' && str_contains($surface, $needle)) {
                $score += 2;
            }
            if ($key !== '' && preg_match('/\\b'.preg_quote($key, '/').'\\b/i', preg_replace('/[^a-z0-9]+/i', ' ', $surface) ?: '')) {
                $score++;
            }
            if ($score > 0) {
                $scores[$tag] = $score;
            }
        }

        if ($scores === []) {
            return [];
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, max(1, $limit));
    }

    private function normalizeCampaignCategoryKey(string $category): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower($category)) ?: '';
    }

    /**
     * @param array<int, array<int, string>> $recentCategoryLists
     */
    public function selectRotatingCategoryFromHistory(
        array $resolved,
        array $recentCategoryLists = [],
        ?array $availableCategories = null,
    ): string {
        if (empty($resolved['category_rotation_enabled']) || trim((string) ($resolved['forced_category'] ?? '')) !== '') {
            return '';
        }

        $pool = collect((array) ($resolved['category_rotation_pool'] ?? []))
            ->map(fn ($category) => trim((string) $category))
            ->filter()
            ->unique(fn ($category) => $this->normalizeCampaignCategoryKey((string) $category))
            ->values()
            ->all();

        if ($pool === []) {
            return '';
        }

        if (($resolved['discovery_process'] ?? '') === HomepageCategoryPoolDefinition::TYPE && $availableCategories !== null) {
            $availableKeys = array_map(fn ($category) => $this->normalizeCampaignCategoryKey((string) $category), $availableCategories);
            $stocked = array_values(array_filter($pool, fn ($category) => in_array($this->normalizeCampaignCategoryKey($category), $availableKeys, true)));
            if ($stocked !== []) {
                $pool = $stocked;
            }
        }

        $canonical = [];
        foreach ($pool as $category) {
            $key = $this->normalizeCampaignCategoryKey((string) $category);
            if ($key !== '') {
                $canonical[$key] = (string) $category;
            }
        }

        $stats = [];
        foreach ($canonical as $category) {
            $stats[$category] = ['count' => 0, 'last_seen' => PHP_INT_MAX];
        }

        foreach ($recentCategoryLists as $index => $categories) {
            foreach ((array) $categories as $category) {
                $key = $this->normalizeCampaignCategoryKey((string) $category);
                if ($key !== '' && isset($canonical[$key])) {
                    $name = $canonical[$key];
                    $stats[$name]['count']++;
                    $stats[$name]['last_seen'] = min((int) $stats[$name]['last_seen'], (int) $index);
                }
            }
        }

        usort($pool, function (string $a, string $b) use ($stats): int {
            $aCount = (int) ($stats[$a]['count'] ?? 0);
            $bCount = (int) ($stats[$b]['count'] ?? 0);
            if ($aCount !== $bCount) {
                return $aCount <=> $bCount;
            }

            return ((int) ($stats[$b]['last_seen'] ?? PHP_INT_MAX)) <=> ((int) ($stats[$a]['last_seen'] ?? PHP_INT_MAX));
        });

        return (string) ($pool[0] ?? '');
    }

    /**
     * @param  array<int, string>  $categories
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    public function applyForcedCategory(array $categories, string $forcedCategory, array $allowed, int $limit): array
    {
        $categories = $this->limitCategories($categories, $limit);
        $forcedCategory = trim($forcedCategory);
        if ($forcedCategory === '' || $this->isDefaultCategoryName($forcedCategory)) {
            return $categories;
        }

        $allowed = $this->limitCategories($allowed, max(1, count($allowed)));
        $allowedMap = [];
        foreach ($allowed as $category) {
            $key = $this->normalizeCampaignCategoryKey((string) $category);
            if ($key !== '') {
                $allowedMap[$key] = trim((string) $category);
            }
        }

        $forcedKey = $this->normalizeCampaignCategoryKey($forcedCategory);
        if ($forcedKey === '') {
            return $categories;
        }

        $canonicalForced = $allowedMap[$forcedKey] ?? $forcedCategory;
        if ($allowedMap !== [] && ! isset($allowedMap[$forcedKey])) {
            return $categories;
        }

        $selected = [$canonicalForced => true];
        foreach ($categories as $category) {
            $category = trim((string) $category);
            $key = $this->normalizeCampaignCategoryKey($category);
            $canonical = $allowedMap[$key] ?? $category;
            if ($category !== '' && $key !== $forcedKey) {
                $selected[$canonical] = true;
            }
            if (count($selected) >= max(1, $limit)) {
                break;
            }
        }

        return array_slice(array_keys($selected), 0, max(1, $limit));
    }

    /**
     * @param  array<int, string>  $generated
     * @param  array<int, string>  $allowed
     * @param  array<int, array<string, mixed>>  $sourceTexts
     * @return array<int, string>
     */
    public function enforceCategories(array $generated, array $allowed, array $sourceTexts, string $html, string $title, int $limit): array
    {
        $generated = $this->limitCategories($generated, max(1, count($generated)));
        $allowed = $this->limitCategories($allowed, max(1, count($allowed)));
        if ($allowed === []) {
            return array_slice($generated, 0, max(1, $limit));
        }

        $allowedMap = [];
        foreach ($allowed as $category) {
            $key = $this->normalizeCampaignCategoryKey($category);
            if ($key !== '' && ! isset($allowedMap[$key])) {
                $allowedMap[$key] = $category;
            }
        }

        $selected = [];
        foreach ($generated as $category) {
            $key = $this->normalizeCampaignCategoryKey((string) $category);
            if ($key !== '' && isset($allowedMap[$key])) {
                $selected[$allowedMap[$key]] = true;
            }
            if ($key !== '' && ! isset($allowedMap[$key])) {
                foreach ($allowedMap as $allowedKey => $allowedName) {
                    if ($allowedKey !== '' && (str_contains($key, $allowedKey) || str_contains($allowedKey, $key))) {
                        $selected[$allowedName] = true;
                        break;
                    }
                }
            }
        }

        if ($selected === []) {
            foreach ($this->fallbackAllowedCampaignCategories($allowed, $sourceTexts, $html, $title, $limit) as $category) {
                $selected[$category] = true;
            }
        }

        if ($selected === []) {
            $selected[$allowed[0]] = true;
        }

        return array_slice(array_keys($selected), 0, max(1, $limit));
    }
}
