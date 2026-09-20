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
            $subject = $this->categorySubject($category);
            $terms = $this->searchPolicy->generic($name)
                ? array_slice($specific, 0, 15)
                : $subject['terms'];
            $terms = array_values(array_unique(array_filter(array_map(
                static fn (mixed $term): string => trim((string) $term),
                $terms,
            ))));
            if ($terms === []) {
                throw new RuntimeException('The homepage category "'.$name.'" has no manifest-derived search subject.');
            }

            $category['terms'] = $terms;
            $category['semantic_context'] = $this->searchPolicy->generic($name)
                ? ['source' => 'manifest_evidence', 'subject' => $name]
                : $subject['context'];
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

    /**
     * @param array<string, mixed> $category
     * @return array{terms:array<int,string>,context:array{source:string,subject:string}}
     */
    private function categorySubject(array $category): array
    {
        $name = trim((string) ($category['name'] ?? ''));
        $knownTerms = $this->searchPolicy->knownTerms($name);
        if ($knownTerms !== []) {
            return [
                'terms' => $knownTerms,
                'context' => ['source' => 'category_vocabulary', 'subject' => $name],
            ];
        }

        $parent = $this->searchPolicy->parentPathContext(
            (string) ($category['homepage_link'] ?? ''),
            (string) ($category['slug'] ?? ''),
        );
        if ($parent !== null) {
            return [
                'terms' => $parent['terms'],
                'context' => ['source' => 'parent_category_path', 'subject' => $parent['subject']],
            ];
        }

        $sections = array_values(array_filter(array_map(
            static fn (array $source): string => trim((string) ($source['section'] ?? '')),
            array_filter((array) data_get($category, 'homepage_evidence.sources', []), 'is_array'),
        )));
        $description = trim((string) ($category['description'] ?? ''));
        $normalizedName = $this->normalizeEvidence($name);
        $hasDistinctSection = collect($sections)->contains(
            fn (string $section): bool => $this->normalizeEvidence($section) !== $normalizedName,
        );
        if ($description === '' && ! $hasDistinctSection) {
            throw new RuntimeException(
                'The homepage category "'.$name.'" lacks manifest-derived semantic context. '
                .'A known parent category URL, description, or distinct Elementor section is required. No AI was called.',
            );
        }

        return [
            'terms' => $this->categoryTerms($category),
            'context' => ['source' => 'manifest_evidence', 'subject' => $name],
        ];
    }

    private function normalizeEvidence(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }
}
