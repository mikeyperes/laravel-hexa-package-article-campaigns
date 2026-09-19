<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Policies\CampaignSourcePacketBudgetPolicy;
use PHPUnit\Framework\TestCase;

class CampaignSourcePacketBudgetPolicyTest extends TestCase
{
    public function test_oversized_complete_source_is_rejected_without_clipping(): void
    {
        $source = [
            'title' => 'Year-long celebrity roundup',
            'url' => 'https://source.test/roundup',
            'text' => str_repeat('a', 50_001),
        ];

        $result = (new CampaignSourcePacketBudgetPolicy())->fit([$source]);

        $this->assertSame([], $result['selected']);
        $this->assertSame('source_exceeds_budget', $result['rejected'][0]['reason']);
        $this->assertSame(50_001, $result['rejected'][0]['characters']);
        $this->assertSame(50_000, $result['max_characters']);
        $this->assertSame(50_001, mb_strlen($source['text']));
    }

    public function test_packet_keeps_complete_sources_in_priority_order_within_budget(): void
    {
        $sources = [
            ['title' => 'Primary', 'url' => 'https://source.test/primary', 'text' => str_repeat('a', 30_000)],
            ['title' => 'Too large for remainder', 'url' => 'https://source.test/second', 'text' => str_repeat('b', 25_000)],
            ['title' => 'Replacement fit', 'url' => 'https://source.test/third', 'text' => str_repeat('c', 20_000)],
        ];

        $result = (new CampaignSourcePacketBudgetPolicy())->fit($sources);

        $this->assertSame(['Primary', 'Replacement fit'], array_column($result['selected'], 'title'));
        $this->assertSame('packet_exceeds_budget', $result['rejected'][0]['reason']);
        $this->assertSame(50_000, $result['used_characters']);
    }
}
