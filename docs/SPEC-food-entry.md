# SPEC — Food Entry

**Status:** BUILT and verified. §4's three open questions were decided on review; the
decisions are recorded in place and the alternatives kept, because the reasoning for the
rejected options is what stops them being re-proposed later.

How a food gets into a day. Covers the placement of search, scan, usuals and by-hand,
and what changes between a day with a plan and a day without one.

Related: `SPEC-coaching.md` §4.4 (adherence and the one-tap log), `SPEC-nutrition.md` in
`source-projects/keto-extract` (the intake model this borrows from),
`docs/design/DESIGN.md` (accent budget, one prompt per view).

---

## 1. The problem

The four ways to add a food live **inside each meal card**, behind that meal's `Add food`
button, with their own tab strip. That placement is correct for one case and wrong for the
other, and the app currently only serves the first.

**With a plan,** `Add food` is the DEVIATION path. The primary action is `Ate as planned`,
one tap, and everything about `Add food` follows from being the second choice: nested in the
meal, secondary styling, one tap deeper. That hierarchy is right and this spec does not
change it.

**Without a plan — the baseline fortnight — deviation is the only path.** There is nothing
to deviate from. So a control designed to be the second choice is the only choice: buried
one tap deep, repeated across five cards, with a four-tab strip that resets every time
because the `way` state lives inside each `AddFood` instance.

Logging three meals during baseline is currently: tap `Add food`, tap `Search`, type, add —
then repeat the whole sequence twice more, re-picking `Search` each time.

**Why this matters more than it looks.** The baseline is fourteen days of logging with
nothing returned: no plan, no adaptation, no coaching. It is the highest-friction,
lowest-reward stretch of the product, it is mandatory, and it comes first. Every friction
cost in this flow is paid fourteen times before the user sees the thing that makes Yoked
different from a food diary.

### What is NOT the problem

- **The meal structure.** Planned-first is the product, and the adherence signal
  (`as_planned` / `substituted` / `skipped`) is what the weekly adaptation runs on.
- **The four ways in.** Favourites-first, then scan, then search, then by-hand is the right
  order of prominence and stays.
- **Correcting a logged food.** Shipped: an entry's serving can be edited and rescales
  server-side.

---

## 2. Principle

**When there is a plan, the plan leads. When there is not, logging leads.**

One set of components, two arrangements. Not two screens: a separate baseline layout means
two things to maintain and a jarring change on graduation day, when the user has just earned
something and should not also have to relearn the screen.

---

## 3. What changes

| | Baseline day (no prescription) | Planned day |
|---|---|---|
| Top of the Food section | Entry panel, **open** | Same line, **collapsed** |
| Meal card | Name, entries, total | Plan, `Ate as planned`, `Add food`, `Skipped it` |
| `Add food` on a meal | Present, opens the panel with that slot chosen | Same |
| Where the panel renders | In place: under whatever opened it | Same |
| Delta row | Hidden (shipped) | Shown (shipped) |

**The strip is in the same position on both days.** Only its default state differs — open
when there is nothing planned, collapsed when there is. That is the one thing the two
arrangements have to disagree about, and everything else stays put.

### 3.1 One AddFood, hoisted

`AddFood` is mounted **once**, in `Food`, not once per `Meal`. State moves up with it:

```
const [addingSlot, setAddingSlot] = useState(null)   // null = closed
```

| Trigger | Effect |
|---|---|
| The top-level strip | `setAddingSlot(nextSlot ?? 'breakfast')`, `setAddingAt('strip')` |
| A meal's `Add food` | `setAddingSlot(meal.slot)`, `setAddingAt(meal.slot)` |
| `Add a snack` | `setAddingSlot(freeSnackSlot)`, `setAddingAt('snacks')` |
| Adding a food, or `Never mind` | both to `null` |

**Two pieces of state, not one, and they are genuinely different things.** `addingSlot` is
*which meal the food goes to* and the user can change it in the picker. `addingAt` is *where
the panel is drawn* and the user cannot — it is fixed by whatever they tapped. Collapsing
them into one value would mean changing the picker made the panel jump to a different card
mid-interaction.

So `Food` renders the panel in exactly one of its possible positions:

```
{addingAt === 'strip' && <AddFood … />}      // top of the section
… per meal:  {addingAt === meal.slot && <AddFood … />}
… snacks:    {addingAt === 'snacks' && <AddFood … />}
```

One mounted instance at a time, because `addingAt` holds one value. The `way` tab and the
favourites filter survive between openings only if that state lives ABOVE this conditional —
in `Food`, passed down — since a conditional render unmounts the component and loses
anything held inside it. That is the part most easily got wrong, and §6.2 asserts it.

Consequences, all of them wanted:

- **The `way` tab persists.** Pick `Search` once and it is still `Search` for the next food.
  This needs `way` lifted into `Food` as well — hoisting the mount is not enough, because the
  panel is still conditionally rendered and a conditional render unmounts it. Same for the
  favourites filter box.
- **The favourites list is fetched once.** It already is; `Food` owns it.
- **There is one open panel, ever.** Two meals cannot both be adding at once, which is
  possible today: `Meal` and `Snacks` hold separate `adding` states, so two tab strips can be
  on screen disagreeing about `way`.

### 3.2 The slot picker

The panel shows which meal the food is going to, and it is editable.

- **Pre-filled** from whatever opened it. A meal's button passes its own slot, so tapping
  `Add food` on Dinner and getting Dinner needs no thought.
- **Editable**, because the strip has to pick something and because tapping the wrong card
  should not mean closing and reopening.
- **Six slots, presented as four options:** Breakfast, Lunch, Dinner, Snack. Snack resolves
  to the first empty `snack_am` / `snack_pm` / `snack_eve`, falling back to `snack_eve` —
  the logic `Snacks` already uses for `freeSlot`. The three snack slots exist because a plan
  can prescribe a specific one; a user adding an ad-hoc snack does not care which.

