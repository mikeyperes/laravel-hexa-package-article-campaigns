<?php

namespace hexa_package_article_campaigns\Contracts;

use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\SourceBatch;

interface SourceDiscoveryPort
{
    public function discover(CampaignRunContext $context): SourceBatch;
}
