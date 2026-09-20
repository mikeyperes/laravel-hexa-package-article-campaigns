<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Policies\CampaignArticleLengthPolicy;
use PHPUnit\Framework\TestCase;

final class CampaignArticleLengthPolicyTest extends TestCase
{
    public function test_article_and_source_floors_share_one_bounded_policy(): void
    {
        $policy = new CampaignArticleLengthPolicy();

        $this->assertSame(400, $policy->minimumArticleWords());
        $this->assertSame(350, $policy->minimumArticleWords(200));
        $this->assertSame(450, $policy->minimumArticleWords(650));
        $this->assertSame(400, $policy->minimumSourceWords(650));
        $this->assertSame(350, $policy->minimumSourceWords(350));
    }
}
