<?php

namespace hexa_package_article_campaigns\Policies;

class CampaignModeResolver
{
    public const AUTOMATIC_DELIVERY_MODE = 'auto-publish';
    public const AUTOMATIC_POST_STATUS = 'publish';

    public function automaticDeliveryMode(): string
    {
        return self::AUTOMATIC_DELIVERY_MODE;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function automaticAttributes(array $attributes = []): array
    {
        return array_replace($attributes, [
            'delivery_mode' => self::AUTOMATIC_DELIVERY_MODE,
            'auto_publish' => true,
            'post_status' => self::AUTOMATIC_POST_STATUS,
        ]);
    }

    /**
     * @param string|null $mode
     * @return string draft|wp-draft|publish
     */
    public function toExecutionMode(?string $mode): string
    {
        return match ($mode) {
            'auto-publish', 'publish' => 'publish',
            'draft-wordpress', 'wp-draft' => 'wp-draft',
            'draft-local', 'draft' => 'draft',
            'review', 'notify', null, '' => 'publish',
            default => 'publish',
        };
    }

    /**
     * @param string|null $mode
     * @return string
     */
    public function normalizeDeliveryMode(?string $mode): string
    {
        return match ($mode) {
            'publish', 'auto-publish' => 'auto-publish',
            'wp-draft', 'draft-wordpress' => 'draft-wordpress',
            'draft', 'draft-local' => 'draft-local',
            default => self::AUTOMATIC_DELIVERY_MODE,
        };
    }
}
