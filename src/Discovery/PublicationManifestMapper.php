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

    private const MAXIMUM_NATIVE_QUERY_RESULTS = 50;

    private const NATIVE_QUERY_PROVIDERS = ['elementor_pro', 'jet_engine', 'jet_engine_query_builder'];

    private CampaignDefinitionCompiler $definitionCompiler;

    public function __construct(
        private HomepageCategorySearchPolicy $searchPolicy,
        ?CampaignDefinitionCompiler $definitionCompiler = null,
    ) {
        $this->definitionCompiler = $definitionCompiler ?? new CampaignDefinitionCompiler($searchPolicy);
    }

    public function manifestUrl(string $siteUrl): string
    {
        $canonical = $this->canonicalSiteUrl($siteUrl);
        if ($canonical === null) {
            throw $this->failure('the connected website URL is invalid');
        }

        return rtrim($canonical, '/').self::MANIFEST_PATH;
    }

    /**
     * The fourth argument is retained for source compatibility only. Version 2
     * definitions never read campaign names, old topics or saved prompt text.
     *
     * @param array{name?: string, topic?: string} $campaignEditorial
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

        $collectionStatus = trim((string) ($homepage['collection_status'] ?? $manifest['collection_status'] ?? $meta['collection_status'] ?? 'complete'));
        $collectionWarnings = $this->normalizeCollectionWarnings(
            $homepage['collection_warnings'] ?? $manifest['collection_warnings'] ?? $meta['collection_warnings'] ?? [],
        );
        if (! in_array($collectionStatus, ['complete', 'partial'], true)) {
            throw $this->failure('the manifest collection status is invalid');
        }
        if ($collectionStatus === 'partial') {
            $warning = trim(implode('; ', array_slice(array_map(
                fn (array $value): string => $this->collectionWarningSummary($value),
                $collectionWarnings,
            ), 0, 3)));
            throw $this->failure('the Elementor homepage collection is partial'.($warning !== '' ? ' ('.$warning.')' : ''));
        }

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
        $schema = $this->requiredMap($manifest, 'schema');
        $taxonomyCapabilities = PublicationTaxonomyCapabilities::fromManifest($publishingRequirements, $schema);
        $defaultCategoryId = (int) ($publishingRequirements['default_category_id'] ?? 0);
        if ($defaultCategoryId > 0 && isset($campaignIndex[$defaultCategoryId])) {
            throw $this->failure('the WordPress default category was incorrectly marked campaign eligible');
        }

        unset($campaignEditorial);

        return $this->definitionCompiler->compile(
            $manifestUrl,
            self::API_VERSION,
            (string) data_get($manifest, 'plugin.version'),
            $manifestFingerprint,
            $homepageUrl,
            $taxonomyCapabilities,
            array_values($campaignIndex),
            [
                'name' => $publicationName,
                'description' => (string) ($publication['description'] ?? ''),
                'homepage_title' => (string) ($homepage['title'] ?? ''),
            ],
            $this->deliveryCapabilities($delivery),
        )->toArray();
    }

    /** @return array{article_audio:bool} */
    private function deliveryCapabilities(array $delivery): array
    {
        if (array_key_exists('article_audio', $delivery) && ! is_bool($delivery['article_audio'])) {
            throw $this->failure('the article-audio delivery capability is malformed');
        }

        return [
            'article_audio' => ($delivery['article_audio'] ?? false) === true,
        ];
    }

    private function validateManifestIdentity(array $manifest): void
    {
        $allowedRoots = [
            'api_version', 'plugin', 'publication', 'homepage', 'taxonomies', 'recent_content',
            'authors', 'post_types', 'publishing_requirements', 'media', 'seo', 'schema',
            'delivery_capabilities', 'meta', 'collection_status', 'collection_warnings',
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
            $description = trim((string) ($record['description'] ?? ''));
            $policy = $record['campaign_policy'] ?? null;
            $status = is_array($policy) ? (string) ($policy['status'] ?? '') : '';

            if (! is_int($id) || $id < 1 || $name === '' || mb_strlen($name) > 191
                || preg_match('/[\x00-\x1F\x7F]/u', $name)
                || $slug === '' || mb_strlen($slug) > 200
                || preg_match('/[\x00-\x20\x7F\/\\?#]/u', $slug)
                || mb_strlen($description) > 2000
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $description)
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
                'description' => $description,
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
                || ! is_array($widget['categories'] ?? null)) {
                throw $this->failure('an Elementor query widget is incomplete or incompatible');
            }

            // CAMPAIGN-BUG-037: a resolved native query can validly return
            // posts with no public category terms. It contributes no evidence;
            // the catalog equality and per-category source checks still fail
            // closed if any declared homepage category lacks widget evidence.
            if ($widget['categories'] === []) {
                if (! $this->isResolvedZeroCategoryWidget($widget)) {
                    throw $this->failure('an Elementor query widget is incomplete or incompatible');
                }

                continue;
            }

            $widgetCategories = $this->categoryIndex($widget['categories'], $siteUrl, false);
            foreach (array_keys($widgetCategories) as $categoryId) {
                $categoryIds[$categoryId] = $categoryId;
                $sources[$categoryId.'|'.$elementorId.'|'.$widgetType] = true;
            }
        }

        return ['category_ids' => array_values($categoryIds), 'sources' => $sources];
    }

    private function isResolvedZeroCategoryWidget(array $widget): bool
    {
        $nativeQuery = $widget['native_query'] ?? null;
        $warnings = $widget['warnings'] ?? null;
        if (! is_array($nativeQuery) || array_is_list($nativeQuery)
            || ! is_array($warnings) || $warnings !== []
            || ($widget['category_source'] ?? null) !== 'native_query_results'
            || ($nativeQuery['attempted'] ?? null) !== true
            || ($nativeQuery['resolved'] ?? null) !== true
            || ! in_array($nativeQuery['provider'] ?? null, self::NATIVE_QUERY_PROVIDERS, true)) {
            return false;
        }

        $postCount = $nativeQuery['post_count'] ?? null;
        $resultLimit = $nativeQuery['result_limit'] ?? null;

        return is_int($postCount)
            && is_int($resultLimit)
            && $postCount >= 0
            && $resultLimit >= 1
            && $resultLimit <= self::MAXIMUM_NATIVE_QUERY_RESULTS
            && $postCount <= $resultLimit;
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

    /**
     * Validate the machine-readable warning objects emitted by SMP 2.0.9+.
     * Diagnostic data stays bounded before it is included in an exception.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCollectionWarnings(mixed $warnings): array
    {
        if (! is_array($warnings) || ! array_is_list($warnings) || count($warnings) > 100) {
            throw $this->failure('the manifest collection warnings are malformed');
        }

        $normalized = [];
        foreach ($warnings as $warning) {
            if (! is_array($warning) || array_is_list($warning)) {
                throw $this->failure('the manifest collection warnings are malformed');
            }

            $code = trim((string) ($warning['code'] ?? ''));
            $message = $this->boundedWarningText($warning['message'] ?? null, 500);
            $elementorId = $this->boundedWarningKey($warning['elementor_id'] ?? '', 100);
            $widgetType = $this->boundedWarningKey($warning['widget_type'] ?? '', 100);
            $templateId = $warning['template_id'] ?? 0;
            $templateChain = $warning['template_chain'] ?? [];
            $context = $warning['context'] ?? [];

            if (preg_match('/^[a-z0-9_-]{1,100}$/', $code) !== 1
                || $message === ''
                || ! is_int($templateId) || $templateId < 0
                || ! is_array($templateChain) || ! array_is_list($templateChain) || count($templateChain) > 32
                || array_filter($templateChain, static fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []
                || ! is_array($context)) {
                throw $this->failure('the manifest collection warnings are malformed');
            }

            $remainingContextItems = 50;
            $normalized[] = [
                'code' => $code,
                'message' => $message,
                'elementor_id' => $elementorId,
                'widget_type' => $widgetType,
                'template_id' => $templateId,
                'template_chain' => array_values($templateChain),
                'context' => $this->normalizeWarningContext($context, 0, $remainingContextItems),
            ];
        }

        return $normalized;
    }

    private function collectionWarningSummary(array $warning): string
    {
        $context = (array) ($warning['context'] ?? []);
        foreach (['elementor_id', 'widget_type', 'template_id'] as $field) {
            $value = $warning[$field] ?? null;
            if ($value !== null && $value !== '' && $value !== 0) {
                $context[$field] = $value;
            }
        }
        $contextJson = $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($contextJson)) {
            $contextJson = '';
        }
        $contextJson = mb_substr($contextJson, 0, 500);

        return (string) $warning['code'].': '.(string) $warning['message']
            .($contextJson !== '' ? ' '.$contextJson : '');
    }

    private function boundedWarningText(mixed $value, int $limit): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $limit);
    }

    private function boundedWarningKey(mixed $value, int $limit): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (! is_string($value)) {
            throw $this->failure('the manifest collection warnings are malformed');
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $limit || preg_match('/^[a-z0-9_-]+$/i', $value) !== 1) {
            throw $this->failure('the manifest collection warnings are malformed');
        }

        return $value;
    }

    private function normalizeWarningContext(array $context, int $depth, int &$remainingItems): array
    {
        if ($depth > 3 || count($context) > 20) {
            throw $this->failure('the manifest collection warnings are malformed');
        }

        $normalized = [];
        foreach ($context as $key => $value) {
            if (--$remainingItems < 0) {
                throw $this->failure('the manifest collection warnings are malformed');
            }
            if (! is_int($key)
                && (! is_string($key) || preg_match('/^[a-z0-9_.-]{1,64}$/i', $key) !== 1)) {
                throw $this->failure('the manifest collection warnings are malformed');
            }
            if (is_array($value)) {
                $normalized[$key] = $this->normalizeWarningContext($value, $depth + 1, $remainingItems);
            } elseif (is_string($value)) {
                $normalized[$key] = $this->boundedWarningText($value, 200);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $normalized[$key] = $value;
            } else {
                throw $this->failure('the manifest collection warnings are malformed');
            }
        }

        return $normalized;
    }

    private function failure(string $reason): RuntimeException
    {
        return new RuntimeException(
            'SMP publication manifest is required: '.$reason
            .'. Homepage HTML and WordPress category fallbacks are disabled. No AI was called.'
        );
    }
}
