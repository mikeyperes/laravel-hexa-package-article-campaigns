# Laravel Hexa Package — Article Campaigns

Reusable article-campaign domain behavior for Laravel applications.

This package owns campaign definitions, manifest mapping, query planning,
candidate state transitions, scheduling, eligibility, spend and concurrency
policies, and port-based execution coordination. It deliberately contains no
Publish models, database tables, controllers, routes, views, provider clients,
or WordPress delivery code. Consuming applications supply those through
adapters.
