<?php

namespace hexa_package_article_campaigns\Policies;

use Illuminate\Support\Str;
use hexa_package_article_campaigns\Discovery\HomepageCategoryPoolDefinition;
use hexa_package_article_campaigns\Discovery\HomepageCategorySearchPolicy;

class CampaignSourceRelevancePolicy
{
    private HomepageCategorySearchPolicy $homepageCategorySearchPolicy;

    public function __construct(
        private CampaignNegativeTopicMatcher $negativeTopicMatcher,
        ?HomepageCategorySearchPolicy $homepageCategorySearchPolicy = null,
    ) {
        $this->homepageCategorySearchPolicy = $homepageCategorySearchPolicy ?? new HomepageCategorySearchPolicy();
    }

    public function filterRelevant(array $sourceTexts, array $resolved, callable $emit): array
    {
        $requiresCelebrityBusiness = $this->requiresCelebrityBusinessSources($resolved);
        $searchTerms = $this->resolvedIntentTerms($resolved);
        $hasCampaignIntent = $this->campaignIntentMatch('', '', $searchTerms)['configured'];

        if (! $requiresCelebrityBusiness && ! $hasCampaignIntent && ($resolved['discovery_process'] ?? '') !== HomepageCategoryPoolDefinition::TYPE) {
            return $sourceTexts;
        }

        $kept = [];
        foreach ($sourceTexts as $source) {
            $intent = $this->campaignIntentMatch(
                implode(' ', [(string) ($source['title'] ?? ''), (string) ($source['url'] ?? '')]),
                Str::limit(strip_tags((string) ($source['text'] ?? '')), 5000, ''),
                $searchTerms
            );
            if ($this->sourceMatchesResolvedIntent((array) $source, $resolved)) {
                $kept[] = $source;

                continue;
            }

            $emit('warning', 'Dropped off-topic campaign source: '.Str::limit((string) ($source['title'] ?? $source['url'] ?? 'Untitled'), 90), [
                'stage' => 'extraction',
                'substage' => 'relevance_dropped',
                'title' => $source['title'] ?? null,
                'url' => $source['url'] ?? null,
                'details' => implode(' | ', array_filter([
                    $requiresCelebrityBusiness ? 'celebrity_business_intent=missing' : null,
                    'campaign_term='.(string) ($intent['term'] ?? ''),
                    'primary_matches='.(string) ($intent['primary_matches'] ?? 0),
                    'secondary_matches='.(string) ($intent['secondary_matches'] ?? 0),
                    'required='.(string) ($intent['required'] ?? 0),
                ])),
            ]);
        }

        return $kept;
    }

    /**
     * Apply the same resolved campaign intent to discovery candidates and
     * fully extracted sources so an off-topic result cannot advance merely
     * because it matched a broad WordPress category label.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $resolved
     */
    public function sourceMatchesResolvedIntent(array $source, array $resolved): bool
    {
        if (($resolved['discovery_process'] ?? '') === HomepageCategoryPoolDefinition::TYPE) {
            $category = trim((string) ($resolved['forced_category'] ?? ''));
            $terms = [];
            foreach ((array) data_get($resolved, 'homepage_pool.categories', []) as $lane) {
                if ($category === '' || strcasecmp($category, (string) $lane['name']) === 0) {
                    $terms = array_merge($terms, (array) $lane['terms']);
                }
            }
            $searchPolicy = $this->homepageCategorySearchPolicy;
            return $searchPolicy->matches($source, $terms)
                && $searchPolicy->matchesPublicationFocus($source, (array) ($resolved['homepage_pool'] ?? []));
        }
        if ($this->requiresCelebrityBusinessSources($resolved)) {
            return $this->sourceMatchesCelebrityBusinessIntent($source);
        }

        $intent = $this->campaignIntentMatch(
            implode(' ', [(string) ($source['title'] ?? ''), (string) ($source['url'] ?? '')]),
            Str::limit(strip_tags(implode(' ', [
                (string) ($source['text'] ?? ''),
                (string) ($source['description'] ?? ''),
                (string) ($source['content'] ?? ''),
                (string) ($source['snippet'] ?? ''),
            ])), 5000, ''),
            $this->resolvedIntentTerms($resolved)
        );

        return ! $intent['configured'] || $intent['matched'];
    }

