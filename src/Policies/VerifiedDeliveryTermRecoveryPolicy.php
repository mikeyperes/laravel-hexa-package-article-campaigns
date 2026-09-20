<?php

namespace hexa_package_article_campaigns\Policies;

/** Recover missing taxonomy IDs only from a verified delivery bound to the same output. */
final class VerifiedDeliveryTermRecoveryPolicy
{
    private const TERM_KEYS = [
        'category_ids',
        'tag_ids',
        'publication_term_ids',
        'article_type_term_ids',
        'smpi_article_type_term_ids',
    ];

    /**
     * @param array<string, mixed> $current
     * @param array<int, array<string, mixed>> $attestations Newest first.
     * @param array<int, string> $requiredTermKeys Taxonomy families that must be
     *        recovered before an older attestation can be ignored.
     * @return array<string, mixed>
     */
    public function recoverMissing(
        array $current,
        int|string $deliveryKey,
        array $attestations,
        array $requiredTermKeys = self::TERM_KEYS,
    ): array
    {
        $deliveryKey = trim((string) $deliveryKey);
        $requiredTermKeys = array_values(array_intersect(
            self::TERM_KEYS,
            array_values(array_unique(array_map('strval', $requiredTermKeys))),
        ));
        if ($deliveryKey === '' || $deliveryKey === '0' || ! $this->hasMissingRequiredTerms($current, $requiredTermKeys)) {
            return $current;
        }

        foreach ($attestations as $attestation) {
            if (! is_array($attestation)
                || ($attestation['verified'] ?? false) !== true
                || ! hash_equals($deliveryKey, trim((string) ($attestation['delivery_key'] ?? '')))) {
                continue;
            }

            $contentHash = strtolower(trim((string) ($attestation['content_hash'] ?? '')));
            $termIds = is_array($attestation['term_ids'] ?? null) ? $attestation['term_ids'] : [];
            if (! preg_match('/^[a-f0-9]{64}$/', $contentHash)
                || $this->integerList($termIds['category_ids'] ?? []) === []) {
                continue;
            }

            foreach (self::TERM_KEYS as $key) {
                if ($this->integerList($current[$key] ?? []) !== []) {
                    continue;
                }

                $ids = $this->integerList($termIds[$key] ?? []);
                if ($ids !== []) {
                    $current[$key] = $ids;
                }
            }

            // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-043. A newer verified
            // attestation can be incomplete for one taxonomy family. Continue
            // to older same-output evidence until every taxonomy family the
            // caller says is still expected has been recovered.
            if (! $this->hasMissingRequiredTerms($current, $requiredTermKeys)) {
                return $current;
            }
        }

        return $current;
    }

    /** @param array<int, string> $requiredTermKeys */
    private function hasMissingRequiredTerms(array $current, array $requiredTermKeys): bool
    {
        foreach ($requiredTermKeys as $key) {
            if ($this->integerList($current[$key] ?? []) === []) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> */
    private function integerList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
