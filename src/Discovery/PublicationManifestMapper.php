<?php

namespace hexa_package_article_campaigns\Discovery;

use JsonException;
use RuntimeException;

/**
 * Validate an already-fetched SMP publication manifest and map it into an
 * application-neutral homepage-category campaign definition.
 */
final class PublicationManifestMapper
{
    public const MANIFEST_PATH = '/wp-json/smpi/v1/publication-manifest';

    private const API_VERSION = 1;

    private const MINIMUM_PLUGIN_VERSION = '2.0.5';

    private const MAXIMUM_CATEGORIES = 60;

    public function __construct(
        private HomepageCategorySearchPolicy $searchPolicy,
    ) {}

    public function manifestUrl(string $siteUrl): string
    {
        $canonical = $this->canonicalSiteUrl($siteUrl);
        if ($canonical === null) {
            throw $this->failure('the connected website URL is invalid');
        }

        return rtrim($canonical, '/').self::MANIFEST_PATH;
    }

    /**
     * @param array{name?: string, topic?: string} $campaignEditorial The consuming campaign's own
     *        name and topic, used only when the manifest identity establishes no focus or subject.
     */
    public function map(array $manifest, string $siteUrl, ?string $effectiveUrl = null, array $campaignEditorial = []): array
    {
        $siteUrl = $this->canonicalSiteUrl($siteUrl);
        if ($siteUrl === null) {
            throw $this->failure('the connected website URL is invalid');
        }
        $manifestUrl = rtrim($siteUrl, '/').self::MANIFEST_PATH;
        if (! $this->sameEndpoint($effectiveUrl ?: $manifestUrl, $manifestUrl)) {
            throw $this->failure('the endpoint redirected outside the connected website manifest route');
        }

        $this->validateManifestIdentity($manifest);

        $publication = $this->requiredMap($manifest, 'publication');
        $homepage = $this->requiredMap($manifest, 'homepage');
        $taxonomies = $this->requiredMap($manifest, 'taxonomies');
        $delivery = $this->requiredMap($manifest, 'delivery_capabilities');
        $meta = $this->requiredMap($manifest, 'meta');

        $publicationUrl = (string) ($publication['url'] ?? '');
        $homepageUrl = (string) ($homepage['url'] ?? '');
        $publicationName = trim((string) ($publication['name'] ?? ''));
        if ($publicationName === '' || mb_strlen($publicationName) > 191
            || preg_match('/[\x00-\x1F\x7F]/u', $publicationName)) {
            throw $this->failure('the publication identity is incomplete');
        }
        if (! $this->sameSiteRoot($publicationUrl, $siteUrl) || ! $this->sameSiteRoot($homepageUrl, $siteUrl)) {
            throw $this->failure('the manifest identifies a different website');
        }
        if (($publication['visibility'] ?? null) !== 'public') {
            throw $this->failure('the manifest does not identify a public publication');
        }
        if (($homepage['builder'] ?? null) !== 'elementor' || ($homepage['content_source'] ?? null) !== '_elementor_data') {
            throw $this->failure('the homepage is not backed by Elementor publication data');
        }
        if (! is_int($homepage['page_id'] ?? null) || (int) $homepage['page_id'] < 1) {
            throw $this->failure('the Elementor homepage page ID is missing');
        }
        if (! is_array($homepage['sections'] ?? null) || ! is_array($homepage['query_widgets'] ?? null)) {
            throw $this->failure('the Elementor homepage structure is incomplete');
        }

        $manifestCapability = $delivery['public_manifest'] ?? null;
        if (($delivery['rest_api'] ?? null) !== true
            || ($delivery['categories'] ?? null) !== true
            || ! is_array($manifestCapability)
            || ($manifestCapability['namespace'] ?? null) !== 'smpi/v1'
            || ($manifestCapability['route'] ?? null) !== '/publication-manifest'
            || ($manifestCapability['version'] ?? null) !== self::API_VERSION) {
            throw $this->failure('the manifest delivery capability is incompatible');
        }

        $manifestFingerprint = (string) ($meta['fingerprint'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $manifestFingerprint)) {
            throw $this->failure('the manifest fingerprint is missing or invalid');
        }
        if (! $this->fingerprintMatches($manifest, $manifestFingerprint)) {
            throw $this->failure('the manifest fingerprint does not match its payload');
        }

        $homepageCategories = $homepage['categories'] ?? null;
        $campaignCategories = $homepage['campaign_categories'] ?? null;
        if (! is_array($homepageCategories) || ! is_array($campaignCategories)) {
            throw $this->failure('the homepage category collections are missing');
        }
        if ($homepageCategories === []) {
            throw $this->failure('the Elementor homepage contains no publication categories');
        }
        if (count($homepageCategories) > self::MAXIMUM_CATEGORIES) {
            throw $this->failure('the Elementor homepage exposes more than '.self::MAXIMUM_CATEGORIES.' categories');
        }

        $homepageIndex = $this->categoryIndex($homepageCategories, $siteUrl, true);
        $widgetEvidence = $this->queryWidgetEvidence($homepage['query_widgets'], $siteUrl);
        $widgetCategoryIds = $widgetEvidence['category_ids'];
        $homepageCategoryIds = array_keys($homepageIndex);
        sort($widgetCategoryIds);
        sort($homepageCategoryIds);
        if ($widgetCategoryIds !== $homepageCategoryIds) {
            throw $this->failure('the homepage category catalog does not match its Elementor query widgets');
        }
        if ($campaignCategories === []) {
            $statuses = array_unique(array_column($homepageIndex, 'policy_status'));
            $reason = count(array_diff($statuses, ['reserved', 'excluded'])) === 0
                ? 'the Elementor homepage contains only reserved or excluded categories'
                : 'the Elementor homepage contains no eligible campaign categories';
            throw $this->failure($reason);
        }
        if (count($campaignCategories) > self::MAXIMUM_CATEGORIES) {
            throw $this->failure('the manifest exposes more than '.self::MAXIMUM_CATEGORIES.' eligible campaign categories');
        }

        $campaignIndex = $this->categoryIndex($campaignCategories, $siteUrl, true, 'eligible');
        $eligibleIds = array_keys(array_filter(
            $homepageIndex,
            static fn (array $category): bool => $category['policy_status'] === 'eligible',
        ));
        $campaignIds = array_keys($campaignIndex);
        sort($eligibleIds);
        sort($campaignIds);
        if ($campaignIds !== $eligibleIds) {
            throw $this->failure('the eligible campaign categories do not match the homepage category policy');
        }

        foreach ($campaignIndex as $id => $category) {
            $homepageCategory = $homepageIndex[$id] ?? null;
            if (! is_array($homepageCategory)
                || $homepageCategory['name'] !== $category['name']
                || $homepageCategory['slug'] !== $category['slug']
                || $this->sourceKeys($homepageCategory) !== $this->sourceKeys($category)) {
                throw $this->failure('an eligible campaign category does not match the homepage category catalog');
            }
            foreach ($category['homepage_evidence']['sources'] as $source) {
                $key = $id.'|'.$source['elementor_id'].'|'.$source['widget_type'];
                if (! isset($widgetEvidence['sources'][$key])) {
                    throw $this->failure('an eligible campaign category has unmatched Elementor query-widget evidence');
                }
            }
        }

        $taxonomyCategories = $taxonomies['categories'] ?? null;
        if (! is_array($taxonomyCategories)) {
            throw $this->failure('the WordPress category catalog is missing');
        }
        $taxonomyIndex = $this->categoryIndex($taxonomyCategories, $siteUrl, false);
        foreach ($campaignIndex as $id => $category) {
            $taxonomyCategory = $taxonomyIndex[$id] ?? null;
            if (! is_array($taxonomyCategory)
                || $taxonomyCategory['policy_status'] !== 'eligible'
                || $taxonomyCategory['name'] !== $category['name']
                || $taxonomyCategory['slug'] !== $category['slug']) {
                throw $this->failure('an eligible homepage category does not match the WordPress category catalog');
            }
        }

        $publishingRequirements = $this->requiredMap($manifest, 'publishing_requirements');
        $defaultCategoryId = (int) ($publishingRequirements['default_category_id'] ?? 0);
        if ($defaultCategoryId > 0 && isset($campaignIndex[$defaultCategoryId])) {
            throw $this->failure('the WordPress default category was incorrectly marked campaign eligible');
        }

        $identity = trim(implode(' ', [
            $publicationName,
            (string) ($publication['description'] ?? ''),
            (string) ($homepage['title'] ?? ''),
        ]));
        $focus = $this->searchPolicy->publicationFocus($identity);
        // CRITICAL — see laravel-hexa-app-publish BUGLOG.md CAMPAIGN-BUG-006. A manifest
        // identity is often only a name and slogan; without this fallback the pool ran with
        // no focus, which disabled the focus gate and focus-prefixed discovery.
        $editorialIdentity = trim(implode(' ', [
            (string) ($campaignEditorial['name'] ?? ''),
            (string) ($campaignEditorial['topic'] ?? ''),
        ]));
        if ($focus === null && $editorialIdentity !== '') {
            $focus = $this->searchPolicy->publicationFocus($editorialIdentity);
            if ($focus !== null) {
                $focus['source'] = 'campaign_editorial';
            }
        }
        $categories = $this->buildSearchCategories(
            array_values($campaignIndex),
            $identity,
            $focus,
            (string) ($campaignEditorial['topic'] ?? ''),
        );

        $definition = [
            'version' => 1,
            'retrieval_method' => HomepageCategoryPoolDefinition::RETRIEVAL_METHOD,
            'manifest_url' => $manifestUrl,
            'manifest_api_version' => self::API_VERSION,
            'manifest_plugin_version' => (string) data_get($manifest, 'plugin.version'),
            'manifest_fingerprint' => $manifestFingerprint,
            'homepage_url' => $homepageUrl,
            'categories' => $categories,
        ];
        if ($focus !== null) {
            $definition['publication_focus'] = $focus;
        }

        $definition['fingerprint'] = hash('sha256', json_encode([
            $definition['homepage_url'],
            $definition['categories'],
            $definition['publication_focus'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return $definition;
    }

    private function validateManifestIdentity(array $manifest): void
    {
        $allowedRoots = [
            'api_version', 'plugin', 'publication', 'homepage', 'taxonomies', 'recent_content',
            'authors', 'post_types', 'publishing_requirements', 'media', 'seo', 'schema',
            'delivery_capabilities', 'meta',
        ];
        if (array_diff(array_keys($manifest), $allowedRoots) !== []) {
            throw $this->failure('the manifest contains unsupported root fields');
        }
        if (($manifest['api_version'] ?? null) !== self::API_VERSION) {
            throw $this->failure('the manifest API version is incompatible');
        }

        $plugin = $this->requiredMap($manifest, 'plugin');
        $version = (string) ($plugin['version'] ?? '');
        if (($plugin['name'] ?? null) !== 'SMP Publication Integration'
            || ($plugin['slug'] ?? null) !== 'smp-publication-integration'
            || ($plugin['namespace'] ?? null) !== 'smpi/v1'
            || $version === ''
            || ! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)
            || ! version_compare($version, self::MINIMUM_PLUGIN_VERSION, '>=')) {
            throw $this->failure('the SMP Publication Integration identity or version is incompatible');
        }
    }

    /**
     * @param array<int, mixed> $records
     * @return array<int, array<string, mixed>>
     */
    private function categoryIndex(array $records, string $siteUrl, bool $requireSources, ?string $requiredStatus = null): array
    {
        $index = [];
        foreach ($records as $record) {
            if (! is_array($record) || array_is_list($record)) {
                throw $this->failure('a category record is malformed');
            }
            $id = $record['id'] ?? null;
            $name = trim((string) ($record['name'] ?? ''));
            $slug = trim((string) ($record['slug'] ?? ''));
            $url = trim((string) ($record['url'] ?? ''));
            $policy = $record['campaign_policy'] ?? null;
            $status = is_array($policy) ? (string) ($policy['status'] ?? '') : '';

            if (! is_int($id) || $id < 1 || $name === '' || mb_strlen($name) > 191
                || preg_match('/[\x00-\x1F\x7F]/u', $name)
                || $slug === '' || mb_strlen($slug) > 200
                || preg_match('/[\x00-\x20\x7F\/\\?#]/u', $slug)
                || ! in_array($status, ['eligible', 'reserved', 'excluded'], true)
                || ($requiredStatus !== null && $status !== $requiredStatus)
                || ! $this->belongsToSite($url, $siteUrl)) {
                throw $this->failure('a category record is incomplete or incompatible');
            }
            if (isset($index[$id])) {
                throw $this->failure('the manifest contains a duplicate category ID');
            }
            if ($status === 'eligible' && in_array(strtolower($slug), ['uncategorized', 'uncategorised', 'digital-magazine'], true)) {
                throw $this->failure('a reserved WordPress category was incorrectly marked campaign eligible');
            }

            $sources = [];
            if ($requireSources) {
                if (! is_array($record['sources'] ?? null) || $record['sources'] === []) {
                    throw $this->failure('an Elementor homepage category has no query-widget evidence');
                }
                foreach ($record['sources'] as $source) {
                    if (! is_array($source) || array_is_list($source)) {
                        throw $this->failure('an Elementor query-widget source is malformed');
                    }
                    $elementorId = trim((string) ($source['elementor_id'] ?? ''));
                    $widgetType = trim((string) ($source['widget_type'] ?? ''));
                    $section = trim((string) ($source['section'] ?? ''));
                    if ($elementorId === '' || ! preg_match('/^[a-z0-9_-]{1,100}$/i', $elementorId)
                        || $widgetType === '' || ! preg_match('/^[a-z0-9_-]{1,100}$/i', $widgetType)
                        || in_array(strtolower($widgetType), ['nav-menu', 'wp-widget-nav_menu'], true)
                        || mb_strlen($section) > 191) {
                        throw $this->failure('an Elementor query-widget source is incomplete or incompatible');
                    }
                    $sourceRecord = [
                        'elementor_id' => $elementorId,
                        'widget_type' => $widgetType,
                        'section' => $section,
                    ];
                    $sources[$elementorId.'|'.$widgetType.'|'.$section] = $sourceRecord;
                }
                $sources = array_values($sources);
            }

            $index[$id] = [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'homepage_link' => $url,
                'homepage_evidence' => [
                    'kind' => 'smp_publication_manifest',
                    'content_source' => '_elementor_data',
                    'sources' => $sources,
                ],
                'policy_status' => $status,
            ];
        }

        return $index;
    }

    /** @return array{category_ids: array<int, int>, sources: array<string, true>} */
    private function queryWidgetEvidence(array $widgets, string $siteUrl): array
    {
        if ($widgets === []) {
            throw $this->failure('the Elementor homepage has no category query widgets');
        }

        $categoryIds = [];
        $sources = [];
        foreach ($widgets as $widget) {
            if (! is_array($widget) || array_is_list($widget)) {
                throw $this->failure('an Elementor query widget is malformed');
            }
            $elementorId = trim((string) ($widget['elementor_id'] ?? ''));
            $widgetType = trim((string) ($widget['widget_type'] ?? ''));
            if ($elementorId === '' || ! preg_match('/^[a-z0-9_-]{1,100}$/i', $elementorId)
                || $widgetType === '' || ! preg_match('/^[a-z0-9_-]{1,100}$/i', $widgetType)
                || in_array(strtolower($widgetType), ['nav-menu', 'wp-widget-nav_menu'], true)
                || ! is_array($widget['categories'] ?? null)
                || $widget['categories'] === []) {
                throw $this->failure('an Elementor query widget is incomplete or incompatible');
            }

            $widgetCategories = $this->categoryIndex($widget['categories'], $siteUrl, false);
            foreach (array_keys($widgetCategories) as $categoryId) {
                $categoryIds[$categoryId] = $categoryId;
                $sources[$categoryId.'|'.$elementorId.'|'.$widgetType] = true;
            }
        }

        return ['category_ids' => array_values($categoryIds), 'sources' => $sources];
    }

    /** @return array<int, string> */
    private function editorialTopicTerms(string $topic): array
    {
        $phrases = preg_split('/\s*(?:,|;|\band\b)\s*/i', $topic) ?: [];
        $terms = [];
        foreach ($phrases as $phrase) {
            $phrase = trim((string) preg_replace('/\bnews\b\.?$/i', '', trim($phrase)));
            if ($phrase !== '' && mb_strlen($phrase) <= 60) {
                $terms[] = $phrase;
            }
        }

        return array_values(array_unique($terms));
    }

    /** @return array<int, string> */
    private function sourceKeys(array $category): array
    {
        $keys = array_map(
            static fn (array $source): string => $source['elementor_id'].'|'.$source['widget_type'].'|'.$source['section'],
            (array) data_get($category, 'homepage_evidence.sources', []),
        );
        sort($keys);

        return $keys;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildSearchCategories(array $categories, string $identity, ?array $focus, string $editorialTopic = ''): array
    {
        $specific = [];
        foreach ($categories as $category) {
            if (! $this->searchPolicy->generic($category['name'])) {
                $specific = array_merge($specific, $this->searchPolicy->terms($category['name']));
            }
        }
        if ($specific === []) {
            foreach ([
                'SEO' => 'search engine optimization',
                'hosting' => 'web hosting',
                'book' => 'book publishing',
                'medical' => 'medical technology',
                'women' => 'women entrepreneurs',
                'blockchain' => 'blockchain',
                'golf' => 'golf',
                'press release' => 'public relations',
                'public relations' => 'public relations',
            ] as $needle => $subject) {
                if (stripos($identity, $needle) !== false) {
                    $specific = array_merge($specific, $this->searchPolicy->terms($subject));
                }
            }
        }
        // Homepages that expose only generic sections ("Press Release", "Features") borrow
        // the publication focus, then the campaign's own topic phrases, before failing.
        if ($specific === [] && $focus !== null) {
            $specific = (array) ($focus['terms'] ?? []);
        }
        if ($specific === [] && trim($editorialTopic) !== '') {
            $specific = $this->editorialTopicTerms($editorialTopic);
        }
        $specific = array_values(array_unique($specific));

        foreach ($categories as &$category) {
            $category['terms'] = $this->searchPolicy->generic($category['name'])
                ? array_slice($specific, 0, 15)
                : $this->searchPolicy->terms($category['name']);
            $category['terms'] = array_values(array_unique(array_filter(array_map(
                static fn ($term): string => trim((string) $term),
                $category['terms'],
            ))));
            if ($category['terms'] === []) {
                throw $this->failure('the homepage category "'.$category['name'].'" has no clear search subject');
            }

            $category['content_mode'] = in_array(strtolower($category['name']), [
                'knowledge base', 'resources', 'guides', 'tutorials', 'how to',
            ], true) ? 'evergreen' : 'news';
            $suffix = $category['content_mode'] === 'evergreen' ? ' guide' : ' news';
            $category['queries'] = array_map(
                static fn (string $term): string => $term.$suffix,
                array_slice($category['terms'], 0, 5),
            );
            if (count($category['queries']) === 1) {
                $category['queries'][] = $category['terms'][0].' industry developments';
                $category['queries'][] = $category['terms'][0].' research innovation';
            }
            if ($focus !== null) {
                $category['queries'] = array_map(
                    static fn (string $query): string => $focus['query_prefix'].' '.$query,
                    $category['queries'],
                );
            }
            unset($category['policy_status']);
        }
        unset($category);

        return $categories;
    }

    private function fingerprintMatches(array $manifest, string $expected): bool
    {
        unset($manifest['meta']['generated_at'], $manifest['meta']['fingerprint']);
        try {
            $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return hash_equals($expected, hash('sha256', $encoded));
    }

    private function requiredMap(array $value, string $key): array
    {
        if (! isset($value[$key]) || ! is_array($value[$key]) || array_is_list($value[$key])) {
            throw $this->failure('the manifest section "'.$key.'" is missing or malformed');
        }

        return $value[$key];
    }

    public function canonicalSiteUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');

        return $scheme.'://'.$host.$port.($path === '/' ? '' : $path).'/';
    }

    private function sameEndpoint(string $actual, string $expected): bool
    {
        $actualParts = $this->safeUrlParts($actual);
        $expectedParts = $this->safeUrlParts($expected);

        return $actualParts !== null
            && $expectedParts !== null
            && $this->hostPortKey($actualParts) === $this->hostPortKey($expectedParts)
            && rtrim((string) ($actualParts['path'] ?? ''), '/') === rtrim((string) ($expectedParts['path'] ?? ''), '/')
            && ! isset($actualParts['query'], $actualParts['fragment']);
    }

    private function sameSiteRoot(string $actual, string $expected): bool
    {
        $actualParts = $this->safeUrlParts($actual);
        $expectedParts = $this->safeUrlParts($expected);

        return $actualParts !== null
            && $expectedParts !== null
            && $this->hostPortKey($actualParts) === $this->hostPortKey($expectedParts)
            && trim((string) ($actualParts['path'] ?? ''), '/') === trim((string) ($expectedParts['path'] ?? ''), '/')
            && ! isset($actualParts['query'], $actualParts['fragment']);
    }

    private function belongsToSite(string $actual, string $siteUrl): bool
    {
        $actualParts = $this->safeUrlParts($actual);
        $siteParts = $this->safeUrlParts($siteUrl);
        if ($actualParts === null || $siteParts === null || $this->hostPortKey($actualParts) !== $this->hostPortKey($siteParts)) {
            return false;
        }

        $sitePath = '/'.trim((string) ($siteParts['path'] ?? ''), '/');
        $actualPath = '/'.trim((string) ($actualParts['path'] ?? ''), '/');

        return ($sitePath === '/' || $actualPath === $sitePath || str_starts_with($actualPath, rtrim($sitePath, '/').'/'))
            && ! isset($actualParts['user'], $actualParts['pass'], $actualParts['fragment']);
    }

    private function safeUrlParts(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $parts;
    }

    private function hostPortKey(array $parts): string
    {
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $port = (int) ($parts['port'] ?? 0);
        if (($port === 80 && strtolower((string) $parts['scheme']) === 'http')
            || ($port === 443 && strtolower((string) $parts['scheme']) === 'https')) {
            $port = 0;
        }

        return $host.':'.$port;
    }

    private function failure(string $reason): RuntimeException
    {
        return new RuntimeException(
            'SMP publication manifest is required: '.$reason
            .'. Homepage HTML and WordPress category fallbacks are disabled. No AI was called.'
        );
    }
}
