# SPEC — Onboarding Quiz

**Status:** DRAFT for review. Question wording and set are expected to change.

Everything Claude knows about a user originates here. This is the highest-leverage
artifact in Yoked: a weak onboarding produces a plausible-sounding plan built on
nothing.

## Design rules

1. **Every answer has a consumer.** Each question below is tagged with what reads
   it. If nothing reads an answer, the question is cut — onboarding length is the
   main thing standing between a user and their first plan.
2. **Structured, not prose.** Answers are enum / number / multi-select wherever
   possible so code can enforce them. Free text is for things only a human can
   phrase, and is passed to Claude as context rather than being parsed.
3. **Claimed, not verified.** Onboarding records what the user *says*. The baseline
   fortnight records what they *do*. Claude is explicitly shown both and told the
   second overrides the first. This is why the baseline is unskippable.
4. **Constraints are captured with a reason and a tier.** Never a bare flag —
   `hard`/`soft` plus why, so it can be revisited later (see `SPEC-safety.md`).
5. **Nothing here gates on medical clearance.** Clearance is recorded as
   self-reported and flagged as such. It is context for Claude, not a lock.

## Tiering

- **§1–§3 are required** to generate anything. Blocking.
- **§4–§8 are required** to finish onboarding, but can be completed across
  sessions. The quiz is resumable; partial answers persist.
- **§10 is optional** and can be skipped or revisited any time.

Target: ~12 minutes for §1–§8 at a normal pace.

---

## §1 Identity & baseline metrics

*Consumer: profile, all target math, Claude context*

| # | Question | Type |
|---|---|---|
| 1.1 | Date of birth | date |
| 1.2 | Sex assigned at birth *(drives BMR/TDEE estimation — asked for that reason and stated as such in the UI)* | enum: male, female |
| 1.3 | Height | number (in/cm) |
| 1.4 | Current weight | number (lb/kg) |
| 1.5 | Units preference | enum: imperial, metric |
| 1.6 | Starting measurements — waist, hips, chest, arm, thigh, neck | 6 × number, each optional |
| 1.7 | Starting photos — front, side, back | 3 × optional upload |

**Note:** 1.6 and 1.7 are optional here but prompted again at the end of the
baseline fortnight, when the user has more reason to care. Waist is the single most
important measurement for a visceral-fat goal — the UI should say so rather than
presenting six equal blanks.

---

## §2 Goals

*Consumer: goal record, plan generation, weekly evaluation*

| # | Question | Type |
|---|---|---|
| 2.1 | Primary goal | enum: lose fat, build muscle, recomp (both), improve cardio, improve strength, general health |
| 2.2 | Secondary goals | multi-select, same list |
| 2.3 | In your own words, what does success look like? | free text |
| 2.4 | Is there a deadline or event? | free text + optional date |
| 2.5 | Requested timeline | enum: 8 weeks, 12 weeks, 16 weeks, 6 months, a year, no fixed timeline |
| 2.6 | Which matters more — the scale, or how you look and feel? | enum: scale, look/feel, both |

**2.3 is the most valuable question in the quiz.** "Look like Brad Pitt in Fight
Club" and "slay in a little black dress" carry more usable signal than any enum —
they encode physique target, motivation style, and self-awareness at once. Store
verbatim, never parse, always include in the generation prompt.

**2.5 is a request, not a commitment.** Per the scoping decision: Claude has final
say on feasibility. If the requested timeline is unrealistic for the stated goal,
the first plan says so plainly and proposes what *is* achievable in that window.
Longer timelines unlock more varied programming; short ones constrain it.

**2.6 decides what the progress screen leads with** — trend weight, or
measurements and photos.

---

## §3 Medical & safety

*Consumer: `safety_constraints` (hard tier), plan generation, meal generation*

| # | Question | Type |
|---|---|---|
| 3.1 | Food allergies or intolerances | multi-select + free text → **hard** constraints |
| 3.2 | Conditions affecting diet or exercise | multi-select (diabetes T1/T2, heart condition, hypertension, thyroid, PCOS, IBS/GI, joint, other) + free text |
| 3.3 | Medications affecting appetite, energy, or heart rate | free text |
| 3.4 | Current or past injuries | repeatable: body area + description + `hard`/`soft` + "work up to this?" |
| 3.5 | Movements you can't do, or hate | multi-select + free text → **soft** unless flagged |
| 3.6 | Cleared by a physician for vigorous exercise? | enum: yes, no, haven't asked → **recorded as self-reported, does not gate** |
| 3.7 | Anything else a trainer should know? | free text |

