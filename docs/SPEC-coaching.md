# SPEC — The Coaching Engine

**Status:** DRAFT for review.

This is the core of Yoked and the part with no source material. Keto Tracker judged
a user against a target they set themselves. Yoked has Claude **author** the target,
the menu, and the training week — then observe what happened and adapt.

Related: `SPEC-onboarding.md` (what Claude knows), `SPEC-safety.md` (what Claude may
not prescribe).

---

## 1. The loop

```
onboard → baseline (2 wks) → generate week → user follows & logs
             ↑                    ↑                    ↓
             │                    │            daily check-in
             │                    │                    ↓
             │              adapt / reshuffle ←  drift detected?
             │                    ↑                    ↓
             └──── re-baseline ← weekly check-in ←──────┘
```

Five distinct jobs, specified below: **generate** (§3), **observe** (§4), **veto**
(§5), **interject** (§6), **adapt** (§7).

---

## 2. Plans are versioned artifacts

The single most important structural decision in Yoked.

A plan is not a mutable row that gets edited. It is a **version**, with a reason for
existing:

```
plan_versions
  id, user_id, week_start
  version         1, 2, 3…
  reason          initial | veto | interjection | drift_adaptation | check_in
  trigger_ref     the veto / chat turn / check-in that caused it
  generated_at
  superseded_at   null = current
```

Why this rather than mutation:

- **Adherence needs a stable referent.** "Did the user follow the plan?" is
  meaningless if the plan silently changed. Each logged day is measured against the
  version that was live when it was logged.
- **Claude needs history to coach.** Next week's decision reads what was prescribed,
  what was done, and *why the plan changed mid-week*. A mutated row destroys the
  third.
- **Chat can change the plan** (per scoping), so changes are frequent, and every one
  must be attributable and revertable.

Corollary: **prescriptions are never overwritten.** A vetoed meal stays in the
record, marked vetoed, with its reason and replacement.

---

## 3. Generation

### 3.1 Cadence

**A full week at once**, generated at the start of the week. Confirmed in scoping:
the user can shop for a menu and see the week's shape. Mid-week revisions produce new
versions (§7), not a different cadence.

### 3.2 Inputs

Everything below goes into the generation call. Deliberately generous — cost analysis
puts the whole app at roughly $10–15/month for four users, so there is no reason to
economize on context quality.

| Input | Source |
|---|---|
| Profile & metrics | onboarding §1 |
| Goal, timeline, success-in-own-words | §2 |
| Hard + soft constraints, with reasons | `SPEC-safety.md` |
| Condition guidance | safety §3 |
| Weekly availability grid | §7.1 |
| Food preferences, structure dial, logistics | §4, §5 |
| Training history, cardio prefs, split | §6 |
| Tone brief + explanation depth | §9 |
| **Baseline findings** | the 2-week fortnight (§8) |
| **Last 4 weeks: prescribed vs. actual** | adherence history |
| **Standing vetoes** | §5 |
| **Recent check-ins** | §4 |
| **Progression targets** | safety §4 |
| **Trend metrics** | weight/measurement trend, not raw points |

The user's stable profile is **prompt-cached** across calls — it barely changes and
it's the bulk of the context.

### 3.3 Output — training

Per training day, per the §7.1 grid:

- Session type: strength / cardio / hybrid / mobility / rest
- Focus: upper / lower / full / push / pull / core
- Target duration, respecting that day's available minutes
- Warm-up (**required** where a cardiac-history modifier is present)
- Exercises: canonical name, sets, target reps, target weight or intensity, rest
- Cardio: modality, duration, intensity target
- A one-line *why this session* — depth per §9.4

**Target weights need a starting point.** Onboarding §6.7 seeds them if known;
otherwise the baseline fortnight establishes them. This is a large part of what the
baseline is *for*.

**Warm-ups are prescribed, not left to the user.** Duration and content are part of
the session. Where a cardiac or joint modifier applies, the warm-up is marked
**required** and its duration is longer.

**The *why* line may attach per-exercise, not only per-session.** Any substitution
that would otherwise look arbitrary carries its reason — "trap bar rather than
straight bar; at 6'4" the higher neutral handle is kinder to your lower back."
This is the difference between a spreadsheet and a coach, and it is cheap.

