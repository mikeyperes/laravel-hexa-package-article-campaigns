<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Data\CampaignConfigurationSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CampaignConfigurationSnapshotTest extends TestCase
{
    public function test_associative_key_order_does_not_change_the_fingerprint(): void
    {
        $first = CampaignConfigurationSnapshot::issue([
            'models' => ['primary' => 'haiku', 'fallback' => 'gpt-mini'],
            'categories' => [9, 3],
        ], ['publication_id' => 7, 'campaign_id' => 11]);
        $second = CampaignConfigurationSnapshot::issue([
            'categories' => [9, 3],
            'models' => ['fallback' => 'gpt-mini', 'primary' => 'haiku'],
        ], ['campaign_id' => 11, 'publication_id' => 7]);

        $this->assertSame($first['fingerprint'], $second['fingerprint']);
        $this->assertTrue(CampaignConfigurationSnapshot::valid($first));
    }

    public function test_configuration_or_identity_tampering_is_rejected(): void
    {
        $snapshot = CampaignConfigurationSnapshot::issue(
            ['search_terms' => ['business', 'fashion']],
            ['campaign_id' => 11, 'publication_id' => 7],
        );

        $changedConfiguration = $snapshot;
        $changedConfiguration['configuration']['search_terms'][] = 'politics';
        $changedIdentity = $snapshot;
        $changedIdentity['identity']['campaign_id'] = 12;

        $this->assertFalse(CampaignConfigurationSnapshot::valid($changedConfiguration));
        $this->assertFalse(CampaignConfigurationSnapshot::valid($changedIdentity));
        $this->expectException(InvalidArgumentException::class);
        CampaignConfigurationSnapshot::configuration($changedConfiguration);
    }

    public function test_non_serializable_configuration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CampaignConfigurationSnapshot::issue(['service' => new \stdClass]);
    }
}
