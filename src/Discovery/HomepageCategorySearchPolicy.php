<?php

namespace hexa_package_article_campaigns\Discovery;

use Illuminate\Support\Str;

/** Deterministic category expansion and relevance checks; no I/O or AI calls. */
class HomepageCategorySearchPolicy
{
    /** @var array<string, mixed> */
    private array $semantics;

    /** @var array<int, array<string, mixed>> */
    private array $focusProfiles;

    public function __construct(?array $semantics = null, ?array $focusProfiles = null)
    {
        $this->semantics = $semantics ?? require dirname(__DIR__, 2).'/resources/category-semantics.php';
        // Publication-specific vocabulary and focus profiles are policy input,
        // not generic package defaults. New campaign definitions compile from
        // their first-party manifest unless an application explicitly injects
        // reviewed configuration here.
        $this->focusProfiles = $focusProfiles ?? [];
    }

    public function isKnownCategoryName(string $name): bool
    {
        return $this->knownTerms($name) !== [];
    }

    /** @return array<int, string> */
    public function knownTerms(string $category): array
    {
        $key = $this->categoryKey($category);
        $vocabulary = $this->vocabulary();
        $aliases = (array) ($this->semantics['aliases'] ?? []);
        foreach (array_values(array_unique([$key, Str::singular($key)])) as $candidate) {
            $candidate = $aliases[$candidate] ?? $candidate;
            if (isset($vocabulary[$candidate])) {
                return array_values($vocabulary[$candidate]);
            }
        }

        $key = str_replace('-', ' ', $key);
        if (isset($vocabulary[$key])) {
            return array_values($vocabulary[$key]);
        }
        foreach ($vocabulary as $terms) {
            if (Str::lower($terms[0]) === $key) {
                return array_values($terms);
            }
        }

        return [];
    }

    /**
     * Resolve the nearest known parent subject embedded in a WordPress
     * category URL, for example `/category/podcasts/plugged-in/`.
     *
     * @return array{subject:string,terms:array<int,string>}|null
     */
    public function parentPathContext(string $categoryUrl, string $leafSlug): ?array
    {
        $path = (string) (parse_url(trim($categoryUrl), PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => trim(str_replace('-', ' ', rawurldecode($segment))),
            explode('/', trim($path, '/')),
        )));
        $leaf = $this->categoryKey(str_replace('-', ' ', $leafSlug));
        $structural = ['category', 'categories', 'topic', 'topics', 'section', 'sections'];

        for ($index = count($segments) - 1; $index >= 0; $index--) {
            $subject = $segments[$index];
            $key = $this->categoryKey($subject);
            if ($key === '' || $key === $leaf || in_array($key, $structural, true)) {
                continue;
            }
            $terms = $this->knownTerms($subject);
            if ($terms === [] && $this->hasStandaloneSubject($subject)) {
                $terms = $this->termsForEvidence($subject, '', [], $segments[$index]);
            }
            if ($terms !== []) {
                return ['subject' => $subject, 'terms' => $terms];
            }
        }

