<?php

namespace hexa_package_article_campaigns\Policies;

final class CampaignSourcePacketBudgetPolicy
{
    public const DEFAULT_MAX_PRIMARY_SOURCE_CHARACTERS = 50_000;

    /**
     * Keep complete primary sources in priority order without clipping any
     * source or exceeding the writer's source-packet budget.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array{
     *     selected: array<int, array<string, mixed>>,
     *     rejected: array<int, array{index:int,title:string,url:string,characters:int,reason:string}>,
     *     used_characters: int,
     *     max_characters: int
     * }
     */
    public function fit(array $sources, int $maxCharacters = self::DEFAULT_MAX_PRIMARY_SOURCE_CHARACTERS): array
    {
        $maxCharacters = max(1, $maxCharacters);
        $selected = [];
        $rejected = [];
        $usedCharacters = 0;

        foreach (array_values($sources) as $index => $source) {
            $text = trim((string) ($source['text'] ?? ''));
            $characters = mb_strlen($text);
            $reason = match (true) {
                $characters > $maxCharacters => 'source_exceeds_budget',
                $usedCharacters + $characters > $maxCharacters => 'packet_exceeds_budget',
                default => null,
            };

            if ($reason !== null) {
                $rejected[] = [
                    'index' => $index,
                    'title' => trim((string) ($source['title'] ?? '')),
                    'url' => trim((string) ($source['url'] ?? '')),
                    'characters' => $characters,
                    'reason' => $reason,
                ];
                continue;
            }

            $selected[] = $source;
            $usedCharacters += $characters;
        }

        return [
            'selected' => $selected,
            'rejected' => $rejected,
            'used_characters' => $usedCharacters,
            'max_characters' => $maxCharacters,
        ];
    }
}
