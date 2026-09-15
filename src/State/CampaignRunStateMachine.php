<?php

namespace hexa_package_article_campaigns\State;

use DomainException;

final class CampaignRunStateMachine
{
    public const CREATED = 'created';
    public const SOURCING = 'sourcing';
    public const GENERATING = 'generating';
    public const DELIVERING = 'delivering';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        self::CREATED => [self::SOURCING, self::FAILED],
        self::SOURCING => [self::GENERATING, self::FAILED],
        self::GENERATING => [self::DELIVERING, self::FAILED],
        self::DELIVERING => [self::COMPLETED, self::FAILED],
        self::COMPLETED => [],
        self::FAILED => [],
    ];

    public function transition(string $from, string $to): string
    {
        if (! isset(self::TRANSITIONS[$from]) || ! in_array($to, self::TRANSITIONS[$from], true)) {
            throw new DomainException("Campaign run state transition {$from} -> {$to} is not allowed.");
        }

        return $to;
    }
}
