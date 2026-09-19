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
- **Status:** Superseded 2026-09-19 by CAMPAIGN-BUG-034
- **Code here:** `PublicationManifestMapper::map()` campaign-editorial focus
  fallback; `HomepageCategorySearchPolicy::publicationFocus()` celebrity-wealth
  profile with `surface = headline`, plus wider medical and transport triggers;
  `matchesPublicationFocus()` headline-only surface.

**Guard — do not remove.** Code marked `CRITICAL — see laravel-hexa-app-publish
BUGLOG.md CAMPAIGN-BUG-006`. An empty focus silently disables the focus gate.

**Superseding correction.** The campaign-editorial fallback fixed a missing
manifest input by importing mutable legacy campaign state. Version 2 definitions
instead compile only the richer first-party manifest identity, categories,
descriptions and Elementor section evidence. Explicit specialist identity still
creates a focus; broad publications correctly have no mandatory niche focus.

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

---

## CAMPAIGN-BUG-023 — Paid refinement dropped reviewed inline images

- **Severity:** High (paid refinement could never be accepted)
- **Status:** Patched 2026-09-18 20:03:02 EST in 1.1.3
- **Owner:** also logged in laravel-hexa-app-publish

**Impact.** Law News Day article 7559 used one paid Haiku refinement pass to
address semantic and metadata findings. The model returned revised prose but
omitted both reviewed inline image figures. The publication audit correctly
raised `inline_image_relevance`, rejected the candidate and preserved the live
article, but the paid pass was consumed without a usable revision.

**Root cause.** The revision prompt asked the model to preserve inline images,
but the reusable flow treated that request as the only preservation mechanism.
Model output therefore controlled media that had already been selected,
reviewed and published.

**Patch.** `Refinement/ArticleRevisionMediaPreserver` removes model-returned
images and deterministically restores the exact reviewed figures, standalone
images and photo placeholders at their approximate original body positions.
The Publish adapter invokes it before sanitizing and auditing a candidate.

**Guard — do not remove.** Existing reviewed media must stay outside the paid
generative boundary. A revision may change prose and metadata, but it may
neither omit nor invent inline media. Unit coverage includes omitted,
substituted and newly invented images.

---

## CAMPAIGN-BUG-030 — Oversized complete source reached the writing boundary

- **Severity:** High (a reserved campaign slot failed before generation)
- **Status:** Patched 2026-09-19 12:14 EST in 1.1.4
- **Owner:** also logged in laravel-hexa-app-publish

**Impact.** Rich Reporter operation 6777 selected one 57,061-character annual
celebrity-obituary roundup. Extraction accepted it, but the writer correctly
refused the packet at its 50,000-character limit. No AI generation ran and the
slot produced no article.

**Root cause.** The complete-source safety limit existed only at the final
prompt boundary. Discovery and replacement selection had no reusable packet
budget policy, so they could declare an impossible source set ready.

**Patch.** `CampaignSourcePacketBudgetPolicy` keeps complete primary sources in
priority order up to the shared 50,000-character limit and reports every
rejection. The Publish adapter applies it before generation and continues its
existing bounded replacement discovery whenever too few sources remain.

**Guard — do not remove.** Never clip a factual source to make it fit. Reject
the complete source before paid generation, preserve the exact budget in this
generic policy, and let the campaign's bounded replacement route find another
publication-ready source.

---

## CAMPAIGN-BUG-031 — Incidental phrase locked the wrong homepage category

- **Severity:** High (wrong category and off-topic media reached a live post)
- **Status:** Patched 2026-09-19 12:36 EST in 1.1.5
- **Owner:** also logged in laravel-hexa-app-publish

**Impact.** Rich Reporter article 7605 was locked to `Travel` because its
source headline ended with “luxury travel,” although the complete article was
about California school funding, the governor, the Legislature and the state
budget. The generated post inherited Travel and selected an airplane image.

**Root cause.** Category rotation locked the discovery lane before extraction.
The complete-source relevance check only counted selected-lane mentions and
never compared the extracted article with the publication's other specific
manifest categories. Repeated incidental wording could therefore preserve the
wrong lane even when another category clearly dominated the complete source.

**Patch.** `HomepageCategorySearchPolicy` now scores complete extracted source
text across every specific manifest category. Reclassification requires at
least three distinct concepts and a decisive lead, while generic sections such
as Trending remain stable. Politics keeps its original five query terms and
adds full-source public-budget signals after them. `CampaignSourceRelevancePolicy`
exposes the deterministic result to the Publish adapter before generation.

**Guard — do not remove.** Compare complete source text before AI generation,
require decisive multi-concept evidence, preserve generic selected sections,
and never use one incidental phrase as proof that a category dominates.

---

## CAMPAIGN-BUG-032 — Saved-article audit rejected the reconciled homepage category

- **Severity:** High (the supported no-AI correction route could not repair the live post)
- **Status:** Patched 2026-09-19 12:51 EST in 1.1.6
- **Owner:** also logged in laravel-hexa-app-publish

**Impact.** Rich Reporter article 7605 was correctly reclassified from its
incidental discovery lane to the dominant complete-source category before the
correction was retried. The saved-article publication audit nevertheless used
the older literal lane-term matcher and rejected that same category, leaving
the wrong live taxonomy and image in place.

**Root cause.** The complete-source resolver returned only whether a different
category decisively displaced the selected one. A recovery audit no longer has
the discarded pre-extraction category, so it could not prove that its stored
specific category was already the strongly supported winner.

