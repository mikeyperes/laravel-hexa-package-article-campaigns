# Campaign Bug Log — laravel-hexa-package-article-campaigns

Permanent record of critical and high-severity article campaign bugs that
involve this generic engine. Bug IDs are shared with the Publish app log, so
`CAMPAIGN-BUG-003` means the same incident in both repositories.

## Rules for every contributor and AI agent

1. **Read this file and the
   [laravel-hexa-app-publish BUGLOG](https://github.com/mikeyperes/laravel-hexa-app-publish/blob/main/BUGLOG.md)
   before changing scheduling, pool definitions, manifest mapping, eligibility,
   spend or concurrency policy.**
2. Code marked `CRITICAL — see BUGLOG.md CAMPAIGN-BUG-NNN` is a regression
   guard. Do not remove or "simplify" it. A refactor that moves it must keep
   the behavior and update the entry in the same commit.
3. Log every new critical or high-severity campaign bug here in the same commit
   as its patch, with the bug ID in the commit message.
4. Never delete an entry; mark it Resolved, Superseded or Not a bug.
5. Timestamps are EST (UTC−05:00).

---

## CAMPAIGN-BUG-003 — Manifest-only pool validation invalidated live campaigns

- **Severity:** Critical (caused the 41-hour scheduler outage logged as
  CAMPAIGN-BUG-001 in laravel-hexa-app-publish)
- **Status:** Open — plugin rollout to affected sites in progress
- **Code here:** `src/Discovery/HomepageCategoryPoolDefinition.php`
  `isUsableManifestDefinition()`; `src/Discovery/PublicationManifestMapper.php`
  `MANIFEST_PATH` (`/wp-json/smpi/v1/publication-manifest`)

**What happened.** A homepage category pool is only usable when it was built
from the `smp-publication-integration` WordPress plugin manifest: it needs
`retrieval_method = smp_publication_manifest`, `manifest_api_version = 1`,
`manifest_plugin_version` 2.0.5 or later, and SHA-256 `manifest_fingerprint`
and `fingerprint`. Publish commit `d1629cb5` (2026-09-14 17:13:57 EST) enforced
this while 12 active campaigns still held pools scanned the old way earlier
that day. Their sites run plugin 1.0.22–2.0.4 or no plugin, and plugin 2.0.5
had not been released, so those pools could not be rebuilt.

**Guard.** A change that tightens `isUsableManifestDefinition()` or the manifest
contract must ship with a rescan or migration of every live campaign it
affects, and the plugin version it depends on must be released first.

---

## CAMPAIGN-BUG-006 — Manifest pools ran with no publication focus

- **Severity:** High
- **Status:** Patched 2026-09-16 — full entry in the laravel-hexa-app-publish BUGLOG
- **Code here:** `PublicationManifestMapper::map()` campaign-editorial focus
  fallback; `HomepageCategorySearchPolicy::publicationFocus()` celebrity-wealth
  profile with `surface = headline`, plus wider medical and transport triggers;
  `matchesPublicationFocus()` headline-only surface.

**Guard — do not remove.** Code marked `CRITICAL — see laravel-hexa-app-publish
BUGLOG.md CAMPAIGN-BUG-006`. An empty focus silently disables the focus gate.

**2026-09-17 addition (1.0.9).** An entrepreneurship profile (`surface = headline`)
for identities such as "Your Entrepreneurial Journey Starts Here" (breaking9to5.com,
campaign 39). Without it the campaign published "Economic Collapse Pushes Afghan
Migrants Out of Iran…" (article 7478) and earlier Indian political and
enforcement stories. Against its last 25 headlines the profile blocks those and
passes founder, small-business and company stories.

---

## CAMPAIGN-BUG-009 — Generic-only homepages failed the scan

- **Severity:** High
- **Status:** Patched 2026-09-16 — full entry in the laravel-hexa-app-publish BUGLOG
- **Code here:** `PublicationManifestMapper::buildSearchCategories()` falls back
  to focus terms when no section has a specific subject. A campaign-topic phrase
  fallback was removed in 1.0.7 after it produced lanes that never passed
  relevance (campaign 63).

---

## CAMPAIGN-BUG-011 — Sentence-style discovery query returned nothing

- **Severity:** High
- **Status:** Patched 2026-09-17 in 1.0.7 — full entry in the laravel-hexa-app-publish BUGLOG
- **Code here:** `CampaignSourceRelevancePolicy::alignDiscoveryQueryWithResolvedIntent()`
  now builds a compact OR-group query without instruction prose.

**Guard — do not remove.** Code marked `CRITICAL — see laravel-hexa-app-publish
BUGLOG.md CAMPAIGN-BUG-011`.

---

## CAMPAIGN-BUG-014 — Business headlines about rates or inflation failed the lane check

- **Severity:** Medium (paid draft rejected; campaign 39 published nothing)
- **Status:** Patched 2026-09-17 in 1.0.8
- **Impact:** Operation #6636 (campaign 39, Breaking 9 To 5) found a Fox Business
  Federal Reserve rate-hike story through "business news", generated "Fed Lifts
  Interest Rates as Stubborn Inflation Persists", then failed Publication fit:
  the headline contained none of the Business lane terms (business, companies,
  economy, earnings, acquisition).

**Patch.** `HomepageCategorySearchPolicy::VOCABULARY['business']` adds interest
rates, inflation, federal reserve, markets, revenue and profit after the first
five query terms, so queries are unchanged. The Fed headline now passes; the
off-topic Rich Reporter headlines from CAMPAIGN-BUG-006 still fail. Pools store
terms at scan time, so campaigns 39 and 52 were rescanned.

---

## CAMPAIGN-BUG-020 — Law publication accepted a general economy article

- **Severity:** High (off-topic article published on Law News Day)
- **Status:** Patched 2026-09-17 in 1.1.2; affected campaign pool requires rebuild
- **Impact:** Campaign 68 article 7517 published a Russia wartime-economy story
  under `Features`, even though the story had no legal subject. The automated
  publication-fit check passed on `economy`, `interest rates`, `inflation` and
  other general business terms.

**Root cause.** `Business Law` had no exact category vocabulary, so compound
label expansion matched `business` and supplied general economy terms. The
publication-focus resolver also had no legal-news identity, so `Law News Day`
did not require law, courts, regulation, litigation or legal practice to be the
story's subject. The generic `Features` lane inherited the same business-only
terms.

**Patch.** Add exact `Business Law` and `Law` vocabularies and a headline-only
legal-news publication focus. General economy stories no longer pass merely on
business language, while court, regulation, litigation, compliance and legal-
practice stories remain eligible. Rebuilding a manifest pool applies the new
terms and focus to every relevant category without site-specific configuration.

**Guard — do not remove.** Keep legal-publication focus headline-bound and keep
`Business Law` distinct from the broad `business` vocabulary. Regression tests
must include the rejected Law News Day economy headline and an accepted court
or regulation headline.
