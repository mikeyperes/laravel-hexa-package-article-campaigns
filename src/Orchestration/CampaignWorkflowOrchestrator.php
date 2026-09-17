<?php

namespace hexa_package_article_campaigns\Orchestration;

use hexa_package_article_campaigns\Contracts\CampaignWorkflowPort;
use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\CampaignRunResult;
use hexa_package_article_campaigns\Data\CampaignStageResult;
use hexa_package_article_campaigns\Data\CampaignWorkflowState;
use hexa_package_article_campaigns\State\CampaignRunStateMachine;

/** Executes an application adapter through the reusable campaign lifecycle. */
final class CampaignWorkflowOrchestrator
{
    public function __construct(private CampaignRunStateMachine $states) {}

    public function run(CampaignRunContext $context, CampaignWorkflowPort $workflow): CampaignRunResult
    {
        $state = CampaignRunStateMachine::CREATED;
        $workflowState = new CampaignWorkflowState($context->runtime);

        foreach ([
            CampaignRunStateMachine::PREPARING => 'prepare',
            CampaignRunStateMachine::SOURCING => 'discover',
            CampaignRunStateMachine::GENERATING => 'generate',
            CampaignRunStateMachine::DELIVERING => 'deliver',
        ] as $nextState => $method) {
            $state = $this->states->transition($state, $nextState);
            /** @var CampaignStageResult $stage */
            $stage = $workflow->{$method}($context, $workflowState);
            $workflowState = $stage->state;

            if (! $stage->successful) {
                return new CampaignRunResult(
                    successful: false,
                    state: $this->states->transition($state, CampaignRunStateMachine::FAILED),
                    failureCode: $stage->failureCode ?: $method.'_failed',
                    message: $stage->message,
                    metadata: array_replace($stage->metadata, ['failed_phase' => $method]),
                );
            }

            $lastMetadata = $stage->metadata;
        }

        return new CampaignRunResult(
            successful: true,
            state: $this->states->transition($state, CampaignRunStateMachine::COMPLETED),
            message: $lastMetadata['message'] ?? null,
            metadata: $lastMetadata,
        );
    }
}