#### 3.3a Committed vs. optional days

**Capacity is a commitment, not a target to exceed.** If a user states 5 days, the
plan prescribes **5 committed sessions**. Anything beyond is **optional** — labelled
as such, and it does **not** count toward adherence.

| | |
|---|---|
| **Committed** | The week. 5 of 5 = a successful week, full stop. |
| **Optional** | Bonus. Never debt. Ignoring every optional day is still a perfect week. |

A user who hits their stated capacity and sees `5/7` has been shown a failure they
never agreed to. That is the same manufactured-failure pattern removed for User #2 in
`SPEC-safety.md` §8, and it is worse here because the number came from the user.

**Cut by goal value, not by calendar position.** When capacity forces fewer sessions
than the ideal structure wants, drop what the *goal* needs least — never simply the
last day of the week. Concretely: a user whose lagging metric is cardio keeps the
cardio day committed and gives up a strength day, even though four strength days
"looks" like the more serious plan. A naive implementation drops Sunday and calls it
done; that is exactly wrong for the user who most needs the conditioning work.

Active recovery is its own session type and **never counts against** the committed
day count.

#### 3.3b Core on every strength day

**Built in by default, not asked as a preference.**

> Briefly violated and now fixed: a `profiles.core_emphasis` dial was added to the Profile
> with None/Light/Standard/Heavy, which contradicted this section twice over — it let a user
> switch core work off entirely, and it turned a structural programming decision into a
> preference. Removed. The column remains in the schema with a 'standard' default and nothing
> reads it. Core is always 8-12 minutes, as below.

Every strength session carries **8–12 minutes of core work**, pattern-matched to the
day's focus:

| Day focus | Core emphasis |
|---|---|
| Lower — squat | Anti-rotation, lower back, isometric holds |
| Lower — hinge | Lower back, anti-rotation, loaded carries |
| Upper — horizontal | Anti-extension, flexion |
| Upper — vertical | Anti-lateral-flexion, overhead stability |

**Why default rather than an onboarding question.** This is a training principle, not
a preference. Core strength underpins form, and form is what keeps a deconditioned
trainee safe under load. Asking "would you like core work?" invites a wrong answer to
a question that has a right one. Adjustable in settings (light / standard / heavy) for
someone genuinely getting enough elsewhere — but on unless turned off.

**Placement: after the main work, not before it.** A fatigued core ahead of heavy
squats is a form risk, which inverts the whole point. Brief anti-rotation activation
belongs in the warm-up; the real core block goes at the end. Same ten minutes, no
interference with the lifts that matter.

**For buddy pairs, the core block is identical** — same exercises, same sets, same
reps, same holds. See §10.2a. This is the one place the pair's prescriptions
deliberately converge rather than diverge.

### 3.4 Output — nutrition

- **Daily macro targets** — calories, protein, fat, carbs. Per-day, not per-week:
  training days and rest days differ.
- **Breakfast / lunch / dinner:** fully specified — name, **structured ingredients
  with quantities**, prep time, method. Per the structure dial (§4.6), and honouring
  which meals the user actually eats (§4.4/4.5).
- **Snacks:** macro targets plus optional suggestions.
- **Unplanned slots:** per the negotiated 5.7 number.

**Structured ingredients are non-negotiable.** `SPEC-safety.md` §5 validates every
ingredient against hard food constraints, and prose recipes cannot be validated. The
same structure makes "ate as planned" a one-tap log — which is the single biggest
adherence lever in the app.

### 3.5 Validation

Per `SPEC-safety.md` §5: validate against hard constraints, regenerate with the
violation named, two attempts, then fail loud. Nothing reaches the user unvalidated.

### 3.6 Timeline feasibility

Per scoping: the user requests a timeline, **Claude rules on it.** If the request is
unrealistic for the goal, the first plan says so plainly and proposes what *is*
achievable in the window. Longer timelines unlock more varied programming; short ones
constrain it.

Stored: requested timeline, Claude's assessment, and the plan's actual horizon.

---

## 4. Observation

### 4.1 Daily check-in

Two parts:

**User-reported** (from scoping): energy, sleep, soreness, overall mood. Cheap —
four taps.

