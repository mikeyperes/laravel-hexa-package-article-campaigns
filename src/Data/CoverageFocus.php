<?php

namespace hexa_package_article_campaigns\Data;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A reviewed editorial beat layered on a manifest-backed campaign: what the
 * campaign covers (for example business fraud and scandals), which WordPress
 * categories receive it, which publishers are searched first and how old a
 * source may be once recent news runs out.
 *
 * It is structured policy, not prompt text. The definition compiler turns it
 * into ordinary pool lanes and a publication focus, so discovery, the source
 * policy and the quality gate apply it without a separate code path.
 */
final readonly class CoverageFocus
{
    public const SOURCE = 'campaign_coverage_focus';

    public const DEFAULT_RECENT_DAYS = 14;

    private const MAXIMUM_DAYS = 730;

    private const MAXIMUM_LANES = 6;

    private const MAXIMUM_EVENT_TERMS = 60;

    private const MAXIMUM_LANE_TERMS = 30;

    private const MAXIMUM_LANE_QUERIES = 8;

    private const MAXIMUM_DOMAINS = 8;

    /**
     * @param array<int, string> $eventTerms
     * @param array<int, array{category_id:int,terms:array<int,string>,queries:array<int,string>}> $lanes
     * @param array<int, string> $preferredDomains
     */
    private function __construct(
        public string $label,
        public array $eventTerms,
        public array $lanes,
        public array $preferredDomains,
        public int $recentDays,
        public int $fallbackDays,
        public bool $reportsAllegations,
        public bool $allowDefaultCategory = false,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        $label = self::text($value['label'] ?? '', 160);
        if ($label === '') {
            throw new InvalidArgumentException('Coverage focus needs a label describing what the campaign covers.');
        }

        $eventTerms = self::terms($value['event_terms'] ?? [], self::MAXIMUM_EVENT_TERMS);
        if ($eventTerms === []) {
            throw new InvalidArgumentException('Coverage focus needs event terms; a source must mention one of them.');
        }

        $lanes = [];
        $seen = [];
        foreach ((array) ($value['lanes'] ?? []) as $lane) {
            if (! is_array($lane)) {
                throw new InvalidArgumentException('Each coverage focus lane must be an object.');
            }
            $categoryId = filter_var($lane['category_id'] ?? null, FILTER_VALIDATE_INT);
            if (! is_int($categoryId) || $categoryId < 1 || isset($seen[$categoryId])) {
                throw new InvalidArgumentException('Each coverage focus lane needs one distinct WordPress category ID.');
            }
            $terms = self::terms($lane['terms'] ?? [], self::MAXIMUM_LANE_TERMS);
            $queries = self::terms($lane['queries'] ?? [], self::MAXIMUM_LANE_QUERIES);
            if ($terms === [] || $queries === []) {
                throw new InvalidArgumentException('Coverage focus lane '.$categoryId.' needs subject terms and search queries.');
            }
            foreach ($queries as $query) {
                if (preg_match('/(?:^|\s)site:/i', $query) === 1) {
                    throw new InvalidArgumentException('Coverage focus queries must not name a site; list publishers in preferred_domains.');
                }
            }
            $seen[$categoryId] = true;
            $lanes[] = ['category_id' => $categoryId, 'terms' => $terms, 'queries' => $queries];
        }
        if ($lanes === [] || count($lanes) > self::MAXIMUM_LANES) {
            throw new InvalidArgumentException('Coverage focus needs 1-'.self::MAXIMUM_LANES.' category lanes.');
        }

        $domains = [];
        foreach ((array) ($value['preferred_domains'] ?? []) as $domain) {
            $domain = self::domain((string) $domain);
            if ($domain === null) {
                throw new InvalidArgumentException('Coverage focus preferred domains must be bare host names such as forbes.com.');
            }
            $domains[$domain] = true;
        }
        if (count($domains) > self::MAXIMUM_DOMAINS) {
            throw new InvalidArgumentException('Coverage focus accepts at most '.self::MAXIMUM_DOMAINS.' preferred domains.');
        }

        $recentDays = self::days($value['recent_days'] ?? self::DEFAULT_RECENT_DAYS, 'recent_days');
        $fallbackDays = self::days($value['fallback_days'] ?? $recentDays, 'fallback_days');
        if ($fallbackDays < $recentDays) {
            throw new InvalidArgumentException('Coverage focus fallback_days must be at least recent_days.');
        }

        return new self(
            $label,
            $eventTerms,
            $lanes,
            array_keys($domains),
            $recentDays,
            $fallbackDays,
            filter_var($value['reports_allegations'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // An explicit owner choice to file into the site's default
            // category (often "News"); every other category rule still applies.
            filter_var($value['allow_default_category'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /** The focus compiled into a definition, or null for an ordinary homepage pool. */
    public static function fromDefinition(array $definition): ?self
    {
        $focus = $definition['coverage_focus'] ?? null;

        return is_array($focus) && $focus !== [] ? self::fromArray($focus) : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'event_terms' => $this->eventTerms,
            'lanes' => $this->lanes,
            'preferred_domains' => $this->preferredDomains,
            'recent_days' => $this->recentDays,
            'fallback_days' => $this->fallbackDays,
            'reports_allegations' => $this->reportsAllegations,
            'allow_default_category' => $this->allowDefaultCategory,
        ];
    }

    /** @return array<int, int> */
    public function categoryIds(): array
    {
        return array_column($this->lanes, 'category_id');
    }

    /**
     * The publication focus every candidate and finished article must match:
     * at least one event term in its headline, description or opening.
     *
     * @return array<string, mixed>
     */
    public function publicationFocus(): array
    {
        return [
            'label' => $this->label,
            'query_prefix' => '',
            'terms' => $this->eventTerms,
            'source' => self::SOURCE,
        ];
    }

    /**
     * Lane queries in search order: every query restricted to each preferred
     * publisher in turn, then the same queries across all publishers. The
     * planner runs a lane's queries in this order, so preferred publishers are
     * searched first and the open web is the fallback.
     *
     * @param array{queries:array<int,string>} $lane
     * @return array<int, string>
     */
    public function laneQueries(array $lane): array
    {
        $queries = [];
        foreach ($this->preferredDomains as $domain) {
            foreach ((array) $lane['queries'] as $query) {
                $queries[] = 'site:'.$domain.' '.$query;
            }
        }
        foreach ((array) $lane['queries'] as $query) {
            $queries[] = $query;
        }

        return array_values(array_unique($queries));
    }

    /** Position of a URL's publisher in the preferred list; unlisted publishers rank last. */
    public function domainRank(string $url): int
    {
        $host = self::domain((string) parse_url($url, PHP_URL_HOST)) ?? '';
        foreach ($this->preferredDomains as $rank => $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $rank;
            }
        }

        return count($this->preferredDomains);
    }

    public function isRecent(?CarbonInterface $publishedAt): bool
    {
        return $publishedAt !== null && $publishedAt->gte(Carbon::now()->subDays($this->recentDays));
    }

    private static function text(mixed $value, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));
        if ($text === '' || mb_strlen($text) > $limit || preg_match('/[\x00-\x1F\x7F<>]/u', $text) === 1) {
            return '';
        }

        return $text;
    }

    /** @return array<int, string> */
    private static function terms(mixed $values, int $limit): array
    {
        $terms = [];
        foreach ((array) $values as $value) {
            $term = self::text($value, 80);
            if ($term === '') {
                throw new InvalidArgumentException('Coverage focus terms and queries must be plain text of 80 characters or fewer.');
            }
            $terms[mb_strtolower($term)] ??= $term;
        }
        if (count($terms) > $limit) {
            throw new InvalidArgumentException('Coverage focus accepts at most '.$limit.' entries per list.');
        }

        return array_values($terms);
    }

    private static function domain(string $value): ?string
    {
        $host = preg_replace('/^www\./', '', strtolower(trim($value))) ?? '';

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host) === 1 ? $host : null;
    }

    private static function days(mixed $value, string $field): int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($days) || $days < 1 || $days > self::MAXIMUM_DAYS) {
            throw new InvalidArgumentException('Coverage focus '.$field.' must be 1-'.self::MAXIMUM_DAYS.' days.');
        }

        return $days;
    }
}