        return null;
    }

    /**
     * A plain subject such as `Security` is useful first-party evidence even
     * before it has a curated vocabulary entry. A phrase whose only content
     * token depends on a stop word, such as `Plugged In`, remains ambiguous.
     */
    public function hasStandaloneSubject(string $label): bool
    {
        $tokens = array_values(array_filter(preg_split(
            '/[^a-z0-9]+/',
            Str::lower(Str::ascii(trim($label))),
        ) ?: []));
        $content = array_values(array_filter(
            $tokens,
            fn (string $token): bool => ! in_array($token, (array) ($this->semantics['stop_words'] ?? []), true),
        ));

        return count($content) >= 2 || (count($content) === 1 && count($tokens) === 1);
    }

    public function publicationFocus(string $identity): ?array
    {
        $text = Str::lower(Str::ascii($identity));
        foreach ($this->focusProfiles as $profile) {
            if (! $this->profileMatches($profile, $text)) {
                continue;
            }

            return array_filter([
                'label' => (string) ($profile['label'] ?? ''),
                'query_prefix' => (string) ($profile['query_prefix'] ?? ''),
                'terms' => array_values((array) ($profile['terms'] ?? [])),
                'surface' => $profile['surface'] ?? null,
                'source' => 'manifest_identity',
                'homepage_evidence' => $identity,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return null;
    }

    public function matchesPublicationFocus(array $source, array $definition): bool
    {
        $terms = (array) data_get($definition, 'publication_focus.terms', []);
        if ($terms === []) {
            return true;
        }
        // Require explicit evidence in editorial metadata or the opening.
        // Names and a mention buried in a biography do not establish gender.
        // A focus marked `headline` must appear in the title or description, not the body.
        $headlineOnly = data_get($definition, 'publication_focus.surface') === 'headline';
        $surface = Str::lower(Str::ascii(strip_tags(implode(' ', [
            (string) ($source['title'] ?? ''),
            (string) ($source['description'] ?? $source['excerpt'] ?? $source['snippet'] ?? ''),
            $headlineOnly ? '' : mb_substr((string) ($source['text'] ?? $source['content'] ?? ''), 0, 1600),
        ]))));
        foreach ($terms as $term) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote(Str::lower(Str::ascii((string) $term)), '/').'(?![a-z0-9])/i', $surface)) {
                return true;
            }
        }
        return false;
    }

    public function terms(string $category): array
    {
        return $this->termsForEvidence($category);
    }

    /**
     * Compile category semantics from first-party manifest evidence. An
     * unfamiliar category remains usable without a PHP release.
     *
     * @param  array<int, string>  $sections
     * @return array<int, string>
     */
    public function termsForEvidence(string $category, string $description = '', array $sections = [], string $slug = ''): array
    {
        $known = $this->knownTerms($category);
        if ($known !== []) {
            return $known;
        }

        $key = str_replace('-', ' ', $this->categoryKey($category));
        $vocabulary = $this->vocabulary();

        $expanded = [];
        foreach ($vocabulary as $subject => $terms) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($subject, '/').'(?![a-z0-9])/i', $key)) {
                $expanded = array_merge($expanded, $terms);
            }
        }
        if ($expanded !== []) {
            return array_values(array_unique($expanded));
        }

        $evidence = array_values(array_filter([
            trim($category),
            trim(str_replace('-', ' ', $slug)),
            trim($description),
            ...array_map(static fn (mixed $section): string => trim((string) $section), $sections),
        ]));
        $terms = [];
        $descriptionIndex = trim($description) === '' ? null : array_search(trim($description), $evidence, true);
        foreach ($evidence as $index => $surface) {
            $normalized = trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii(strip_tags($surface)))) ?? '');
            if ($normalized === '') {
                continue;
            }
            $terms[] = $normalized;
            $singularSurface = Str::singular($normalized);
            if ($singularSurface !== $normalized) {
                $terms[] = $singularSurface;
            }
            $stopWords = (array) ($this->semantics['stop_words'] ?? []);
            $tokens = array_values(array_filter(
                preg_split('/[^a-z0-9]+/', $normalized) ?: [],
                static fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, $stopWords, true),
            ));
            // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-108. Single words split
            // from a multi-word name or a shared homepage section ("content",
            // "network", "hosting", "guides") matched unrelated stories. Only a
            // one-word surface or the category description contributes single
            // words; a 3+ word name also contributes its initials (CDN).
            if (count($tokens) !== 1 && $index !== $descriptionIndex) {
                // Initials only when every word counts ("Law and Legal
                // Services" would give a misleading LLS).
                if ($index === 0 && count($tokens) >= 3 && count($tokens) === count(explode(' ', $normalized))) {
                    $terms[] = implode('', array_map(static fn (string $token): string => $token[0], $tokens));
                }
                continue;
            }
            foreach ($tokens as $token) {
                $terms[] = $token;
                $singularToken = Str::singular($token);
                if ($singularToken !== $token) {
                    $terms[] = $singularToken;
                }
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, 24);
    }

    public function matches(array $source, array $terms): bool
    {
        $body = trim((string) ($source['text'] ?? $source['content'] ?? ''));
        if ($body !== '') {
            $body = Str::lower(Str::ascii(strip_tags($body)));
            $title = Str::lower(Str::ascii((string) ($source['title'] ?? '')));
            $mentions = 0;
            $titleMatch = false;
            foreach (array_unique($terms) as $term) {
                $term = Str::lower(Str::ascii((string) $term));
                if ($term === '') {
                    continue;
                }
                $pattern = '/(?<![a-z0-9])'.preg_quote($term, '/').'(?:s|es)?(?![a-z0-9])/i';
                $mentions += preg_match_all($pattern, $body);
                $titleMatch = $titleMatch || preg_match($pattern, $title) === 1;
            }
            // A passing reference in an unrelated article is not its subject.
            // Discovery metadata remains broad; full text must substantiate it.
            // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-094. A category named in
            // both the headline and complete body has two independent surfaces
            // of evidence; requiring two additional body mentions rejected the
            // same category that the complete-source resolver preserved.
            return $mentions >= ($titleMatch ? 1 : 3);
        }
        $text = Str::lower(Str::ascii(strip_tags(implode(' ', array_map(
            static fn (string $key): string => (string) ($source[$key] ?? ''),
            ['title', 'description', 'snippet', 'text', 'content']
        )))));
        foreach ($terms as $term) {
            $term = Str::lower(Str::ascii((string) $term));
            if ($term !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?:s|es)?(?![a-z0-9])/i', $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a clearly dominant specific category from complete extracted
     * source text. Search snippets can contain one incidental lane phrase, so
     * reassignment requires several distinct concepts and a decisive lead.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{selected_category:string,resolved_category:string,selected_category_supported:bool,reclassified:bool,reason:string,scores:array<int,array<string,mixed>>}
     */
    public function resolveDominantCategory(array $sources, array $categories, string $selectedCategory): array
    {
        $selectedCategory = trim($selectedCategory);
        $scores = [];

        foreach ($categories as $category) {
            $name = trim((string) ($category['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $terms = array_values(array_unique(array_filter(array_map(
                static fn (mixed $term): string => trim((string) $term),
                array_merge((array) ($category['terms'] ?? []), $this->terms($name)),
            ))));
            $sourceFormat = $this->sourceFormat($name);
            $contextTerms = $sourceFormat === null
                ? []
                : $this->sourceFormatContextTerms($category, $categories);
            $formatSupported = $sourceFormat !== null
                && $contextTerms !== []
                && collect($sources)->contains(
                    fn (array $source): bool => $this->matchesSourceFormat($source, $terms)
                        && $this->matches($source, $contextTerms),
                );
            $evidence = $this->categoryEvidence($sources, $terms);
            $scores[] = $evidence + [
                'category' => $name,
                'generic' => $this->generic($name),
                'source_format' => $sourceFormat,
                'source_format_supported' => $formatSupported,
            ];
        }

        $selected = collect($scores)->first(
            static fn (array $score): bool => strcasecmp((string) $score['category'], $selectedCategory) === 0,
        );
        $selected ??= [
            'category' => $selectedCategory,
            'score' => 0,
            'distinct_terms' => 0,
            'title_hits' => 0,
            'body_hits' => 0,
            'generic' => false,
            'source_format' => null,
            'source_format_supported' => false,
        ];

        if ($selectedCategory === '' || ($selected['generic'] ?? false)) {
            return [
                'selected_category' => $selectedCategory,
                'resolved_category' => $selectedCategory,
                'selected_category_supported' => false,
                'reclassified' => false,
                'reason' => $selectedCategory === '' ? 'no_selected_category' : 'generic_category_preserved',
                'scores' => $scores,
            ];
        }

        if (($selected['source_format'] ?? null) !== null
            && (bool) ($selected['source_format_supported'] ?? false)) {
            return [
                'selected_category' => $selectedCategory,
                'resolved_category' => $selectedCategory,
                'selected_category_supported' => true,
                'reclassified' => false,
                'reason' => 'source_format_and_publication_subject_supported',
                'scores' => $scores,
            ];
        }

        $ranked = collect($scores)
            ->reject(static fn (array $score): bool => (bool) ($score['generic'] ?? false))
            ->reject(static fn (array $score): bool => ($score['source_format'] ?? null) !== null
                && ! (bool) ($score['source_format_supported'] ?? false))
            ->sortByDesc(static fn (array $score): array => [
                (int) ($score['score'] ?? 0),
                (int) ($score['distinct_terms'] ?? 0),
                (int) ($score['title_hits'] ?? 0),
            ])
            ->values();
        $winner = $ranked->first();
        $winnerName = trim((string) ($winner['category'] ?? ''));
        $selectedScore = (int) ($selected['score'] ?? 0);
        $winnerScore = (int) ($winner['score'] ?? 0);
        $winnerDistinct = (int) ($winner['distinct_terms'] ?? 0);
        // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-032. Saved-article audits no
        // longer retain the pre-extraction lane. Expose whether the stored
        // specific category is itself the strongly supported winner so every
        // adapter can validate it with the same complete-source classifier.
        $selectedSupported = $winnerName !== ''
            && strcasecmp($winnerName, $selectedCategory) === 0
            && $winnerDistinct >= 3
            && $winnerScore >= 12;
        $decisive = $winnerName !== ''
            && strcasecmp($winnerName, $selectedCategory) !== 0
            && $winnerDistinct >= 3
            && $winnerScore >= max(12, $selectedScore + 5)
            && ($selectedScore === 0 || $winnerScore >= (int) ceil($selectedScore * 1.35));

        return [
            'selected_category' => $selectedCategory,
            'resolved_category' => $decisive ? $winnerName : $selectedCategory,
            'selected_category_supported' => $selectedSupported,
            'reclassified' => $decisive,
            'reason' => $decisive ? 'dominant_complete_source_category' : 'selected_category_not_decisively_displaced',
            'scores' => $scores,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @param  array<int, string>  $terms
     * @return array{score:int,distinct_terms:int,title_hits:int,body_hits:int,matched_terms:array<int,string>}
     */
    private function categoryEvidence(array $sources, array $terms): array
    {
        $titles = Str::lower(Str::ascii(strip_tags(implode(' ', array_map(
            static fn (array $source): string => implode(' ', [
                (string) ($source['title'] ?? ''),
                (string) ($source['description'] ?? $source['snippet'] ?? ''),
            ]),
            $sources,
        )))));
        $bodies = Str::lower(Str::ascii(strip_tags(implode(' ', array_map(
            static fn (array $source): string => (string) ($source['text'] ?? $source['content'] ?? ''),
            $sources,
        )))));
        $titleHits = 0;
        $bodyHits = 0;
        $matched = [];

        foreach ($terms as $term) {
            $term = Str::lower(Str::ascii(trim($term)));
            if ($term === '') {
                continue;
            }
            $pattern = '/(?<![a-z0-9])'.preg_quote($term, '/').'(?:s|es)?(?![a-z0-9])/i';
            $termTitleHits = preg_match_all($pattern, $titles);
            $termBodyHits = preg_match_all($pattern, $bodies);
            if ($termTitleHits + $termBodyHits === 0) {
                continue;
            }
            $matched[] = $term;
            $titleHits += $termTitleHits;
            $bodyHits += $termBodyHits;
        }

        $distinctTerms = count(array_unique($matched));

        return [
            'score' => ($titleHits * 8) + min($bodyHits, 12) + (max(0, $distinctTerms - 1) * 4),
            'distinct_terms' => $distinctTerms,
            'title_hits' => $titleHits,
            'body_hits' => $bodyHits,
            'matched_terms' => array_values(array_unique($matched)),
        ];
    }

    public function generic(string $name): bool
    {
        return in_array($this->categoryKey($name), (array) ($this->semantics['generic_sections'] ?? []), true);
    }

    public function sourceFormat(string $name): ?string
    {
        $formats = (array) ($this->semantics['source_format_sections'] ?? []);
        $key = $this->categoryKey($name);

        foreach (array_values(array_unique([$key, Str::singular($key)])) as $candidate) {
            $format = trim((string) ($formats[$candidate] ?? ''));
            if ($format !== '') {
                return $format;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public function sourceFormatTerms(string $format): array
    {
        return $this->normalizedTerms((array) data_get(
            $this->semantics,
            'source_format_terms.'.trim($format),
            [],
        ));
    }

    /**
     * Keep source-format evidence separate from the publication subject. A
     * press release is eligible only when both surfaces are present.
     *
     * @param array<string, mixed> $lane
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, string>
     */
    public function sourceFormatContextTerms(array $lane, array $categories): array
    {
        $stored = $this->normalizedTerms((array) ($lane['context_terms'] ?? []));
        if ($stored !== []) {
            return $stored;
        }

        $context = [];
        foreach ($categories as $candidate) {
            $name = trim((string) ($candidate['name'] ?? ''));
            if ($name === '' || $this->generic($name) || $this->sourceFormat($name) !== null) {
                continue;
            }
            $context = array_merge(
                $context,
                (array) ($candidate['terms'] ?? []),
                $this->terms($name),
            );
        }

        return array_slice($this->normalizedTerms($context), 0, 24);
    }

    /** @param array<int, string> $formatTerms */
    public function matchesSourceFormat(array $source, array $formatTerms): bool
    {
        $metadata = Str::lower(Str::ascii(strip_tags(implode(' ', [
            (string) ($source['title'] ?? ''),
            (string) ($source['description'] ?? $source['excerpt'] ?? $source['snippet'] ?? ''),
            str_replace(['-', '_', '/'], ' ', (string) ($source['url'] ?? '')),
        ]))));

        foreach ($this->normalizedTerms($formatTerms) as $term) {
            $pattern = '/(?<![a-z0-9])'.preg_quote(Str::lower(Str::ascii($term)), '/').'(?:s|es)?(?![a-z0-9])/i';
            if (preg_match($pattern, $metadata) === 1) {
                return true;
            }
        }

        return $this->matches($source, $formatTerms);
    }

    public function contentMode(string $name): string
    {
        return in_array($this->categoryKey($name), (array) ($this->semantics['evergreen_sections'] ?? []), true)
            ? 'evergreen'
            : 'news';
    }

    /** @return array<string, array<int, string>> */
    private function vocabulary(): array
    {
        return (array) ($this->semantics['vocabulary'] ?? []);
    }

    private function categoryKey(string $name): string
    {
        $key = Str::lower(trim($name));
        $key = preg_replace('/\s*&\s*/u', ' and ', $key) ?? $key;

        return trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $key) ?? $key);
    }

    /**
     * @param array<int, mixed> $terms
     * @return array<int, string>
     */
    private function normalizedTerms(array $terms): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $term): string => trim((string) $term),
            $terms,
        ))));
    }

    /** @param array<string, mixed> $profile */
    private function profileMatches(array $profile, string $text): bool
    {
        if (isset($profile['exclude']) && preg_match((string) $profile['exclude'], $text) === 1) {
            return false;
        }
        if (isset($profile['match']) && preg_match((string) $profile['match'], $text) !== 1) {
            return false;
        }
        if (isset($profile['require']) && preg_match((string) $profile['require'], $text) !== 1) {
            return false;
        }
        if (isset($profile['signals'])) {
            $matched = 0;
            foreach ((array) $profile['signals'] as $signal) {
                if (preg_match('/(?<![a-z0-9])'.preg_quote((string) $signal, '/').'(?![a-z0-9])/i', $text) === 1) {
                    $matched++;
                }
            }
            if ($matched < (int) ($profile['minimum_signals'] ?? 1)) {
                return false;
            }
        }

        return isset($profile['match']) || isset($profile['signals']);
    }
}
