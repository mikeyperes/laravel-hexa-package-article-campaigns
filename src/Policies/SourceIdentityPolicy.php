<?php

namespace hexa_package_article_campaigns\Policies;

use Illuminate\Support\Str;

final class SourceIdentityPolicy
{
    /**
     * @param array<int, string> $urls
     * @param array<string, bool> $usedSourceKeys
     * @return array<int, string>
     */
    public function filterUnused(array $urls, array $usedSourceKeys, int $limit = 3, ?callable $exclude = null): array
    {
        $selected = [];
        $seen = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '' || ($exclude !== null && $exclude($url))) {
                continue;
            }

            $key = $this->urlKey($url);
            if ($key === '' || isset($seen[$key]) || isset($usedSourceKeys[$key])) {
                continue;
            }

            $selected[] = $url;
            $seen[$key] = true;
            if (count($selected) >= max(1, $limit)) {
                break;
            }
        }

        return $selected;
    }

    /** @param array<int, array<string, mixed>|string> $sources */
    public function fingerprint(array $sources): ?string
    {
        $keys = [];
        foreach ($sources as $source) {
            $key = $this->urlKey(is_array($source) ? ($source['url'] ?? '') : (string) $source);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys === [] ? null : sha1(implode('|', $keys));
    }

    public function urlKey(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return sha1(strtolower(preg_replace('/[#?].*$/', '', $url) ?: $url));
        }

        $host = strtolower((string) $parts['host']);
        $host = preg_replace('/^www\\./', '', $host) ?: $host;
        $path = strtolower(rtrim((string) ($parts['path'] ?? ''), '/'));

        return $host.$path;
    }

    /**
     * @param array<int, array{id?:mixed,article_id?:mixed,title?:mixed}> $existing
     * @return array{id:int|null,article_id:string|null,title:string,similarity:float}|null
     */
    public function findDuplicateTitle(array $existing, string $title, float $threshold = 0.74): ?array
    {
        $titleKey = $this->titleKey($title);
        if ($titleKey === '') {
            return null;
        }

        $best = null;
        foreach ($existing as $record) {
            $existingTitle = trim((string) ($record['title'] ?? ''));
            if ($existingTitle === '' || str_starts_with(strtolower($existingTitle), 'campaign run starting')) {
                continue;
            }

            $similarity = $this->titleSimilarity($titleKey, $this->titleKey($existingTitle));
            if ($similarity < $threshold || ($best !== null && $similarity <= $best['similarity'])) {
                continue;
            }

            $best = [
                'id' => isset($record['id']) ? (int) $record['id'] : null,
                'article_id' => isset($record['article_id']) ? (string) $record['article_id'] : null,
                'title' => $existingTitle,
                'similarity' => round($similarity, 3),
            ];
        }

        return $best;
    }

    private function titleKey(string $title): string
    {
        $normalized = strtolower(Str::ascii(strip_tags($title)));
        $normalized = preg_replace('/\\b(the|a|an|and|or|but|with|without|into|as|to|for|of|in|on|at|by|from|why|how|new|latest|breaking|report|analysis|today|now)\\b/i', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?: $normalized;
        $tokens = array_values(array_filter(preg_split('/\\s+/', trim($normalized)) ?: [], static fn ($token): bool => strlen($token) > 2));
        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    private function titleSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }

        similar_text($left, $right, $percent);
        $leftTokens = array_values(array_filter(explode(' ', $left)));
        $rightTokens = array_values(array_filter(explode(' ', $right)));
        $union = array_values(array_unique(array_merge($leftTokens, $rightTokens)));
        $intersection = array_values(array_intersect($leftTokens, $rightTokens));
        $jaccard = count($union) > 0 ? count($intersection) / count($union) : 0.0;
        $shorter = min(count($leftTokens), count($rightTokens));
        $coverage = $shorter > 0 ? count($intersection) / $shorter : 0.0;
        $topicOverlap = count($intersection) >= 4 ? $coverage : 0.0;

        return max(((float) $percent) / 100.0, $jaccard, $topicOverlap);
    }
}
