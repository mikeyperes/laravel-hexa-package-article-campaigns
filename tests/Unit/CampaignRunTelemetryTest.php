<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Contracts\CampaignWorkflowPort;
use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\CampaignStageResult;
use hexa_package_article_campaigns\Data\CampaignWorkflowState;
use hexa_package_article_campaigns\Orchestration\CampaignWorkflowOrchestrator;
use hexa_package_article_campaigns\State\CampaignRunStateMachine;
use hexa_package_article_campaigns\Telemetry\CampaignRunProvenance;
use PHPUnit\Framework\TestCase;

final class CampaignRunTelemetryTest extends TestCase
{
    public function test_workflow_records_total_and_each_generic_stage(): void
    {
        $workflow = new class implements CampaignWorkflowPort {
            public function prepare(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
            {
                return CampaignStageResult::continued($state);
            }

            public function discover(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
            {
                return CampaignStageResult::continued($state);
            }

            public function generate(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
            {
                return CampaignStageResult::continued($state);
            }

            public function deliver(CampaignRunContext $context, CampaignWorkflowState $state): CampaignStageResult
            {
                return CampaignStageResult::continued($state, ['message' => 'Complete']);
            }
        };

        $result = (new CampaignWorkflowOrchestrator(new CampaignRunStateMachine()))
            ->run(new CampaignRunContext('campaign', 'publication'), $workflow);

        $this->assertTrue($result->successful);
        $this->assertSame(['prepare', 'discover', 'generate', 'deliver'], array_column($result->metadata['timing']['stages'], 'stage'));
        $this->assertSame(['completed', 'completed', 'completed', 'completed'], array_column($result->metadata['timing']['stages'], 'status'));
        $this->assertIsInt($result->metadata['timing']['duration_ms']);
        $this->assertNotEmpty($result->metadata['timing']['started_at']);
        $this->assertNotEmpty($result->metadata['timing']['completed_at']);
    }

    public function test_provenance_distinguishes_agent_origin_and_detects_tampering(): void
    {
        $provenance = new CampaignRunProvenance();
        $record = $provenance->issue([
            'origin' => 'codex',
            'actor_type' => 'agent',
            'actor_label' => 'Codex',
            'client' => 't3-code',
            'session_id' => 'session-secret-value',
            'request_id' => 'request-secret-value',
            'configuration_fingerprint' => str_repeat('a', 64),
        ], 'application-signing-key');

        $this->assertSame('codex', $record['origin']);
        $this->assertFalse($record['site_native']);
        $this->assertSame(64, strlen($record['session_fingerprint']));
        $this->assertArrayNotHasKey('session_id', $record);
        $this->assertTrue($provenance->verify($record, 'application-signing-key'));

        $record['origin'] = 'claude';
        $this->assertFalse($provenance->verify($record, 'application-signing-key'));
    }
}
