<?php

namespace hexa_package_article_campaigns\Providers;

use Illuminate\Support\ServiceProvider;

final class ArticleCampaignsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Core services are concrete, stateless objects and are resolved by
        // Laravel automatically. Applications bind only the ports they use.
    }
}
