# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Yoked: an AI personal trainer and nutrition coach. Claude authors each user's weekly workout
and menu, observes what they actually do, and adapts. PHP 8.4 / MySQL 8.4 / React 18 SPA / PWA
on SiteGround shared hosting. No framework, no Composer.

`README.md` covers the feature status table, the specs index, and the load-bearing product
decisions. Read it. This file covers what the README does not: how to run things, and the
architecture that only becomes visible after reading several files.

## Working on this codebase

**There is no local PHP.** Everything runs on the server over SSH. `php -l`, the test suites,
the CLI scripts — all of it. Deploy first, then run.

**Deploy from PowerShell, not the Bash tool.** `bin/deploy.sh` needs an ssh-agent that Git Bash
cannot reach on this machine. Use `.\bin\deploy.ps1`. SSH is port **18765**; port 22 is closed,
and its timeout reads like a firewall block rather than a wrong port.

**Never pass inline PHP over ssh.** Quoting breaks in ways that produce confusing errors. Write
a script, `scp` it, run it, remove it.

**Never bulk-edit files with PowerShell `Get-Content`/`Set-Content`.** It writes a UTF-8 BOM —
which in PHP is output before `<?php`, so the file dies with a `strict_types` error that
`php -l` reports without a reason unless you pass `-d display_errors=1` — and it mojibakes every
non-ASCII character through cp1252. The BOM fails loudly; the mojibake compiles fine and
surfaces later as a string comparison failing for no visible reason. Use the Edit tool, or
`sed -i` via Bash.

## Commands

```powershell
.\bin\deploy.ps1                 # ship src, database, bin, public_html + run migrations
.\bin\deploy.ps1 -DryRun         # list what would ship
.\bin\deploy.ps1 -Verify         # + envcheck, dbcheck, smoketest, goal tests
.\bin\deploy.ps1 -NoMigrate      # ship without migrating
.\bin\reseed-ui.ps1              # re-seed the three browser fixtures
```

```sh
# On the server, after deploying:
bash bin/testall.sh              # every PHP suite, ~20s, free
bash bin/lintall.sh              # parse-check every PHP file
php bin/test-plans.php           # one suite
php bin/test-plans.php --live    # + real generations: costs money, minutes
php bin/migrate.php --status     # what is applied, what is pending
php bin/cron.php --dry-run       # what the sweep would do, no API calls
php bin/cron.php --job=weekly_plan --user=42
php bin/aicalls.php              # last 20 API calls: tokens, retries, cost
php bin/aicalls.php 100          # more of them
```

```sh
cd app
npm run build                    # Vite → public_html/ (deploy after)
npm run drive                    # re-seed fixtures, then drive the browser
npm run drive:logging            # skip the re-seed (only right after a manual seed)
```

`bin/testall.sh` reports a suite as passing only on an explicit `N passed, 0 failed` AND exit 0.
An earlier version matched a progress line and announced ALL GREEN over a run killed by a
timeout.

That match is anchored end to end, so **a summary line with anything trailing it reads as NO
SUMMARY** and the suite is reported broken. `test-claude.php` printed
`22 passed, 0 failed (offline — API calls skipped)` and would never have matched. Notes go on
the line after.

**`testall.sh` is free, and that is now true rather than aspirational.** `test-claude.php`
called the API by default and took `--offline` to skip, so every routine sweep paid for six
requests. Everything costly is opt-in behind `--live`: `test-plans.php`, `test-claude.php`,
`test-chat.php`, `test-vetoes.php`, `test-skeleton-live.php`.

**The browser suite signs in nine times against a 20-per-IP-per-15-minute login limit.** Two
back-to-back runs exhaust it and everything after fails at the sign-in screen — which looks like
a catastrophic regression and is not. Wait out the window.

## Architecture

### Request path

`public_html/.htaccess` → `public_html/api/index.php` → `src/bootstrap.php` (requires every
lib in dependency order) → `Csrf::verify()` → router dispatch.

CSRF is verified **once in the front controller, before routing**. A per-route check is one
omission away from a hole, so route handlers never call it — a per-route `Csrf::` call is a bug.

Route files register onto `$router` and **load order is significant**: first match wins, and
`checkin.php` must precede `training.php` because `PUT checkin/{date}` would otherwise swallow
`PUT checkin/weekly` with `date="weekly"`.

### The generation pipeline

`Plans::generateWeek()` is the centre of the app. It makes **two** model calls:

1. **Training** — `PlanSchema::training()`, validated by `Safety::validateTraining()`
2. **Nutrition** — `PlanSchema::nutrition()`, validated by `Safety::validateNutrition()`

They were one call until a short answer on the food half started destroying complete, valid
training weeks. A failed training half fails the week; a failed nutrition half persists the
training, marks `generation_meta.nutrition_pending`, and the `nutrition_backfill` cron job
finishes it. Both calls share the same system prompt so the cached prefix is paid for once —
which only holds while both build it identically.

Prompt assembly splits deliberately: stable facts go in `systemPrompt()` (the cached prefix),
volatile ones in `userPrompt()`. **Reading only one when checking whether a rule reaches the
model has produced false "the rule is missing" reports more than once** — check both.

