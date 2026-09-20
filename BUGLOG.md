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

## CAMPAIGN-BUG-055 — Press-release lane inherited every publication topic

- **Severity:** High
- **Status:** Patched 2026-09-20 04:02 EST in 1.1.21.
- **Impact:** A normal Bitcoin market article was generated and published in
  the `Press Releases` category because that lane searched the complete
  Cryptocurrency, Blockchain and Podcasts vocabulary instead of release-format
  evidence.
- **Root cause:** `Press Release` and `Press Releases` were classified as
  generic homepage sections. Generic sections intentionally inherit all
  specific homepage subjects, which is correct for cross-topic sections such
  as Features but not for a source format.
- **Patch:** Press-release categories are now specific, application-neutral
  format lanes with release and official-announcement vocabulary. They no
  longer inherit publication-wide topic terms and participate in the existing
  complete-source category reconciliation.
- **Guard:** A homepage with Cryptocurrency, Blockchain and Press Releases must
  compile Press Releases from release-format terms and never include Bitcoin
  or blockchain merely because those are sibling categories.

---

## CAMPAIGN-BUG-058 — Press-release discovery lost publication context

- **Severity:** High
- **Status:** Patched 2026-09-20 04:33:20 EST in 1.1.22.
- **Impact:** The first Press Releases isolation fix correctly stopped the lane
  from treating every publication topic as release-format evidence, but it also
  reduced discovery to generic searches such as `press release news`. A crypto
  publication therefore examined unrelated government, gaming, sports and
  consumer releases before stopping with zero extracted sources.
- **Root cause:** One `terms` collection was serving two different constraints:
  source format and publication subject. Isolating the format removed the
  subject from both queries and full-source validation.
- **Patch:** Source-format lanes now keep `terms` for format evidence and a
  separate `context_terms` set compiled from non-generic, non-format homepage
  categories. Every format query contains both a publication subject and a
  format term. Full-source validation also requires both, while ordinary
  topical news without format evidence can be reclassified to its topical lane.
- **Guard:** A crypto Press Releases lane must accept a crypto press release,
  reject an unrelated sports release, and reclassify ordinary crypto news when
  no press-release evidence exists. Generic sections must never inherit format
  vocabulary as publication subject matter.

---

## CAMPAIGN-BUG-054 — Manifest delivery capabilities were discarded

- **Severity:** High
- **Status:** Patched 2026-09-20 03:17 EST in 1.1.20.
- **Impact:** The publication manifest could describe delivery support, but the
  reusable campaign definition retained only taxonomy capabilities. Publish
  therefore had no durable, generic input for deciding whether a site supports
  article audio and attempted TTS on unsupported sites.
- **Root cause:** `PublicationManifestMapper` validated delivery capabilities
  only to establish manifest compatibility and discarded them before compiling
  the immutable campaign definition.
- **Patch:** Current definitions now preserve a normalized
  `delivery_capabilities.article_audio` boolean and expose it through
  `HomepageCategoryPoolDefinition::applySettings()`. Definitions created
  before this field remain fingerprint-compatible and valid until their normal
  manifest refresh adds the capability.
- **Guard:** Application adapters must consume this definition field instead
  of site names, manual campaign flags, plugin probing, or unconditional TTS.

---

## CAMPAIGN-BUG-052 — Generic sections inherited raw ambiguous child labels

- **Severity:** High
- **Status:** Patched 2026-09-20 02:49 EST in 1.1.19.
- **Impact:** The `Plugged In` lane correctly compiled to podcast semantics,
  but the generic `Features` lane still aggregated the pre-context literal
  terms `plugged in` and `plugged`. An unrelated brand could therefore enter
  the generic lane even after the child lane itself was repaired.
- **Root cause:** The first compiler pass built generic-section subjects with
  raw category evidence; contextual child resolution occurred only later.
- **Patch:** The compiler now resolves every specific lane once, caches that
  subject, and builds generic sections only from those resolved semantic terms.
- **Guard:** `Features` inherits podcast terms from a nested `Plugged In` lane
  and never inherits the literal ambiguous phrase.

## CAMPAIGN-BUG-051 — Plain standalone subjects were treated as unsupported labels

- **Severity:** High
- **Status:** Patched 2026-09-20 02:44 EST in 1.1.18.
- **Impact:** After generic sections were repaired, the Smartech Daily rescan
  stopped before AI on the valid `Security` category because it had no parent,
  description, or distinct Elementor heading.
- **Root cause:** The ambiguity gate did not distinguish a self-contained
  subject label from a phrase whose meaning depends on a stop word.
