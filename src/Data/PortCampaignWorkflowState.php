<?php

namespace hexa_package_article_campaigns\Data;

/** Internal compatibility state for the original discovery/generation/delivery ports. */
final class PortCampaignWorkflowState
{
    public ?SourceBatch $sources = null;

    public ?GeneratedArticle $article = null;

    public ?DeliveryResult $delivery = null;
}
