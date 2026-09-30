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
     * @param array<string, mixed> $deliveryCapabilities
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
        array $deliveryCapabilities = [],
    ): CampaignDefinition {
        $identity = trim(implode(' ', [
            (string) ($publication['name'] ?? ''),
            (string) ($publication['description'] ?? ''),
            (string) ($publication['homepage_title'] ?? ''),
        ]));
        $focus = $this->searchPolicy->publicationFocus($identity);
        $compiledCategories = $this->compileCategories(
            $categories,
            $identity,
            $focus,
            trim((string) ($publication['name'] ?? '')),
        );

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
            $deliveryCapabilities,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @param array<string, mixed>|null $focus
     * @return array<int, array<string, mixed>>
     */
    private function compileCategories(array $categories, string $identity, ?array $focus, string $publicationName = ''): array
    {
        $specific = [];
        $specificAnchors = [];
        $subjects = [];
        $coreLanes = [];
        $coreTerms = [];
        $coreAnchors = [];
        $sharedSections = $this->sharedSections($categories);
        foreach ($categories as $index => $category) {
            $name = (string) ($category['name'] ?? '');
            if (! $this->searchPolicy->generic($name)) {
                $subjects[$index] = $this->categorySubject($category, $sharedSections);
                if ($this->searchPolicy->laneSourceFormat($category) === null) {
                    $specific = array_merge($specific, $subjects[$index]['terms']);
                    $specificAnchors[] = (string) ($subjects[$index]['terms'][0] ?? '');
                    // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-158. A topical lane
                    // named by the publication itself ("Golf" on Mens Golf
                    // Journal) is the publication's core subject.
                    if ($this->searchPolicy->namesSubject($publicationName, $subjects[$index]['terms'])) {
                        $coreLanes[$index] = true;
                        $coreTerms = array_merge($coreTerms, $subjects[$index]['terms']);
                        $coreAnchors[] = (string) ($subjects[$index]['terms'][0] ?? '');
                    }
                }
            }
        }
        $coreTerms = array_values(array_unique(array_filter(array_map('trim', $coreTerms))));
        $coreAnchors = array_values(array_unique(array_filter(array_map('trim', $coreAnchors))));
        // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-158. Every other lane is a facet
        // of that subject: "Brands" on a golf publication means golf brands, and
        // the bare facet ("brands news") returned mall retail, bottled water and
        // laundry stories. Facet, generic and format lanes pair with the core
        // subject. A focus profile already prefixes every query.
        $anchorLanes = $focus === null && $coreAnchors !== [];

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
        $specificAnchors = array_values(array_unique(array_filter(array_map(
            static fn (mixed $term): string => trim((string) $term),
            array_merge($specificAnchors, $specific),
        ))));

        foreach ($categories as $index => &$category) {
            $name = trim((string) ($category['name'] ?? ''));
            $generic = $this->searchPolicy->generic($name);
            // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-153. A lane under a format
            // section (a podcast child) pairs the format with the publication's
            // topical lanes instead of searching the bare medium.
            $sourceFormat = $this->searchPolicy->laneSourceFormat($category);
            $subject = $generic ? null : ($subjects[$index] ?? $this->categorySubject($category, $sharedSections));
            $terms = $generic
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
            $category['semantic_context'] = $generic
                ? ['source' => 'manifest_evidence', 'subject' => $name]
                : $subject['context'];
            $category['content_mode'] = $this->searchPolicy->contentMode($name);
            if ($sourceFormat !== null) {
                $category['source_format'] = $sourceFormat;
                $category['context_terms'] = array_slice($anchorLanes ? $coreTerms : $specific, 0, 24);
                if ($category['context_terms'] === []) {
                    throw new RuntimeException('The source-format homepage category "'.$name.'" has no manifest-derived publication subject. No AI was called.');
                }
                $category['queries'] = $this->sourceFormatQueries($terms, $anchorLanes ? $coreAnchors : $specificAnchors);
            } else {
                unset($category['source_format'], $category['context_terms']);
                $suffix = $category['content_mode'] === 'evergreen' ? ' guide' : ' news';
                $category['queries'] = array_map(
                    static fn (string $term): string => $term.$suffix,
                    array_slice($terms, 0, 5),
                );
                if (count($category['queries']) === 1) {
                    $category['queries'][] = $terms[0].' industry developments';
                    $category['queries'][] = $terms[0].' research innovation';
                }
            }
            unset($category['anchor_terms']);
            if ($anchorLanes && $sourceFormat === null && ! isset($coreLanes[$index])) {
                $category['anchor_terms'] = array_slice($coreTerms, 0, 24);
                $category['queries'] = $this->anchoredQueries($category['queries'], $coreAnchors, $coreTerms);
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

    /**
     * Prefix each query with the publication's core subject unless it already
     * names it ("golf news" stays; "brands news" becomes "golf brands news").
     *
     * @param array<int, string> $queries
     * @param array<int, string> $anchors
     * @param array<int, string> $coreTerms
     * @return array<int, string>
     */
    private function anchoredQueries(array $queries, array $anchors, array $coreTerms): array
    {
        $prefix = count($anchors) === 1
            ? $anchors[0]
            : '('.implode(' OR ', array_map(
                static fn (string $anchor): string => str_contains($anchor, ' ') ? '"'.$anchor.'"' : $anchor,
                $anchors,
            )).')';

        return array_values(array_unique(array_map(
            fn (string $query): string => $this->searchPolicy->namesSubject($query, $coreTerms)
                ? $query
                : trim($prefix.' '.$query),
            $queries,
        )));
    }

    /**
     * @param array<int, string> $formatTerms
     * @param array<int, string> $contextTerms
     * @return array<int, string>
     */
    private function sourceFormatQueries(array $formatTerms, array $contextTerms): array
    {
        $formats = array_slice($formatTerms, 0, 2);
        $contexts = array_slice($contextTerms, 0, 6);
        if ($formats === [] || $contexts === []) {
            return [];
        }
        $queries = [];
        foreach ($contexts as $index => $context) {
            $format = (string) ($formats[$index % count($formats)] ?? '');
            if ($context !== '' && $format !== '') {
                $queries[] = trim($context.' '.$format);
            }
        }

        return array_values(array_unique($queries));
    }

    /**
     * @param array<string, mixed> $category
     * @param array<int, string> $sharedSections normalized headings that group several categories
     */
    private function categoryTerms(array $category, array $sharedSections): array
    {
        // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-119. A homepage heading that
        // groups several categories ("Deep Dives & Hosting Guides") names the
        // group, not this category's subject; as a term it became a wasted
        // search on every lane.
        $sections = array_values(array_filter(
            $this->evidenceSections($category),
            fn (string $section): bool => ! in_array($this->normalizeEvidence($section), $sharedSections, true),
        ));

        return $this->searchPolicy->termsForEvidence(
            (string) ($category['name'] ?? ''),
            (string) ($category['description'] ?? ''),
            $sections,
            (string) ($category['slug'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $category
     * @return array<int, string>
     */
    private function evidenceSections(array $category): array
    {
        return array_values(array_filter(array_map(
            static fn (array $source): string => trim((string) ($source['section'] ?? '')),
            array_filter((array) data_get($category, 'homepage_evidence.sources', []), 'is_array'),
        )));
    }

    /**
     * Normalized homepage section headings that appear on more than one category.
     *
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, string>
     */
    private function sharedSections(array $categories): array
    {
        $owners = [];
        foreach ($categories as $index => $category) {
            foreach ($this->evidenceSections($category) as $section) {
                $owners[$this->normalizeEvidence($section)][$index] = true;
            }
        }

        return array_keys(array_filter($owners, static fn (array $categoryIndexes): bool => count($categoryIndexes) > 1));
    }

    /**
     * @param array<string, mixed> $category
     * @param array<int, string> $sharedSections
     * @return array{terms:array<int,string>,context:array{source:string,subject:string}}
     */
    private function categorySubject(array $category, array $sharedSections): array
    {
        $name = trim((string) ($category['name'] ?? ''));
        $sourceFormat = $this->searchPolicy->laneSourceFormat($category);
        if ($sourceFormat !== null) {
            $formatTerms = $this->searchPolicy->sourceFormatTerms($sourceFormat);
            if ($formatTerms !== []) {
                return [
                    'terms' => $formatTerms,
                    'context' => ['source' => 'structural_source_format', 'subject' => $name],
                ];
            }
        }
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

        $sections = $this->evidenceSections($category);
        $description = trim((string) ($category['description'] ?? ''));
        $normalizedName = $this->normalizeEvidence($name);
        $hasDistinctSection = collect($sections)->contains(
            fn (string $section): bool => $this->normalizeEvidence($section) !== $normalizedName,
        );
        if ($description === '' && ! $hasDistinctSection && ! $this->searchPolicy->hasStandaloneSubject($name)) {
            throw new RuntimeException(
                'The homepage category "'.$name.'" lacks manifest-derived semantic context. '
                .'A known parent category URL, description, or distinct Elementor section is required. No AI was called.',
            );
        }

        return [
            'terms' => $this->categoryTerms($category, $sharedSections),
            'context' => ['source' => 'manifest_evidence', 'subject' => $name],
        ];
    }

    private function normalizeEvidence(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }
}