**Computed:** did they log food? train as prescribed? how far off were the macros?

### 4.1a Next Day Review

Late each evening, the user is shown **tomorrow's session and meals** with a chance to
call an audible before the day arrives.

This exists because the Sunday plan cannot know everything. Travel discovered on
Wednesday for a Friday session has no other path into the plan — the plan was built
before the fact existed, and waiting until Friday morning wastes the day.

- Surfaces tomorrow's session, meals, and prep requirements
- Accepts an interjection (§6) or veto (§5) against tomorrow specifically
- Prep-heavy meals get flagged *tonight* — "tomorrow's dinner needs 40 minutes"
- Optional and dismissible; it must not become the noise the user was promised
  they'd be spared

A same-day audible is still possible via §6 — the Next Day Review just makes the
common case (something known in advance) cheap and quiet.

### 4.2 The escalation rule

From scoping, and the main cost-control mechanism:

> If things are close to prescribed, no adaptation until the weekly check-in. If days
> are missing or severely off, Claude asks questions.

Concretely:

| State | Action |
|---|---|
| **On track** — logged, roughly on target | SQL only. **No Claude call.** |
| **Minor drift** — one missed session, macros loose | Note it. Aggregate for the weekly check-in. |
| **Significant drift** — missed session + heavy overeat, or 2+ days unlogged | **Claude asks questions** (§7.1) |
| **Absent** — no data for N days (default 3, per §9.3) | Nudge, escalating per §9.2 |

The on-track path being pure SQL is what keeps this cheap and, more importantly, keeps
the app quiet. A coach who comments on every good day is noise.

### 4.3 Adherence measurement

Per day, against the plan version live at log time:

- **Training:** **committed** sessions completed vs. prescribed (§3.3a — optional
  sessions never count against the user); per-exercise prescribed vs. actual
  weight/reps; RPE
- **Nutrition:** prescribed vs. actual macros; meals eaten as planned vs. substituted
  vs. skipped
- **A verdict** from the goal evaluator

**Macro adherence outranks menu adherence.** Per scoping: if the user ignored the
menu but hit the macros, that is fine. Wildly misaligned is the problem. So the
nutrition verdict is computed on **macros**, with menu-following tracked separately
as a signal about menu quality — persistent substitution means the menu is wrong,
not the user.

**The User #2 rule**, per `SPEC-safety.md` §8: protein and carbs land, calories short
→ **adherent with a note**, not a failure. Falls out of the `at_least` /
`range_pct` constraint vocabulary from `keto-extract/specs/SPEC-targets.md`; no
special-case code.

### 4.4 Logging effort

Kept deliberately cheap, since abandoned logging ends the app:

- **Training:** per-**exercise** (not per-set) — actual weight, actual reps, one RPE.
  ~3 taps.
- **Nutrition:** "ate as planned" one-tap per meal; otherwise AI food search /
  barcode / favorites, all reused from Keto Tracker.
- **The additive `manualDelta`** correction pattern carries over intact — the
  most-used correction path in the original app.

---

## 5. Vetoes

### 5.1 Reason required

Confirmed in scoping. No bare rejection. The reason is the whole value.

### 5.2 Scope — the key distinction

| Scope | Meaning | Effect |
|---|---|---|
| **Just today** | "Thunderstorms, can't hike" | Replace this instance only |
| **Standing** | "I hate salmon, never again" | Replace + **promote to a soft constraint** |

Standing vetoes are the one automated constraint-write path (`SPEC-safety.md` §7),
and only ever create **soft** constraints.

Without this split the app either forgets a permanent dislike or reshuffles forever
around a trip that ended weeks ago.

### 5.3 Replacement

A veto produces a new plan version with a replacement that still serves the goal —
not a deletion. "No time to cook Thursday" → a faster meal hitting similar macros,
not a dropped meal.

### 5.4 Rejected vetoes

Per scoping: *"There's no 'nah, I don't want to do that workout' without a very good
excuse."* Claude may **decline** a veto and hold the line.

A declined veto is still **logged**. That record is signal: a user vetoing legs every
Thursday for four weeks is a pattern to address, not silently accommodate. Claude
should raise it at the weekly check-in rather than re-litigating each week.