**3.4 is where the tier split earns its place.** A bad knee marked `soft` +
"work up to this" is a progression target for month three, not a permanent
exclusion. Marked `hard`, it never appears. Same question, different tier, and the
user chooses.

**3.2 free text matters as much as the checkboxes.** "T2 diabetic, MI five years
ago, heart recovered well, diabetes is the day-to-day limiter" is exactly the
nuance the enum destroys.

---

## §4 Food — habits & preferences

*Consumer: menu generation, veto seeding*

| # | Question | Type |
|---|---|---|
| 4.1 | Foods you won't eat | multi-select + free text → **soft** constraints |
| 4.2 | Cuisines you like | multi-select |
| 4.3 | Dietary pattern | enum: none, vegetarian, vegan, pescatarian, keto/low-carb, paleo, halal, kosher, other |
| 4.4 | Meals you actually eat | multi-select: breakfast, lunch, dinner, snacks |
| 4.5 | Meals you'd rather skip | multi-select, same |
| 4.6 | How much structure do you want? | enum: **spell everything out** / **give me targets and options** / **mix — structured dinners, free rein elsewhere** |
| 4.7 | Honestly, how do you eat now? | free text |
| 4.8 | Caffeine / energy drinks per day | number |
| 4.9 | Alcohol per week | number |
| 4.10 | Water — typical daily intake | enum: barely any, some, a lot, no idea |

**4.4 + 4.5 shape the menu structure directly.** "No breakfast" produces a light
morning snack and a more substantial lunch rather than a breakfast the user ignores.

**4.6 is the freedom dial** from scoping. `spell everything out` → every meal fully
specified. `targets and options` → macro targets plus 2–3 suggestions per slot.
Applies per-slot, and the big-3-specified / snacks-as-targets default is the `mix`
setting.

---

## §5 Food — logistics

*Consumer: menu generation (recipe complexity, prep time, cost)*

| # | Question | Type |
|---|---|---|
| 5.1 | Cooking skill | enum: can't cook, basic, competent, good, excellent |
| 5.2 | Weekday minutes available to cook | number |
| 5.3 | Weekend minutes available to cook | number |
| 5.4 | Cooking for how many? | number |
| 5.5 | Will you meal prep in advance? | enum: yes eagerly, sometimes, no |
| 5.6 | **Now:** eating out / takeaway per week | number |
| 5.7 | **Going forward:** how many meals per week should the plan leave unplanned for eating out? *(a request — Claude has final say)* | number |
| 5.8 | Grocery budget sensitivity | enum: tight, moderate, not a concern |
| 5.9 | Kitchen equipment you have | multi-select: oven, stovetop, microwave, air fryer, slow cooker, instant pot, grill, blender, food scale |

**Current state vs. preference.** §5 mixes two kinds of fact and the UI must label
which is which, or answers are unusable:

- **Current habit** (compared against the baseline fortnight): 5.6
- **Capacity — a hard limit on what can be prescribed:** 5.2, 5.3, 5.4, 5.9
- **Preference — how the user wants the plan shaped:** 5.5, 5.7, 5.8

5.6 and 5.7 were one question in the first draft and that was a mistake: "I eat out
five times a week" and "plan around two meals out" are different facts, and the gap
between them is itself the goal. Ask both.

**5.7 is a request, not a setting.** Same rule as the timeline in 2.5: Claude has
final say. A user asking for 7 unplanned meals a week is asking for no meal plan at
all, and Claude should push back and negotiate to a number that still serves the
goal — naming the tradeoff rather than silently overriding. Store both the requested
number and the number Claude settled on; the gap is useful signal, and a user who
keeps asking for more unplanned meals is telling you something about the menu.

**5.1 vs 5.2 is the key tension, and it's User #1 exactly:** a good cook with 20
minutes needs efficient recipes, not simple ones. Those are different requests and
the menu generator must not conflate them. A food scale in 5.9 also determines
whether quantities can be given by weight or must be given in household units.

---

## §6 Training — history & preferences

*Consumer: plan generation, exercise selection, starting loads*

