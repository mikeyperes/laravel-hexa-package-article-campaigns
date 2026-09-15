<?php

namespace hexa_package_article_campaigns\Policies;

use hexa_package_article_campaigns\Data\ConcurrencyLimits;

final class CampaignConcurrencyPolicy
{
    public function limits(int $global = 4, int $publication = 2): ConcurrencyLimits
    {
        return new ConcurrencyLimits(
            global: max(1, $global),
            publication: max(1, $publication),
        );
    }
}
