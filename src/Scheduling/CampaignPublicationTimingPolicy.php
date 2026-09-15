<?php

namespace hexa_package_article_campaigns\Scheduling;

use Carbon\Carbon;
use DateTimeInterface;

final class CampaignPublicationTimingPolicy
{
    /**
     * Resolve a public campaign write at the moment delivery occurs.
     * Missed or delayed slots publish immediately; only a genuinely future
     * timestamp is sent to WordPress as a scheduled post.
     *
     * @return array{post_status:string,date:?string,scheduled_for:?string}
     */
    public function resolve(string $requestedStatus, ?DateTimeInterface $scheduledFor = null): array
    {
        $requestedStatus = strtolower(trim($requestedStatus));
        $requestedStatus = $requestedStatus !== '' ? $requestedStatus : 'draft';
        $scheduled = $scheduledFor
            ? Carbon::parse($scheduledFor->format(DATE_ATOM))
            : null;

        if ($scheduled && in_array($requestedStatus, ['publish', 'future'], true)) {
            if ($scheduled->isFuture()) {
                return [
                    'post_status' => 'future',
                    'date' => $scheduled->format('Y-m-d H:i:s'),
                    'scheduled_for' => $scheduled->toIso8601String(),
                ];
            }

            return [
                'post_status' => 'publish',
                'date' => null,
                'scheduled_for' => $scheduled->toIso8601String(),
            ];
        }

        return [
            'post_status' => $requestedStatus,
            'date' => null,
            'scheduled_for' => $scheduled?->toIso8601String(),
        ];
    }
}
