# Yoked

An AI personal trainer and nutrition coach. Claude authors each user's weekly workout
routine and menu, observes what they actually do, and adapts.

Small, invite-only, PWA on PHP 8 / MySQL shared hosting.

## Status

A user can sign up, answer the quiz, log food and training through a two-week
baseline, and report their week back to the coach that plans the next one.

| | |
|---|---|
| Schema | ✅ 44 tables, 73 FKs, 22 migrations, live on SiteGround |
| Seed data | ✅ 1002 exercises, 943 aliases, 8 goal presets |
| Deploy | ✅ one command (`bin/deploy.ps1`) |
| `Goals.php` | ✅ pluggable evaluator; reproduces the original keto rule exactly |
| `Claude.php` | ✅ API client, prompt caching, constraint-retry loop |
| `Plans.php` | ✅ week generation, validation, versioned persistence |
| API tier | ✅ `api/index.php`, global CSRF, session auth |
| Onboarding UI | ✅ the quiz, tier confirmation, answer review |
| Journal (logging) | ⚠️ works, but the food half needs the rework below |
| Dashboard | ✅ landing page: nudges, coach reviews, week-at-a-glance |
| Admin | ✅ members, roles, suspension, invite codes. No delete: suspension is reversible, a cascade is not |
| Baseline lifecycle | ✅ two weeks, Monday-aligned, per-user local time, graduates to active |
| Weekly check-in | ✅ Sat 18:00 local, shapes Sunday's plan, late answers reviewed |
| Drift and nudges | ✅ §4.2 escalation, pure SQL on track; §9 absence ladder, tone-matched |
| Next Day Review | ✅ §4.1a evening card: tomorrow, prep flags, dismissible |
| Chat | ✅ §6 evaluate-and-revise at `#/coach`; the write path cannot touch a plan |
| Vetoes | ✅ §5 reason required, replace not delete, standing promotes to a SOFT constraint |
| Profile | ✅ quiz sections, the settings the quiz never asked, and an off switch for soft preferences only |
| Progress photos | ✅ owner-only, EXIF stripped, stored outside the web root, never sent to Claude |
| Visibility | ✅ one authority for who sees what; the privacy flags finally bite (§10.4) |
| Friends | ✅ §10.1 the social graph: search, request, block. Prefix on handles, full email only |
| Buddy pairing | ✅ §10.1 handshake, §10.5 either side unpairs |
| Buddy schedules | ✅ §10.1a two schedules, §10.3a compromise, §10.3b the surplus choice. Generation puts both in the gym on the same days |
| Synced sessions | ✅ §10.6 one shared skeleton per pair. A shared day means both training, not the same workout |
| Inherited limits | ✅ §10.2b a buddy's training avoids arrive SOFT; food and conditions never transfer |
| Buddy absence | ✅ §10.5 declared travel, mid-week illness, silent fallback. The partner keeps a complete week |
| Exercise selection | ✅ vocabulary scoped by access, home kit, category and level; isolation capped per muscle; no movement more than 3× a week |
| Menu variety | ⬜ nothing tracks recent meals, so the same week can repeat |
| Password reset | ⬜ blocked: no mail delivery decided. Invites are issued by hand today |

## What is being worked on

**The food logging screen.** The intelligence behind Yoked beats what it was built from;
the screen you touch every day for fourteen straight days does not, and that stretch comes
first and gives nothing back. Someone bails during baseline and never sees the coaching.

Established by comparing the live screen against `source-projects/keto-extract`:

1. **A logged entry cannot be corrected.** If search returns 100g of mustard and you ate a
   teaspoon, every number downstream is wrong — the day, the drift detection, the check-in,
   and the menu Claude writes off the back of it. `PATCH /api/nutrition/entries/{id}`,
   `Nutrition::updateEntry()` and `api.nutrition.updateEntry()` all exist and **nothing in
   the UI calls them**. The gap is a control and the rescale maths, not a feature.
2. **No servings field before adding** a search or scan result. The barcode flow is the only
   place that ever asks, and search is the commoner path.
3. **The delta row renders with no plan.** "Cooked in oil? Nudge it." is a correction against
   a *prescribed* meal; during baseline there is nothing prescribed, so it is a prompt for a
   case the user is not in. It is also calories-only, and cannot say "and 3g of fat".
4. **No macro rings.** The day's four numbers are a text line that reads as data rather than
   progress. The information is already on the day payload.
5. **Search, scan and favourites are nested inside every meal**, because they are the
   DEVIATION path — the answer to "I ate something other than the plan". During baseline
   deviation is the only path, so a control shaped like a second choice is the only choice,
   repeated five times with its sub-tabs resetting each time.

Rescaling is automatic and server-side: if the maths can be done it gets done, and it is all
or nothing rather than some foods scaling and others asking. Item 5 gets a spec document
before any code, because it changes the shape of the screen and interacts with the planned
meal model in ways 1-4 do not.

Not adopted from Keto: editing the four meal totals to compensate for a wrong entry. It
makes the day's number right while leaving the record a lie, and Claude reads the record.

**Next after that: training logging.** Untouched so far and the larger of the two halves.

### Running the tests