    /**
     * Keep discovery queries aligned with mandatory source validation rules.
     *
     * Category rotation may select a narrower term that omits a campaign-wide
     * concept. When that concept is required by the relevance gate, restore it
     * before any free or paid provider sees the query so valid results remain
     * discoverable instead of being searched broadly and rejected afterward.
     *
     * @param  array<string, mixed>  $resolved
     */
    public function alignDiscoveryQueryWithResolvedIntent(string $query, array $resolved): string
    {
        $query = trim($query);
        if (($resolved['discovery_process'] ?? '') === HomepageCategoryPoolDefinition::TYPE) {
            return $query;
        }
        if (! $this->requiresCelebrityBusinessSources($resolved)) {
            return $query;
        }

        $querySurface = Str::lower($query);
        $hasCelebritySignal = $this->containsCelebritySignal($querySurface);
        $hasBusinessEventSignal = $this->containsAnyText($querySurface, [
            'deal', 'investment', 'brand', 'launch', 'acquisition',
            'real estate', 'property', 'wealth', 'finance', 'financial', 'earnings',
        ]);
        if ($hasCelebritySignal && $hasBusinessEventSignal) {
            return $query;
        }

        // CRITICAL — see laravel-hexa-app-publish BUGLOG.md CAMPAIGN-BUG-011. Keyword
        // providers AND every word: a sentence-style query ("recent celebrity business
        // deals ... reported in the last 14 days excluding evergreen guides") returned
        // nothing from Google News RSS and HTTP 422 from NewsData. Keep the alignment as a
        // compact OR group and drop filler words; result filters handle listicles.
        $core = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\b(?:news|today|latest|breaking)\b/i', ' ', $query)));
        $prefix = ($hasCelebritySignal ? '' : 'celebrity ')
            .($hasBusinessEventSignal ? '' : '(deal OR investment OR brand OR launch OR "real estate")');

        return trim($prefix.' '.$core);
    }

    /**
     * Require every source to pass the same resolved relevance policy used during extraction.
     *
     * @param  array<int, array<string, mixed>>  $sourceTexts
     * @param  array<string, mixed>  $resolved
     */
    public function allSourcesMatchResolvedIntent(array $sourceTexts, array $resolved): bool
    {
        if ($sourceTexts === []) {
            return false;
        }

        return count($this->filterRelevant(
            $sourceTexts,
            $resolved,
            static function (string $type, string $message, array $context): void {},
        )) === count($sourceTexts);
    }

