<?php

namespace hexa_package_article_campaigns\Policies;

/**
 * Campaign delivery modes and the WordPress status each one delivers with:
 * automatic campaigns publish, review campaigns submit Pending Review, draft
 * campaigns save a WordPress draft. Review and draft campaigns never publish.
 * Any other stored value resolves to automatic publication.
 */
class CampaignModeResolver
{
    public const AUTOMATIC_DELIVERY_MODE = 'auto-publish';
    public const AUTOMATIC_POST_STATUS = 'publish';
    public const REVIEW_DELIVERY_MODE = 'pending-review';
    public const REVIEW_POST_STATUS = 'pending';
    public const DRAFT_DELIVERY_MODE = 'draft-wordpress';
    public const DRAFT_POST_STATUS = 'draft';

    /** Campaign delivery mode => the WordPress status its articles are delivered with. */
    public const POST_STATUS_BY_MODE = [
        self::AUTOMATIC_DELIVERY_MODE => self::AUTOMATIC_POST_STATUS,
        self::REVIEW_DELIVERY_MODE => self::REVIEW_POST_STATUS,
        self::DRAFT_DELIVERY_MODE => self::DRAFT_POST_STATUS,
    ];

    public function automaticDeliveryMode(): string
    {
        return self::AUTOMATIC_DELIVERY_MODE;
    }

    /** @return array<int, string> */
    public function campaignDeliveryModes(): array
    {
        return array_keys(self::POST_STATUS_BY_MODE);
    }

    /** The campaign's own delivery mode: a known campaign mode, otherwise automatic. */
    public function campaignDeliveryMode(?string $mode): string
    {
        $normalized = $this->normalizeDeliveryMode($mode);

        return isset(self::POST_STATUS_BY_MODE[$normalized]) ? $normalized : self::AUTOMATIC_DELIVERY_MODE;
    }

    /** The WordPress status a campaign article is delivered with. */
    public function postStatus(?string $mode): string
    {
        return self::POST_STATUS_BY_MODE[$this->campaignDeliveryMode($mode)];
    }

    /** The campaign delivery mode for a requested WordPress status (publish, pending, draft), or null. */
    public function deliveryModeForPostStatus(?string $postStatus): ?string
    {
        $mode = array_search(strtolower(trim((string) $postStatus)), self::POST_STATUS_BY_MODE, true);

        return $mode === false ? null : $mode;
    }

    /** Whether this campaign mode makes articles public on its own. */
    public function publishesAutomatically(?string $mode): bool
    {
        return $this->campaignDeliveryMode($mode) === self::AUTOMATIC_DELIVERY_MODE;
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