| # | Question | Type |
|---|---|---|
| 6.1 | Training experience | enum: never, beginner (<1yr), intermediate (1–3yr), advanced (3yr+), returning after a long break |
| 6.2 | Currently training? | enum: not at all, occasionally, 1–2×/wk, 3–4×/wk, 5+×/wk |
| 6.3 | Have you been in great shape before? | yes/no + free text |
| 6.4 | Self-rated strength | enum: poor, below average, average, good, strong |
| 6.5 | Self-rated cardio | enum: poor, below average, average, good, excellent |
| 6.6 | Strength lifts you know how to do | multi-select (squat, deadlift, bench, OHP, row, pull-up, lunge, hip thrust, …) |
| 6.7 | Best recent lifts, if known | repeatable: exercise + weight + reps |
| 6.8 | Cardio you'll actually do | multi-select: treadmill, elliptical, rower, bike, stair, walking, hiking, running, swimming, tennis, pickleball, skiing, classes |
| 6.9 | Cardio you refuse | multi-select, same |
| 6.10 | Preferred training split | enum: full body, upper/lower, push/pull/legs, no preference |
| 6.11 | How do you feel about cardio? | free text |

**6.3 is high-signal for User #2** — "my last serious gym grind worked amazingly
well" means a proven-effective approach exists and Claude should ask what it was
rather than inventing something.

**6.7 seeds starting loads.** Absent, the baseline fortnight establishes them
instead, which is a large part of what the baseline is *for*.

**6.9 is a hard-ish constraint.** Prescribing cardio a user has explicitly refused
is the fastest way to lose adherence. Claude may propose an alternative, never the
refused item.

---

## §7 Schedule & equipment

*Consumer: plan generation (session count, session length, day placement)*

**Availability is per-day, not per-user.** 7.1 is a single repeating question asked
once for each of Mon–Sun. Gym access is a property *of a day*, not of a person.

### 7.1 — Weekly availability grid *(one row per weekday)*

| Field | Type |
|---|---|
| Can you train this day? | enum: yes, no, sometimes |
| Minutes available | number |
| What do you have access to? | enum: full gym, home gym, bodyweight only, outdoors only |
| Equipment *(if home gym)* | multi-select |
| Usually chaotic? | bool |

### Remaining

| # | Question | Type |
|---|---|---|
| 7.2 | Preferred time of day | enum: early morning, morning, midday, afternoon, evening, varies |
| 7.3 | Work / school pattern | enum: standard weekday, shift work, irregular, student, retired, other |
| 7.4 | Anything else about your week? | free text |

**Why the grid.** "Full gym at work Mon–Fri, bodyweight at home on weekends" is a
completely ordinary pattern, and a single user-level `gym_access` enum flattens it to
`mixed` — destroying the exact fact plan generation needs. A weekend session then
gets prescribed barbell work the user cannot perform, which is the fastest possible
way to break trust in the plan. The grid also absorbs per-day minutes, so Saturday
can be 90 minutes and Tuesday 40.

**This is the hardest structural constraint in plan generation.** Four days at 45
minutes is a fundamentally different program than six at 90, and no amount of good
intent overrides it. `sometimes` days are schedulable but are where Claude places
the session it can most afford to lose.

**Chaotic informs placement, not exclusion** — put the short session or the rest day
there rather than refusing to schedule it.

---

## §8 Daily life

*Consumer: plan generation (recovery, volume), daily check-in baselines*

| # | Question | Type |
|---|---|---|
| 8.1 | Typical sleep per night | number (hours) |
| 8.2 | Sleep quality | enum: poor, fair, good, great |
| 8.3 | Day-to-day activity level | enum: sedentary, lightly active, moderately active, very active |
| 8.4 | Typical stress level | enum: low, moderate, high, very high |
| 8.5 | Typical energy level | enum: drained, low, ok, good, high |
| 8.6 | Rough daily step count, if known | number, optional |

These double as the **baselines the daily check-in compares against**. "Energy: low"
means nothing without knowing that low is normal for this user — a check-in metric
is only useful as a delta.

---

## §9 Coaching style — MOVED OUT OF THE QUIZ

*Consumer: tone applied to all generated copy and nudges*

> **These are no longer onboarding questions.** All six shipped fields moved to the
> Profile's settings screen (`Settings::save`, `app/src/components/Settings.jsx`), and
> section 9 was removed from `Onboarding::SECTIONS` and from `app/src/questions.js`.
>
> The reason: none of them is really a question about the user. They are controls over how
> the app behaves, and asking someone to choose a coaching voice before they have read a
> single word the coach writes is asking them to guess. Every column keeps its schema
> default, so a user who never opens the Profile gets exactly what this section would have
> given them.
>
> 9.7 and 9.8 were never built. They were declared in `SECTIONS` but had no question in the
> client and no projector, so they inflated the section's "total" by two and accepted writes
> that went nowhere. They are the buddy system (SPEC-coaching §10), and belong with that
> feature rather than here.
>
> The table below is kept as the record of what the fields are and what their defaults mean.