---

## 6. Interjection (chat)

Per scoping: **the user supplies facts; Claude decides what to do about them.**

### 6.1 The rule

The user's message **never edits the plan.** It is recorded as a *stated
circumstance*. Claude evaluates it. Only Claude's decision produces a new plan
version.

```
user message → circumstance (recorded) → Claude evaluates
                                            ├→ plan revision (new version)
                                            ├→ clarifying question
                                            └→ declined, with reasoning
```

This is structural, not prompt-level. There is no code path from user text to plan
mutation that bypasses Claude's judgment — so "chat that can be talked into anything"
is not a failure mode that exists.

### 6.2 Legitimate vs. not

| Legitimate — a fact about reality | Not — a preference restated |
|---|---|
| "Travelling Mon–Thu, no gym, no kitchen" | "Don't feel like legs today" |
| "Thunderstorms on hiking day" | "Can we do less cardio" |
| "Slept 4 hours, wrecked" | "I'd rather do arms" |
| "Tweaked my shoulder" | "This is too hard" |

The left column reshuffles the week. The right gets pushback — with the tone the user
chose.

Note the line is *facts vs. preferences*, not *hard vs. easy*. "This is too hard" is
worth Claude asking about (form? load too aggressive?) — it just doesn't
automatically change the plan.

### 6.3 Claude may ask for specifics

"Can't make Thursday" → "what's the constraint — time, travel, or energy?" Not
friction for its own sake: the answer decides whether the fix is a shorter session, a
hotel-room session, or a rest day.

### 6.4 Circumstances expire

Same temporary/standing split as vetoes. "Travelling this week" has an end date.
"I hate salmon" is permanent and gets promoted. Expired circumstances stop
influencing generation.

### 6.5 Constraints are not chat-editable

Per `SPEC-safety.md` §6. "My knee's fine now" gets Claude *suggesting you review the
constraint* — it does not edit one.

---

## 7. Adaptation

### 7.1 Mid-week

Triggered by significant drift (§4.2), a veto, or an interjection. Claude asks first,
then acts:

- *Not feeling well* → lighten expectations until the user reports recovery. Explicitly
  from scoping: illness lightens the week rather than failing it.
- *Busy — work/school* → reshuffle sessions to available days; shorten
- *Missed sessions* → redistribute across remaining days, or accept a lower-volume week
- *Heavy overeat* → adjust remaining days without punishing; never a punitive deficit

Output is a new plan version with `reason = drift_adaptation` and the trigger.

**Adaptation is not punishment.** A bad day adjusts the plan; it does not add
penance. That framing is wrong for anyone and actively harmful for User #2.

### 7.2 Weekly check-in

End of week, the full pass. Inputs: weight, measurements, photos (optional, encouraged)
plus the week's adherence and daily check-ins.

Claude produces:
- A read on progress vs. goal and timeline
- What worked, what didn't
- Adjustments for next week — targets, volume, exercise selection, menu variety
- Next week's plan

**Trend over points.** Weight moves for a dozen reasons daily. Evaluation uses trend
direction over time, never a single reading — and per onboarding 2.6, users who
prefer look/feel get measurements and photos led first.

### 7.2a User-steered emphasis — the adherence dividend

**A user who follows the plan earns input into it.**

At the weekly check-in the user may request an emphasis shift — "glutes aren't
developing as fast as I expected, focus there." Claude weighs the request against:

| Check | Effect |
|---|---|
| **Adherence** — have they been a good student? | Strong adherence → grant it |
| **Counter to the goal?** | Contradicts the stated goal → discuss, don't silently apply |
| **Constraint conflict?** | Hard constraint → declined per `SPEC-safety.md` |
| **Realistic?** | Timeline/volume feasible → adjust upcoming weeks |

Granted requests adjust **upcoming weeks** — exercise selection, volume distribution,
accessory emphasis — not the current week mid-flight.

**Why this is a privilege and not a setting.** It is the reward for adherence, and the
user genuinely knows things the app can't see — how a muscle group feels, where they
want to look different. A user with poor adherence asking to drop the parts they've
been skipping is a different conversation, and Claude should have it rather than
comply.

