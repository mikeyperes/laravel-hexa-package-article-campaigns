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

        $reason = match (true) {
            $videoCatalogue => 'video_catalogue_not_article',
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
            ],
        ];
    }

    private function plain(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
