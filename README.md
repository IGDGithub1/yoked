# Yoked

An AI personal trainer and nutrition coach. Claude authors each user's weekly workout
routine and menu, observes what they actually do, and adapts.

Small, invite-only, PWA on PHP 8 / MySQL shared hosting.

## Status

A user can sign up, answer the quiz, log food and training through a two-week
baseline, and report their week back to the coach that plans the next one.

| | |
|---|---|
| Schema | ✅ 39 tables, 57 FKs, live on SiteGround |
| Seed data | ✅ 90 exercises, 53 aliases, 8 goal presets |
| Deploy | ✅ one command (`bin/deploy.ps1`) |
| `Goals.php` | ✅ pluggable evaluator; reproduces the original keto rule exactly |
| `Claude.php` | ✅ API client, prompt caching, constraint-retry loop |
| `Plans.php` | ✅ week generation, validation, versioned persistence |
| API tier | ✅ `api/index.php`, global CSRF, session auth |
| Onboarding UI | ✅ the quiz, tier confirmation, answer review |
| Journal (logging) | ✅ food (search, barcode, favorites), training incl. free-logging, check-in |
| Dashboard | ✅ landing page: nudges, coach reviews, week-at-a-glance |
| Baseline lifecycle | ✅ two weeks, Monday-aligned, per-user local time, graduates to active |
| Weekly check-in | ✅ Sat 18:00 local, shapes Sunday's plan, late answers reviewed |
| Drift and nudges | ✅ §4.2 escalation, pure SQL on track; §9 absence ladder, tone-matched |
| Next Day Review | ✅ §4.1a evening card: tomorrow, prep flags, dismissible |
| Chat | ✅ §6 evaluate-and-revise at `#/coach`; the write path cannot touch a plan |
| Vetoes | ✅ §5 reason required, replace not delete, standing promotes to a SOFT constraint |
| Profile | ✅ quiz sections, the settings the quiz never asked, and an off switch for soft preferences only |

`bin/test-plans.php` seeds the two users from the specs, generates their weeks,
and asserts the output against the spec decisions.

## The loop

```
onboard → baseline (2 wks) → generate week → user follows & logs
             ↑                    ↑                    ↓
             │                    │            daily check-in
             │                    │                    ↓
             │              adapt / reshuffle ←  drift detected?
             │                    ↑                    ↓
             └──── re-baseline ← weekly check-in ←──────┘
```

## Specs

Read in this order:

| Doc | Covers |
|---|---|
| [SPEC-onboarding.md](docs/SPEC-onboarding.md) | The quiz. Everything Claude knows starts here. |
| [SPEC-safety.md](docs/SPEC-safety.md) | Per-user constraints, hard/soft tiers, enforcement. |
| [SPEC-coaching.md](docs/SPEC-coaching.md) | Generation, observation, vetoes, chat, adaptation, buddy system. |
| [sample-week.md](docs/sample-week.md) | A worked example — two paired users, week 5. Review artifact. |
| [SCHEMA.md](docs/SCHEMA.md) | What the tables are and why. |

## Setup

```sh
cp src/config.example.php src/config.php   # db creds, Anthropic key, model
php bin/migrate.php --status               # what's applied, what's pending
php bin/migrate.php                        # apply pending migrations
```

Deploy to SiteGround — see [DEPLOY.md](docs/DEPLOY.md) for first-time setup.
On Windows use the PowerShell script; `deploy.sh` needs an ssh-agent that Git
Bash cannot reach:

```powershell
.\bin\deploy.ps1 -DryRun    # list what would ship
.\bin\deploy.ps1            # ship + migrate
.\bin\deploy.ps1 -Verify    # + envcheck, dbcheck, smoketest, goal tests
```

## Verifying it works

Every suite runs on the server via SSH. The `--offline` / `--seed-only` flags
skip API calls where a suite has them.

```sh
php bin/envcheck.php          # PHP version, extensions, outbound HTTPS
php bin/dbcheck.php           # connection, table count, FK count
php bin/smoketest.php         # 22 schema assertions, rolled back
php bin/test-goals.php        # 46 evaluator assertions incl. keto parity
php bin/test-schedule.php     # 21 date assertions; no DB, no API
php bin/test-baseline.php     # 24 lifecycle assertions: observation, graduation
php bin/test-checkin.php      # 29 assertions: opening, lateness, skipping
php bin/test-drift.php        # 32 assertions: escalation ladder, nudges, notifications
php bin/test-tomorrow.php     # 18 assertions: the evening window, prep flags, dismissal
php bin/test-claude.php       # API client; --offline for shape checks only
php bin/test-logging.php      # 46 assertions over real HTTP: food, training, check-in
php bin/test-plans.php        # end-to-end generation; --seed-only to skip API
```

