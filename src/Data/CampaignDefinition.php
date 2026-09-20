<?php

namespace hexa_package_article_campaigns\Data;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable, versioned policy input for a manifest-backed campaign.
 *
 * This object contains only validated first-party publication evidence and
 * deterministic compiled policy. Campaign names, old topics, model prompts,
 * database rows and application adapters are intentionally outside it.
 */
final readonly class CampaignDefinition
{
    public const DEFINITION_VERSION = 3;

    public const POLICY_VERSION = 'manifest-homepage-v3';

    /**
     * @param array<string, mixed> $taxonomyCapabilities
     * @param array<int, array<string, mixed>> $categories
     * @param array<string, mixed>|null $publicationFocus
     */
    private function __construct(
        public string $retrievalMethod,
        public string $manifestUrl,
        public int $manifestApiVersion,
        public string $manifestPluginVersion,
        public string $manifestFingerprint,
        public string $homepageUrl,
        public array $taxonomyCapabilities,
        public array $categories,
        public ?array $publicationFocus,
        public array $deliveryCapabilities,
        public string $fingerprint,
    ) {}

    /**
     * @param array<string, mixed> $taxonomyCapabilities
     * @param array<int, array<string, mixed>> $categories
     * @param array<string, mixed>|null $publicationFocus
     */
    public static function compile(
        string $retrievalMethod,
        string $manifestUrl,
        int $manifestApiVersion,
        string $manifestPluginVersion,
        string $manifestFingerprint,
        string $homepageUrl,
        array $taxonomyCapabilities,
        array $categories,
        ?array $publicationFocus = null,
        array $deliveryCapabilities = [],
    ): self {
        $payload = self::payload(
            $retrievalMethod,
            $manifestUrl,
            $manifestApiVersion,
            $manifestPluginVersion,
            $manifestFingerprint,
            $homepageUrl,
            $taxonomyCapabilities,
            $categories,
            $publicationFocus,
            $deliveryCapabilities,
        );
        self::validate($payload);

        return new self(
            $retrievalMethod,
            $manifestUrl,
            $manifestApiVersion,
            $manifestPluginVersion,
            $manifestFingerprint,
            $homepageUrl,
            $taxonomyCapabilities,
            $categories,
            $publicationFocus,
            $deliveryCapabilities,
            self::hash($payload),
        );
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        self::validate($definition);
        $payload = self::payload(
            (string) $definition['retrieval_method'],
            (string) $definition['manifest_url'],
            (int) $definition['manifest_api_version'],
            (string) $definition['manifest_plugin_version'],
            (string) $definition['manifest_fingerprint'],
            (string) $definition['homepage_url'],
            (array) $definition['taxonomy_capabilities'],
            array_values((array) $definition['categories']),
            isset($definition['publication_focus']) ? (array) $definition['publication_focus'] : null,
            (array) ($definition['delivery_capabilities'] ?? []),
        );
        $expected = self::hash($payload);
        $actual = (string) ($definition['fingerprint'] ?? '');
        if (! hash_equals($expected, $actual)) {
            throw new InvalidArgumentException('Campaign definition fingerprint does not match its policy input.');
        }

        return new self(
            (string) $definition['retrieval_method'],
            (string) $definition['manifest_url'],
            (int) $definition['manifest_api_version'],
            (string) $definition['manifest_plugin_version'],
            (string) $definition['manifest_fingerprint'],
            (string) $definition['homepage_url'],
            (array) $definition['taxonomy_capabilities'],
            array_values((array) $definition['categories']),
            isset($definition['publication_focus']) ? (array) $definition['publication_focus'] : null,
            (array) ($definition['delivery_capabilities'] ?? []),
            $actual,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::payload(
            $this->retrievalMethod,
            $this->manifestUrl,
            $this->manifestApiVersion,
            $this->manifestPluginVersion,
            $this->manifestFingerprint,
            $this->homepageUrl,
            $this->taxonomyCapabilities,
            $this->categories,
            $this->publicationFocus,
            $this->deliveryCapabilities,
        ) + ['fingerprint' => $this->fingerprint];
    }

    /** @return array<string, mixed> */
    private static function payload(
        string $retrievalMethod,
        string $manifestUrl,
        int $manifestApiVersion,
        string $manifestPluginVersion,
        string $manifestFingerprint,
        string $homepageUrl,
        array $taxonomyCapabilities,
        array $categories,
        ?array $publicationFocus,
        array $deliveryCapabilities,
    ): array {
        $payload = array_filter([
            'definition_version' => self::DEFINITION_VERSION,
            'policy_version' => self::POLICY_VERSION,
            'retrieval_method' => $retrievalMethod,
            'manifest_url' => $manifestUrl,
            'manifest_api_version' => $manifestApiVersion,
            'manifest_plugin_version' => $manifestPluginVersion,
            'manifest_fingerprint' => $manifestFingerprint,
            'homepage_url' => $homepageUrl,
            'taxonomy_capabilities' => $taxonomyCapabilities,
            'categories' => array_values($categories),
            'publication_focus' => $publicationFocus,
        ], static fn (mixed $value, string $key): bool => $key !== 'publication_focus' || $value !== null, ARRAY_FILTER_USE_BOTH);

        if ($deliveryCapabilities !== []) {
            $payload['delivery_capabilities'] = $deliveryCapabilities;
        }

        return $payload;
    }

    /** @param array<string, mixed> $definition */
    private static function validate(array $definition): void
    {
        if (($definition['definition_version'] ?? null) !== self::DEFINITION_VERSION
            || ($definition['policy_version'] ?? null) !== self::POLICY_VERSION
            || ($definition['retrieval_method'] ?? null) !== 'smp_publication_manifest'
            || ($definition['manifest_api_version'] ?? null) !== 1
            || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', (string) ($definition['manifest_plugin_version'] ?? '')) !== 1
            || ! version_compare((string) $definition['manifest_plugin_version'], '2.0.5', '>=')
            || preg_match('/^[a-f0-9]{64}$/', (string) ($definition['manifest_fingerprint'] ?? '')) !== 1
            || ! filter_var((string) ($definition['manifest_url'] ?? ''), FILTER_VALIDATE_URL)
            || ! filter_var((string) ($definition['homepage_url'] ?? ''), FILTER_VALIDATE_URL)
            || ! is_array($definition['taxonomy_capabilities'] ?? null)
            || ! is_array($definition['categories'] ?? null)
            || $definition['categories'] === []) {
            throw new InvalidArgumentException('Campaign definition is incomplete or uses an unsupported schema.');
        }

        if (array_key_exists('delivery_capabilities', $definition)
            && (! is_array($definition['delivery_capabilities'])
                || ! is_bool($definition['delivery_capabilities']['article_audio'] ?? null))) {
            throw new InvalidArgumentException('Campaign definition contains invalid delivery capabilities.');
        }

        $ids = [];
        foreach ($definition['categories'] as $category) {
            $id = is_array($category) ? ($category['id'] ?? null) : null;
            if (! is_int($id) || $id < 1 || isset($ids[$id])
                || trim((string) ($category['name'] ?? '')) === ''
                || ! is_array($category['semantic_context'] ?? null)
                || ! in_array(($category['semantic_context']['source'] ?? null), [
                    'category_vocabulary',
                    'parent_category_path',
                    'manifest_evidence',
                ], true)
                || trim((string) ($category['semantic_context']['subject'] ?? '')) === ''
                || ! is_array($category['terms'] ?? null) || $category['terms'] === []
                || ! is_array($category['queries'] ?? null) || $category['queries'] === []) {
                throw new InvalidArgumentException('Campaign definition contains an invalid category lane.');
            }
            $ids[$id] = true;
        }

        if (isset($definition['fingerprint'])
            && preg_match('/^[a-f0-9]{64}$/', (string) $definition['fingerprint']) !== 1) {
            throw new InvalidArgumentException('Campaign definition fingerprint is invalid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Campaign definition cannot be fingerprinted.', previous: $exception);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
