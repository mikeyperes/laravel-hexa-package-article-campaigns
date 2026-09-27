<?php

namespace hexa_package_article_campaigns\Gates;

/**
 * The one declared list of campaign gates.
 *
 * Every gate states where it runs, whether it runs before or after paid
 * generation, and what a failure does:
 * - block:  stop the article (it is not published);
 * - repair: fix automatically, re-check, and block only if the fix fails;
 * - warn:   record the finding and continue.
 *
 * Applications may override an action per gate (for example from config);
 * an unknown override or gate falls back to block.
 */
final class CampaignGateRegistry
{
    public const BLOCK = 'block';

    public const REPAIR = 'repair';

    public const WARN = 'warn';

    public const PRE_SPEND = 'pre_spend';

    public const POST_SPEND = 'post_spend';

    /** @var array<string, array{label: string, stage: string, cost_position: string, action: string}> */
    private const GATES = [
        // Before any paid generation.
        'source_classification' => ['label' => 'Source category and publication fit (model classification)', 'stage' => 'extraction', 'cost_position' => self::PRE_SPEND, 'action' => self::BLOCK],
        'source_content' => ['label' => 'Complete article text (no paywall, video or listing page)', 'stage' => 'extraction', 'cost_position' => self::PRE_SPEND, 'action' => self::BLOCK],
        'source_sufficiency' => ['label' => 'Enough relevant source text for the length target', 'stage' => 'extraction', 'cost_position' => self::PRE_SPEND, 'action' => self::BLOCK],
        'negative_topic' => ['label' => 'Campaign negative topics', 'stage' => 'extraction', 'cost_position' => self::PRE_SPEND, 'action' => self::BLOCK],
        'repeat_story' => ['label' => 'Same story already published', 'stage' => 'discovery', 'cost_position' => self::PRE_SPEND, 'action' => self::BLOCK],
        // After paid generation, before WordPress.
        'core_content_integrity' => ['label' => 'Core content integrity', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'source_provenance' => ['label' => 'Source provenance', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'complete_excerpt' => ['label' => 'Complete excerpt', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'publication_fit' => ['label' => 'Publication fit', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'single_narrative' => ['label' => 'Coherent single narrative', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'repetition' => ['label' => 'Repetition detection', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'source_citations' => ['label' => 'Source citation integrity', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'featured_image_relevance' => ['label' => 'Featured image relevance', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'inline_image_relevance' => ['label' => 'Inline image relevance', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
        'inline_image_coverage' => ['label' => 'Inline image coverage', 'stage' => 'quality', 'cost_position' => self::POST_SPEND, 'action' => self::WARN],
        // At the WordPress boundary.
        'secret_leak' => ['label' => 'No credential in outgoing text', 'stage' => 'delivery', 'cost_position' => self::POST_SPEND, 'action' => self::BLOCK],
    ];

    /** @param array<string, string> $overrides gate id => action */
    public function __construct(private array $overrides = []) {}

    /** @return array<string, array{label: string, stage: string, cost_position: string, action: string}> */
    public function all(): array
    {
        $gates = self::GATES;
        foreach ($gates as $id => $gate) {
            $gates[$id]['action'] = $this->action($id);
        }

        return $gates;
    }

    public function has(string $id): bool
    {
        return isset(self::GATES[$id]);
    }

    public function action(string $id): string
    {
        $override = strtolower(trim((string) ($this->overrides[$id] ?? '')));
        if (in_array($override, [self::BLOCK, self::REPAIR, self::WARN], true)) {
            return $override;
        }

        return self::GATES[$id]['action'] ?? self::BLOCK;
    }

    /** A failed gate stops the article unless its action is warn. */
    public function blocks(string $id): bool
    {
        return $this->action($id) !== self::WARN;
    }
}
