<?php

namespace hexa_package_article_campaigns\Orchestration;

use hexa_package_article_campaigns\Contracts\ArticleDeliveryPort;
use hexa_package_article_campaigns\Contracts\ArticleGenerationPort;
use hexa_package_article_campaigns\Contracts\SourceDiscoveryPort;
use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\CampaignRunResult;
use hexa_package_article_campaigns\Data\PortCampaignWorkflowState;
use hexa_package_article_campaigns\State\CampaignRunStateMachine;

/** Backward-compatible three-port facade over the single workflow coordinator. */
final class CampaignOrchestrator
{
    private CampaignWorkflowOrchestrator $workflow;

    private ThreePortCampaignWorkflowAdapter $adapter;

    public function __construct(
        SourceDiscoveryPort $sources,
        ArticleGenerationPort $generator,
        ArticleDeliveryPort $delivery,
        CampaignRunStateMachine $states,
    ) {
        $this->workflow = new CampaignWorkflowOrchestrator($states);
        $this->adapter = new ThreePortCampaignWorkflowAdapter($sources, $generator, $delivery);
    }

    public function run(CampaignRunContext $context): CampaignRunResult
    {
        $result = $this->workflow->run(new CampaignRunContext(
            campaignKey: $context->campaignKey,
            publicationKey: $context->publicationKey,
            settings: $context->settings,
            runtime: new PortCampaignWorkflowState(),
        ), $this->adapter);

        return new CampaignRunResult(
            successful: $result->successful,
            state: $result->state,
            failureCode: $result->failureCode,
            message: $result->message,
            article: $result->metadata['article'] ?? null,
            delivery: $result->metadata['delivery'] ?? null,
            metadata: $result->metadata,
        );
    }
}
