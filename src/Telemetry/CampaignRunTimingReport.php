<?php

namespace hexa_package_article_campaigns\Telemetry;

/** Builds a queryable leaf-task timing report from application-owned events. */
final class CampaignRunTimingReport
{
    private const TIME_INTENSIVE_MINIMUM_MS = 5000;

    private const TIME_INTENSIVE_RUN_SHARE_PERCENT = 5.0;

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function summarize(array $events, ?int $runDurationMs = null): array
    {
        $runDurationMs = $runDurationMs === null ? null : max(0, $runDurationMs);
        $sections = [];
        $slowest = [];
        $measuredDurationMs = 0;

        foreach ($events as $sequence => $event) {
            $section = $this->key($event['timing_section'] ?? null);
            $task = $this->key($event['timing_task'] ?? null);
            $durationMs = $this->nonNegativeInteger($event['duration_ms'] ?? null);
            if ($section === null || $task === null || $durationMs === null) {
                continue;
            }

            $record = array_filter([
                'task' => $task,
                'label' => $this->label($event['timing_label'] ?? null, $task),
                'duration_ms' => $durationMs,
                'started_at' => $this->bounded($event['started_at'] ?? null, 64),
                'completed_at' => $this->bounded($event['completed_at'] ?? null, 64),
                'status' => $this->status($event),
                'attempt' => $this->positiveInteger($event['timing_attempt'] ?? null),
                'provider' => $this->bounded($event['provider'] ?? null, 80),
                'model' => $this->bounded($event['model'] ?? null, 120),
                'boundary' => $this->key($event['timing_boundary'] ?? null),
                'work_type' => $this->key($event['timing_work_type'] ?? null),
                'target' => $this->bounded($event['timing_target'] ?? $event['url'] ?? null, 240),
                'outcome' => $this->bounded($event['message'] ?? null, 500),
                'details' => $this->bounded($event['details'] ?? null, 1000),
                'run_share_percent' => $this->percentOfRun($durationMs, $runDurationMs),
                'sequence' => $sequence + 1,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            if (! isset($sections[$section])) {
                $sections[$section] = [
                    'section' => $section,
                    'label' => $this->label($event['timing_section_label'] ?? null, $section),
                    'duration_ms' => 0,
                    'task_count' => 0,
                    'started_at' => null,
                    'completed_at' => null,
                    'boundary' => $record['boundary'] ?? null,
                    'work_type' => $record['work_type'] ?? null,
                    'tasks' => [],
                ];
            }

            $sections[$section]['duration_ms'] += $durationMs;
            $sections[$section]['task_count']++;
            $sections[$section]['started_at'] ??= $record['started_at'] ?? null;
            if (isset($record['completed_at'])) {
                $sections[$section]['completed_at'] = $record['completed_at'];
            }
            $sections[$section]['tasks'][] = $record;
            $measuredDurationMs += $durationMs;
            $slowest[] = ['section' => $section] + $record;
        }

        usort($slowest, static function (array $left, array $right): int {
            return ($right['duration_ms'] <=> $left['duration_ms'])
                ?: ($left['sequence'] <=> $right['sequence']);
        });

        foreach ($sections as &$section) {
            $section['run_share_percent'] = $this->percentOfRun($section['duration_ms'], $runDurationMs);
        }
        unset($section);

        $timeIntensiveTasks = array_values(array_filter(
            $slowest,
            fn (array $task): bool => $this->isTimeIntensive((int) $task['duration_ms'], $runDurationMs)
        ));
        foreach ($timeIntensiveTasks as $rank => &$task) {
            $task['rank'] = $rank + 1;
        }
        unset($task);

        $timeIntensiveSections = array_values(array_filter(
            $sections,
            fn (array $section): bool => $this->isTimeIntensive((int) $section['duration_ms'], $runDurationMs)
        ));
        usort($timeIntensiveSections, static function (array $left, array $right): int {
            return ($right['duration_ms'] <=> $left['duration_ms'])
                ?: strcmp((string) $left['section'], (string) $right['section']);
        });
        foreach ($timeIntensiveSections as $rank => &$section) {
            $section['rank'] = $rank + 1;
        }
        unset($section);

        $failedTasks = array_values(array_filter(
            $slowest,
            static fn (array $task): bool => ($task['status'] ?? null) === 'failed'
        ));
        $retriedTasks = array_values(array_filter(
            $slowest,
            static fn (array $task): bool => (int) ($task['attempt'] ?? 0) > 1
        ));
        $unmeasuredDurationMs = $runDurationMs === null
            ? null
            : max(0, $runDurationMs - $measuredDurationMs);
        $overlapDurationMs = $runDurationMs === null
            ? null
            : max(0, $measuredDurationMs - $runDurationMs);

        return array_filter([
            'measured_duration_ms' => $measuredDurationMs,
            'unmeasured_duration_ms' => $unmeasuredDurationMs,
            'overlap_duration_ms' => $overlapDurationMs,
            'coverage_percent' => $runDurationMs && $runDurationMs > 0
                ? round(min(100, ($measuredDurationMs / $runDurationMs) * 100), 2)
                : null,
            'sections' => array_values($sections),
            'slowest_tasks' => array_slice($slowest, 0, 10),
            'time_intensive_criteria' => [
                'minimum_duration_ms' => self::TIME_INTENSIVE_MINIMUM_MS,
                'minimum_run_share_percent' => self::TIME_INTENSIVE_RUN_SHARE_PERCENT,
                'rule' => 'duration_or_run_share',
            ],
            'time_intensive_sections' => array_slice($timeIntensiveSections, 0, 10),
            'time_intensive_tasks' => array_slice($timeIntensiveTasks, 0, 20),
            'failed_tasks' => array_slice($failedTasks, 0, 20),
            'retried_tasks' => array_slice($retriedTasks, 0, 20),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function isTimeIntensive(int $durationMs, ?int $runDurationMs): bool
    {
        if ($durationMs >= self::TIME_INTENSIVE_MINIMUM_MS) {
            return true;
        }

        return ($this->percentOfRun($durationMs, $runDurationMs) ?? 0)
            >= self::TIME_INTENSIVE_RUN_SHARE_PERCENT;
    }

    private function percentOfRun(int $durationMs, ?int $runDurationMs): ?float
    {
        if ($runDurationMs === null || $runDurationMs <= 0) {
            return null;
        }

        return round(($durationMs / $runDurationMs) * 100, 2);
    }

    private function key(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value === '' ? null : substr($value, 0, 80);
    }

    private function label(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 160) : ucfirst(str_replace('_', ' ', $fallback));
    }

    /** @param array<string, mixed> $event */
    private function status(array $event): string
    {
        $status = strtolower(trim((string) ($event['timing_status'] ?? $event['type'] ?? 'completed')));

        return in_array($status, ['error', 'failed'], true) ? 'failed'
            : (in_array($status, ['warning', 'skipped'], true) ? $status : 'completed');
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function positiveInteger(mixed $value): ?int
    {
        $value = $this->nonNegativeInteger($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function bounded(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
