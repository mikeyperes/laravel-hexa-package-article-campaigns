<?php

namespace hexa_package_article_campaigns\Discovery;

use InvalidArgumentException;

/** Build a bounded, round-robin query plan from a reviewed pool definition. */
final class HomepageCategoryQueryPlanner
{
    /**
     * @return array<int, array{category:array<string, mixed>,query:string}>
     */
    public function plan(array $definition, int $limit = 12, ?string $categoryName = null): array
    {
        if (! HomepageCategoryPoolDefinition::isUsableManifestDefinition($definition)) {
            throw new InvalidArgumentException('A valid manifest-backed category definition is required.');
        }

        $limit = max(1, min(300, $limit));
        $lanes = array_values(array_filter(
            (array) $definition['categories'],
            static fn (mixed $category): bool => is_array($category)
                && ($categoryName === null || strcasecmp($categoryName, (string) ($category['name'] ?? '')) === 0),
        ));
        $maximumVariants = 0;
        foreach ($lanes as $lane) {
            $maximumVariants = max($maximumVariants, count((array) ($lane['queries'] ?? [])));
        }

        $planned = [];
        $seen = [];
        for ($index = 0; $index < $maximumVariants && count($planned) < $limit; $index++) {
            foreach ($lanes as $lane) {
                $query = trim((string) (($lane['queries'] ?? [])[$index] ?? ''));
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
}
