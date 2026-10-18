<?php

namespace hexa_package_article_campaigns\Discovery;

use InvalidArgumentException;

/** Build a bounded, round-robin query plan from a reviewed pool definition. */
final class HomepageCategoryQueryPlanner
{
    /**
     * @return array<int, array{category:array<string, mixed>,query:string}>
     */
    public function plan(array $definition, ?int $limit = 12, ?string $categoryName = null): array
    {
        if (! HomepageCategoryPoolDefinition::isUsableManifestDefinition($definition)) {
            throw new InvalidArgumentException('A valid manifest-backed category definition is required.');
        }

        // Null enumerates the reviewed definition so an I/O owner can apply
        // its execution limit after checking query cooldowns.
        $limit = $limit === null ? PHP_INT_MAX : max(1, min(300, $limit));
        $lanes = array_values(array_filter(
            (array) $definition['categories'],
            static fn (mixed $category): bool => is_array($category)
                && ($categoryName === null || strcasecmp($categoryName, (string) ($category['name'] ?? '')) === 0),
        ));
        // CRITICAL — see laravel-hexa-app-publish BUGLOG.md CAMPAIGN-BUG-229. A
        // lane's few compiled phrases returned the same stories on every
        // refill, so pools stayed tiny. Each news lane also searches its subject
        // from distinct news angles; each angle is its own result set.
        $lanes = array_map(fn (array $lane): array => array_replace($lane, ['queries' => $this->laneQueries($lane)]), $lanes);
        $maximumVariants = 0;
        foreach ($lanes as $lane) {
            $maximumVariants = max($maximumVariants, count((array) ($lane['queries'] ?? [])));
        }

        $planned = [];
        $seen = [];
        for ($index = 0; $index < $maximumVariants && count($planned) < $limit; $index++) {
            foreach ($lanes as $lane) {
                // CRITICAL — see laravel-hexa-app-publish BUGLOG.md CAMPAIGN-BUG-228.
                // WordPress category names arrive HTML-escaped ("Health &amp; Wellness");
                // the escape must not be searched literally.
                $query = trim(html_entity_decode((string) (($lane['queries'] ?? [])[$index] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $key = mb_strtolower(preg_replace('/\s+/', ' ', $query) ?: $query);
                if ($query === '' || isset($seen[$key])) {
                    continue;
                }

                $planned[] = ['category' => $lane, 'query' => $query];
                $seen[$key] = true;
                if (count($planned) >= $limit) {
                    break 2;
                }
            }
        }

        return $planned;
    }

    /** News-event angles; each pulls a different slice of a subject's coverage. */
    private const NEWS_ANGLES = [
        'announces', 'report', 'study', 'deal', 'funding', 'launch', 'lawsuit',
        'regulation', 'expansion', 'partnership', 'CEO', 'market', 'trends',
        'award', 'opens', 'acquisition', 'survey', 'policy', 'growth', 'warns',
    ];

    /** @return array<int, string> the lane's compiled queries, then its angle variants */
    private function laneQueries(array $lane): array
    {
        $queries = array_values(array_filter(array_map(
            static fn (mixed $query): string => trim(html_entity_decode((string) $query, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            (array) ($lane['queries'] ?? []),
        )));
        if (($lane['content_mode'] ?? 'news') !== 'news' || isset($lane['source_format']) || $queries === []) {
            return $queries;
        }
        // Angles attach to a lane's plain subject ("real estate news" gives
        // "real estate"), never to an already-angled compiled variant.
        $subjects = [];
        foreach ($queries as $query) {
            if (preg_match('/^(.+?)\s+news$/i', $query, $match) === 1) {
                $subjects[] = $match[1];
            }
        }
        $subjects = array_slice(array_values(array_unique($subjects)), 0, 3);
        if ($subjects === []) {
            return $queries;
        }
        $angled = [];
        foreach (self::NEWS_ANGLES as $angle) {
            foreach ($subjects as $subject) {
                $angled[] = $subject.' '.$angle;
            }
        }

        return array_values(array_unique(array_merge($queries, $angled)));
    }
}
