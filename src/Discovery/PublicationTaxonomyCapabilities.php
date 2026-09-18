<?php

namespace hexa_package_article_campaigns\Discovery;

/** Normalize manifest-declared WordPress taxonomy support for downstream delivery. */
final class PublicationTaxonomyCapabilities
{
    /** @return array{registered: array<int, string>, article_type_taxonomy: string|null} */
    public static function fromManifest(array $publishingRequirements, array $schema): array
    {
        $registered = [];
        foreach ((array) ($publishingRequirements['taxonomies'] ?? []) as $taxonomy) {
            $taxonomy = strtolower(trim((string) $taxonomy));
            if ($taxonomy === '' || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $taxonomy) !== 1) {
                continue;
            }
            $registered[] = $taxonomy;
        }
        $registered = array_values(array_unique($registered));
        sort($registered);

        $articleTypeTaxonomy = strtolower(trim((string) ($schema['article_type_taxonomy'] ?? '')));
        if ($articleTypeTaxonomy === '' || ! in_array($articleTypeTaxonomy, $registered, true)) {
            $articleTypeTaxonomy = null;
        }

        return [
            'registered' => $registered,
            'article_type_taxonomy' => $articleTypeTaxonomy,
        ];
    }

    /** Null means that a legacy campaign definition did not record capabilities. */
    public static function supports(array $capabilities, string $taxonomy): ?bool
    {
        if (! array_key_exists('registered', $capabilities) || ! is_array($capabilities['registered'])) {
            return null;
        }

        $taxonomy = strtolower(trim($taxonomy));

        return $taxonomy !== '' && in_array($taxonomy, array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $capabilities['registered'],
        ), true);
    }
}