`Claude::generateValidated()` runs validation inside a retry loop and merges retries via
`Plans::mergePlans()`. A retry returns *less* than the first attempt, not a corrected whole, so
replacing rather than merging throws away six good days to fix one meal.

### Safety is a boundary, not a suggestion

`Safety::validatePlan()` runs after generation and rejects. Anything that narrows what the model
is *offered* — the vocabulary filters, the banned-exercise annotation — is an optimisation that
saves a wasted generation, never a replacement for the check.

Constraints are per-user rows, never hardcoded rules, and **cannot be changed by conversation**.
`user_constraints.source` must never gain a `'chat'` member. `veto_promotion` is the only
automated write path and creates SOFT constraints only. There is no code path from user text to
plan mutation.

`Safety::promptBlock()` interpolates the raw `subject` and calls `foodTerms()` for allergen
category expansion — relabelling a subject anywhere Safety reads would silently disable it.
`ConstraintLabel` is display-only and Safety must never reference it.

### The exercise library

~1000 exercises. The vocabulary sent to the model is filtered on four axes, composed:

- **access** — the union of levels present in the week (`full_gym`, `home_gym`, `bodyweight`,
  `outdoors`), since access is per-day
- **home kit** — `training_preferences.home_equipment`; NULL means never asked and stays
  permissive, `[]` means "I own nothing" and narrows to bodyweight
- **category** — activities are reportable-only unless there is an outdoors day, mobility never
  goes (warm-ups are `warmup_detail` prose, not slugs)
- **level** — a beginner is never shown an expert movement; NULL level is never filtered out

Isolation is capped at six per muscle, ordered by name length so the plain variant survives.
Compounds are not capped — 58 squats is depth, 50 curls is redundancy.

**Existing exercise rows are canonical.** `logged_exercises` and `prescribed_exercises` reference
`exercises.id` with `ON DELETE RESTRICT`. New names that mean an existing movement become
`exercise_aliases` rows, never new exercises, or load history detaches silently.

### Cron

One entry point, jobs in a fixed order, each claimed per `(job, user_id, period)` via a unique
key on `cron_runs` — claim before work, so two overlapping sweeps cannot both pay for the same
generation. A job that throws must not take the remaining jobs with it.

Scheduling is the one place that is not UTC: `profiles.timezone` decides when a slot fires,
because "Saturday 18:00" has to mean Saturday evening where the user lives.

**`bin/cron.php` and every `bin/test-*.php` list their requires explicitly.** Adding a library
that `Plans::gatherContext()` touches means adding a `require` to ~14 files, or cron dies on an
undefined class — which a dry run cannot catch.

### Migrations

Idempotent, guarded on `information_schema` with `PREPARE`/`EXECUTE`. Two things that have each
cost multiple wrong diagnoses:

- **`COMMENT` comes before `AFTER`** in a column definition. The 1064 quotes the comment text
  and reads like a quoting problem.
- **No apostrophes in comments** inside a single-quoted `PREPARE` string — one terminates the
  statement early and the error points at the following line.

Append to an ENUM, never insert into the middle: MySQL stores the ordinal, so inserting
renumbers every stored row.

MySQL here has **no STRICT mode**. A bad ENUM value inserts as `''` and fails silently on screen
rather than erroring.

## Testing

PHP suites are the fast, free layer and they run on the server. The browser suite is where the
client/server contract is actually proven — a clean build proves only that it compiles.

**The browser suite runs against one long-lived page and a shared fixture.** A test that changes
visible state changes it for everything after it, and a test that mutates a stored setting must
restore it. Both have caused failures in unrelated assertions that looked like real regressions.

The weekly check-in form **has no close button** — the only other control is "Not this week",
which skips the check-in permanently and destroys the fixture for every later test.

Three guards a unit test cannot see: no sideways scroll at 360px, one accent per view, and
**every selected control looks selected**. That last has shipped broken twice, both times a chip
using `role="radio"`/`aria-checked` against CSS that only styled `aria-pressed`.

Safety properties are usually phrased as absences ("no off switch", "the reason does not leak"),
and an absence assertion passes trivially against a row that never rendered. Assert presence
first.

## Verifying model behaviour

Prompt-shape problems — a contradiction, a missing instruction, wrong data reaching the model —
are findable for free: render the prompt and read it. Do that before spending a generation.

What a rendered prompt cannot tell you is whether the model obeys. That needs a real call, and
**live runs cost money and are opt-in for that reason** (`--live`). Get explicit approval before
running one.

When a generation fails, `bin/aicalls.php` distinguishes the two cases: output *descending*
across retries with headroom under the ceiling is the model returning less by choice, not
truncation, and `mergePlans` cannot rescue it because attempt 0 was already short.

## Conventions

Comments explain *why*, especially where the obvious implementation is wrong — most of this
codebase's comments record a decision or a defect, and several exist because the same mistake
was made twice.

Copy voice: no "fortnight", no em dashes in user-facing strings, don't explain the machinery.
Jargon gets a `?` tooltip via `Help`. `Tone::clean()` is the enforcement chokepoint, because
prompt rules about phrasing are ignored often enough to need code behind them.

`src/config.php`, `bin/deploy.env` and `source-projects/` are gitignored and hold live
credentials.
