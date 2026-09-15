<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Discovery\HomepageCategorySearchPolicy;
use hexa_package_article_campaigns\Policies\CampaignNegativeTopicMatcher;
use hexa_package_article_campaigns\Policies\CampaignSourceRelevancePolicy;
use PHPUnit\Framework\TestCase;

class HomepageCategorySearchPolicyTest extends TestCase
{
    public function test_pharmaceuticals_category_accepts_a_source_about_a_clinical_cell_therapy_trial(): void
    {
        $search = new HomepageCategorySearchPolicy();
        $terms = $search->terms('Pharmaceuticals');
        $source = [
            'title' => 'FDA clears trial of dual-targeted CAR T-cell therapy for cancer',
            'url' => 'https://source.test/car-t-cell-trial',
            'text' => str_repeat(
                'The clinical trial tests CAR T-cell therapy developed for patients with advanced cancer. ',
                6,
            ),
        ];

        $this->assertContains('clinical trials', $terms);
        $this->assertContains('cell therapy', $terms);
        $this->assertTrue($search->matches($source, $terms));

        $relevance = new CampaignSourceRelevancePolicy(new CampaignNegativeTopicMatcher(), $search);
        $intent = $relevance->campaignIntentMatch(
            $source['title'],
            $source['text'],
            array_merge(['Pharmaceuticals'], $terms),
        );

        $this->assertTrue($intent['configured']);
        $this->assertTrue($intent['matched']);
    }
}