This is the one place the user gets to *steer* rather than supply facts. It does not
override §6: mid-week interjections are still facts-only. Emphasis requests are a
check-in-time privilege, earned.

Stored as a **standing emphasis** with the adherence context that justified it, so
later weeks keep honouring it without the user re-asking.

### 7.3 Anti-staleness

An explicit requirement from scoping: both training and menu must grow and change.

- **Exercise variety** — Claude sees recent weeks and rotates deliberately
- **Progressive overload** — RPE and actual-vs-prescribed drive load progression
- **Progression targets** — scaffolding toward `working_toward` movements
  (`SPEC-safety.md` §4)
- **Menu variety** — track recent meals; avoid repetition unless the user *likes* it.
  Some users want the same breakfast daily; the structure dial (§4.6) hints at which.

### 7.4 Re-baselining

The plan horizon (§3.6) ends → a fuller review. Goal met or timeline expired is
**v2** per scoping, but plans carry their horizon from the start so that work has
something to read.

---

## 8. The baseline fortnight

Per scoping: **2 weeks, unskippable, with a provisional plan after week 1.**

| | |
|---|---|
| **Week 1** | Pure observation. Log food, activity, daily check-ins. No prescription. |
| **End of week 1** | **Provisional plan.** Something to follow while week 2 refines it. |
| **Week 2** | Follow the provisional plan; observation continues. |
| **End of week 2** | Full baseline analysis → first real plan. |

The provisional plan exists for a specific reason: two weeks of logging with nothing
in return loses User #1, who would rather be on the couch. Week 1 buys honest
observation; the week-1 plan buys continued engagement.

### What the baseline establishes

- **Real intake** vs. claimed (onboarding §4.7)
- **Real activity** vs. claimed (§6.2, §8.3)
- **Starting loads** — where §6.7 was absent or optimistic
- **Adherence character** — does this user log daily, or in bursts?
- **Weight trend** — two weeks of readings beats one

**Claimed vs. actual is the point.** Claude is shown both and told the second wins.
That gap is the most useful thing the app learns in the first month.

---

## 9. Nudges

Per scoping: in-app, escalating, tone-matched. **"I hate noisy apps."**

| Days quiet | Action |
|---|---|
| 1 | Nothing |
| 2 | Passive in-app indicator |
| 3 *(9.3 default)* | Direct nudge, in the user's tone |
| 5+ | Escalated per 9.2 (gentle → relentless) |

In-app only for now. Web push is a possible later addition — Friendspace has a
service worker — but it needs VAPID keys and, on iOS, home-screen installation. Not
worth it for four users who open the app anyway.

**Nudges never shame a bad day.** They address *absence*, which is the thing that
actually ends the coaching relationship. A logged bad day is a success.

---

## 10. The buddy system

Two users who train together pair up and **sync their weeks as far as they can**. It solves
a real problem for both of Yoked's first users — one needs motivation to show up, the other
needs someone watching so she doesn't coast — and unpaid accountability is the most
effective adherence mechanism the app has.

### 10.0 The trade, stated plainly

**Pairing costs precision and buys adherence, and that is the point.**

A paired user's plan is deliberately less individually optimal than a solo one. They inherit
their buddy's training limits, they train on days the pair agreed rather than the days that
suit them best, and their shared sessions match their buddy's shape rather than their own
ideal. All of that is a real cost.

It is worth paying because the alternative is worse. A pinpoint-accurate plan someone
abandons after a month produces less than a compromised plan they are still doing in a year.
Being beholden to another person is what keeps most people engaged; self-motivation is what
most people run out of. So where individual optimisation and staying paired conflict, **the
pairing wins** — and the app should say so rather than quietly degrading someone's
programming and hoping they don't notice.

This section is the exception to §3's general rule that the plan serves the individual. It
applies only while a pair is active, only to training, and never to safety.

**Nutrition is out of scope.** v1 pairs training only. Sharing meals is a plausible later
iteration and is not designed here.

### 10.1 Shape

**Friends → buddy pair → synced weeks.** Both users opt in explicitly; either can unpair at
any time, no reason required. Pairing requires an existing friendship (invite-only app,
friends-only social surface).

