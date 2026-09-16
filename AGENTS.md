# Agent instructions — laravel-hexa-package-article-campaigns

## Campaign bug log (mandatory)

- Read [BUGLOG.md](BUGLOG.md) and the laravel-hexa-app-publish BUGLOG before
  changing scheduling, pool definitions, manifest mapping, eligibility, spend
  or concurrency policy.
- Code marked `CRITICAL — see BUGLOG.md CAMPAIGN-BUG-NNN` is a regression guard
  for a production incident. Do not remove or "simplify" it. A refactor that
  moves it must keep the behavior and update that BUGLOG entry in the same
  commit.
- When you find or fix a critical or high-severity campaign bug, add a
  BUGLOG.md entry (symptom, impact, root cause, patch, guard) in the same
  commit and put the bug ID in the commit message.
