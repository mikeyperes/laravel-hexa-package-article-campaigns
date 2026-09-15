<?php

namespace hexa_package_article_campaigns\Contracts;

use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\DeliveryResult;
use hexa_package_article_campaigns\Data\GeneratedArticle;

interface ArticleDeliveryPort
{
    public function deliver(CampaignRunContext $context, GeneratedArticle $article): DeliveryResult;
}
