<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Policies\CampaignMediaAudiencePolicy;
use PHPUnit\Framework\TestCase;

final class CampaignMediaAudiencePolicyTest extends TestCase
{
    public function test_explicit_audience_is_preserved_in_generic_stock_searches(): void
    {
        $policy = new CampaignMediaAudiencePolicy();

        $this->assertSame(
            'women small business owner at computer working',
            $policy->qualifySearchTerm(
                'small business owner at computer working',
                'Rural Women Entrepreneurs Focus on Workforce Challenges',
            ),
        );
        $this->assertSame(
            'women entrepreneurs meeting',
            $policy->qualifySearchTerm('women entrepreneurs meeting', 'Women Entrepreneurs Report'),
        );
        $this->assertSame(
            'small business owner at computer working',
            $policy->qualifySearchTerm('small business owner at computer working', 'Small Business Survey'),
        );
    }

    public function test_candidate_with_only_conflicting_audience_is_rejected(): void
    {
        $policy = new CampaignMediaAudiencePolicy();
        $title = 'Rural Women Entrepreneurs Focus on Workforce Challenges';

        $this->assertTrue($policy->rejectsCandidate(
            ['alt' => 'Businessman working alone at a computer'],
            'women small business owner working',
            $title,
        ));
        $this->assertFalse($policy->rejectsCandidate(
            ['alt' => 'Women business owners collaborating at computers'],
            'women small business owner working',
            $title,
        ));
        $this->assertFalse($policy->rejectsCandidate(
            ['alt' => 'People collaborating around a computer'],
            'women small business owner working',
            $title,
        ));
    }
}