One pair at a time. The spec describes a pair, not a group, and the schema agrees.

A synced day produces one **shared session skeleton**:

| Shared | Individual |
|---|---|
| Day and time slot | Loads |
| Session type and focus | Rep ranges |
| Movement patterns and order | Rest intervals |
| Warm-up timing | Exercise **variant** |
| Equipment context | Set counts |
| The core block, in full (§10.2a) | |

So both users are at the same rack doing the same movement in the same order — and one is
goblet squatting 50 lb for 3 × 10 while the other back squats 95 lb for 4 × 8. This is how a
competent trainer handles a mismatched pair: one session, two prescriptions.

### 10.1a Two schedules, not one

**A paired user has an individual schedule and a buddy schedule. The buddy schedule is the
priority; the individual one is the fallback and the filler.**

This is the load-bearing structure of the whole feature.

- The **individual schedule** is the §7.1 availability grid, answered at onboarding. It is
  never overwritten by pairing and never lost.
- The **buddy schedule** is the set of days the pair agreed to train together. It is stored
  against the pair, not the user, and it disappears when the pair does.

Where they disagree, the buddy schedule wins for shared days. Where the buddy schedule does
not cover a user's committed capacity, the surplus is drawn from their individual grid and
generated for them alone.

Two things follow, and both are why the model is worth the extra table:

1. **A user can agree to a day they did not originally offer.** Compromise is the point
   (§10.3a), and it must not require editing the answer they gave about their own week.
2. **A buddy going quiet does not strand the other** (§10.5). Falling back means generating
   from the individual grid, which is still there and still accurate.

### 10.2 Convergence is the goal; safety is the limit

**Structure is negotiable. Safety and goal-direction are not.**

Where a shared skeleton would require prescribing something a user's **hard** constraints
forbid, that exercise **diverges** — different movement, same slot, same time. Both users
still train together. Divergence is normal and expected, not a failure of pairing.

Everything softer than a hard constraint should bend toward the pair rather than away from
it. A soft preference, an ideal split, a slightly better exercise selection: these lose to
staying synced. This is a deliberate reversal of how the rest of §3 reads, and §10.0 is why.

Sunday-league examples: a hypertension modifier sends one user to a machine press while the
other goes overhead with a barbell; a knee constraint swaps a squat variant. Same day, same
gym, same hour.

### 10.2a The core block is shared, not individualized

**Exception to §10.1's split.** For a buddy pair the core block is **identical** — same
exercises, same sets, same reps, same holds — where the main lifts diverge freely.

- **It is mostly floor work.** Planks, dead bugs, bird dogs, Pallof presses. No barbell, no
  loading problem, so nothing forces the prescriptions apart the way a squat does.
- **Core strength converges far more than limb strength.** A deconditioned 52-year-old and a
  trained 21-year-old are much closer on a plank than on a back squat.
- **Side-by-side on mats is social time.** Ten minutes of matched work at the end of a
  session is where a pair talks, competes a little, and messes about. That is the buddy
  system's actual mechanism.
- **Volume parity is worth less than adherence.** Losing two sets of optimal individualized
  core work is a trivial cost against making gym day something both users look forward to.

**Individualization is still available where genuinely needed:** a hard constraint (back
injury, hernia) diverges the affected movement per §10.2. Loaded core work — weighted
carries, cable woodchops — keeps individual **loads**, since a 60 lb suitcase carry and a
35 lb one are the same exercise.

**Rule of thumb:** bodyweight and isometric core work is identical; loaded core work shares
the movement and scales the weight.

### 10.2b Inherited training limits

**While paired, each user takes on their buddy's training avoids.** If one cannot ski,
skiing is not suggested to either of them for as long as the pair lasts.

**Tiers do not transfer.** A user's own limits stay **hard** and reject a plan that violates
them. An inherited limit arrives **soft**: the coach steers away from it and may still
propose it with a stated reason, and the user can veto it (§5) like any other suggestion.

The asymmetry is deliberate, and it is SPEC-safety §6 holding the line. A hard constraint is
a limit the user set for themselves, deliberately. Nothing another person does should create
one, because a constraint the user never chose is a constraint they cannot reason about — and
a plan rejected over someone else's preference is a failure they cannot fix.

