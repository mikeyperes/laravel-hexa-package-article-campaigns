<?php

namespace hexa_package_article_campaigns\Policies;

use Illuminate\Support\Str;

/** Conservative duplicate evidence: shared subject words alone never reject an event. */
class CampaignEventDuplicatePolicy
{
    public function sameEvent(string $left, string $right): bool
    {
        $normalize = static fn (string $text): string => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii(html_entity_decode($text)))) ?: '');
        $left = $normalize($left);
        $right = $normalize($right);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }
        preg_match_all('/\b\d+\b/', $left, $leftNumbers);
        preg_match_all('/\b\d+\b/', $right, $rightNumbers);
        if ($leftNumbers[0] !== $rightNumbers[0]) {
            return false;
        }
        $stop = ['a', 'an', 'the', 'and', 'of', 'to', 'in', 'on', 'for', 'with', 'by', 'at'];
        $a = array_values(array_diff(array_unique(explode(' ', $left)), $stop));
        $b = array_values(array_diff(array_unique(explode(' ', $right)), $stop));
        $shared = count(array_intersect($a, $b));
        return $shared >= 6 && $shared / max(count($a), count($b), 1) >= 0.9;
    }
}
