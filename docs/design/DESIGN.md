# Yoked — Design Language

The single source of truth for tokens. If a component needs a colour, a shadow,
or a type size, it comes from here or it does not exist.

Reviewed and approved: palette, shadows, the yolk-as-progress concept, and the
dark inversion on the tier-confirmation dialog.

---

## The idea

**A yolk is already a progress ring** — a filled circle inside a ring.

Onboarding is ten sections and the question a user keeps asking is "how far
through am I." So the logo and the primary affordance are the same object: one
mark that fills as the quiz completes, reused in the header, the favicon, and
the loading state.

That is what earns the yellow. Applied as decoration, `#F5B92E` at this
saturation reads as a warning label. Applied as the thing that *measures*, it
has a job. **One yellow element per view** — the progress mark or the primary
action, never both.

---

## Colour

| Token | Light | Dark | Use |
|---|---|---|---|
| `--shell` | `#F7F5F0` | `#17140F` | Page background. Warm off-white — albumen, deliberately not the AI-default cream. |
| `--white` | `#FFFFFF` | `#201C16` | Cards and raised surfaces. |
| `--yolk` | `#F5B92E` | same | The accent. Once per view. |
| `--yolk-deep` | `#E0A216` | same | Hover, pressed, and borders on selected state. |
| `--ink` | `#2A241C` | `#F2EEE6` | Text. Warm charcoal, never pure black. |
| `--muted` | `#7A7266` | `#9C9385` | Labels, help text, secondary. |
| `--hairline` | `#E4DFD6` | `#322C23` | Borders and dividers. |
| `--paprika` | `#C4402C` | same | Errors only. Warm enough not to fight the yellow. |

The accent does not change between modes — a yolk is a yolk.

### Contrast

`--muted` on `--shell` is the tightest pairing in the system and clears 4.5:1.
`--ink` on `--yolk` (the primary button) clears 7:1. Any new colour pairing gets
checked before it ships; nothing in the palette is decorative enough to justify
failing contrast.

---

## Shadow

Two levels. More reads as noise.

```css
--lift:   0 1px 3px rgba(0,0,0,.06), 0 6px 16px rgba(0,0,0,.04);  /* cards */
--lift-2: 0 2px 4px rgba(0,0,0,.10), 0 12px 28px rgba(0,0,0,.05);  /* dialogs */
```

Dark mode needs **different numbers, not the same ones** — a 10% black shadow is
invisible on `#17140F`:

```css
--lift:   0 1px 3px rgba(0,0,0,.24), 0 6px 16px rgba(0,0,0,.18);
--lift-2: 0 2px 4px rgba(0,0,0,.32), 0 12px 28px rgba(0,0,0,.22);
```

Two things worth preserving here:

- **The near shadow is more present than the far one.** That reads as a card
  resting on paper rather than floating above it, which suits this palette. The
  reverse was tried and was worse.
- **Neutral black, not tinted.** Tinting with the ink brown went muddy at these
  alphas, and the warm shell already does the warming.

---

## Type

Both faces sans — the personality comes from **weight contrast**, not a serif.

| Role | Face | Notes |
|---|---|---|
| Display | **Bricolage Grotesque** | 600–800. Slight width irregularity keeps headings from reading as Inter-with-more-weight. |
| Body / data | **Inter** | 400–600. `font-variant-numeric: tabular-nums` everywhere. |

Self-hosted `woff2`, subset to Latin. No external font host: it is a third-party
dependency on every page load and a privacy leak for no benefit.

Numbers are **tabular everywhere**. Macros, loads, and RPE sit in columns, and
proportional digits make a column of numbers jitter.

### Scale

| Use | Size | Weight | Tracking |
|---|---|---|---|
| Page title | 34px | 800 | −0.03em |
| Section / question | 21px | 600 | −0.017em |
| Card heading | 17px | 600 | −0.01em |
| Body | 16px | 400 | 0 |
| Secondary / help | 14px | 400 | 0 |
| Label / eyebrow | 11.5px | 600–700 | +0.07em to +0.13em, uppercase |
| Question number | 12px | 600 | +0.04em, tabular |

Negative tracking on display sizes, positive on uppercase labels. Both are
corrections for what the size does to apparent letter-spacing.

---

## Geometry

```css
--r-card: 14px;   /* cards, dialogs */
--r-pill: 999px;  /* buttons */
```

Inputs and choice rows use 8–10px — smaller than cards, so a control never
competes with the surface holding it.

---

## Components that carry the app

### The quiz question

The most-repeated component: ~80 instances. Number small and muted, question
large in display face. **The number is wayfinding; the question is content**, and
the type scale should say which is which.

### The availability grid

Seven rows, one per weekday, each with its own access control.

Access is a property of a **day**, not of a person — "full gym at work Monday to
Friday, bodyweight at home on weekends" is ordinary. A single global setting
flattens that and then prescribes barbell work on a Saturday. The schema learned
this; the UI has to make it obvious.

### The tier-confirmation dialog — the one loud moment

The only place the design raises its voice: dark ink panel, yellow eyebrow,
`--lift-2`.

Everywhere else is quiet so this lands. It is the screen where the app is being
genuinely careful with someone — "you marked ACL surgery as something to work
around" — and a standard confirm modal would undersell it.

The copy explains what each setting **does** rather than asking "are you sure",
and closes on *"Either is fine — you know your body."* The user's judgement is
final; the app is only making sure the choice was deliberate.

---

## Writing

- **Sentence case. Active voice.** "Save changes", not "Submit".
- **An action keeps its name through the whole flow.** The button that says
  "Continue" produces a state that says continued — never a synonym.
- **Name things by what the person controls**, never by how it is built. A user
  has a *plan*, not a `plan_version`.
- **Errors say what happened and what to do.** They do not apologise and are
  never vague. "That invite code is not valid. Check it for typos, or ask
  whoever invited you for a new one."
- **An empty state is an invitation**, not a mood. "No plan yet. Your first week
  arrives once the baseline fortnight is done."
- **Each element does one job.** A label labels; an example demonstrates.

---

## Quality floor

Not optional, and not announced in the UI:

- Responsive to 360px. The availability grid reflows to stacked rows.
- `:focus-visible` on every interactive element — 2.5px `--yolk-deep`, 2px
  offset.
- `prefers-reduced-motion` collapses every transition to 1ms.
- Theme follows `prefers-color-scheme`, with `data-theme` on the root as an
  override that wins in both directions.
- Semantic roles on custom controls: `radiogroup`/`radio` on choices,
  `aria-pressed` on segmented buttons.

## Motion

Sparing. Two places only:

1. **The yolk filling** — 600ms `cubic-bezier(.22,1,.36,1)` on progress change.
   The one orchestrated moment.
2. **Control feedback** — 140ms on border and background, a 1px translate on
   `:active`.

No scroll-triggered reveals, no ambient animation. Extra motion is the fastest
way to make a considered design read as generated.
