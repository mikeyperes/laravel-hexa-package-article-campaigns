<?php

namespace hexa_package_article_campaigns\Contracts;

use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\CampaignStageResult;
use hexa_package_article_campaigns\Data\CampaignWorkflowState;

/**
 * Application adapter for the four durable phases of a campaign run.
 *
 * Implementations own persistence, providers and delivery. The generic engine
 * owns ordering and stops the workflow as soon as a phase fails.
 */
interface CampaignWorkflowPort
{
    public function prepare(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult;

    public function discover(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult;

    public function generate(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult;

    public function deliver(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult;
}
