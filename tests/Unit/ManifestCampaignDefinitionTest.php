<?php

namespace Tests\Unit;

use hexa_package_article_campaigns\Contracts\ArticleDeliveryPort;
use hexa_package_article_campaigns\Contracts\ArticleGenerationPort;
use hexa_package_article_campaigns\Contracts\SourceDiscoveryPort;
use hexa_package_article_campaigns\Data\CampaignRunContext;
use hexa_package_article_campaigns\Data\DeliveryResult;
use hexa_package_article_campaigns\Data\GeneratedArticle;
use hexa_package_article_campaigns\Data\SourceBatch;
use hexa_package_article_campaigns\Discovery\HomepageCategoryPoolDefinition;
use hexa_package_article_campaigns\Discovery\HomepageCategorySearchPolicy;
use hexa_package_article_campaigns\Discovery\PublicationManifestMapper;
use hexa_package_article_campaigns\Orchestration\CampaignOrchestrator;
use hexa_package_article_campaigns\Policies\CampaignNegativeTopicMatcher;
use hexa_package_article_campaigns\Policies\CampaignSourceRelevancePolicy;
use hexa_package_article_campaigns\State\CampaignRunStateMachine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ManifestCampaignDefinitionTest extends TestCase
{
    public function test_unfamiliar_category_compiles_from_manifest_evidence_without_custom_code(): void
    {
        $definition = $this->map($this->manifest([
            $this->category(71, 'Oceanic Cartography', 'oceanic-cartography', 'Maps ocean currents and seafloor surveys'),
        ], 'Atlas Review', 'Reporting from scientific maps and field surveys.'));

        $this->assertTrue(HomepageCategoryPoolDefinition::isCurrentManifestDefinition($definition));
        $this->assertSame(71, $definition['categories'][0]['id']);
        $this->assertContains('oceanic cartography', $definition['categories'][0]['terms']);
        $this->assertContains('currents', $definition['categories'][0]['terms']);
        $this->assertNotEmpty($definition['categories'][0]['queries']);
    }

    public function test_broad_publication_gets_no_mandatory_niche_focus_but_first_party_specialist_identity_does(): void
    {
        $categories = [
            $this->category(10, 'Business', 'business'),
            $this->category(11, 'Fashion', 'fashion'),
            $this->category(12, 'Politics', 'politics'),
            $this->category(13, 'Travel', 'travel'),
        ];
        $broad = $this->map($this->manifest(
            $categories,
            'Daily Ledger',
            'Independent reporting across business, fashion, politics and travel.',
        ), ['name' => 'Celebrity Wealth Daily', 'topic' => 'celebrities, luxury and net worth']);
        $specialist = $this->map($this->manifest(
            [$this->category(91, 'Business Law', 'business-law')],
            'Court Ledger',
            'A law news publication covering courts, litigation and legal practice.',
        ));

        $this->assertArrayNotHasKey('publication_focus', $broad);
        $this->assertStringNotContainsString('celebrity', strtolower(implode(' ', $broad['categories'][0]['queries'])));
        $this->assertSame('manifest_identity', $specialist['publication_focus']['source']);
        $this->assertSame('headline', $specialist['publication_focus']['surface']);
    }

    public function test_current_definition_fingerprint_ignores_binding_metadata_but_rejects_policy_tampering(): void
    {
        $definition = $this->map($this->manifest([$this->category(10, 'Business', 'business')]));
        $stored = $definition + ['publish_site_id' => 44, 'scanned_at' => '2026-09-19T18:00:00Z'];

        $this->assertTrue(HomepageCategoryPoolDefinition::isCurrentManifestDefinition($stored));
        $stored['categories'][0]['terms'][] = 'unreviewed override';
        $this->assertFalse(HomepageCategoryPoolDefinition::isCurrentManifestDefinition($stored));
        $this->assertFalse(HomepageCategoryPoolDefinition::isUsableManifestDefinition($stored));

        $legacy = array_diff_key($definition, ['definition_version' => true, 'policy_version' => true]);
        $legacy['version'] = 1;
        $this->assertTrue(HomepageCategoryPoolDefinition::isUsableManifestDefinition($legacy));
        $this->assertFalse(HomepageCategoryPoolDefinition::isCurrentManifestDefinition($legacy));
    }

    public function test_complete_source_is_reclassified_before_lane_rejection_and_unrelated_companion_is_removed(): void
    {
        $definition = $this->map($this->manifest([
            $this->category(1496, 'Travel', 'travel'),
            $this->category(9542, 'Politics', 'politics'),
        ]));
        $sources = [
            [
                'title' => 'Governor and Legislature Clash Over State Budget and Public School Funds',
                'text' => str_repeat('The governor and Legislature debated government spending, public policy, state budget allocations and public funds. ', 4),
            ],
            [
                'title' => 'Airlines Add Hotels to Mediterranean Travel Packages',
                'text' => str_repeat('Travel operators said tourism demand supports airlines, hotels and beach destinations. ', 5),
            ],
        ];
        $policy = new CampaignSourceRelevancePolicy(new CampaignNegativeTopicMatcher());
        $decision = $policy->resolveAndFilterHomepageSources($sources, [
            'discovery_process' => HomepageCategoryPoolDefinition::TYPE,
            'forced_category' => 'Travel',
            'homepage_pool' => $definition,
        ]);

        $this->assertTrue($decision['reclassified']);
        $this->assertSame('Politics', $decision['resolved_category']);
        $this->assertSame(9542, $decision['resolved_category_id']);
        $this->assertCount(1, $decision['accepted_sources']);
        $this->assertCount(1, $decision['rejected_sources']);
        $this->assertSame('different_manifest_category', $decision['rejected_sources'][0]['reason']);
    }

    public function test_partial_collection_stops_before_generation_or_delivery(): void
    {
        $manifest = $this->manifest([$this->category(10, 'Business', 'business')]);
        $manifest['homepage']['collection_status'] = 'partial';
        // Exact SMP Publication Integration 2.0.9 warning-object schema.
        $manifest['homepage']['collection_warnings'] = [[
            'code' => 'query_scope_not_statically_resolved',
            'message' => 'No positive category restriction could be resolved from this query widget; runtime results may contain additional categories.',
            'elementor_id' => '50e0f07',
            'widget_type' => 'loop-grid',
            'template_id' => 0,
            'template_chain' => [],
            'context' => [],
        ], [
            'code' => 'unsupported_taxonomy_operator',
            'message' => 'A taxonomy clause uses an operator the manifest collector cannot interpret.',
            'elementor_id' => 'unsupported-operator',
            'widget_type' => 'posts',
            'template_id' => 0,
            'template_chain' => [],
            'context' => ['taxonomy' => 'category', 'operator' => 'XOR'],
        ]];
        $manifest = $this->fingerprint($manifest);
        $calls = (object) ['generation' => 0, 'delivery' => 0, 'error' => null];
        $mapper = $this->mapper();
        $orchestrator = new CampaignOrchestrator(
            new class($mapper, $manifest, $calls) implements SourceDiscoveryPort {
                public function __construct(
                    private PublicationManifestMapper $mapper,
                    private array $manifest,
                    private object $calls,
                ) {}

                public function discover(CampaignRunContext $context): SourceBatch
                {
                    try {
                        $this->mapper->map($this->manifest, 'https://publication.test/');
                    } catch (RuntimeException $exception) {
                        $this->calls->error = $exception->getMessage();
                        return new SourceBatch([], [['reason' => $exception->getMessage()]]);
                    }

                    return new SourceBatch([['title' => 'unexpected']]);
                }
            },
            new class($calls) implements ArticleGenerationPort {
                public function __construct(private object $calls) {}

                public function generate(CampaignRunContext $context, SourceBatch $sources): GeneratedArticle
                {
                    $this->calls->generation++;
                    throw new RuntimeException('Generation must not run.');
                }
            },
            new class($calls) implements ArticleDeliveryPort {
                public function __construct(private object $calls) {}

                public function deliver(CampaignRunContext $context, GeneratedArticle $article): DeliveryResult
                {
                    $this->calls->delivery++;
                    throw new RuntimeException('Delivery must not run.');
                }
            },
            new CampaignRunStateMachine(),
        );

        $result = $orchestrator->run(new CampaignRunContext('campaign', 'publication'));

        $this->assertFalse($result->successful);
        $this->assertSame('source_pool_exhausted', $result->failureCode);
        $this->assertSame(0, $calls->generation);
        $this->assertSame(0, $calls->delivery);
        $this->assertStringContainsString('collection is partial', (string) $calls->error);
        $this->assertStringContainsString('query_scope_not_statically_resolved', (string) $calls->error);
        $this->assertStringContainsString('"widget_type":"loop-grid"', (string) $calls->error);
        $this->assertStringContainsString('"operator":"XOR"', (string) $calls->error);
        $this->assertStringContainsString('No AI was called', (string) $calls->error);
    }

    public function test_resolved_query_widget_without_category_terms_does_not_invalidate_other_evidence(): void
    {
        $manifest = $this->manifest([
            $this->category(10, 'Business', 'business'),
        ]);
        $manifest['homepage']['query_widgets'][] = [
            'elementor_id' => 'resolved-empty-widget',
            'widget_type' => 'loop-grid',
            'section' => 'Press Releases',
            'categories' => [],
            'category_source' => 'native_query_results',
            'native_query' => [
                'attempted' => true,
                'resolved' => true,
                'provider' => 'elementor_pro',
                'post_count' => 24,
                'result_limit' => 50,
            ],
            'warnings' => [],
        ];
        $manifest['homepage']['query_widgets'][] = [
            'elementor_id' => 'resolved-empty-query-builder-widget',
            'widget_type' => 'jet-listing-grid',
            'section' => 'Press Releases',
            'categories' => [],
            'category_source' => 'native_query_results',
            'native_query' => [
                'attempted' => true,
                'resolved' => true,
                'provider' => 'jet_engine_query_builder',
                'post_count' => 10,
                'result_limit' => 50,
            ],
            'warnings' => [],
        ];

        $definition = $this->map($manifest);

        $this->assertSame([10], array_column($definition['categories'], 'id'));
        $this->assertSame('Business', $definition['categories'][0]['name']);

        $manifest['homepage']['query_widgets'][2]['native_query']['resolved'] = false;
        try {
            $this->map($manifest);
            $this->fail('An unresolved empty-category widget must not be ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'an Elementor query widget is incomplete or incompatible',
                $exception->getMessage(),
            );
        }
    }

    /** @param array<string, mixed> $editorial */
    private function map(array $manifest, array $editorial = []): array
    {
        return $this->mapper()->map(
            $this->fingerprint($manifest),
            'https://publication.test/',
            'https://publication.test/wp-json/smpi/v1/publication-manifest',
            $editorial,
        );
    }

    private function mapper(): PublicationManifestMapper
    {
        return new PublicationManifestMapper(new HomepageCategorySearchPolicy());
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function manifest(
        array $categories,
        string $name = 'Publication Test',
        string $description = 'Independent publication reporting across its homepage sections.',
    ): array {
        return [
            'api_version' => 1,
            'plugin' => [
                'name' => 'SMP Publication Integration',
                'slug' => 'smp-publication-integration',
                'namespace' => 'smpi/v1',
                'version' => '2.0.6',
            ],
            'publication' => [
                'name' => $name,
                'url' => 'https://publication.test/',
                'description' => $description,
                'visibility' => 'public',
            ],
            'homepage' => [
                'url' => 'https://publication.test/',
                'title' => $name,
                'builder' => 'elementor',
                'content_source' => '_elementor_data',
                'page_id' => 10,
                'sections' => [],
                'collection_status' => 'complete',
                'collection_warnings' => [],
                'categories' => $categories,
                'campaign_categories' => $categories,
                'query_widgets' => array_map(static fn (array $category): array => [
                    'elementor_id' => $category['sources'][0]['elementor_id'],
                    'widget_type' => $category['sources'][0]['widget_type'],
                    'categories' => [array_diff_key($category, ['sources' => true])],
                ], $categories),
            ],
            'taxonomies' => [
                'categories' => array_map(
                    static fn (array $category): array => array_diff_key($category, ['sources' => true]),
                    $categories,
                ),
            ],
            'publishing_requirements' => [
                'default_category_id' => 999,
                'taxonomies' => ['category', 'post_tag'],
            ],
            'schema' => ['article_type_taxonomy' => 'category'],
            'delivery_capabilities' => [
                'rest_api' => true,
                'categories' => true,
                'public_manifest' => [
                    'namespace' => 'smpi/v1',
                    'route' => '/publication-manifest',
                    'version' => 1,
                ],
            ],
            'meta' => ['generated_at' => '2026-09-19T18:00:00Z'],
        ];
    }

    /** @return array<string, mixed> */
    private function category(int $id, string $name, string $slug, string $description = ''): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'url' => 'https://publication.test/category/'.$slug.'/',
            'sources' => [[
                'elementor_id' => 'widget-'.$id,
                'widget_type' => 'posts',
                'section' => $name,
            ]],
            'campaign_policy' => ['status' => 'eligible'],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function fingerprint(array $manifest): array
    {
        unset($manifest['meta']['fingerprint']);
        $payload = $manifest;
        unset($payload['meta']['generated_at']);
        $manifest['meta']['fingerprint'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $manifest;
    }
}
