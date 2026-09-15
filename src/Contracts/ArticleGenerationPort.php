<?php

namespace hexa_package_article_campaigns\Contracts;

use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\GeneratedArticle;
use hexa_package_article_campaigns\Data\SourceBatch;

interface ArticleGenerationPort
{
    public function generate(CampaignRunContext $context, SourceBatch $sources): GeneratedArticle;
}