**Training only.** Food constraints, allergies, dietary patterns and conditions never
transfer. Nutrition is out of scope for v1 (§10.0), and an allergy is not a preference to
compromise over.

Inherited limits vanish on unpairing. They are a property of the pair, not of the user.

### 10.3 The shared schedule

Synced days start from the **intersection** of both users' §7.1 grids: days both are free.
`sometimes` counts as free — the grid's own vocabulary makes it a maybe rather than a no, and
treating it as a no reports zero shared days for exactly the chaotic-schedule pair that most
needs the accountability.

Where the intersection covers both users' committed counts, that is the buddy schedule and
nothing needs negotiating.

The shared duration for a day is the **shorter** of the two users' available minutes: a
shared session cannot outlast whichever of them has to leave.

### 10.3a Thin overlap is surfaced, not solved silently

**Where the intersection is too thin to pair meaningfully, the app tells both users and asks
them to compromise.** It does not quietly generate two solo weeks and leave them to work out
why pairing did nothing.

"Too thin" is: the intersection covers fewer days than the smaller of the two committed
counts. One user wanting M/W/F/Sa and the other Tu/Th/Sa/Sun overlap only on Saturday, which
is not a training partnership.

The compromise is a negotiation between the two users, in the app. Either may offer a day
outside their own grid. Agreement writes the **buddy schedule** — never the individual grid,
which stays the record of what that person actually said about their own week (§10.1a).

An unresolved negotiation leaves the pair active with whatever natural overlap exists. It is
never a blocker: a pair that cannot agree still gets the adherence signal (§10.4) and still
trains, just less together.

### 10.3b Surplus days are the user's choice

**When the buddy schedule covers fewer days than a user's committed count, that user decides
what to do with the difference.** The app asks; it does not pick.

Three options, and all three are defensible:

| Choice | Effect |
|---|---|
| Keep my commitment | Buddy days plus individual days, totalling the stated count |
| Buddy days committed, extras optional | Committed count drops to the shared days; individual days are generated as optional, no adherence cost |
| Match the buddy schedule | Committed count is reduced to the shared days; nothing extra generated |

**Why this is a question and not a rule.** `SPEC-safety`'s committed-count check exists
because "under-prescribing quietly shrinks their week" — a 5-day user who pairs on 2 days and
silently trains twice a week loses training *and* the accountability the pairing was for.
But forcing all five is equally wrong for someone who paired precisely because they wanted a
lighter, more social week. Only the user knows which they meant.

Asked once when the buddy schedule is agreed, changeable afterwards, and re-asked if the
shared schedule changes.

### 10.4 Shared adherence signal

The most effective nudge in the app, and it costs nothing: *"your buddy trained Monday, you
didn't."*

**Buddy nudges are gentle by default, regardless of tone setting.** A user may have chosen
sarcastic hardass for themselves; there is a real difference between the app roasting you and
your friend watching you skip. The tone dial governs the app's voice, not social pressure.

What a buddy sees by default: whether sessions were completed, and shared-session
performance. **Not** covered by this: weight, measurements, and photos, which follow the
privacy settings and default to hidden. Pairing up to train is not consent to share body
metrics.

### 10.5 Absence never strands the other user

A buddy who travels, gets ill, or unpairs must never leave the other waiting. There are
three cases and they are genuinely different.

**Planned, declared before generation.** Travel can be seen coming. A buddy states an absence
with a return date, ideally prompted at the Saturday check-in, before Sunday's generation. The
partner's week is then built **solo from their individual grid** and they are told why:
"Sam is away this week, so this one is yours alone."

**Unplanned, declared mid-week.** Illness cannot be planned. A buddy declares it on Tuesday
with a return date. The partner's week is already built and **stays built** — reshuffling a
week someone is halfway through is worse than letting them finish it — but they are told
immediately, so an empty rack on Thursday is expected rather than confusing. If the return
date falls beyond the end of the week, the following week generates solo.

**Undeclared.** Someone simply stops. Where drift (§4.2) already shows a buddy has logged
nothing for their nudge window, the partner's next week generates solo rather than around a
ghost. This is a safety net for the silent-quitter case, not the primary path.