    /**
     * @param  array<int, string>  $searchTerms
     * @return array{configured: bool, matched: bool, term: ?string, primary_matches: int, secondary_matches: int, required: int}
     */
    /**
     * Terms that describe what this campaign is about.
     *
     * The rotation can hand back an empty search-term list, and an empty list
     * switches topical filtering off entirely. A long-running campaign then
     * accepts whatever its providers return, which is how a Philippines
     * franchise expo reached a high-net-worth title. Fall back to the
     * campaign topic so the filter fails closed instead.
     *
     * @param  array<string, mixed>  $resolved
     * @return array<int, string>
     */
    private function resolvedIntentTerms(array $resolved): array
    {
        $portfolioTerms = array_values(array_filter(array_map(
            static fn ($term): string => trim((string) $term),
            (array) ($resolved['portfolio_search_terms'] ?? [])
        ), static fn (string $term): bool => $term !== ''));
        if ($this->isBroadNewsPortfolio($portfolioTerms)) {
            return $portfolioTerms;
        }

        $terms = array_values(array_filter(array_map(
            static fn ($term): string => trim((string) $term),
            (array) ($resolved['search_terms'] ?? [])
        ), static fn (string $term): bool => $term !== ''));

        if ($terms !== []) {
            return $terms;
        }

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            preg_split('/[,;]+/', (string) ($resolved['topic'] ?? '')) ?: []
        ), static fn (string $part): bool => $part !== ''));
    }

    public function campaignIntentMatch(string $primary, string $secondary, array $searchTerms): array
    {
        if ($this->isBroadNewsPortfolio($searchTerms)) {
            return [
                'configured' => true,
                'matched' => true,
                'term' => 'broad multi-category news portfolio',
                'primary_matches' => 0,
                'secondary_matches' => 0,
                'required' => 0,
            ];
        }

        $groups = $this->intentConceptGroups($searchTerms);
        if ($groups === []) {
            return [
                'configured' => false,
                'matched' => true,
                'term' => null,
                'primary_matches' => 0,
                'secondary_matches' => 0,
                'required' => 0,
            ];
        }

        $primaryTokens = $this->surfaceTokenSet($primary);
        $secondaryTokens = $this->surfaceTokenSet($secondary);
        $combinedTokens = $primaryTokens + $secondaryTokens;
        $best = [
            'configured' => true,
            'matched' => false,
            'term' => null,
            'primary_matches' => 0,
            'secondary_matches' => 0,
            'required' => 0,
        ];

        foreach ($groups as $group) {
            $conceptCount = count($group['concepts']);
            $required = $conceptCount <= 1 ? 1 : 2;
            $primaryMatches = $this->matchedConceptCount($group['concepts'], $primaryTokens);
            $secondaryMatches = $this->matchedConceptCount($group['concepts'], $secondaryTokens);
            $combinedMatches = $this->matchedConceptCount($group['concepts'], $combinedTokens);
            $requiredConcepts = array_values(array_filter($group["concepts"], static fn (array $aliases): bool => in_array("woman", $aliases, true) || in_array("female", $aliases, true)));
            $requiredIntentMatched = $requiredConcepts === []
                || $this->matchedConceptCount($requiredConcepts, $primaryTokens) === count($requiredConcepts);
            $matched = $requiredIntentMatched && ($primaryMatches >= $required
                || ($primaryMatches >= 1 && $combinedMatches >= $required)
                || $combinedMatches >= min(3, $conceptCount));
            $candidate = [
                'configured' => true,
                'matched' => $matched,
                'term' => $group['term'],
                'primary_matches' => $primaryMatches,
                'secondary_matches' => $secondaryMatches,
                'required' => $required,
            ];
            if ($matched) {
                return $candidate;
            }
            if ($best['term'] === null || ($primaryMatches + $secondaryMatches) > ($best['primary_matches'] + $best['secondary_matches'])) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceTexts
     * @param  array<int, string>  $negativeTopics
     * @return array<int, array<string, mixed>>
     */
    /**
     *  array<string, mixed> $resolved
     *  array<int, string>
     */
    public function negativeTopics(array $resolved): array
    {
        $baseline = [
            'gambling',
            'betting',
            'best bets',
            'sportsbook',
            'casino',
            'wagering',
            'odds',
            'daily fantasy',
            'dfs',
            'sports picks',
            'betting guide',
            'adult content',
            'sex-related content',
            'pornography',
            'escort',
        ];

        return $this->negativeTopicMatcher->normalizeTopics(array_merge($baseline, (array) ($resolved['negative_topics'] ?? [])));
    }

    public function filterNegativeTopics(array $sourceTexts, array $negativeTopics, callable $emit): array
    {
        $negativeTopics = $this->negativeTopicMatcher->normalizeTopics($negativeTopics);
        if ($negativeTopics === []) {
            return $sourceTexts;
        }

        $kept = [];
        foreach ($sourceTexts as $source) {
            $match = $this->negativeTopicMatcher->matchTopicSurface(implode("\n", [
                (string) ($source['title'] ?? ''),
                (string) ($source['url'] ?? ''),
            ]), Str::limit((string) ($source['text'] ?? ''), 8000, ''), $negativeTopics, [
                'title' => (string) ($source['title'] ?? ''),
                'url' => (string) ($source['url'] ?? ''),
            ]);

            if ($match === null) {
                $kept[] = $source;

                continue;
            }

            $emit('warning', 'Dropped source matching negative topic: '.Str::limit((string) ($source['title'] ?? $source['url'] ?? 'Untitled'), 90), [
                'stage' => 'extraction',
                'substage' => 'negative_topic_dropped',
                'title' => $source['title'] ?? null,
                'url' => $source['url'] ?? null,
                'details' => implode(' | ', array_filter([
                    'topic='.(string) ($match['topic'] ?? ''),
                    'reason='.(string) ($match['reason'] ?? ''),
                    'coverage='.(string) ($match['coverage'] ?? ''),
                ])),
            ]);
        }

        return $kept;
    }

    private function requiresCelebrityBusinessSources(array $resolved): bool
    {
        $terms = Str::lower(implode(' ', array_map('strval', array_merge(
            (array) ($resolved['search_terms'] ?? []),
            (array) ($resolved['portfolio_search_terms'] ?? [])
        ))));
        $terms .= ' '.Str::lower(implode(' ', [
            (string) ($resolved['campaign_instructions'] ?? ''),
            (string) ($resolved['ai_instructions'] ?? ''),
        ]));

        return $this->containsAnyText($terms, [
            'celebrity business',
            'celebrity wealth',
            'celebrity finance',
            'celebrity investor',
            'celebrity investment',
            'celebrity real estate',
            'celebrity brand',
            'entertainment business deal',
            'luxury economy celebrity',
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceMatchesCelebrityBusinessIntent(array $source): bool
    {
        $haystack = Str::lower(strip_tags(implode(' ', [
            (string) ($source['title'] ?? ''),
            (string) ($source['text'] ?? ''),
            (string) ($source['description'] ?? ''),
            (string) ($source['content'] ?? ''),
            (string) ($source['snippet'] ?? ''),
            (string) ($source['url'] ?? ''),
        ])));

        $hasCelebritySignal = $this->containsCelebritySignal($haystack);
        $hasBusinessAction = $this->containsAnyText($haystack, [
            'acquire', 'acquisition', 'deal', 'merger', 'takeover', 'investment', 'investor',
            'venture capital', 'funding', 'valuation', 'stake', 'ownership', 'brand', 'company',
            'startup', 'real estate', 'property', 'sale', 'launch', 'contract', 'salary', 'revenue',
            'ipo', 'lawsuit', 'asset management', 'private equity', 'financial', 'finance', 'finances',
            'wealth', 'net worth', 'fortune', 'earnings', 'income', 'bankrupt', 'bankruptcy', 'debt',
            'mortgage', 'royalties', 'payday', 'paycheck',
        ]);
        $hasHardReject = $this->containsAnyText($haystack, ['tourism', 'tourist', 'travel visa', 'pre-pandemic', 'foreign tourists', 'ministry of tourism', 'ukraine war', 'kremlin', 'front lines', 'military spending', 'russian soldier', 'sanctions']);
        $hasDirectBusinessEvent = $this->containsAnyText($haystack, ['acquire', 'acquisition', 'deal', 'merger', 'takeover', 'investment', 'stake', 'ownership', 'real estate', 'property', 'contract', 'sale', 'funding', 'valuation']);

        if ($hasHardReject && ! $hasDirectBusinessEvent) {
            return false;
        }

        return $hasCelebritySignal && $hasBusinessAction;
    }

    private function containsCelebritySignal(string $haystack): bool
    {
        return $this->containsAnyText($haystack, [
            'celebrity', 'celebrities', 'actor', 'actress', 'singer', 'musician', 'rapper', 'athlete',
            'entertainer', 'hollywood', 'influencer', 'creator', 'youtube creator', 'youtuber',
            'youtube star', 'social media sensation', 'billionaire', 'media personality', 'producer',
            'artist', 'sports star', 'onlyfans',
        ]);
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function containsAnyText(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, array{term: string, concepts: array<int, array<int, string>>}>
     */
    private function intentConceptGroups(array $terms): array
    {
        $stop = array_flip([
            'a', 'an', 'and', 'article', 'articles', 'breaking', 'coverage', 'daily', 'for', 'from', 'in', 'latest',
            'news', 'of', 'on', 'report', 'reports', 'story', 'the', 'today', 'update', 'updates', 'with',
        ]);
        $groups = [];

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $concepts = [];
            $seen = [];
            foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($this->expandIntentAcronyms($term)))) ?: [] as $token) {
                if ($token === '' || isset($stop[$token]) || strlen($token) < 3) {
                    continue;
                }
                $aliases = $this->intentAliases($token);
                $key = implode('|', $aliases);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $concepts[] = $aliases;
            }
            if ($concepts !== []) {
                $groups[] = ['term' => $term, 'concepts' => $concepts];
            }
        }

        return $groups;
    }

    /**
     * @return array<string, true>
     */
    private function surfaceTokenSet(string $surface): array
    {
        $surface = $this->expandIntentAcronyms($surface);
        $tokens = [];
        foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii(strip_tags($surface)))) ?: [] as $token) {
            if ($token === '' || strlen($token) < 2) {
                continue;
            }
            $tokens[$token] = true;
            $tokens[$this->singularIntentToken($token)] = true;
        }

        // Asset names are not unconditionally financial: stock may be food or
        // inventory, and Bond may be a name. Require a financial cue on the
        // same surface before treating either as an investing concept.
        $financialCues = array_flip([
            'wealth', 'trading', 'share', 'investor', 'investment', 'investing',
            'broker', 'brokerage', 'equity', 'earnings', 'dividend', 'portfolio',
            'treasury', 'yield', 'debt',
        ]);
        if ((isset($tokens['stock']) || isset($tokens['bond']))
            && array_intersect_key($tokens, $financialCues) !== []) {
            $tokens['investment'] = true;
        }

        return $tokens;
    }

    private function expandIntentAcronyms(string $surface): string
    {
        // Expand the complete uppercase acronym, never letters inside a word
        // or individual concepts such as artificial flowers or intelligence.
        return preg_replace('/(?<![a-zA-Z0-9])A\.?I\.?(?![a-zA-Z0-9])/', 'artificial intelligence', $surface) ?? $surface;
    }

    /**
     * @param  array<int, array<int, string>>  $concepts
     * @param  array<string, true>  $surfaceTokens
     */
    private function matchedConceptCount(array $concepts, array $surfaceTokens): int
    {
        $matched = 0;
        foreach ($concepts as $aliases) {
            foreach ($aliases as $alias) {
                if (isset($surfaceTokens[$alias]) || isset($surfaceTokens[$this->singularIntentToken($alias)])) {
                    $matched++;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * @return array<int, string>
     */
    private function intentAliases(string $token): array
    {
        $token = $this->singularIntentToken($token);
        $aliases = match ($token) {
            'woman' => ['woman', 'women', 'female'],
            'female' => ['female', 'woman', 'women'],
            'founder', 'entrepreneur', 'entrepreneurship' => ['founder', 'cofounder', 'entrepreneur', 'entrepreneurship'],
            'funding' => ['funding', 'financing', 'capital', 'investment', 'investor', 'raise', 'raised', 'round'],
            'executive' => ['executive', 'ceo', 'chief', 'president', 'chair'],
            'leadership' => ['leadership', 'leader', 'ceo', 'executive'],
            'tech', 'technology' => ['tech', 'technology', 'software', 'digital', 'ai'],
            'startup' => ['startup', 'venture'],
            'company' => ['company', 'business', 'firm'],
            'finance' => ['finance', 'financial', 'funding', 'capital'],
            'invest', 'investing', 'investment', 'investor' => ['invest', 'investing', 'investment', 'investor', 'shareholder', 'dividend', 'etf'],
            'growth' => ['growth', 'grow', 'growing', 'expand', 'expanded', 'expansion'],
            'health', 'healthcare', 'medical' => ['health', 'healthcare', 'medical', 'clinical', 'patient', 'medtech'],
            'digital' => ['digital', 'software', 'platform', 'analytics', 'data', 'online'],
            'innovation' => ['innovation', 'innovative', 'technology', 'platform', 'research', 'development'],
            'policy', 'regulation', 'regulatory' => ['policy', 'regulation', 'regulatory', 'law', 'legal', 'rules', 'enforcement'],
            'legal', 'law' => ['legal', 'law', 'court', 'litigation', 'attorney', 'firm', 'justice'],
            'sport' => ['sport', 'athlete', 'athletic', 'team', 'game', 'league'],
            default => [$token],
        };

        return array_values(array_unique(array_map(fn (string $alias): string => $this->singularIntentToken($alias), $aliases)));
    }

    private function singularIntentToken(string $token): string
    {
        if ($token === 'women') {
            return 'woman';
        }
        if (str_ends_with($token, 'ies') && strlen($token) > 4) {
            return substr($token, 0, -3).'y';
        }
        if (str_ends_with($token, 'sses') && strlen($token) > 5) {
            return substr($token, 0, -2);
        }
        if (str_ends_with($token, 's') && ! str_ends_with($token, 'ss') && strlen($token) > 4) {
            return substr($token, 0, -1);
        }

        return $token;
    }

    /**
     * @param  array<int, string>  $searchTerms
     */
    private function isBroadNewsPortfolio(array $searchTerms): bool
    {
        if (count($searchTerms) < 8) {
            return false;
        }

        $surface = ' '.Str::lower(Str::ascii(implode(' ', array_map('strval', $searchTerms)))).' ';
        $broadCategories = [
            'viral', 'celebrity', 'sports', 'technology', 'business', 'entrepreneur', 'music', 'politics',
            'international', 'entertainment', 'culture',
        ];
        $matched = 0;
        foreach ($broadCategories as $category) {
            if (str_contains($surface, ' '.$category)) {
                $matched++;
            }
        }

        return $matched >= 6;
    }

    /**
     * @param  array<int, string>  $negativeTopics
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function matchNegativeTopic(string $surface, string $body, array $negativeTopics, array $context = []): ?array
    {
        return $this->negativeTopicMatcher->matchTopicSurface(
            $surface,
            $body,
            $this->negativeTopicMatcher->normalizeTopics($negativeTopics),
            $context
        );
    }
}
