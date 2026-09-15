<?php

namespace hexa_package_article_campaigns\Discovery;

class NewsDiscoveryOptionsService
{
    /**
     * Applications provide their own configuration and persistence adapters.
     * The package keeps only the normalized, application-agnostic values.
     *
     * @param array<int, string> $configuredDiscoveryModes
     * @param array<int, string> $configuredFinalArticleMethods
     * @param array<int, string> $configuredNewsCategories
     */
    public function __construct(
        private readonly array $configuredDiscoveryModes = [
            'keyword',
            'local',
            'trending',
            'genre',
        ],
        private readonly array $configuredFinalArticleMethods = [
            'news-search',
        ],
        private readonly array $configuredNewsCategories = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function discoveryModes(): array
    {
        return $this->normalized($this->configuredDiscoveryModes);
    }

    /**
     * @return array<int, string>
     */
    public function finalArticleMethods(): array
    {
        return $this->normalized($this->configuredFinalArticleMethods);
    }

    /**
     * @return array<int, string>
     */
    public function newsCategories(): array
    {
        return $this->normalized($this->configuredNewsCategories);
    }

    /** @param array<int, mixed> $values */
    final protected function normalized(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }
}
