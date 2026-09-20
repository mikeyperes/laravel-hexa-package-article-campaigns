<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Contracts\CampaignWorkflowPort;
use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\CampaignStageResult;
use hexa_package_article_campaigns\Data\CampaignWorkflowState;
use hexa_package_article_campaigns\Orchestration\CampaignWorkflowOrchestrator;
use hexa_package_article_campaigns\State\CampaignRunStateMachine;
use hexa_package_article_campaigns\Telemetry\CampaignRunProvenance;
use hexa_package_article_campaigns\Telemetry\CampaignRunTimingReport;
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

    public function test_leaf_task_report_groups_sections_and_exposes_slowest_work(): void
    {
        $report = (new CampaignRunTimingReport)->summarize([
            [
                'timing_section' => 'source_acquisition',
                'timing_task' => 'initial_discovery',
                'duration_ms' => 15000,
                'started_at' => '2026-09-20T04:22:06+00:00',
                'completed_at' => '2026-09-20T04:22:21+00:00',
                'type' => 'success',
                'timing_boundary' => 'source_provider',
                'timing_work_type' => 'remote',
                'provider' => 'google_news',
            ],
            [
                'timing_section' => 'wordpress_preparation',
                'timing_task' => 'inline_media_upload',
                'duration_ms' => 20000,
                'started_at' => '2026-09-20T04:23:05+00:00',
                'completed_at' => '2026-09-20T04:23:25+00:00',
                'type' => 'success',
                'timing_boundary' => 'wordpress',
                'timing_work_type' => 'remote',
            ],
            [
                'timing_section' => 'source_acquisition',
                'timing_task' => 'replacement_discovery',
                'duration_ms' => 2000,
                'timing_attempt' => 2,
                'type' => 'error',
                'message' => 'Provider timed out.',
            ],
        ], 60000);

        $this->assertSame(37000, $report['measured_duration_ms']);
        $this->assertSame(23000, $report['unmeasured_duration_ms']);
        $this->assertSame('inline_media_upload', $report['slowest_tasks'][0]['task']);
        $this->assertSame(['source_acquisition', 'wordpress_preparation'], array_column($report['sections'], 'section'));
        $this->assertSame(['wordpress_preparation', 'source_acquisition'], array_column($report['time_intensive_sections'], 'section'));
        $this->assertSame(['inline_media_upload', 'initial_discovery'], array_column($report['time_intensive_tasks'], 'task'));
        $this->assertSame('wordpress', $report['time_intensive_tasks'][0]['boundary']);
        $this->assertSame(33.33, $report['time_intensive_tasks'][0]['run_share_percent']);
        $this->assertSame('replacement_discovery', $report['failed_tasks'][0]['task']);
        $this->assertSame('replacement_discovery', $report['retried_tasks'][0]['task']);
        $this->assertSame(5000, $report['time_intensive_criteria']['minimum_duration_ms']);
    }
}