| # | Question | Type |
|---|---|---|
| 9.1 | Coaching tone | enum — see below |
| 9.2 | How hard should nudges get when you go quiet? | enum: leave me alone, gentle, persistent, relentless |
| 9.3 | Days quiet before nudges escalate | number, default 3 |
| 9.4 | How much explanation do you want? | enum: just tell me what to do, brief reasons, explain the reasoning |
| 9.5 | Hide progress photos from other users? | bool, default true |
| 9.6 | Hide weight and measurements from other users? | bool, default true |
| 9.7 | Interested in training with a buddy? | enum: yes, maybe later, no |
| 9.8 | If yes — who? | user picker, friends only |

### 9.1 — Tone options

Each tone is a named voice with a written character brief that goes into every
generation prompt. The brief is what makes the tone real; the label is just how the
user picks it.

| Tone | Voice |
|---|---|
| **Sarcastic humorous hardass** | Dry, teasing, profane-adjacent. Roasts excuses, never the person. The joke is always at the situation's expense. |
| **High school coach** | Results-driven and relentlessly pushing for more. Never satisfied, but always in your corner. "Good. Now do it again heavier." |
| **Motivational speaker** | Big energy, stakes-raising, aspirational. Every session is The Session. |
| **Funny and positive** | Light, warm, genuinely silly. Celebrates small wins without irony. |
| **Friendly and encouraging** | Calm, patient, supportive. The default for anyone who doesn't want a personality. |
| **Direct, no fluff** | Says the thing and stops. No jokes, no pep, no padding. |

**"Clinical & data-driven" was cut** — it differed from *direct, no fluff* only in
whether numbers appeared, and that is 9.4's job, not 9.1's. Tone controls *voice*;
9.4 controls *how much explanation*. Keeping both as tones would have meant two
dials fighting over the same output. Any tone can be combined with
"explain the reasoning" to get a data-heavy result.

**Tone applies globally** — including to food and body. Per the scoping decision,
there is no carve-out; a user who picks sarcastic hardass gets it everywhere.

**9.4 is a real dial, not a cosmetic one.** "Just tell me what to do" and "explain
the reasoning" produce meaningfully different plan output for the same underlying
prescription.

**Implementation note.** Tone is a prompt-level character brief, never a
post-processing pass. Generating neutral copy and then "adding personality" produces
uniformly worse output than asking for the voice up front.

**9.7/9.8 seed the buddy system** (`SPEC-coaching.md` §10). Asked here so pairing is
discoverable rather than a feature users stumble into, but both sides must opt in, and
either can unpair at any time. Requires an existing friendship. Pairing shares
*training* — completed sessions and shared-session performance — and never overrides
9.5/9.6: training together is not consent to share body metrics.

**9.5 / 9.6 default to hidden.** Users can see each other per the scoping decision,
but sharing a body metric should be a choice the user makes, not one they discover
they made.

---

## §10 Optional context

*Consumer: Claude context only, never parsed*

| # | Question | Type |
|---|---|---|
| 10.1 | What's worked for you before? | free text |
| 10.2 | What's failed, and why do you think it failed? | free text |
| 10.3 | What will make you quit? | free text |
| 10.4 | Anything else? | free text |

**10.2 and 10.3 are worth more than their placement suggests.** "I know what to do,
I just don't do it" is a completely different coaching problem than "I don't know
where to start," and it changes what the app should emphasize — accountability
versus instruction. Optional because they're hard questions to answer cold; prompt
again after the first month, when the user has evidence.

---

## Resolved in review

- **§5 current-vs-preference ambiguity** — 5.6 split into current habit (5.6) and
  planning preference (5.7); every §5 question now labelled by kind.
- **§7 availability** — restructured into a per-weekday grid. Gym access is a
  property of a day, not of a user.
- **§9.1 tone** — "clinical & data-driven" cut as redundant with 9.4; added high
  school coach, motivational speaker, funny and positive.
- **§10 placement** — end of onboarding, as an optional final section.

## Open items for review

1. **Length.** ~80 questions, counting the §7 grid as one repeatable. §1–§3 alone
   gets to a usable first plan. Confirm the required/optional split is right.
2. **Sex assigned at birth (1.2)** is asked for BMR/TDEE estimation only. Worth
   confirming the wording and that the stated reason appears in the UI.
3. **6.7 (recent lifts)** overlaps what the baseline fortnight measures directly.
   Keep both — a claim to compare against reality is exactly the point of §3 rule 3.
4. **Tone free-text.** Six presets now. Worth also allowing a free-text tone
   description for a user who wants something specific, or do the presets cover it?