- **Patch:** Unknown labels now compile directly when they contain either one
  standalone content token or at least two content tokens. A phrase such as
  `Plugged In`, whose only content token is paired with a stop word, still
  requires parent or other manifest context.
- **Guard:** `Security` compiles from first-party manifest evidence while the
  uncontextualized `Plugged In` regression remains blocked before AI.

## CAMPAIGN-BUG-050 — Generic homepage sections entered the ambiguity blocker

- **Severity:** High
- **Status:** Patched 2026-09-20 02:39 EST in 1.1.17.
- **Impact:** The first manifest rescan after CAMPAIGN-BUG-048 stopped on a
  normal `Features` lane before AI, preventing an otherwise valid campaign
  definition from being refreshed.
- **Root cause:** Compilation resolved semantic context before branching on a
  known generic section. Generic sections were therefore treated like unknown
  literal labels even though their subject is intentionally inherited from the
  site's specific homepage lanes.
- **Patch:** Generic sections now bypass unknown-label resolution, inherit the
  compiled specific homepage subjects, and store manifest-evidence context.
- **Guard:** A manifest containing `Business` plus `Features` must compile the
  generic lane with business terms and non-empty queries without invoking the
  ambiguity blocker.

## CAMPAIGN-BUG-049 — Source and output length gates contradicted the approved floor

- **Severity:** High
- **Status:** Patched 2026-09-20 02:33 EST in 1.1.16.
- **Impact:** A complete 430-word relevant source was rejected against a
  650-word template floor, while the generated-output gate independently used
  550 words. Discovery then continued to an unrelated source and spent AI
  credits on a draft that could not pass publication fit.
- **Root cause:** The Publish adapter derived source and article floors in two
  separate places instead of using one reusable campaign length policy.
- **Patch:** The generic policy caps the approved article floor at 450 words
  and the single-source evidence floor at 400 words. Lower configured floors
  remain respected down to 350 words.
- **Guard:** Both the output gate and source-extraction boundary must consume
  `CampaignArticleLengthPolicy`; no adapter may recreate independent numeric
  clamps.

## CAMPAIGN-BUG-048 — Ambiguous category labels matched unrelated brands and domains

- **Severity:** High
- **Status:** Patched 2026-09-20 02:25 EST in 1.1.15.
- **Impact:** A homepage lane named `Plugged In` compiled literal `plugged in`
  queries. Discovery rejected one short relevant source, then selected an
  unrelated golf site whose brand/domain contained the same phrase and paid for
  a draft that the publication-fit gate later rejected.
- **Root cause:** Category compilation ignored the trusted parent context in the
  WordPress category URL and treated an otherwise unsupported label as its own
  editorial subject. Old versioned definitions could also fall through the
  legacy compatibility path.
- **Patch:** Unknown child labels now inherit the nearest recognized parent
  category subject from their manifest URL. Literal child names do not become
  query or relevance terms in that case. Unknown labels with no known parent,
  description, or distinct Elementor section fail before AI. Definition schema
  v3 stores the semantic-context source, and every versioned definition must
  validate against the current schema.
- **Guard:** The regression fixture compiles
  `/category/podcasts/plugged-in/` into podcast terms, rejects a Plugged In Golf
  source, rejects an uncontextualized `Plugged In` lane before AI, and rejects
  the old v2 definition as usable.

## CAMPAIGN-BUG-047 — Explicit article audience was dropped from media selection

- **Severity:** High
- **Status:** Generic policy patched 2026-09-19 23:37 EST in 1.1.12; Publish adapter release pending.
- **Impact:** A Grit Daily article explicitly about women entrepreneurs selected
  an inline stock image of a man working alone because its generated search term
  degraded to `small business owner at computer working`.
- **Root cause:** Media search treated each generated phrase independently from
  the article's explicit audience. Fallback queries noticed some audience words,
  but the primary query and candidate acceptance did not preserve them.
- **Patch:** `CampaignMediaAudiencePolicy` qualifies a generic stock query with
  an explicit audience from the article title and rejects descriptive candidate
  metadata that identifies only the conflicting audience. Neutral candidate
  descriptions remain eligible; the policy does not guess from missing metadata.
- **Guard:** An explicit audience must remain in every primary and fallback
  media query. Reject a candidate only on positive conflicting description
  evidence; never infer identity from a URL, article claim or absent metadata.

---

## CAMPAIGN-BUG-046 — Campaign runs lacked durable stage timing and initiator provenance

