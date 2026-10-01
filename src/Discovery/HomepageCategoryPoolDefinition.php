<?php

namespace hexa_package_article_campaigns\Discovery;

use hexa_package_article_campaigns\Data\CampaignDefinition;
use RuntimeException;
use Throwable;

/** Stable, application-neutral settings for a manifest-backed campaign pool. */
class HomepageCategoryPoolDefinition
{
    public const TYPE = 'homepage_category_pool';

    public const RETRIEVAL_METHOD = 'smp_publication_manifest';

    public const MANIFEST_API_VERSION = 1;

    public static function applySettings(array $resolved, array $definition): array
    {
        if (! self::isUsableManifestDefinition($definition)) {
            throw new RuntimeException('Campaign homepage pool is missing a valid SMP publication manifest definition. Scan the manifest before running. No AI was called.');
        }

        $categories = array_column($definition['categories'], 'name');

        return array_replace($resolved, [
            'discovery_process' => self::TYPE,
            'homepage_pool' => $definition,
            'coverage_focus' => is_array($definition['coverage_focus'] ?? null) ? $definition['coverage_focus'] : null,
            'taxonomy_capabilities' => (array) ($definition['taxonomy_capabilities'] ?? []),
            'delivery_capabilities' => (array) ($definition['delivery_capabilities'] ?? []),
            'allowed_categories' => $categories,
            'category_rotation_enabled' => true,
            'category_rotation_pool' => $categories,
            'category_rotation_queries' => [],
            'paid_search_primary_source_fallback_enabled' => false,
            'search_online_for_additional_context' => false,
            'online_search_model_primary' => null,
            'online_search_model_fallback' => null,
            'scrape_ai_model_primary' => null,
            'scrape_ai_model_fallback' => null,
            'search_terms' => $categories,
            'topic' => implode(', ', $categories),
            // CRITICAL — see BUGLOG.md CAMPAIGN-BUG-141. The writing rules live in
            // the application's article contract. A pool campaign's legacy topic
            // prompt, headline rules and instructions never reach the writer.
            'article_preset_values' => array_replace((array) ($resolved['article_preset_values'] ?? []), [
                'ai_prompt' => '',
                'headline_rules' => '',
            ]),
            'operator_instructions' => '',
            'ai_instructions' => '',
        ]);
    }

    public static function isUsableManifestDefinition(array $definition): bool
    {
        // Every versioned definition must pass the current schema. Falling an
        // older version through the legacy branch can revive unsafe compiled
        // queries after category semantics change.
        if (array_key_exists('definition_version', $definition)) {
            return self::isCurrentManifestDefinition($definition);
        }

        return ($definition['retrieval_method'] ?? null) === self::RETRIEVAL_METHOD
            && ($definition['manifest_api_version'] ?? null) === self::MANIFEST_API_VERSION
            && is_string($definition['manifest_plugin_version'] ?? null)
            && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', (string) $definition['manifest_plugin_version']) === 1
            && version_compare((string) $definition['manifest_plugin_version'], '2.0.5', '>=')
            && preg_match('/^[a-f0-9]{64}$/', (string) ($definition['manifest_fingerprint'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/', (string) ($definition['fingerprint'] ?? '')) === 1
            && ! empty($definition['categories']);
    }

    /** A strict definition for new setup, activation, refresh and migration paths. */
    public static function isCurrentManifestDefinition(array $definition): bool
    {
        try {
            CampaignDefinition::fromArray($definition);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function definition(array $definition): CampaignDefinition
    {
        return CampaignDefinition::fromArray($definition);
    }
}