```
sh bin/testall.sh              every PHP suite, ~20s, free
sh bin/lintall.sh              parse-check every PHP file
cd app && npm run drive        re-seed the fixtures, then drive the browser
```

`bin/testall.sh` reports a suite as passing only if it prints an explicit
`N passed, 0 failed` AND exits 0. An earlier version matched a progress line and
announced ALL GREEN over a run that had been killed by a timeout.

**Nothing in `testall.sh` calls the API.** Every suite that can is opt-in behind
`--live`: `test-plans.php` (two real week generations), `test-claude.php`,
`test-chat.php`, `test-vetoes.php`, and `test-skeleton-live.php`.
`bin/test-plans.php` seeds the two users from the specs, generates their weeks, and
asserts the output against the spec decisions.

`test-claude.php` was the exception until it was not: it called the API by DEFAULT and
took `--offline` to skip, which put six paid requests inside the sweep documented as
"every PHP suite, ~20s, free" and run after every deploy. The flag is inverted now.
`--offline` is still accepted and does nothing, because silently ignoring a flag that
used to change behaviour is worse than honouring it.

The browser suite mutates its fixture, so `npm run drive` re-seeds before running.
`npm run drive:logging` skips that and is only right straight after a manual seed.

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

Every PHP suite runs on the server via SSH. `bin/testall.sh` runs them all; the
individual scripts are listed here because you will usually want just one.

```sh
php bin/envcheck.php          # PHP version, extensions, outbound HTTPS
php bin/dbcheck.php           # connection, table count, FK count
php bin/smoketest.php         # schema assertions, rolled back
php bin/test-goals.php        # evaluator, incl. keto parity
php bin/test-schedule.php     # date arithmetic; no DB, no API
php bin/test-baseline.php     # observation window and graduation
php bin/test-checkin.php      # opening, lateness, skipping
php bin/test-drift.php        # escalation ladder, nudges, notifications
php bin/test-tomorrow.php     # the evening window, prep flags, dismissal
php bin/test-vetoes.php       # SPEC-safety 7: promotion is SOFT-only
php bin/test-settings.php     # the profile; a hard constraint has no off switch
php bin/test-visibility.php   # who sees what; the privacy flags
php bin/test-friends.php      # search cannot enumerate; blocking reveals nothing
php bin/test-buddies.php      # no pairing without a friendship; either side can unpair
php bin/test-buddy-schedule.php  # the grid is never rewritten; a conceded day is legal
php bin/test-claude.php       # API client, shape checks only; --live to call the API
php bin/test-logging.php      # over real HTTP: food, training, check-in
php bin/test-plans.php        # generation; --live to actually generate
php bin/test-admin.php        # the guards; asserts no member-delete route exists
php bin/test-skeleton-live.php   # §10.6 the shared skeleton both buddies derive
```

The exercise library has its own tooling, all dry-run first:

```sh
php bin/import-exercises.php --report   # what would change, nothing written
php bin/import-exercises.php --audit    # rule-based checks on the mapping
php bin/import-exercises.php --commit   # actually write
php bin/aicalls.php                     # last 20 API calls: tokens, retries, cost
```

The logging UI is driven in a real browser, because the client/server contract is
where the interesting failures live and a build only proves it compiles.

```powershell
cd app; npm run drive           # re-seed, then drive
```

The fixtures must be seeded on the day you run them: the screen opens on today and
the plan week has to contain it. `npm run drive` handles that. The suite mutates
what it seeds, so running `drive:logging` twice without re-seeding will refuse to
start rather than produce a screenful of misleading failures.

Four fixtures, all `coaching_paused` so cron never spends money on them:

```
uitest_logging   active, plan for today, constraints of every facet
uitest_baseline  day 3 of 14, no plan
uitest_review    a real evening review_hour, in a timezone picked to be inside it
uitest_admin     an admin, plus three invites: one open, one expired, one used
```

`uitest_admin` is a fixture rather than a promoted real account for two reasons: the
last-admin guards need two admins to be exercisable at all, and a suite that suspends or
demotes a real account is one bug away from locking somebody out of their own app.

The browser suite also guards three things a unit test cannot see: that the page
never scrolls sideways at 360px, that the accent stays spent once per view, and that
**every selected control looks selected**. That last one has shipped broken twice —
both times a chip using `role="radio"`/`aria-checked` against CSS that only styled
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
- **The log is a record, not a running total.** A correction fixes the entry that is wrong,
  never a total that compensates for it. The source app edits meal totals instead, which
  makes the day's number right while leaving the record a lie — and Claude reads the record
  to write next week's menu, so a lie there is a lie in the plan.
- **Repetition is a within-week problem.** The same movement on the same weekday for two
  months is a programme; six times in one week is a defect. The ceiling is three, and a
  session never repeats a movement at all.
- **Existing exercise rows are canonical.** `logged_exercises` and `prescribed_exercises`
  reference them with `ON DELETE RESTRICT`, so a new name that means an existing movement
  becomes an alias. Deleting or renaming detaches load history silently.
- **Suspension, never deletion.** The cascade from a user reaches plans, logged days,
  photos, buddy pairs and check-ins with no undo, and "stop this account working" is the
  real requirement. A used invite is not revocable either: it is the only record of who let
  a given person in.

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