The logging UI is driven in a real browser, because the client/server contract is
where the interesting failures live and a build only proves it compiles. Seed the
fixtures first. They have to be seeded on the day you run them, since the screen
opens on today and the plan week must contain it:

```sh
php bin/seed-uitest.php       # two users, re-runnable:
                              #   uitest_logging  active, plan for TODAY
                              #   uitest_baseline day 3 of 14, no plan
                              #   uitest_review   review_hour 1, plan for tomorrow
                              # plus an open weekly check-in and a reviewed one
```
```powershell
cd app; npm run drive:logging   # 110 checks: log it, reload, confirm it stuck
```

Re-seed before each run — the suite mutates the fixture, and it refuses to start
against a dirty one rather than producing a screenful of misleading failures.

It also guards three things a unit test cannot see: that the page never scrolls
sideways at 360px, that the accent stays spent once per view, and that **every
selected control looks selected**. That last one has shipped broken twice — both
times a chip using `role="radio"`/`aria-checked` against CSS that only styled
`aria-pressed`, so a screen reader announced the choice while a sighted user saw
nothing change.

## Load-bearing decisions

- **Claude authors the plan; the app holds the truth.** Constraints are per-user data,
  never hardcoded rules, and cannot be changed by conversation.
- **Plans are versioned artifacts.** Adherence needs a stable referent, and every
  change carries the reason that caused it.
- **Capacity is a commitment, not a target.** A user who states 5 days gets 5 committed
  sessions; extras are optional and never count against them.
- **Macro adherence outranks menu adherence.** Ignoring the menu but hitting the macros
  is fine. Persistent substitution means the menu is wrong, not the user.
- **The user supplies facts; Claude decides.** Chat cannot dictate terms, and there is
  no code path from user text to plan mutation.
- **Two views, one job each.** The Dashboard is data REVIEW and the Journal is data
  ENTRY. Everything review-shaped had been piling onto the logging screen and needed a
  `yieldAccent` prop so the meals would go quiet when a check-in was open; splitting
  them means "one yellow prompt per view" holds by construction instead.
- **All navigation is in the header.** Icons with `aria-label` plus `title`, so the name
  reaches a screen reader and a hover alike. A bottom tab bar plus a top bar meant
  checking two edges of the screen for "where can I go"; the browser suite asserts
  structurally that no second bar comes back.
- **Quiet by default.** On-track days make no Claude call at all, and neither does
  minor drift: one missed session aggregates for the weekly check-in rather than
  becoming a conversation. Only significant drift asks a question.
- **Nudges address absence, never a bad day.** A logged bad day is a success. There
  is deliberately no notification type for a missed session, because absence is what
  ends a coaching relationship and scolding teaches people to stop logging the hard
  weeks.
- **Everything is stored UTC**, converted client-side. The one exception is
  *scheduling*: `profiles.timezone` decides when a weekly slot fires, because
  "Saturday 18:00" has to mean Saturday evening where the user actually lives.
- **The check-in precedes the plan.** It opens Saturday 18:00 and the plan generates
  Sunday 18:00, so the user's own account of their week can shape it. Answer late
  and the plan is already built; the coach reads the check-in anyway and changes the
  plan only if something in it needs changing.

## Lineage

Yoked is its own project, but borrows from two earlier ones:

- **Keto Tracker** — nutrition intake (AI food search, barcode scanning, favorites,
  the additive meal-delta correction), and the goal-evaluator vocabulary that makes
  "did this day hit target?" configurable rather than hardcoded.
- **Friendspace** — the hand-rolled PHP 8 foundation: router, session auth with
  remember-me tokens, CSRF, PDO wrapper, rate limiting, media pipeline, cron, PWA
  shell. No framework, no Composer.

Reference source for both lives in `source-projects/` (gitignored — it contains live
credentials and is not part of this project).

## Stack

PHP 8.4 · MySQL 8.4 · vanilla PDO · no Composer · React SPA · Lucide icons · PWA · SiteGround shared
hosting · `claude-sonnet-5` for coaching.

No Composer means the Anthropic SDK isn't available, so `src/lib/Claude.php` is
hand-rolled cURL against `POST /v1/messages`. Four things about the current model
family are each a 400 if you get them wrong, and all four are asserted in
`bin/test-claude.php`:

- adaptive thinking only — `budget_tokens` is **removed**, not deprecated
- `temperature` / `top_p` / `top_k` are rejected outright
- `effort` nests inside `output_config`, not at the top level
- assistant-turn prefill is rejected; structured outputs replace it

Structured outputs add two more that only surface at request time: every object
needs `additionalProperties: false`, and a schema may have at most **24 optional
parameters**. `PlanSchema::lint()` checks both so a bad schema fails a test
instead of a user's plan.
