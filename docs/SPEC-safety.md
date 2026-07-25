# SPEC — Safety Constraints

**Status:** DRAFT for review.

Yoked lets Claude author the plan. That only works if the app holds facts Claude
cannot talk itself out of. A constraint is such a fact: a per-user statement about
what may or may not be prescribed.

Related: `SPEC-onboarding.md` §3–§7 (where constraints originate),
`SPEC-coaching.md` (where they are enforced).

---

## 1. Core principle

**Constraints are data, not code.** There is no hardcoded rule anywhere in Yoked
saying "never prescribe X to a diabetic." There is a `constraints` table, seeded
from onboarding, editable by the user, and consulted on every generation.

This matters for the reason the pivot notes made about the keto rule: a rule baked
into four places is a rule that cannot change. Yoked's users understand their own
bodies, and the app's job is to hold what they told it — not to overrule them.

**Two consequences, stated plainly:**

- A user can edit or delete any constraint about their own body. The app does not
  know better.
- A constraint cannot be changed by *conversation*. Claude cannot be persuaded that
  an allergy has lapsed. Editing a constraint is a deliberate act in the profile,
  not a sentence in a chat. See §6.

---

## 2. The two tiers

| Tier | Meaning | Enforcement |
|---|---|---|
| **hard** | Never prescribe. | Checked in code after generation. A violation is a bug. |
| **soft** | Strongly avoid. | In the prompt. Claude may propose it *with a reason*; the user accepts or vetoes. |

### Why two tiers

One tier fails in both directions. Everything-hard means a bad knee permanently
excludes squatting, so the user never progresses past their worst day. Everything-soft
means an allergy is a suggestion.

The split maps onto a real distinction: **hard is about harm, soft is about
preference and current capacity.** Capacity changes, which is why soft constraints
carry a progression path (§4).

### Tier assignment

| Source | Default tier |
|---|---|
| Food allergy / intolerance (§3.1) | **hard** |
| Medical condition (§3.2) | **hard**, as a modifier — see §3 |
| Injury marked "can't do" (§3.4) | **hard** |
| Injury marked "work up to" (§3.4) | **soft** + progression target |
| Movements disliked (§3.5) | **soft** |
| Foods won't eat (§4.1) | **soft** |
| Dietary pattern (§4.3) | **hard** (vegan, halal, kosher) or **soft** (paleo, low-carb) |
| Refused cardio (§6.9) | **soft**, but see below |
| Equipment not available (§7.1 grid) | **hard** — physically impossible, not a preference |

Defaults only. The user sets the tier at capture time and can change it later.

**Refused cardio is deliberately soft.** Prescribing cardio someone has explicitly
refused is the fastest way to lose adherence, so in practice it behaves like hard.
But User #1's stated goal is "improve cardio" while hating all of it — if every
disliked modality were hard, there would be nothing left to prescribe. Soft lets
Claude propose the least-hated option with a reason, which is the actual coaching
problem.

---

## 3. Conditions are modifiers, not blocks

A medical condition rarely means "prescribe nothing." It means "prescribe
differently." Modelling conditions as blocks produces a useless plan; modelling them
as modifiers is the whole point.

Each condition carries **guidance text** included in generation, not a veto:

| Condition | Modifier |
|---|---|
| Type II diabetes | Carb distribution and meal timing matter. Avoid large isolated carb loads. Not a carb ban. |
| Cardiac history | Include a real warm-up. Progress intensity gradually. Not an intensity ceiling. |
| Hypertension | Avoid prolonged breath-holding under load; caution on heavy overhead work. |
| Joint issues | Prefer lower-impact substitutions for the affected joint. |
| GI / IBS | Note trigger foods; prefer lower-FODMAP options when flagged. |

**Physician clearance is recorded, never enforced.** §3.6 stores yes / no /
haven't-asked as **self-reported**, surfaced to Claude as context. It does not gate
anything. Per the scoping decision: the users understand their own bodies.

---

## 4. Progression targets

A soft constraint may carry a **progression target** — a movement the user currently
can't or won't do, but wants to work toward.

```
{ movement: "back squat",
  reason: "left knee, old injury",
  tier: "soft",
  progression: { target: "back squat", status: "working_toward" } }
```

Claude is shown these and may prescribe **intermediate steps** (box squat, goblet
squat, leg press) as scaffolding toward the target. When the user reports the target
is achievable, the constraint is retired.

This is the mechanism that keeps the app from being permanently limited by whatever
was true on signup day. Without it, month-three programming is identical to week-one
programming, which is exactly the staleness the app exists to prevent.

---

## 5. Enforcement

### Hard constraints — post-generation validation

Claude generates a plan. Before the plan is persisted or shown, code validates it
against every hard constraint. A violation is not surfaced to the user as an
apology — it is a **regeneration** with the violation named in the retry prompt.

```
Plan → validate(hard constraints) → violation? → regenerate with explicit callout
                                  → clean?     → persist
```

