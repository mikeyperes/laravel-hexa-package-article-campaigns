<?php

namespace hexa_package_article_campaigns\Scheduling;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use hexa_package_article_campaigns\Data\CampaignSchedule;

final class CampaignSchedulePolicy
{
    public function canRetryFailedProduction(
        CampaignSchedule $schedule,
        array $counts,
        ?CarbonInterface $nextRunAt = null,
        ?CarbonInterface $reference = null,
        int $maximumFailedAttempts = 2,
    ): bool {
        $reference = ($reference ?: now())->copy();
        [, $windowEnd] = $this->currentWindowUtc($schedule, $reference);
        if ($nextRunAt && $nextRunAt->gt($reference) && $nextRunAt->lt($windowEnd)) {
            return false;
        }

        return (int) ($counts['occupied'] ?? 0) < $schedule->articlesPerInterval
            && (int) ($counts['failed_shells'] ?? 0) > 0
            && (int) ($counts['failed_shells'] ?? 0) < max(1, $maximumFailedAttempts)
            && ($counts['latest_failed_at'] ?? null) !== null
            && Carbon::parse($counts['latest_failed_at'], 'UTC')
                ->lessThanOrEqualTo($reference->copy()->subMinutes(15));
    }

    public function initialRunAt(CampaignSchedule $schedule, ?CarbonInterface $from = null): Carbon
    {
        $reference = ($from ?: now())->copy()->setTimezone($schedule->timezone);
        if ($schedule->intervalUnit === 'hourly') {
            return $reference->copy()->addHour()->setSecond(0)->utc();
        }

        $candidate = $reference->copy()->setTimeFromTimeString($schedule->runAtTime)->setSecond(0);

        return $candidate->lessThanOrEqualTo($reference)
            ? $this->incrementForInterval($candidate, $schedule->intervalUnit)->utc()
            : $candidate->utc();
    }

    public function nextRunAt(CampaignSchedule $schedule, ?CarbonInterface $from = null): Carbon
    {
        $reference = ($from ?: now())->copy()->setTimezone($schedule->timezone)->setSecond(0);
        if ($schedule->intervalUnit === 'hourly') {
            return $reference->copy()->addHour()->utc();
        }

        $next = $this->incrementForInterval(
            $reference->copy()->setTimeFromTimeString($schedule->runAtTime),
            $schedule->intervalUnit,
        );
        while ($next->lessThanOrEqualTo($reference)) {
            $next = $this->incrementForInterval($next, $schedule->intervalUnit);
        }

        return $next->utc();
    }

    public function nextProductionSlotAt(
        CampaignSchedule $schedule,
        int $producedInWindow,
        ?CarbonInterface $reference = null,
    ): Carbon {
        $reference = ($reference ?: now())->copy();
        if ($producedInWindow >= $schedule->articlesPerInterval) {
            return $this->nextRunAt($schedule, $reference);
        }

        [$windowStart, $windowEnd] = $this->currentWindowUtc($schedule, $reference);
        $nextSlot = $windowStart->copy()->addMinutes($producedInWindow * $schedule->dripIntervalMinutes);
        if ($nextSlot->lessThan($reference)) {
            $nextSlot = $reference->copy()->addMinutes($schedule->dripIntervalMinutes)->setSecond(0);
        }

        return $nextSlot->greaterThanOrEqualTo($windowEnd)
            ? $this->nextRunAt($schedule, $reference)
            : $nextSlot;
    }

    public function outageRetryAt(
        CampaignSchedule $schedule,
        int $minutes = 15,
        ?CarbonInterface $reference = null,
    ): Carbon {
        $reference = ($reference ?: now())->copy();
        $retry = $reference->copy()->addMinutes(max(5, $minutes))->setSecond(0);
        [, $windowEnd] = $this->currentWindowUtc($schedule, $reference);

        return $retry->lessThan($windowEnd)
            ? $retry
            : $this->nextRunAt($schedule, $reference);
    }

    public function productionSlotKey(
        CampaignSchedule $schedule,
        int $slotNumber,
        ?CarbonInterface $reference = null,
    ): string {
        [$windowStart] = $this->currentWindowUtc($schedule, $reference);

        return implode(':', [
            'campaign',
            (string) $schedule->campaignKey,
            'window',
            $windowStart->format('Ymd\THis\Z'),
            'slot',
            max(1, $slotNumber),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function currentWindowUtc(
        CampaignSchedule $schedule,
        ?CarbonInterface $reference = null,
    ): array {
        $local = ($reference ?: now())->copy()->setTimezone($schedule->timezone);

        if ($schedule->intervalUnit === 'hourly') {
            $startLocal = $local->copy()->startOfHour();
            $endLocal = $startLocal->copy()->addHour();
        } elseif ($schedule->intervalUnit === 'weekly') {
            $startLocal = $local->copy()->startOfWeek()->setTimeFromTimeString($schedule->runAtTime)->setSecond(0);
            if ($local->lt($startLocal)) {
                $startLocal->subWeek();
            }
            $endLocal = $startLocal->copy()->addWeek();
        } elseif ($schedule->intervalUnit === 'monthly') {
            $startLocal = $local->copy()->startOfMonth()->setTimeFromTimeString($schedule->runAtTime)->setSecond(0);
            if ($local->lt($startLocal)) {
                $startLocal->subMonth();
            }
            $endLocal = $startLocal->copy()->addMonth();
        } else {
            $startLocal = $local->copy()->setTimeFromTimeString($schedule->runAtTime)->setSecond(0);
            if ($local->lt($startLocal)) {
                $startLocal->subDay();
            }
            $endLocal = $startLocal->copy()->addDay();
        }

        return [$startLocal->copy()->utc(), $endLocal->copy()->utc()];
    }

    private function incrementForInterval(Carbon $date, string $intervalUnit): Carbon
    {
        return match ($intervalUnit) {
            'hourly' => $date->copy()->addHour(),
            'weekly' => $date->copy()->addWeek(),
            'monthly' => $date->copy()->addMonth(),
            default => $date->copy()->addDay(),
        };
    }
}
