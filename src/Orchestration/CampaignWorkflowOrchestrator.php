<?php

namespace hexa_package_article_campaigns\Orchestration;

use Carbon\CarbonImmutable;
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
        $runStartedAt = CarbonImmutable::now('UTC');
        $runStartedClock = hrtime(true);
        $timings = [];
        $state = CampaignRunStateMachine::CREATED;
        $workflowState = new CampaignWorkflowState($context->runtime);

        foreach ([
            CampaignRunStateMachine::PREPARING => 'prepare',
            CampaignRunStateMachine::SOURCING => 'discover',
            CampaignRunStateMachine::GENERATING => 'generate',
            CampaignRunStateMachine::DELIVERING => 'deliver',
        ] as $nextState => $method) {
            $state = $this->states->transition($state, $nextState);
            $stageStartedAt = CarbonImmutable::now('UTC');
            $stageStartedClock = hrtime(true);
            /** @var CampaignStageResult $stage */
            $stage = $workflow->{$method}($context, $workflowState);
            $stageCompletedAt = CarbonImmutable::now('UTC');
            $timings[] = [
                'stage' => $method,
                'started_at' => $stageStartedAt->toIso8601String(),
                'completed_at' => $stageCompletedAt->toIso8601String(),
                'duration_ms' => $this->elapsedMilliseconds($stageStartedClock),
                'status' => $stage->successful ? 'completed' : 'failed',
            ];
            $workflowState = $stage->state;

            if (! $stage->successful) {
                return new CampaignRunResult(
                    successful: false,
                    state: $this->states->transition($state, CampaignRunStateMachine::FAILED),
                    failureCode: $stage->failureCode ?: $method.'_failed',
                    message: $stage->message,
                    metadata: array_replace($stage->metadata, [
                        'failed_phase' => $method,
                        'timing' => $this->timingSummary($runStartedAt, $runStartedClock, $timings),
                    ]),
                );
            }

            $lastMetadata = $stage->metadata;
        }

        return new CampaignRunResult(
            successful: true,
            state: $this->states->transition($state, CampaignRunStateMachine::COMPLETED),
            message: $lastMetadata['message'] ?? null,
            metadata: array_replace($lastMetadata, [
                'timing' => $this->timingSummary($runStartedAt, $runStartedClock, $timings),
            ]),
        );
    }

    /** @param array<int, array<string, int|string>> $stages */
    private function timingSummary(
        CarbonImmutable $startedAt,
        int $startedClock,
        array $stages,
    ): array {
        return [
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'duration_ms' => $this->elapsedMilliseconds($startedClock),
            'stages' => $stages,
        ];
    }

    private function elapsedMilliseconds(int $startedClock): int
    {
        return max(0, (int) round((hrtime(true) - $startedClock) / 1_000_000));
    }
}