**Default when opened from the strip:** `nextSlot` — the first prescribed meal with nothing
logged, in eating order — falling back to `breakfast` when nothing is prescribed. `Food`
already computes `nextSlot` for the accent.

### 3.3 The strip

**Top of the Food section, on every day.** One position, so the control does not move on
graduation day and does not have to be re-found. Only the default state differs.

| State | Rendering |
|---|---|
| No prescription for the day | Panel, **open** |
| Prescription exists, nothing opened | Quiet line: "Log something else" |
| Panel opened from the strip | Panel, slot picker at `nextSlot` |
| Panel opened from a meal or snack button | Panel renders **under that card** (§4.1) |

Collapsed it is one quiet line, never a button competing with `Ate as planned`, and never
the accent — on this screen the accent belongs to the next unlogged meal.

Open on a baseline day there is no line above it, because there is nothing to collapse
toward. Logging is the whole job that day.

### 3.4 Why the per-meal button stays

Considered removing it once the strip exists, since two doorways into one room is
duplication. Rejected:

- On a planned day, deviation is usually about the specific meal you are looking at.
  `Add food` on that card is more direct than opening a shared panel and changing a picker.
- The button costs nothing now that it does not own a panel — it sets a slot.
- An affordance that vanishes on graduation day is worse than one that is occasionally
  redundant.

---

## 4. Decisions

Each of these was open on the first draft. The rejected options are kept because the reason
they lost is what stops them being re-proposed.

### 4.1 The panel renders IN PLACE, under whatever opened it

**The problem:** a panel fixed at the top of the section means tapping `Add food` on Dinner
scrolls the user away from Dinner.

| Option | For | Against |
|---|---|---|
| A. Always at the top, scroll to it | One position, predictable | Moves you away from the card you tapped |
| **B. In place, under the card that opened it** ← | Food stays next to its meal | The panel appears in different places between uses |
| C. Top on a baseline day, in place from a meal | Each case gets the better position | Two rules for one component |

**Chosen: B.** The panel is about a specific meal, and rendering it against that meal is
what makes the slot picker read as a confirmation rather than a question.

This composes with 4.2 rather than fighting it: opened from the STRIP the panel is at the
top, because that is where the strip is. Opened from a meal it is under that meal. One
rule — *the panel appears where you asked for it* — and two outcomes.

### 4.2 The strip is in the SAME PLACE on both days

**Chosen: top of the Food section, always.** Only the default state differs — open with no
plan, collapsed with one.

The first draft argued for the bottom on a planned day, on the grounds that the plan should
lead. Rejected: a control that moves between two positions depending on a state the user did
not choose has to be re-found, and graduation day is the worst possible time for that,
because the user has just earned something and should not also have to relearn the screen.
Collapsed-and-quiet already says "the plan leads" without moving anything.

### 4.3 Snacks are unchanged except as a trigger

`Snacks` is one card presenting three slots, with its own `Add a snack` button. It becomes
another trigger that sets `addingSlot` to the first empty snack slot, and the panel renders
under the Snacks card per 4.1.

`Ate the planned one` stays exactly as it is. It is the snack equivalent of `Ate as planned`
and belongs to the plan-leads path, which this spec does not touch.

---

## 5. What this does not include

- **Menu variety** (nothing tracks recent meals) — separate work, on the list.
- **Editing a favourite's macros.** The route exists; no UI. Not this spec.
- **A dedicated "quick add" for repeat days** ("same as yesterday"). Plausible and out of
  scope; it would need a decision about what "same" means when the plan differs.

---

## 6. Verification

Browser assertions, since this is a client/server contract and a layout question. PHP suites
cannot see any of it — the routes do not change.

1. Exactly one `AddFood` is mounted, whatever is open. Asserted by count, not by eye: two
   panels open at once is possible today and is the thing being removed.
2. The `way` tab survives adding a food and reopening the panel.
3. A meal's `Add food` pre-selects that meal in the picker.
4. Changing the picker sends the entry to the chosen slot, asserted against the day payload
   rather than the screen — the point is where the food landed, not what was displayed.
5. Opened from a meal, the panel renders **under that meal**: asserted geometrically, by
   comparing the panel's top against that card's and the next card's.
6. Opened from the strip, the panel renders at the top of the section.
7. A baseline day renders the panel open, with no prescription anywhere on screen. Presence
   before absence: assert the panel exists, THEN that nothing plan-shaped does.
8. A planned day renders the strip collapsed, and the accent still lands on the next unlogged
   meal and nowhere else. The existing accent-budget assertion covers the second half.
9. The strip's vertical position is the same on both days. This is the decision from §4.2 and
   the one a later refactor is most likely to undo by accident.
10. No sideways scroll at 360px with the panel open.
11. Snacks still resolve to the first empty snack slot, and `Ate the planned one` still works.

**Fixture note:** `uitest_logging` has a plan and `uitest_baseline` does not, so both
arrangements are already reachable without new fixtures.

**Cleanup is not optional.** The suite shares one fixture and one long-lived page. Assertion
4 logs a food into a snack slot to prove the picker works, and the first run left it there —
so the two snack assertions further down counted an extra entry and reported a defect that
was this test's litter. It deletes what it logs, through the API rather than the × button,
because proving where the food LANDED should not depend on finding the right row to undo it.

**Known flake, unrelated:** `hours slept saves on blur` and `the check-in ratings came back`
fail together intermittently. The check-in disables every control through one shared `busy`
and the suite fires energy, soreness and sleep with no waits between them. Not caused by this
work — `CheckIn.jsx` is untouched — and it passes on a re-run.
