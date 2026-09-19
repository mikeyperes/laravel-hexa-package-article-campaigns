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

## Input-only campaign setup

New publication campaigns use `PublicationManifestMapper` to validate the SMP
manifest and compile a schema-2 `CampaignDefinition`. The definition is the
authoritative reusable policy input: publication/homepage identity, fixed
category IDs, taxonomy capabilities, manifest-derived search subjects, queries
and any explicit first-party specialist focus. Campaign names, old topics,
site IDs, prompt text and application models are not compiler inputs.

Use `HomepageCategoryPoolDefinition::isCurrentManifestDefinition()` for new
setup, activation, refresh and migration. `isUsableManifestDefinition()` remains
only for runtime compatibility with saved schema-1 definitions until each live
campaign has been rebuilt. A schema-2 definition with a stale fingerprint is
never accepted as legacy.

`CampaignSourceRelevancePolicy::resolveAndFilterHomepageSources()` resolves the
priority-ordered complete primary source across the full homepage whitelist,
returns the exact manifest category ID, and rejects unrelated companion sources
with the same semantics used by the final source gate.

## Campaign bug log

[BUGLOG.md](BUGLOG.md) records every critical and high-severity campaign bug
involving this engine, its root cause and the code that fixed it. Read it, and
the Publish app's BUGLOG, before changing campaign code. Code marked
`CRITICAL — see BUGLOG.md CAMPAIGN-BUG-NNN` must not be removed or simplified
away. New critical campaign bugs are logged there in the same commit as their
patch.