**Patch.** `HomepageCategorySearchPolicy::resolveDominantCategory()` now also
reports `selected_category_supported` when the selected specific category is
the highest-scoring lane across all eligible categories with the same minimum
score and distinct-concept evidence required by reclassification. The shared
source policy combines current generic vocabulary with saved manifest terms so
every attached source is evaluated independently with the same category
meaning. Generic sections remain unsupported by this signal and keep their
separate lane policy.

**Guard — do not remove.** Generation and saved-article recovery must consume
the same complete-source category evidence. Compare all eligible categories,
preserve per-source relevance, do not duplicate thresholds in an adapter, treat
a generic section as a dominant subject, or accept a stored specific category
that is not the supported winner.

---

## CAMPAIGN-BUG-034 — Legacy campaign text contaminated manifest definitions

- **Severity:** High (new publications could require recurring custom repair)
- **Status:** Patched in source 2026-09-19 13:38:27 EST; pending coordinated release and migration

**Impact.** When a homepage manifest did not match a hardcoded profile, its
campaign name/topic could become a mandatory publication focus. Vocabulary and
focus branches also lived in the classifier, so an unfamiliar category could
require a PHP change before setup worked.

**Root cause.** The saved campaign row was treated as discovery input, and
declarative language data was mixed with policy algorithms.

**Patch.** `CampaignDefinition` schema 2 and `CampaignDefinitionCompiler`
compile one fingerprinted policy input from the validated manifest only.
Category vocabulary and first-party identity profiles are package resources;
unknown categories derive bounded terms from their manifest label, description,
slug and Elementor section evidence. App binding metadata is excluded from the
semantic fingerprint. Partial collection fails before generation. Version 1
definitions remain readable until coordinated migration, while malformed
version 2 definitions cannot fall back to legacy validation.

**Guard — do not remove.** New setup/activation/refresh must require a current
definition. Never use campaign names, topics, site IDs or saved prompt text to
compile manifest policy. Migrate live version 1 pools before enforcing version
2 at runtime.

---

## CAMPAIGN-BUG-035 — Companion source prevented correct lane reconciliation

- **Severity:** High (correct primary source could be rejected before generation)
- **Status:** Patched in source 2026-09-19 13:38:27 EST; pending coordinated release

**Impact.** A complete primary source could clearly belong to another homepage
category, but an unrelated companion source reinforced the stale discovery lane
enough to block reclassification.

**Root cause.** Aggregate scoring ran before the priority-ordered primary source
was resolved, and the early selected-lane gate did not share the final article
classifier's full-whitelist semantics.

**Patch.** `resolveAndFilterHomepageSources()` resolves the complete primary
source across every fixed manifest category before lane rejection, returns the
resolved category ID, then checks each companion independently against that same
lane and focus. Current definitions use this path in the generic prefilter;
legacy definitions retain their prior behavior until migration.

**Guard — do not remove.** Preserve source priority, compare the full whitelist
before selected-lane rejection, keep fixed manifest IDs, and reject every
unrelated companion independently before any paid generation.

---

## CAMPAIGN-BUG-036 — Structured manifest warnings were rejected as malformed

- **Severity:** High (valid SMP manifests could not reach actionable partial-scan reporting)
- **Status:** Patched and released in 1.1.8 on 2026-09-19; pending coordinated deployment

**Impact.** Rich Reporter compiled, but Her Forward, Block Editorial,
Breaking 9 To 5 and Americas Gone Viral previews stopped with `manifest
collection warnings are malformed` instead of reporting the actual incomplete
Elementor query evidence. No paid generation ran.

**Root cause.** The mapper accepted collection warnings only as strings. SMP
Publication Integration 2.0.9 has always emitted machine-readable objects with
`code`, `message`, widget/template provenance and structured `context`.

**Patch.** The mapper now validates the producer's exact warning-object shape,
bounds and sanitizes code/message/provenance/context, and includes up to three
safe structured summaries in the partial-collection exception. Malformed
objects still fail closed, and every genuine `collection_status=partial`
manifest still stops before definition compilation or paid work.

**Guard — do not remove.** Keep warning parsing aligned with the plugin's
machine-readable schema. Never cast warning arrays to strings, discard their
diagnostic code/context, or allow a partial collection to become runnable.

---

## CAMPAIGN-BUG-037 — Zero-category native widget invalidated a complete manifest

- **Severity:** High (generic campaign setup was blocked for a valid publication)
- **Status:** Released in 1.1.9 on 2026-09-19; pending live runtime activation
- **Code here:** `PublicationManifestMapper::queryWidgetEvidence()`

**Impact.** Block Editorial's complete SMP 2.0.11 manifest could not compile a
campaign definition. Its homepage exposed all seven eligible categories with
matched query-widget sources, but one additional Press Releases widget returned
six posts with no public WordPress category terms. The scan stopped before any
AI call with `an Elementor query widget is incomplete or incompatible`.

**Root cause.** SMP deliberately records a successfully resolved native query
even when its result contributes zero category terms. The mapper incorrectly
required every individual query widget to contain a category instead of treating
that record as zero evidence and validating the category union across all widgets.

**Patch.** An empty `categories` array is accepted as zero evidence only when
the widget records a warning-free, successfully resolved native Elementor Pro
or JetEngine query with bounded result counts. It adds no IDs or sources. The
existing nonempty homepage catalog, exact catalog-to-widget union, and per-
category source checks still require complete evidence for every declared
homepage category.

**Guard — do not remove.** A zero-category widget never supplies campaign
evidence. Reject legacy or unresolved empty widgets, unrecognized providers,
warnings and invalid result bounds. Do not weaken partial-collection rejection,
accept a missing or non-array `categories` field, or bypass the exact category-
union and source-matching checks.