In every case the partner keeps a complete, valid week. **Pairing is an enhancement to a
complete single-user plan, never a dependency of one.**

### 10.6 Generation impact

Buddy pairing is the one feature that makes plan generation **sometimes per-pair rather than
strictly per-user**.

Mechanically: generate the shared skeleton from the buddy schedule and both users' structural
needs, then generate each user's prescriptions against it, then generate each user's surplus
days independently, then validate each user's plan **on its own**.

Validation stays per-user. There is no such thing as a pair-level constraint check — each
user's plan must stand on its own against their own hard constraints, inherited limits
included as soft (§10.2b).

### 10.7 Build order

§10 is large and each piece is independently useful. Recorded here because the order matters
and the reasoning is easy to lose:

1. **Handshake** — invite, accept, decline, unpair; visibility follows. *Done.*
2. **Schedules and negotiation** — §10.1a, §10.3, §10.3a, §10.3b, and generation preferring
   shared days. Both users end up in the gym together, which is most of the adherence value.
3. **Inherited limits** — §10.2b. Self-contained, no schema change.
4. **Absence** — §10.5. Needs the check-in and drift.
5. **Synced sessions** — §10.6 and §10.2a. The largest and riskiest: it restructures
   generation, and until it lands the app must not claim sessions are shared.

Each step must leave the app honest about what it does. Copy promising synced workouts before
step 5 exists is the failure mode to avoid — a real pair notices within a week.

## 11. What Claude is called for

| Trigger | Frequency | Notes |
|---|---|---|
| Weekly plan generation | 1/user/wk | The big one |
| Provisional plan | once, end of baseline wk 1 | |
| Baseline analysis | once, end of wk 2 | |
| Daily check-in eval | **only on drift** | On-track days are pure SQL |
| Veto replacement | as needed | |
| Interjection response | as needed | |
| Mid-week re-plan | on significant drift | |
| Weekly check-in | 1/user/wk | |
| Food search | as needed | Reused from Keto Tracker |

~$10–15/month for four users. Cost is not a design constraint; quality is.

**Model choice:** use a current model id, looked up at build time. The reference
`food-search.js` pins `claude-sonnet-4-20250514`, which is generations stale — do not
copy it.

---

## Resolved in review

1. **Provisional plan** — **full week.** Same generation call with thinner inputs.
2. **Weekly check-in initiation** — **cron creates it**, nudges if unanswered.
   Otherwise a quiet user never gets re-planned.
3. **Plan visibility** — **whole week up front**, so the user can spot a conflict
   early (Friday travel, no gym) and get it adjusted. Each day carries a full recap.
   Plus the **Next Day Review** (§4.1a) for facts that surface after the plan was
   built.
4. **Veto patterns — frequency, not count.** Four vetoes in a year says nothing; four
   in four weeks is a pattern. The threshold is **rate-based**, and the judgment stays
   **Claude's** either way. On hitting it, Claude opens a *conversation* at the weekly
   check-in — "you've skipped legs three Thursdays running, what's going on?" — and
   the outcome of that conversation decides whether it becomes a soft constraint.
   Never an automatic promotion: the reason matters, and only asking reveals it.
5. **Photo cadence** — **every two weeks.** Weekly shows too little change to
   motivate.
6. **User-steered emphasis** — added as §7.2a. A user with good adherence earns input
   into upcoming programming.
7. **Committed vs. optional days** — added as §3.3a. Stated capacity is the
   commitment; extra days are optional and never count against adherence. Cuts are made
   by goal value, not calendar position.
8. **Core on every strength day** — added as §3.3b. Built in by default, 8–12 min,
   pattern-matched to the day's focus, placed after the main work. Not an onboarding
   question.
9. **Buddy system** — added as §10. Friends pair up, weeks sync on a shared skeleton,
   prescriptions stay individual, and syncing never compromises either plan.
10. **Shared core block** — added as §10.2a. For buddy pairs the core block is
    identical (bodyweight/isometric work matches exactly; loaded work shares the
    movement and scales the weight). The one deliberate convergence point, because
    mat work side-by-side is the social mechanism the buddy system runs on.

## Open items

None currently. Next artifact is the schema, which these three specs define.