- **Severity:** High
- **Status:** Patched 2026-09-19 23:34 EST in 1.1.12; Publish adapter release pending.
- **Impact:** A completed campaign operation exposed only coarse operation
  timestamps and inconsistent event durations. It could not prove how long each
  reusable workflow phase took or distinguish a site-native run from Codex,
  Claude, the scheduler, API or direct Artisan execution.
- **Root cause:** Timing was emitted opportunistically by application adapters,
  while the generic lifecycle owned the stage order but did not measure it.
  Initiator identity had no normalized, signed domain record.
- **Patch:** The generic workflow now returns start, finish and duration for the
  complete run and each prepare, discover, generate and deliver phase.
  `CampaignRunProvenance` normalizes the six supported origins, hashes session
  and request identifiers, binds the configuration fingerprint and issues a
  server-verifiable HMAC signature without storing raw session identifiers.
- **Guard:** Every application adapter must persist this generic timing record
  and the signed provenance on its durable run. Never infer Codex or Claude from
  article content, store raw agent session identifiers, or label an
  agent-triggered run as site-native.

**Detailed timing follow-up — 2026-09-20 02:00 EST.** The four lifecycle stages
still hid expensive provider, media and WordPress work inside broad totals.
`CampaignRunTimingReport` now groups application-owned leaf timers into durable
sections, reports the ten slowest tasks, and exposes measured, unmeasured and
overlapping time. Keep application-specific task names in the adapter; the
generic package accepts explicit timing events and contains no Publish or site
logic.

**Delay-attribution follow-up — 2026-09-20 01:54:22 EST.** A 160-second live
run showed that a simple slowest-task list still made an auditor reconstruct
the actual bottlenecks. The generic report now promotes any task or section
that takes at least five seconds or five percent of run time into separate,
ranked `time_intensive_tasks` and `time_intensive_sections`. Each record retains
its run share, remote/local work type, owning boundary, provider, model, target,
attempt, outcome and details. Failed and retried tasks are also separate lists,
so provider retries and failures cannot disappear beneath a successful total.

---

## CAMPAIGN-BUG-043 — Revision redelivery erased saved WordPress tags

- **Severity:** High
- **Status:** Patched 2026-09-19 22:25 EST in 1.1.11.
- **Impact:** Transit Tomorrow article 7622 retained three saved Publish tags,
  but its accepted refinement redelivery updated WordPress post 462596 with an
  empty tag-ID list. The public post lost every tag even though its category,
  author, media and saved article record remained correct.
- **Root cause:** Verified taxonomy recovery stopped after the newest same-post
  attestation supplied any valid category. It did not continue to older
  verified evidence when a taxonomy family still expected by the caller, such
  as `tag_ids`, was absent from that newer attestation.
- **Patch:** The generic recovery policy now accepts the taxonomy families the
  caller still expects and walks newest-to-oldest same-output attestations until
  all of those families are recovered. It still stops after the newest evidence
  satisfies the caller, so an intentionally removed taxonomy is not revived.
- **Guard:** A newer verified category-only attestation followed by an older
  category-and-tag attestation must recover tags only when the caller still
  requires tags. Never treat one recovered taxonomy family as proof that every
  expected family is present.

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

---

## CAMPAIGN-BUG-038 — Mapper rejected the released 50-result manifest contract

- **Severity:** High (generic campaign setup was blocked after a valid complete manifest)
- **Status:** Patched in 1.1.10 on 2026-09-19; focused regression passed and live campaign 78 retry is pending
- **Code here:** `PublicationManifestMapper::isResolvedZeroCategoryWidget()`

**Impact.** SEO My Company's SMP 2.0.15 manifest proved its 24-item homepage
directory query complete with a 50-result bound and no warnings, but the generic
mapper still allowed only the former 25-result ceiling. The same contract also
did not recognize SMP's safely resolved `jet_engine_query_builder` provider.
Setup stopped before any AI call.

**Root cause.** The producer's bounded native-query contract evolved without the
consumer's allow-list and maximum being advanced in the same release sequence.

**Patch.** Accept result limits through 50 and add the exact
`jet_engine_query_builder` provider. Preserve every existing warning-free,
resolved, integer-bound, category-union, and per-category source requirement.
The focused fixture covers both a 24-of-50 Elementor result and a warning-free
Query Builder zero-category result, while retaining unresolved-provider
rejection. The focused PHPUnit run passed with 1 test and 3 assertions.

**Guard — do not remove.** Keep this consumer contract synchronized with SMP's
released bounded providers. Never accept arbitrary provider names, limits over
50, warnings, unresolved queries, malformed counts, or zero-category records as
positive category evidence.
