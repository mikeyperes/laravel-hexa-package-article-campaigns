<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Policies\CampaignModeResolver;
use PHPUnit\Framework\TestCase;

final class CampaignModeResolverTest extends TestCase
{
    public function test_each_campaign_mode_delivers_its_own_wordpress_status(): void
    {
        $modes = new CampaignModeResolver();

        $this->assertSame('publish', $modes->postStatus('auto-publish'));
        $this->assertSame('pending', $modes->postStatus('pending-review'));
        $this->assertSame('draft', $modes->postStatus('draft-wordpress'));
        $this->assertSame('draft', $modes->postStatus('wp-draft'));
        $this->assertSame(['auto-publish', 'pending-review', 'draft-wordpress'], $modes->campaignDeliveryModes());
    }

    public function test_a_draft_campaign_never_resolves_to_publication(): void
    {
        $modes = new CampaignModeResolver();

        $this->assertSame('draft-wordpress', $modes->campaignDeliveryMode('draft-wordpress'));
        $this->assertFalse($modes->publishesAutomatically('draft-wordpress'));
        $this->assertFalse($modes->publishesAutomatically('pending-review'));
        $this->assertTrue($modes->publishesAutomatically('auto-publish'));
        $this->assertSame(['delivery_mode' => 'draft-wordpress', 'auto_publish' => false, 'post_status' => 'draft'], $modes->campaignAttributes([], 'draft-wordpress'));
    }

    public function test_status_maps_back_to_a_mode_and_unknown_values_stay_automatic(): void
    {
        $modes = new CampaignModeResolver();

        $this->assertSame('draft-wordpress', $modes->deliveryModeForPostStatus('draft'));
        $this->assertSame('pending-review', $modes->deliveryModeForPostStatus('Pending'));
        $this->assertNull($modes->deliveryModeForPostStatus('future'));
        $this->assertSame('auto-publish', $modes->campaignDeliveryMode('draft-local'));
        $this->assertSame('auto-publish', $modes->campaignDeliveryMode(null));
    }
}
