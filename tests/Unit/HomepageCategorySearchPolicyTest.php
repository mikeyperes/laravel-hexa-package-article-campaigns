<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Discovery\HomepageCategorySearchPolicy;
use hexa_package_article_campaigns\Policies\CampaignNegativeTopicMatcher;
use hexa_package_article_campaigns\Policies\CampaignSourceRelevancePolicy;
use PHPUnit\Framework\TestCase;

class HomepageCategorySearchPolicyTest extends TestCase
{
    public function test_investing_intent_accepts_financial_asset_language_without_unrelated_homonyms(): void
    {
        $policy = new CampaignSourceRelevancePolicy(new CampaignNegativeTopicMatcher());

        foreach ([
            'When Stocks Signal Your Beliefs Instead of Your Wealth',
            'Treasury bond yields rise',
            'Investors reassess their investment strategy',
            'ETF shareholders receive a dividend',
        ] as $title) {
            $this->assertTrue($policy->campaignIntentMatch($title, '', ['Investing'])['matched'], $title);
        }
        foreach ([
            'How chefs prepare vegetable stock',
            'James Bond returns to cinemas',
            'Stores replenish stock before the holiday',
        ] as $title) {
            $this->assertFalse($policy->campaignIntentMatch($title, '', ['Investing'])['matched'], $title);
        }
        $this->assertFalse($policy->campaignIntentMatch(
            'When Stocks Signal Your Beliefs Instead of Your Wealth', '', ['Artificial Intelligence']
        )['matched']);
    }

    public function test_plural_category_name_reuses_the_existing_singular_topic_vocabulary(): void
    {
        $terms = (new HomepageCategorySearchPolicy())->terms('Startups');

        $this->assertContains('startup', $terms);
        $this->assertContains('founder', $terms);
        $this->assertContains('venture capital', $terms);
    }

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

    public function test_business_law_uses_legal_terms_instead_of_general_economy_terms(): void
    {
        $terms = (new HomepageCategorySearchPolicy())->terms('Business Law');

        $this->assertContains('corporate law', $terms);
        $this->assertContains('regulation', $terms);
        $this->assertContains('litigation', $terms);
        $this->assertNotContains('economy', $terms);
        $this->assertNotContains('interest rates', $terms);
    }

    public function test_law_news_focus_rejects_economy_story_without_a_legal_subject(): void
    {
        $search = new HomepageCategorySearchPolicy();
        $focus = $search->publicationFocus('Law News Day');

        $this->assertNotNull($focus);
        $this->assertSame('headline', $focus['surface']);
        $this->assertFalse($search->matchesPublicationFocus([
            'title' => "Russia's Military Spending Strains Economy Despite Apparent Stability",
            'description' => 'Budget deficits, inflation and interest rates weigh on growth.',
            'text' => str_repeat('The economy faces high borrowing costs and weak consumer confidence. ', 6),
        ], ['publication_focus' => $focus]));
        $this->assertTrue($search->matchesPublicationFocus([
            'title' => 'Federal Court Rules on Corporate Antitrust Lawsuit',
            'description' => 'The ruling changes compliance obligations for companies.',
        ], ['publication_focus' => $focus]));
    }

    public function test_complete_source_reclassifies_incidental_travel_phrase_to_dominant_politics_lane(): void
    {
        $policy = new CampaignSourceRelevancePolicy(new CampaignNegativeTopicMatcher());
        $source = [[
            'title' => 'California Withholds School Funds While Blowing Money on Pet Projects, Luxury Travel',
            'text' => implode(' ', [
                'The governor and Legislature withheld public school support in the state budget.',
                'Legislators used the funds to close a deficit while a teachers union filed suit.',
                'The state budget included local projects and public funds with little notice.',
                'A separate inspector report found unallowable travel expenses by a state agency.',
                'Political leaders faced criticism over government spending and public policy.',
            ]),
        ]];
        $categories = [
            ['name' => 'Travel', 'terms' => ['travel', 'tourism', 'airlines', 'hotels', 'destinations']],
            ['name' => 'Politics', 'terms' => ['politics', 'election', 'government', 'legislation', 'Congress']],
            ['name' => 'Luxury', 'terms' => ['luxury', 'luxury brands', 'yachts', 'luxury hotels', 'luxury cars']],
        ];

        $result = $policy->resolveHomepageCategory($source, [
            'discovery_process' => \hexa_package_article_campaigns\Discovery\HomepageCategoryPoolDefinition::TYPE,
            'forced_category' => 'Travel',
            'homepage_pool' => ['categories' => $categories],
        ]);

        $this->assertTrue($result['reclassified']);
        $this->assertSame('Politics', $result['resolved_category']);

        $resolved = $policy->resolveHomepageCategory($source, [
            'discovery_process' => \hexa_package_article_campaigns\Discovery\HomepageCategoryPoolDefinition::TYPE,
            'forced_category' => 'Politics',
            'homepage_pool' => ['categories' => $categories],
        ]);
        $this->assertFalse($resolved['reclassified']);
        $this->assertTrue($resolved['selected_category_supported']);
    }

    public function test_complete_source_keeps_a_genuinely_dominant_travel_lane(): void
    {
        $search = new HomepageCategorySearchPolicy();
        $result = $search->resolveDominantCategory([[
            'title' => 'Luxury Hotels Add Airline Packages for Mediterranean Destinations',
            'text' => str_repeat(
                'Travel operators said tourism demand is lifting hotels, airlines, and beach destinations. ',
                5,
            ).'The governor briefly welcomed the new tourism campaign.',
        ]], [
            ['name' => 'Travel', 'terms' => $search->terms('Travel')],
            ['name' => 'Politics', 'terms' => $search->terms('Politics')],
        ], 'Travel');

        $this->assertFalse($result['reclassified']);
        $this->assertSame('Travel', $result['resolved_category']);
        $this->assertTrue($result['selected_category_supported']);
    }

    public function test_complete_source_preserves_a_generic_selected_section(): void
    {
        $search = new HomepageCategorySearchPolicy();
        $result = $search->resolveDominantCategory([[
            'title' => 'Governor Signs New State Budget',
            'text' => str_repeat('The governor and Legislature approved government spending in the state budget. ', 4),
        ]], [
            ['name' => 'Trending', 'terms' => ['government', 'business', 'travel']],
            ['name' => 'Politics', 'terms' => $search->terms('Politics')],
        ], 'Trending');

        $this->assertFalse($result['reclassified']);
        $this->assertSame('Trending', $result['resolved_category']);
        $this->assertFalse($result['selected_category_supported']);
        $this->assertSame('generic_category_preserved', $result['reason']);
    }
}
