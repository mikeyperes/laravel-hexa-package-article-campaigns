<?php

namespace hexa_package_article_campaigns\Policies;

/**
 * Rejects extracted pages that are not coherent article text before a paid
 * writer sees them. The policy is deterministic and publication-neutral.
 */
final class CampaignSourceContentPolicy
{
    /**
     * @param  array<string, mixed>  $source
     * @return array{accepted: bool, reason: ?string, details: array<string, int|float|string>}
     */
    public function inspect(array $source): array
    {
        $title = trim((string) ($source['title'] ?? ''));
        $url = trim((string) ($source['url'] ?? ''));
        $text = $this->plain((string) ($source['text'] ?? $source['content'] ?? ''));
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($tokens);

        $durationCount = preg_match_all('/(?<!\d)(?:\d{1,2}:)?\d{1,2}:\d{2}(?!\d)/u', $text);
        $datedCardCount = preg_match_all(
            '/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},\s+\d{4}\b/iu',
            $text,
        );
        $videoSurface = mb_strtolower($title.' '.$url);
        $videoCatalogue = $durationCount >= 6
            && ($durationCount / max(1, $wordCount)) >= 0.015
            && ($datedCardCount >= 4
                || str_contains($videoSurface, '/video')
                || preg_match('/\bvideos?\b/u', $videoSurface) === 1);

        $suspiciousCount = 0;
        foreach ($tokens as $token) {
            if (preg_match(
                '/[\x{FFFD}\x{0080}-\x{009F}]|(?:%[0-9a-f]{2}){2,}|\\\\u[0-9a-f]{4}|&#?x?[0-9a-f]{2,};|[a-z0-9+\/]{48,}={0,2}/iu',
                (string) $token,
            ) === 1) {
                $suspiciousCount++;
            }
        }
        $suspiciousRatio = $wordCount > 0 ? $suspiciousCount / $wordCount : 0.0;
        $corrupted = $suspiciousCount >= 12 && $suspiciousRatio >= 0.12;

        $headlineCoverage = $this->headlineCoverage($title, $tokens);
        // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-107. A video page can extract
        // as a feed of unrelated headlines with the clip's one-line summary.
        $offTopicVideoPage = preg_match('~/videos?/~i', (string) parse_url($url, PHP_URL_PATH)) === 1
            && $headlineCoverage !== null
            && $headlineCoverage < 0.34;

        $reason = match (true) {
            $videoCatalogue => 'video_catalogue_not_article',
            $offTopicVideoPage => 'video_page_without_article_text',
            $corrupted => 'encoded_or_corrupted_text',
            default => null,
        };

        return [
            'accepted' => $reason === null,
            'reason' => $reason,
            'details' => [
                'word_count' => $wordCount,
                'duration_markers' => $durationCount,
                'dated_cards' => $datedCardCount,
                'suspicious_tokens' => $suspiciousCount,
                'suspicious_ratio' => round($suspiciousRatio, 4),
                'headline_coverage' => $headlineCoverage === null ? 'n/a' : round($headlineCoverage, 2),
            ],
        ];
    }

    /**
     * Share of 100-word chunks that mention at least two headline words, or
     * null when the page is too short or the headline too generic to judge.
     *
     * @param  array<int, string>  $tokens
     */
    private function headlineCoverage(string $title, array $tokens): ?float
    {
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/u', mb_strtolower($title)) ?: [],
            static fn (string $term): bool => strlen($term) > 3,
        )));
        $chunks = array_chunk($tokens, 100);
        if (count($terms) < 3 || count($chunks) < 3) {
            return null;
        }

        $covered = 0;
        foreach ($chunks as $chunk) {
            $haystack = ' '.mb_strtolower(implode(' ', $chunk)).' ';
            $hits = 0;
            foreach ($terms as $term) {
                if (preg_match('/\b'.preg_quote($term, '/').'\b/u', $haystack) === 1 && ++$hits >= 2) {
                    $covered++;
                    break;
                }
            }
        }

        return $covered / count($chunks);
    }

    private function plain(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