Bounded retries (2), then fail loudly to a log rather than silently shipping a
violating plan. A plan that cannot be generated within constraints is a real signal
— usually a contradiction between constraints worth surfacing to the user.

### Soft constraints — prompt-level

Included in the generation prompt with their reasons. Claude may include a
soft-constrained item **only with a stated rationale**, which the user sees. If they
veto, the veto reason feeds back per `SPEC-coaching.md`.

### What validation actually checks

- **Menus:** every ingredient against hard food constraints (allergies, dietary
  pattern). Requires ingredients as structured data, not just recipe prose — a
  generation-output requirement, noted here because it constrains the plan format.
- **Sessions:** every exercise against hard movement constraints and the day's
  equipment from the §7.1 grid.
- **Targets:** calorie and macro targets against any explicit floors the user set.

---

## 6. What cannot change a constraint

Constraints change **only** through deliberate profile edits.

Specifically **not** through:
- Chat. "My knee's fine now" is a *stated circumstance* Claude may respond to by
  suggesting the constraint be reviewed — it does not edit anything.
- A veto reason.
- Claude's own inference from logged data.

The reason is narrow and worth stating: an LLM that can be argued out of a
constraint has no constraints. The user retains full control; the control just lives
in one deliberate place.

**Every change is audited** — old value, new value, who, when. Cheap, and it means a
plan can always be explained after the fact.

---

## 7. Schema sketch

Not final; the schema lands after `SPEC-coaching.md`.

```
user_constraints
  id, user_id
  kind        food | movement | cardio | condition | equipment | target_floor
  tier        hard | soft
  subject     'peanuts' | 'back squat' | 'type_2_diabetes'
  reason      free text, user-supplied
  guidance    free text, for conditions (§3)
  progression JSON, nullable (§4)
  source      onboarding | user_edit | veto_promotion
  active      bool
  created_at, updated_at

user_constraint_audit
  id, constraint_id, user_id, action, old_value JSON, new_value JSON, created_at
```

`veto_promotion` as a source is the path from `SPEC-coaching.md`: a standing veto
("never suggest salmon again") becomes a soft constraint. That is the one automated
write path, and it only ever creates **soft** constraints — never hard.

---

## 8. Applied to the two users

Not special cases — just what the general mechanism produces from their onboarding.

### User #1 — 52M, T2 diabetic, post-MI, poor cardio

| Constraint | Tier | Note |
|---|---|---|
| Type II diabetes | hard (modifier) | Carb distribution + meal timing guidance |
| Cardiac history, recovered | hard (modifier) | Warm-up required, gradual intensity progression |
| Physician clearance: self-reported | — | Context only, no gate |
| Cardio: dislikes essentially all | soft | Claude proposes least-hated with a reason |
| Prep time: 20 min weekdays | hard (capacity) | Efficient recipes, not simple ones |

Nothing here caps his effort. The couch is its own rate limiter.

### User #2 — 21F, ED history, recomp goal, poor intake

| Constraint | Tier | Note |
|---|---|---|
| Small deficit acceptable | — | Explicitly allowed per scoping |
| Protein floor | hard (target_floor) | Her stated priority; supports the recomp goal |
| Macro-adherence credit | — | **Scoring rule, not a constraint** — see below |

**No maintenance floor pinning her.** The earlier draft proposed one; it was wrong
and is removed. She is not fragile, is no longer chasing the scale, and a small
deficit suits her goal.

What she gets instead is a **scoring rule**: a day where protein and carbs land but
calories come in short scores as **adherent with a note**, not a failure. Coming from
a 500–700 kcal history, a normal intake looks like an enormous amount of food, and
punishing an honest attempt is how the app loses her.

This falls straight out of the existing constraint vocabulary in
`keto-extract/specs/SPEC-targets.md` — `protein: at_least`, `calories: range_pct`
with a generous low bound — so it needs no special-case code. It is also simply the
correct rule for anyone building an eating habit up from a low base, which is why it
belongs in the goal evaluator rather than in a per-user exception.

**Tone is not carved out.** If she picks sarcastic hardass, she gets it about food
too. Per the scoping decision.

---

## Resolved in review

1. **`target_floor` authorship** — **Claude proposes, user confirms.** Stored as
   user-owned once confirmed, and editable thereafter like any other constraint. The
   user is not asked to invent a protein floor from nothing during onboarding.
2. **Constraint conflict** — **fail at generation, not at capture.** Cheap to
   implement, and the honest response to vegan + legume/soy/nut allergy is a
   conversation, not a validation error mid-quiz. The failure surfaces which
   constraints collide.
3. **Retry count** — **two regeneration attempts**, then fail loud to the log. Never
   silently ship a plan that violates a hard constraint.
4. **Condition list** — **add as encountered.** §3 covers the two users plus the
   common cases; there is no value in enumerating conditions no user has.
