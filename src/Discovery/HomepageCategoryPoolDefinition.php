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
            'article_preset_values' => array_replace((array) ($resolved['article_preset_values'] ?? []), [
                'ai_prompt' => 'Write a clear, factual article about the selected source subject in its assigned homepage category. News sections cover events; Knowledge Base, Resources, Guides and Tutorials explain useful topics without inventing a new event. Use concrete evidence from the complete source. Do not import another category, financial thesis, celebrity angle, or topic restriction from a legacy template.',
                'headline_rules' => 'Use a natural, specific headline naming the actual subject and supported development. The headline must fit the assigned category; do not force financial or celebrity language.',
            ]),
            'ai_instructions' => self::instructions($definition),
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

    private static function instructions(array $definition): string
    {
        return 'HOMEPAGE CATEGORY POOL: Write about the selected source subject within its assigned homepage category. Knowledge Base, Resources, Guides and Tutorials support useful evergreen explanations; do not force them into breaking news. Older or undated guides are not evidence of a new event, and dated prices, policies or product details must not be presented as current without support. The writing template controls style and structure; old campaign topic restrictions do not apply in this mode. Keep the article focused on this subject. Use only facts supported by the supplied source material. Preserve event timing: an upcoming, planned or scheduled event must never be reported as already held, completed or observed. Do not calculate elapsed years from relative dates such as this July. Do not add unrelated celebrity, investment, historical or promotional angles to satisfy old campaign examples or internal-link targets. Do not infer a person\'s gender, a missing month or year, financial figures, or unsupported causal claims. Omit uncertain details instead of inventing them. Use neutral names or pronouns when the source does not establish gender. Do not assign categories outside the approved homepage category list.'
            .' Preserve exact numeric values: do not round 79 percent to nearly 80 percent, calculate new figures, or change views into viewers. Preserve attribution: never transfer a quote, experience, statistic or claim from one person or group to another. Do not combine separate interviewees into one account or generalize one company\'s figures to an industry.'
            .' A survey supports only comparisons actually measured and reported; do not infer which interest is greater without comparable results. Distinguish what the source establishes from recommendations, predictions and uncertainty. Attribute rankings, advertised capabilities and opinion-column interpretations to their source, not to independent testing or findings by this publication. Do not invent competitor timelines, universal superiority, guaranteed project delivery, eliminated compliance risk or financial returns from promotional claims.'
            .' Prefer the shortest complete article within the configured word range. Do not pad to the maximum with repetitive conclusions, imagined motivations, generic significance or unsupported predictions. Before returning, compare every quote, number, named actor and timing claim against its exact source passage.'
            .(! empty($definition['publication_focus']['label'])
                ? ' Publication focus established by the homepage: '.$definition['publication_focus']['label'].'. Keep this focus central and explicit in the article opening; it must be supported by the source, never inferred from a name or invented to make an unrelated story fit.'
                : '');
    }
}
