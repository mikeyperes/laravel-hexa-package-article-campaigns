<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Policies\VerifiedDeliveryTermRecoveryPolicy;
use PHPUnit\Framework\TestCase;

class VerifiedDeliveryTermRecoveryPolicyTest extends TestCase
{
    public function test_it_uses_older_same_output_evidence_for_a_required_taxonomy_missing_from_the_newest_attestation(): void
    {
        $policy = new VerifiedDeliveryTermRecoveryPolicy();
        $attestations = [
            $this->attestation(['category_ids' => [48], 'tag_ids' => []]),
            $this->attestation(['category_ids' => [48], 'tag_ids' => [418, 912, 1021]]),
        ];

        $recovered = $policy->recoverMissing(
            [],
            462596,
            $attestations,
            ['category_ids', 'tag_ids'],
        );

        self::assertSame([48], $recovered['category_ids']);
        self::assertSame([418, 912, 1021], $recovered['tag_ids']);
    }

    public function test_it_does_not_revive_older_tags_when_the_caller_no_longer_requires_tags(): void
    {
        $policy = new VerifiedDeliveryTermRecoveryPolicy();
        $attestations = [
            $this->attestation(['category_ids' => [48], 'tag_ids' => []]),
            $this->attestation(['category_ids' => [48], 'tag_ids' => [418, 912, 1021]]),
        ];

        $recovered = $policy->recoverMissing([], 462596, $attestations, ['category_ids']);

        self::assertSame([48], $recovered['category_ids']);
        self::assertArrayNotHasKey('tag_ids', $recovered);
    }

    /** @param array<string, mixed> $termIds */
    private function attestation(array $termIds): array
    {
        return [
            'verified' => true,
            'delivery_key' => '462596',
            'content_hash' => str_repeat('a', 64),
            'term_ids' => $termIds,
        ];
    }
}
