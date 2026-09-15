<?php

namespace hexa_package_article_campaigns\State;

use DomainException;

final class CandidatePoolStateMachine
{
    public const AVAILABLE = 'available';
    public const SELECTED = 'selected';
    public const REJECTED = 'rejected';

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        self::AVAILABLE => [self::AVAILABLE, self::SELECTED, self::REJECTED],
        self::SELECTED => [],
        self::REJECTED => [],
    ];

    public function initialState(): string
    {
        return self::AVAILABLE;
    }

    public function transition(string $from, string $to): string
    {
        if (! isset(self::TRANSITIONS[$from]) || ! in_array($to, self::TRANSITIONS[$from], true)) {
            throw new DomainException("Candidate state transition {$from} -> {$to} is not allowed.");
        }

        return $to;
    }

    public function isSelectable(string $state): bool
    {
        return $state === self::AVAILABLE;
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @param array<int, array<string, mixed>> $rejections
     * @param array<string, mixed> $providerErrors
     * @return array<string, mixed>
     */
    public function outcome(array $articles, int $checked, array $rejections, array $providerErrors = []): array
    {
        $selected = count($articles);

        return [
            'articles' => $articles,
            'ai_calls' => 0,
            'provider_errors' => $providerErrors,
            'discovery_memory' => [
                'checked' => $checked,
                'kept' => $selected,
                'rejected' => count($rejections),
                'rejections' => $rejections,
            ],
            'outcome' => [
                'status' => $selected > 0 ? 'candidates_selected' : 'candidate_pool_exhausted',
                'retryable' => false,
                'selected' => $selected,
                'checked' => $checked,
                'message' => $selected > 0
                    ? 'Campaign pool supplied unused category sources.'
                    : 'Campaign pool has no eligible unused sources. No AI was called.',
            ],
        ];
    }
}
