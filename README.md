# Yoked

An AI personal trainer and nutrition coach. Claude authors each user's weekly workout
routine and menu, observes what they actually do, and adapts.

Small, invite-only, PWA on PHP 8 / MySQL shared hosting.

## Status

**Specification.** No implementation yet. Scope is settled; the schema is next.

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

PHP 8.1+ · MySQL 8 · vanilla PDO · no Composer · React SPA · PWA · SiteGround shared
hosting · Claude API for coaching.
