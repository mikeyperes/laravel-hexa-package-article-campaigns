<?php

namespace hexa_package_article_campaigns\Policies;

use Illuminate\Support\Str;

class CampaignNegativeTopicMatcher
{
    /**
     * @param array<int, string>|string|null $topics
     * @return array<int, string>
     */
    public function normalizeTopics(array|string|null $topics): array
    {
        if (is_string($topics)) {
            $topics = preg_split('/[\r\n]+/', $topics) ?: [];
        }

        return collect((array) $topics)
            ->flatMap(fn ($topic) => preg_split('/[\r\n]+/', (string) $topic) ?: [])
            ->map(fn ($topic) => trim((string) $topic))
            ->filter()
            ->unique(fn ($topic) => mb_strtolower($topic))
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $article
     * @param array<int, string>|string|null $topics
     * @return array<string, mixed>|null
     */
    public function matchArticle(array $article, array|string|null $topics): ?array
    {
        $primary = implode("\n", array_filter(array_map('strval', [
            $article['title'] ?? '',
            $article['name'] ?? '',
            $article['headline'] ?? '',
            $article['source'] ?? '',
            $article['publisher'] ?? '',
            $article['domain'] ?? '',
            $article['url'] ?? '',
        ])));
        $secondary = implode("\n", array_filter(array_map('strval', [
            $article['description'] ?? '',
            $article['snippet'] ?? '',
            $article['summary'] ?? '',
        ])));

        return $this->matchTopicSurface($primary, $secondary, $topics, [
            'title' => (string) ($article['title'] ?? $article['name'] ?? ''),
            'url' => (string) ($article['url'] ?? ''),
        ]);
    }

    /**
     * @param array<int, string>|string|null $topics
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    public function matchText(string $text, array|string|null $topics, array $meta = []): ?array
    {
        $normalizedTopics = $this->normalizeTopics($topics);
        if ($text === '' || $normalizedTopics === []) {
            return null;
        }

        $haystack = $this->normalizeText($text);
        foreach ($normalizedTopics as $topic) {
            $match = $this->topicMatch($haystack, $topic);
            if ($match === null) {
                continue;
            }

            return array_merge($meta, [
                'topic' => $topic,
                'reason' => $match['reason'],
                'matched_terms' => $match['matched_terms'],
                'coverage' => $match['coverage'],
            ]);
        }

        return null;
    }

    /**
     * Match topic exclusions against topic-signaling surfaces.
     *
     * Single-word negatives are intentionally limited to primary surfaces
     * like titles, categories, tags, domains, and URLs. This prevents broad
     * words such as "gambling" from blocking an otherwise valid biography
     * just because the body mentions that someone once gambled.
     *
     * Multi-word negatives are allowed to use secondary surfaces such as
     * summaries and descriptions because phrases carry more intent.
     *
     * @param array<int, string>|string|null $topics
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    public function matchTopicSurface(string $primaryText, string $secondaryText, array|string|null $topics, array $meta = []): ?array
    {
        $normalizedTopics = $this->normalizeTopics($topics);
        if ($normalizedTopics === []) {
            return null;
        }

        $primary = $this->normalizeText($primaryText);
        $secondary = $this->normalizeText($secondaryText);

        foreach ($normalizedTopics as $topic) {
            $needle = $this->normalizeText($topic);
            if ($needle === '') {
                continue;
            }

            $topicTokens = $this->topicTokens($needle);
            if ($topicTokens === []) {
                continue;
            }

            $haystack = count($topicTokens) === 1
                ? $primary
                : trim($primary . ' ' . $secondary);

            if ($haystack === '') {
                continue;
            }

            $match = $this->topicMatch($haystack, $topic);
            if ($match === null) {
                continue;
            }

            return array_merge($meta, [
                'topic' => $topic,
                'reason' => $match['reason'],
                'matched_terms' => $match['matched_terms'],
                'coverage' => $match['coverage'],
                'scope' => count($topicTokens) === 1 ? 'primary_topic_surface' : 'topic_surface',
            ]);
        }

        return null;
    }

    /**
     * @return array{reason: string, matched_terms: array<int, string>, coverage: float}|null
     */
    private function topicMatch(string $haystack, string $topic): ?array
    {
        $needle = $this->normalizeText($topic);
        if ($needle === '') {
            return null;
        }

        if (strlen($needle) >= 5 && str_contains($haystack, $needle)) {
            return [
                'reason' => 'exact_phrase',
                'matched_terms' => [$needle],
                'coverage' => 1.0,
            ];
        }

        $rawTopicTokens = array_values(array_filter(preg_split('/\s+/', $needle) ?: []));
        $topicTokens = $this->topicTokens($needle);
        if ($topicTokens === []) {
            return null;
        }

        $haystackTokens = array_flip($this->topicTokens($haystack));
        $matched = array_values(array_filter($topicTokens, fn ($token) => isset($haystackTokens[$token])));

        if (count($topicTokens) === 1) {
            // Do not collapse branded phrases such as "BC.GAME" into a broad
            // single token ("game") after short identifier terms are filtered.
            if (count($rawTopicTokens) > 1) {
                return null;
            }

            if (count($matched) === 1 && preg_match('/(^| )' . preg_quote($topicTokens[0], '/') . '( |$)/', $haystack) === 1) {
                return [
                    'reason' => 'whole_word',
                    'matched_terms' => $matched,
                    'coverage' => 1.0,
                ];
            }

            return null;
        }

        // Two-word exclusions carry intent only as a phrase. Treating their
        // words as an unordered bag causes unrelated long article bodies to
        // match terms such as "sports picks" merely because both words occur.
        if (count($topicTokens) === 2) {
            return null;
        }

        $coverage = count($matched) / max(1, count($topicTokens));
        $requiredCoverage = count($topicTokens) <= 3 ? 0.75 : 0.67;

        if (count($matched) >= 2 && $coverage >= $requiredCoverage) {
            return [
                'reason' => 'strong_topic_overlap',
                'matched_terms' => $matched,
                'coverage' => round($coverage, 3),
            ];
        }

        return null;
    }

    private function normalizeText(string $text): string
    {
        $text = Str::ascii(strip_tags($text));
        $text = strtolower($text);
        $text = preg_replace('/https?:\/\/|www\.|[?#][^\s]*/', ' ', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?: $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?: $text);
    }

    /**
     * @return array<int, string>
     */
    private function topicTokens(string $text): array
    {
        $stop = [
            'the' => true, 'and' => true, 'for' => true, 'with' => true, 'from' => true,
            'that' => true, 'this' => true, 'into' => true, 'amid' => true, 'over' => true,
            'news' => true, 'latest' => true, 'breaking' => true, 'today' => true,
            'article' => true, 'report' => true, 'analysis' => true,
        ];

        return array_values(array_unique(array_filter(
            preg_split('/\s+/', $text) ?: [],
            fn ($token) => strlen((string) $token) >= 3 && !isset($stop[(string) $token])
        )));
    }
}
