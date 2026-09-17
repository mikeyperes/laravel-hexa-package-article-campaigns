# Laravel Hexa Package — Article Campaigns

Reusable article-campaign domain behavior for Laravel applications.

This package owns campaign definitions, manifest mapping, query planning,
candidate state transitions, scheduling, eligibility, spend and concurrency
policies, verified delivery-term recovery, and port-based execution
coordination. It deliberately contains no
Publish models, database tables, controllers, routes, views, provider clients,
or WordPress delivery code. Consuming applications supply those through
adapters.

`CampaignWorkflowOrchestrator` is the single reusable lifecycle coordinator:
prepare, discover, generate, deliver. The original three-port
`CampaignOrchestrator` remains source compatible by adapting those ports into
that same coordinator; it does not maintain a second execution sequence.

## Campaign bug log

[BUGLOG.md](BUGLOG.md) records every critical and high-severity campaign bug
involving this engine, its root cause and the code that fixed it. Read it, and
the Publish app's BUGLOG, before changing campaign code. Code marked
`CRITICAL — see BUGLOG.md CAMPAIGN-BUG-NNN` must not be removed or simplified
away. New critical campaign bugs are logged there in the same commit as their
patch.
