# Schema Overview

38 tables across four migrations. MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4.

**Status: unverified.** Written but never executed — there is no MySQL available
in the dev environment. Static checks passed (FK types, table ordering, balanced
parens, all InnoDB), but running `bin/migrate.php` against a real database is a
required next step before building on this.

## Conventions

Matching the Friendspace house style:

- `BIGINT UNSIGNED` ids throughout. The keto-extract proposal declared
  `INT UNSIGNED` for user FKs against a `BIGINT users.id` — MySQL rejects
  mismatched FK types, which is why that proposal could never have run.
- `DATETIME`, not `TIMESTAMP`. Everything UTC; the client converts.
- `uk_` unique, `idx_` index, `fk_` foreign key.
- Polymorphic columns (`plan_versions.trigger_id`, `vetoes.subject_id`,
  `notifications.subject_id`) deliberately carry no FK.

## Columns vs. JSON

The rule applied throughout: **anything code reads, filters, or validates
against gets a column. Anything only Claude reads as context stays JSON.**

So the availability grid is seven real rows per user (queried on every
generation), while "what does success look like?" is free text (never parsed),
and onboarding answers are a generic key/value table (resumable, and the
question set will churn during build).

## 001 — foundation

Identity and infrastructure, derived from Friendspace and trimmed.

| Table | Purpose |
|---|---|
| `schema_migrations` | Applied-migration log. Friendspace lacked one; that's how migration 010 got skipped. |
| `users` | Identity + `onboarding_state` gate (`pending → in_progress → baseline → active`). |
| `invites` | Invite-only signup. |
| `auth_tokens` | Persistent auto-login, selector:validator, 60-day sliding. |
| `rate_limits` | Fixed-window limiter, arbitrary bucket keys. |
| `friendships` | Normalized ordered pair. Prerequisite for buddy pairing. |
| `media` | Images + progress photos, stored outside the web root. |
| `notifications` | In-app only. Nudges are notifications with a nudge type. |

## 002 — profile, onboarding, safety

| Table | Purpose |
|---|---|
| `profiles` | Stable facts: metrics, tone, nudge settings, core emphasis, daily-life baselines. |
| `onboarding_answers` | One row per question, keyed `'2.3'`, `'6.7'`. Resumable. |
| `goals` | A table, not columns — goals change and history is coaching context. Holds the requested timeline, Claude's feasibility ruling, and the actual horizon. |
| `availability` | **Seven rows per user, one per weekday.** Access is a property of a *day*, not a user. |
| `food_preferences` | Structure dial, cooking capacity, and the eat-out request/agreed pair. |
| `training_preferences` | Experience, self-ratings, cardio willing/refused, split. |
| `user_constraints` | **Constraints as data.** `hard`/`soft` tiers, reasons, condition guidance, progression targets, target floors. |
| `user_constraint_audit` | Every change, old → new. Any plan can be explained after the fact. |

`user_constraints` is the table that makes the whole safety model work: no
hardcoded rule anywhere says "never prescribe X to a diabetic." Hard constraints
are validated in code post-generation; soft ones live in the prompt.

## 003 — plans

| Table | Purpose |
|---|---|
| `exercises` | Canonical library. Grows by promotion — Claude proposes, accepted exercises get a canonical slug. `pattern` is what lets a buddy pair share a skeleton while diverging on the variant. |
| `exercise_aliases` | Collapses "DB Bench" into "Dumbbell Bench Press" so PR history doesn't fragment. |
| `buddy_pairs` | Requires an accepted friendship, both opt in, either can unpair. |
| `plan_versions` | **The load-bearing table.** One row per generated version, with the reason and trigger. `superseded_at IS NULL` = live. |
| `prescribed_sessions` | `is_committed` implements committed-vs-optional. Warm-ups prescribed, `warmup_required` for cardiac/joint modifiers. |
| `prescribed_exercises` | `block` separates main work from the core block. Per-exercise `rationale` for non-obvious substitutions. |
| `prescribed_days` | Per-day macro targets + the goal constraint JSON. |
| `prescribed_meals` | **Structured `ingredients` JSON, not prose** — allergy validation needs structure, and it makes "ate as planned" one tap. |

Plans are versioned rather than mutable because adherence needs a stable
referent, Claude needs to see *why* a plan changed, and chat can change plans —
so changes are frequent and must be attributable.

## 004 — actuals

| Table | Purpose |
|---|---|
| `logged_days` | Daily check-in + cached goal verdict. `macro_short_but_ok` is the "calories short, macros landed" case. |
| `logged_sessions` | `trained_with_buddy` powers the shared adherence signal. |
| `logged_exercises` | Per-**exercise** (not per-set): weight, reps, one RPE. RPE drives progression. |
| `logged_meals` | `adherence` enum: `as_planned` is the one-tap path. Additive signed deltas. |
| `logged_entries` | Individual food items; net carbs computed at intake. |
| `favorite_foods` | Case-insensitive dedupe via the collation. |
| `food_barcodes` | Open Food Facts cache — repeat scans skip the network. |
| `weekly_checkins` | Weight, six measurements, self-report, and the emphasis request. Created by cron. |
| `checkin_photos` | Front/side/back, every two weeks. |
| `emphasis_grants` | §7.2a adherence dividend. Declined requests kept — poor adherence asking to drop skipped work is a conversation. |
| `vetoes` | Reason required. `scope` = today vs. standing; standing promotes to a soft constraint. Declined vetoes still logged — the pattern is signal. |
| `chat_turns` | Interjection log. `resulting_plan_version_id` set only when Claude decided to change the plan. |
| `circumstances` | Facts with expiry. "Travelling this week" ends; "I hate salmon" promotes. |
| `ai_calls` | Every API call: tokens, retries, constraint violations, cost. |

## The prescribed/actual pairing

The core signal the coaching engine reads:

```
prescribed_sessions  ←→  logged_sessions.prescribed_session_id
prescribed_exercises ←→  logged_exercises.prescribed_exercise_id
prescribed_meals     ←→  logged_meals.prescribed_meal_id
prescribed_days      ←→  logged_days.plan_version_id
```

Each nullable, because unprescribed activity is real: baseline week 1 has no
plan, and a user who just goes to the gym should still be able to log it.

## Known gaps

1. **Never executed.** Needs a real MySQL run.
2. **No seed data.** The exercise library needs a starting set of system
   exercises, and the goal-constraint presets need seeding.
3. **`prescribed_meals.slot` is a fixed enum** of six slots. A user wanting two
   afternoon snacks can't express it. Fine for now; revisit if it bites.
4. **No streak tables.** Deferred with social/gamification.
5. **Weight/measurement trend** is computed from `weekly_checkins` rather than
   cached. Fine at four users; would need a rollup at scale.
