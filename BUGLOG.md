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

---

## CAMPAIGN-BUG-009 — Generic-only homepages failed the scan

- **Severity:** High
- **Status:** Patched 2026-09-16 — full entry in the laravel-hexa-app-publish BUGLOG
- **Code here:** `PublicationManifestMapper::buildSearchCategories()` falls back
  to focus terms, then `editorialTopicTerms()`, when no section has a specific
  subject.

