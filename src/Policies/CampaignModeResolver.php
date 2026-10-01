<?php

namespace hexa_package_article_campaigns\Policies;

/**
 * Campaign delivery modes. Automatic campaigns publish; review campaigns
 * submit every article to WordPress as Pending Review and never publish it.
 * Any other stored value resolves to automatic publication.
 */
class CampaignModeResolver
{
    public const AUTOMATIC_DELIVERY_MODE = 'auto-publish';
    public const AUTOMATIC_POST_STATUS = 'publish';
    public const REVIEW_DELIVERY_MODE = 'pending-review';
    public const REVIEW_POST_STATUS = 'pending';

    public function automaticDeliveryMode(): string
    {
        return self::AUTOMATIC_DELIVERY_MODE;
    }

    /** @return array<int, string> */
    public function campaignDeliveryModes(): array
    {
        return [self::AUTOMATIC_DELIVERY_MODE, self::REVIEW_DELIVERY_MODE];
    }

    /** The campaign's own delivery mode: review when stored as review, otherwise automatic. */
    public function campaignDeliveryMode(?string $mode): string
    {
        return $this->normalizeDeliveryMode($mode) === self::REVIEW_DELIVERY_MODE
            ? self::REVIEW_DELIVERY_MODE
            : self::AUTOMATIC_DELIVERY_MODE;
    }

    /** The WordPress status a campaign article is delivered with. */
    public function postStatus(?string $mode): string
    {
        return $this->campaignDeliveryMode($mode) === self::REVIEW_DELIVERY_MODE
            ? self::REVIEW_POST_STATUS
            : self::AUTOMATIC_POST_STATUS;
    }

    /**
     * A failed pre-publish quality gate blocks every status that hands the
     * article to readers or to an editor for publication. Only a plain
     * WordPress draft may carry known issues for manual repair.
     */
    public function qualityGateBlocks(string $postStatus): bool
    {
        return in_array(strtolower(trim($postStatus)), ['publish', 'future', self::REVIEW_POST_STATUS], true);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function automaticAttributes(array $attributes = []): array
    {
        return $this->campaignAttributes($attributes, self::AUTOMATIC_DELIVERY_MODE);
    }

    /**
     * Campaign delivery columns for the given mode, kept consistent with each other.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function campaignAttributes(array $attributes, ?string $mode): array
    {
        $mode = $this->campaignDeliveryMode($mode);

        return array_replace($attributes, [
            'delivery_mode' => $mode,
            'auto_publish' => $mode === self::AUTOMATIC_DELIVERY_MODE,
            'post_status' => $this->postStatus($mode),
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
            // A review submission is a non-public WordPress write.
            'draft-wordpress', 'wp-draft', self::REVIEW_DELIVERY_MODE, self::REVIEW_POST_STATUS => 'wp-draft',
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
            self::REVIEW_DELIVERY_MODE, self::REVIEW_POST_STATUS => self::REVIEW_DELIVERY_MODE,
            default => self::AUTOMATIC_DELIVERY_MODE,
        };
    }
}
