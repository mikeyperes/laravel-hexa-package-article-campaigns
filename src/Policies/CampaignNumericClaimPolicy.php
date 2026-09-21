<?php

namespace hexa_package_article_campaigns\Policies;

/** Recognizes narrowly provable numeric equivalence without an AI fact-check. */
final class CampaignNumericClaimPolicy
{
    /**
     * @param  array<int, string>  $unsupported
     * @param  array<int, string>  $sourceClaims
     * @return array<int, string>
     */
    public function filterSupportedEquivalents(
        array $unsupported,
        array $sourceClaims,
        string $articleSurface,
    ): array {
        $sourceValues = array_values(array_filter(array_map(
            fn (string $claim): ?array => $this->value($claim),
            $sourceClaims,
        )));

        return array_values(array_filter($unsupported, function (string $claim) use ($sourceValues, $articleSurface): bool {
            $candidate = $this->value($claim);
            if ($candidate === null) {
                return true;
            }

            if ($this->qualifiedRoundingSupported($claim, $candidate, $sourceValues, $articleSurface)) {
                return false;
            }

            if ($this->derivedDifferenceSupported($claim, $candidate, $sourceValues, $articleSurface)) {
                return false;
            }

            return true;
        }));
    }

    /** @return array{number: float, suffix: string, decimals: int}|null */
    private function value(string $claim): ?array
    {
        if (preg_match('/^(\d+(?:\.(\d+))?)([kmbt%]?)$/', $claim, $match) !== 1) {
            return null;
        }

        return [
            'number' => (float) $match[1],
            'suffix' => (string) ($match[3] ?? ''),
            'decimals' => strlen((string) ($match[2] ?? '')),
        ];
    }

    /** @param array<int, array{number: float, suffix: string, decimals: int}> $sourceValues */
    private function qualifiedRoundingSupported(string $claim, array $candidate, array $sourceValues, string $surface): bool
    {
        if (! $this->claimHasNearbyQualifier($claim, $surface)) {
            return false;
        }

        foreach ($sourceValues as $source) {
            if ($source['suffix'] !== $candidate['suffix'] || $source['number'] <= 0) {
                continue;
            }
            $difference = abs($candidate['number'] - $source['number']);
            $relativeDifference = $difference / $source['number'];
            if (($candidate['suffix'] === '%' && $difference <= 0.5)
                || ($candidate['suffix'] !== '%' && $relativeDifference <= 0.01)) {
                return true;
            }
        }

        return false;
    }

    private function claimHasNearbyQualifier(string $claim, string $surface): bool
    {
        return preg_match(
            '/\b(?:nearly|almost|about|around|roughly|approximately|close\s+to|just\s+under|just\s+over)\b.{0,24}'.$this->claimPattern($claim).'/iu',
            $surface,
        ) === 1;
    }

    /** @param array<int, array{number: float, suffix: string, decimals: int}> $sourceValues */
    private function derivedDifferenceSupported(string $claim, array $candidate, array $sourceValues, string $surface): bool
    {
        $difference = '\b(?:difference|gap|spread|decline|drop|fell|decrease|less|lower)\b';
        $claimPattern = $this->claimPattern($claim);
        if (preg_match('/(?:'.$difference.'.{0,120}'.$claimPattern.'|'.$claimPattern.'.{0,120}'.$difference.')/iu', $surface) !== 1) {
            return false;
        }

        foreach ($sourceValues as $leftIndex => $left) {
            foreach ($sourceValues as $rightIndex => $right) {
                if ($rightIndex <= $leftIndex
                    || $left['suffix'] !== $candidate['suffix']
                    || $right['suffix'] !== $candidate['suffix']) {
                    continue;
                }
                $difference = abs($left['number'] - $right['number']);
                if (abs(round($difference, $candidate['decimals']) - $candidate['number']) < 1e-9) {
                    return true;
                }
            }
        }

        return false;
    }

    private function claimPattern(string $claim): string
    {
        preg_match('/^(\d+(?:\.\d+)?)([kmbt%]?)$/', $claim, $match);
        $number = (string) ($match[1] ?? $claim);
        $suffixKey = (string) ($match[2] ?? '');
        $formatted = '';
        if ($suffixKey === '' && ! str_contains($number, '.') && strlen($number) >= 4) {
            $formatted = number_format((int) $number);
        }
        $numberPattern = preg_quote($number, '/');
        if ($formatted !== '') {
            $numberPattern = '(?:'.$numberPattern.'|'.preg_quote($formatted, '/').')';
        }
        $suffix = match ($suffixKey) {
            'k' => '(?:k|thousand)',
            'm' => '(?:m|million)',
            'b' => '(?:b|bn|billion)',
            't' => '(?:t|trillion)',
            '%' => '(?:%|percent)',
            default => '',
        };

        return '(?<!\d)(?:[$£€]\s*)?'.$numberPattern.'\s*'.$suffix.'(?![\d.])';
    }
}
