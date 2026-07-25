# Yoked

An AI personal trainer and nutrition coach. Claude authors each user's weekly workout
routine and menu, observes what they actually do, and adapts.

Small, invite-only, PWA on PHP 8 / MySQL shared hosting.

## Status

Scope is settled and the coaching engine generates real weeks against the live API.

| | |
|---|---|
| Schema | ✅ 39 tables, 57 FKs, live on SiteGround |
| Seed data | ✅ 90 exercises, 53 aliases, 8 goal presets |
| Deploy | ✅ one command (`bin/deploy.ps1`) |
| `Goals.php` | ✅ pluggable evaluator; reproduces the original keto rule exactly |
| `Claude.php` | ✅ API client, prompt caching, constraint-retry loop |
| `Plans.php` | ✅ week generation, validation, versioned persistence |
| Onboarding UI | ⬜ next — nothing collects the quiz answers yet |
| API tier / SPA | ⬜ no `api/index.php`, no front end |
| Logging, check-ins, nudges | ⬜ schema exists, no code |

Everything so far is driven from the CLI. `bin/test-plans.php` seeds the two users
from the specs, generates their weeks, and asserts the output against the spec
decisions — that is currently the only way to see the app work.

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
php bin/test-claude.php       # API client; --offline for shape checks only
php bin/test-plans.php        # end-to-end generation; --seed-only to skip API
```

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
- **Quiet by default.** On-track days make no Claude call at all. Nudges address
  absence, never a bad day.
- **Everything is UTC**, converted client-side.

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

PHP 8.4 · MySQL 8.4 · vanilla PDO · no Composer · React SPA · PWA · SiteGround shared
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
