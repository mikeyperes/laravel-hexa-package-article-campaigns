<?php

namespace hexa_package_article_campaigns\Discovery;

use hexa_package_article_campaigns\Data\CampaignDefinition;
use RuntimeException;

/** Compile validated first-party manifest facts into one reusable policy input. */
final class CampaignDefinitionCompiler
{
    public function __construct(private HomepageCategorySearchPolicy $searchPolicy) {}

    /**
     * @param array<string, mixed> $taxonomyCapabilities
     * @param array<int, array<string, mixed>> $categories
     * @param array{name:string,description?:string,homepage_title?:string} $publication
     */
    public function compile(
        string $manifestUrl,
        int $manifestApiVersion,
        string $manifestPluginVersion,
        string $manifestFingerprint,
        string $homepageUrl,
        array $taxonomyCapabilities,
        array $categories,
        array $publication,
    ): CampaignDefinition {
        $identity = trim(implode(' ', [
            (string) ($publication['name'] ?? ''),
            (string) ($publication['description'] ?? ''),
            (string) ($publication['homepage_title'] ?? ''),
        ]));
        $focus = $this->searchPolicy->publicationFocus($identity);
        $compiledCategories = $this->compileCategories($categories, $identity, $focus);

        return CampaignDefinition::compile(
            HomepageCategoryPoolDefinition::RETRIEVAL_METHOD,
            $manifestUrl,
            $manifestApiVersion,
            $manifestPluginVersion,
            $manifestFingerprint,
            $homepageUrl,
            $taxonomyCapabilities,
            $compiledCategories,
            $focus,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @param array<string, mixed>|null $focus
     * @return array<int, array<string, mixed>>
     */
    private function compileCategories(array $categories, string $identity, ?array $focus): array
    {
        $specific = [];
        foreach ($categories as $category) {
            if (! $this->searchPolicy->generic((string) ($category['name'] ?? ''))) {
                $specific = array_merge($specific, $this->categoryTerms($category));
            }
        }

        if ($specific === [] && $focus !== null) {
            $specific = (array) ($focus['terms'] ?? []);
        }
        if ($specific === []) {
            $specific = $this->searchPolicy->termsForEvidence($identity);
        }
        $specific = array_values(array_unique(array_filter(array_map(
            static fn (mixed $term): string => trim((string) $term),
            $specific,
        ))));

        foreach ($categories as &$category) {
            $name = trim((string) ($category['name'] ?? ''));
            $terms = $this->searchPolicy->generic($name)
                ? array_slice($specific, 0, 15)
                : $this->categoryTerms($category);
            $terms = array_values(array_unique(array_filter(array_map(
                static fn (mixed $term): string => trim((string) $term),
                $terms,
            ))));
            if ($terms === []) {
                throw new RuntimeException('The homepage category "'.$name.'" has no manifest-derived search subject.');
            }

            $category['terms'] = $terms;
            $category['content_mode'] = $this->searchPolicy->contentMode($name);
            $suffix = $category['content_mode'] === 'evergreen' ? ' guide' : ' news';
            $category['queries'] = array_map(
                static fn (string $term): string => $term.$suffix,
                array_slice($terms, 0, 5),
            );
            if (count($category['queries']) === 1) {
                $category['queries'][] = $terms[0].' industry developments';
                $category['queries'][] = $terms[0].' research innovation';
            }
            if ($focus !== null) {
                $category['queries'] = array_map(
                    static fn (string $query): string => trim((string) $focus['query_prefix'].' '.$query),
                    $category['queries'],
                );
            }
            unset($category['policy_status']);
        }
        unset($category);

        return array_values($categories);
    }

    /** @param array<string, mixed> $category */
    private function categoryTerms(array $category): array
    {
        $sections = array_map(
            static fn (array $source): string => (string) ($source['section'] ?? ''),
            array_filter(
                (array) data_get($category, 'homepage_evidence.sources', []),
                'is_array',
            ),
        );

        return $this->searchPolicy->termsForEvidence(
            (string) ($category['name'] ?? ''),
            (string) ($category['description'] ?? ''),
            $sections,
            (string) ($category['slug'] ?? ''),
        );
    }
}
