<?php

namespace hexa_package_article_campaigns\Policies;

/** Keeps an explicitly named audience attached to stock-media search and selection. */
final class CampaignMediaAudiencePolicy
{
    /** @var array<string, array{required: string, conflicts: string}> */
    private const AUDIENCES = [
        'women' => [
            'required' => '\b(?:woman|women|female|businesswoman|businesswomen|mother|mothers|girl|girls)\b',
            'conflicts' => '\b(?:man|men|male|businessman|businessmen|father|fathers|boy|boys)\b',
        ],
        'men' => [
            'required' => '\b(?:man|men|male|businessman|businessmen|father|fathers|boy|boys)\b',
            'conflicts' => '\b(?:woman|women|female|businesswoman|businesswomen|mother|mothers|girl|girls)\b',
        ],
    ];

    /** Search words that ask for people; an object or place scene has no audience. */
    private const PEOPLE = '\\b(?:people|persons?|entrepreneurs?|founders?|leaders?|executives?|workers?|employees?|staff|teams?|owners?|professionals?|students?|audience|crowd|speakers?|panel|colleagues?|customers?|artists?|attendees?|members?)\\b';

    public function qualifySearchTerm(string $searchTerm, string $articleTitle): string
    {
        $searchTerm = trim($searchTerm);
        $audience = $this->requiredAudience($articleTitle);
        // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-140. Only a search for people
        // takes the article's audience; "stack of books" must stay books.
        if ($searchTerm === '' || $audience === null || ! $this->matches($searchTerm, self::PEOPLE)
            || $this->matches($searchTerm, self::AUDIENCES[$audience]['required'])) {
            return $searchTerm;
        }

        return trim($audience.' '.$searchTerm);
    }

    /** @param array<string, mixed> $candidate */
    public function rejectsCandidate(array $candidate, string $searchTerm, string $articleTitle): bool
    {
        $audience = $this->requiredAudience($articleTitle.' '.$searchTerm);
        if ($audience === null) {
            return false;
        }

        $surface = implode(' ', array_filter([
            $candidate['alt'] ?? null,
            $candidate['title'] ?? null,
            $candidate['description'] ?? null,
            $candidate['caption'] ?? null,
            is_array($candidate['tags'] ?? null) ? implode(' ', $candidate['tags']) : ($candidate['tags'] ?? null),
        ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''));
        if ($surface === '') {
            return false;
        }

        $required = self::AUDIENCES[$audience]['required'];
        $conflicts = self::AUDIENCES[$audience]['conflicts'];

        return ! $this->matches($surface, $required) && $this->matches($surface, $conflicts);
    }

    private function requiredAudience(string $surface): ?string
    {
        foreach (self::AUDIENCES as $audience => $patterns) {
            if ($this->matches($surface, $patterns['required'])) {
                return $audience;
            }
        }

        return null;
    }

    private function matches(string $surface, string $pattern): bool
    {
        return preg_match('/'.$pattern.'/iu', $surface) === 1;
    }
}
