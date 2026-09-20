<?php

namespace hexa_package_article_campaigns\Telemetry;

/** Builds a queryable leaf-task timing report from application-owned events. */
final class CampaignRunTimingReport
{
    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function summarize(array $events, ?int $runDurationMs = null): array
    {
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
                'target' => $this->bounded($event['timing_target'] ?? $event['url'] ?? null, 240),
                'outcome' => $this->bounded($event['message'] ?? null, 500),
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

        $runDurationMs = $runDurationMs === null ? null : max(0, $runDurationMs);
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
        ], static fn (mixed $value): bool => $value !== null);
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
