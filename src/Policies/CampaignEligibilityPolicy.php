<?php

namespace hexa_package_article_campaigns\Policies;

use Illuminate\Validation\ValidationException;

final class CampaignEligibilityPolicy
{
    /** @param array<int, string> $supportedArticleTypes */
    /** @param array<int, string> $supportedDeliveryModes */
    public function __construct(
        private array $supportedArticleTypes,
        private array $supportedDeliveryModes,
    ) {}

    /** @return array<int, string> */
    public function supportedArticleTypes(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $this->supportedArticleTypes))));
    }

    /** @return array<int, string> */
    public function supportedDeliveryModes(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $this->supportedDeliveryModes))));
    }

    public function assertArticleTypeAllowed(?string $articleType): void
    {
        if ($articleType !== null && $articleType !== '' && ! in_array($articleType, $this->supportedArticleTypes(), true)) {
            throw ValidationException::withMessages([
                'article_type' => "Campaigns do not support the article type '{$articleType}'.",
            ]);
        }
    }

    public function assertDeliveryModeAllowed(?string $deliveryMode): void
    {
        if ($deliveryMode !== null && $deliveryMode !== '' && ! in_array($deliveryMode, $this->supportedDeliveryModes(), true)) {
            throw ValidationException::withMessages([
                'delivery_mode' => "Campaigns do not support the delivery mode '{$deliveryMode}'.",
            ]);
        }
    }
}
