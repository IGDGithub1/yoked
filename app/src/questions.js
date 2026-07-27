/**
 * The quiz, as the client renders it.
 *
 * Question KEYS are the contract with the server (Onboarding::SECTIONS) — those
 * must match exactly. The wording, help text, and control types live here,
 * because they are presentation and the server neither needs nor validates them.
 *
 * Copy rules from DESIGN.md apply: sentence case, plain verbs, and help text
 * that explains what the answer DOES rather than restating the question.
 */

/** Section 7's grid is a bespoke control, so it has no `questions` array. */
export const SECTIONS = [
  {
    id: '1',
    name: 'About you',
    blurb: 'The numbers behind your targets. Nothing here is shared with anyone.',
    questions: [
      { key: '1.1', type: 'date', label: 'When were you born?', required: true },
      {
        key: '1.2', type: 'choice', required: true,
        label: 'Sex assigned at birth',
        // Stated, not implied: an unexplained question about sex reads worse
        // than an explained one.
        help: 'Used only to estimate your baseline calorie burn, which the maths needs.',
        options: [
          { value: 'male', label: 'Male' },
          { value: 'female', label: 'Female' },
        ],
      },
      {
        key: '1.5', type: 'choice', required: true,
        label: 'Which units do you think in?',
        options: [
          { value: 'imperial', label: 'Pounds and inches' },
          { value: 'metric', label: 'Kilos and centimetres' },
        ],
      },
      { key: '1.3', type: 'number', label: 'How tall are you?', required: true, unit: 'height' },
      { key: '1.4', type: 'number', label: 'What do you weigh right now?', required: true, unit: 'weight' },
      {
        key: '1.6', type: 'measurements',
        label: 'Measurements, if you have them',
        help: 'Optional, and you can add them later. Waist is the one that matters '
            + 'most — it tracks the fat that sits around your organs.',
      },
    ],
  },

  {
    id: '2',
    name: 'What you want',
    blurb: 'The goal everything else gets built around.',
    questions: [
      {
        key: '2.1', type: 'choice', required: true,
        label: 'What are you mainly after?',
        options: [
          { value: 'lose_fat', label: 'Lose fat' },
          { value: 'build_muscle', label: 'Build muscle' },
          // No `note` here: it used to read "recomp", which is the internal
          // value, not something a user needs to see.
          { value: 'recomp', label: 'Both — leaner and stronger' },
          { value: 'improve_cardio', label: 'Get fitter' },
          { value: 'improve_strength', label: 'Get stronger' },
          { value: 'general_health', label: 'General health' },
        ],
      },
      {
        key: '2.2', type: 'multi',
        label: 'Anything else alongside that?',
        options: [
          { value: 'lose_fat', label: 'Lose fat' },
          { value: 'build_muscle', label: 'Build muscle' },
          { value: 'improve_cardio', label: 'Get fitter' },
          { value: 'improve_strength', label: 'Get stronger' },
          { value: 'general_health', label: 'General health' },
        ],
      },
      {
        key: '2.3', type: 'longtext', required: true,
        label: 'In your own words, what does success look like?',
        // True, and worth saying: this answer goes into the prompt verbatim.
        help: 'This one carries more weight than anything else here. Be specific, '
            + 'be unreasonable, mention a photo you have in mind. Your coach reads it.',
        placeholder: 'When I…',
      },
      {
        key: '2.5', type: 'choice', required: true,
        label: 'How long are you giving this?',
        help: 'A longer runway means more variety and a steadier build. If what you '
            + 'want is not realistic in the time, your first plan will say so.',
        options: [
          { value: '8_weeks', label: '8 weeks' },
          { value: '12_weeks', label: '12 weeks' },
          { value: '16_weeks', label: '16 weeks' },
          { value: '6_months', label: '6 months' },
          { value: '1_year', label: 'A year' },
          { value: 'none', label: 'No deadline' },
        ],
      },
      {
        key: '2.4', type: 'text',
        label: 'Anything you are working towards?',
        help: 'A wedding, a race, a holiday. Optional.',
      },
      {
        key: '2.6', type: 'choice', required: true,
        label: 'What would you rather move — the scale, or the mirror?',
        help: 'Decides what your progress screen leads with.',
        options: [
          { value: 'scale', label: 'The scale' },
          { value: 'look_feel', label: 'How I look and feel' },
          { value: 'both', label: 'Both' },
        ],
      },
    ],
  },

  {
    id: '3',
    name: 'Health and limits',
    blurb: 'What your plan must never do. This section is why the app can be '
         + 'trusted to write your training for you.',
    questions: [
      {
        key: '3.1', type: 'tags', required: true,
        label: 'Food allergies or intolerances',
        help: 'These are absolute. Nothing containing them will ever appear in a '
            + 'menu, including things made from them.',
        placeholder: 'Peanuts, shellfish…',
        emptyLabel: 'None',
      },
      {
        key: '3.2', type: 'multi', required: true,
        label: 'Anything that affects how you eat or train?',
        help: 'These change how a plan is built, not whether you get one.',
        options: [
          { value: 'diabetes_t1', label: 'Type 1 diabetes' },
          { value: 'diabetes_t2', label: 'Type 2 diabetes' },
          { value: 'heart', label: 'Heart condition' },
          { value: 'hypertension', label: 'High blood pressure' },
          { value: 'thyroid', label: 'Thyroid condition' },
          { value: 'pcos', label: 'PCOS' },
          { value: 'gi', label: 'IBS or another gut condition' },
          { value: 'joint', label: 'Joint problems' },
        ],
        emptyLabel: 'None of these',
        detailKey: '3.2_detail',
        detailLabel: 'Anything worth adding?',
        detailHelp: 'The detail matters more than the checkbox. "Type 2, and a heart '
                  + 'attack five years ago — heart is fine now, the diabetes is the '
                  + 'day-to-day thing" is exactly the kind of thing to write.',
      },
      {
        key: '3.4', type: 'injuries', required: true,
        label: 'Injuries, current or old',
        help: 'For each one, tell us whether to avoid it entirely or work around it.',
      },
      {
        key: '3.5', type: 'tags', required: true,
        label: 'Movements you cannot do, or hate',
        help: 'Strongly avoided. Your coach may still suggest one, but it has to '
            + 'say why, and you can turn it down.',
        placeholder: 'Overhead press, burpees…',
        emptyLabel: 'None',
      },
      {
        key: '3.3', type: 'text',
        label: 'Medications that affect appetite, energy or heart rate',
        help: 'Optional.',
      },
      {
        key: '3.6', type: 'choice', required: true,
        label: 'Has a doctor cleared you for hard exercise?',
        // Honest about what this does: it is context, not a gate. Pretending
        // otherwise would be a lie the user could detect.
        help: 'Recorded as your word for it. It does not unlock or block anything.',
        options: [
          { value: 'yes', label: 'Yes' },
          { value: 'no', label: 'No' },
          { value: 'not_asked', label: 'Never asked' },
        ],
      },
      { key: '3.7', type: 'longtext', label: 'Anything else a coach should know?' },
    ],
  },

  {
    id: '4',
    name: 'Food',
    blurb: 'How you actually eat, not how you think you should.',
    questions: [
      {
        key: '4.4', type: 'multi', required: true,
        label: 'Which meals do you actually eat?',
        help: 'Skip breakfast and those calories move to the meals you do eat, '
            + 'rather than disappearing.',
        options: [
          { value: 'breakfast', label: 'Breakfast' },
          { value: 'lunch', label: 'Lunch' },
          { value: 'dinner', label: 'Dinner' },
          { value: 'snacks', label: 'Snacks' },
        ],
      },
      {
        key: '4.6', type: 'choice', required: true,
        label: 'How much structure do you want?',
        options: [
          { value: 'spell_it_out', label: 'Spell everything out', note: 'every meal, with recipes' },
          { value: 'mix', label: 'Structured dinners, free rein elsewhere', note: 'recommended' },
          { value: 'targets_and_options', label: 'Give me targets and options', note: 'macros, you choose' },
        ],
      },
      {
        key: '4.3', type: 'choice', required: true,
        label: 'Do you follow a particular way of eating?',
        options: [
          { value: 'none', label: 'No' },
          { value: 'vegetarian', label: 'Vegetarian' },
          { value: 'vegan', label: 'Vegan' },
          { value: 'pescatarian', label: 'Pescatarian' },
          { value: 'halal', label: 'Halal' },
          { value: 'kosher', label: 'Kosher' },
          { value: 'keto', label: 'Keto or low carb' },
          { value: 'paleo', label: 'Paleo' },
          { value: 'other', label: 'Something else' },
        ],
      },
      {
        key: '4.1', type: 'tags',
        label: 'Foods you will not eat',
        help: 'Not an allergy — just things you would rather not see.',
        placeholder: 'Mushrooms, olives…',
      },
      {
        key: '4.2', type: 'multi',
        label: 'Food you like',
        options: [
          { value: 'american', label: 'American' }, { value: 'italian', label: 'Italian' },
          { value: 'mexican', label: 'Mexican' }, { value: 'asian', label: 'Asian' },
          { value: 'indian', label: 'Indian' }, { value: 'mediterranean', label: 'Mediterranean' },
          { value: 'middle_eastern', label: 'Middle Eastern' }, { value: 'bbq', label: 'BBQ' },
        ],
      },
      {
        key: '4.7', type: 'longtext',
        label: 'Honestly, how do you eat now?',
        help: 'No judgement, and it helps. "Whatever is convenient, usually a '
            + 'drive-thru" is a genuinely useful answer.',
      },
      { key: '4.8', type: 'number', label: 'Coffees or energy drinks a day', integer: true },
      { key: '4.9', type: 'number', label: 'Drinks a week', integer: true },
    ],
  },

  {
    id: '5',
    name: 'Cooking',
    blurb: 'What is realistic on a weeknight.',
    questions: [
      {
        key: '5.1', type: 'choice', required: true,
        label: 'How well do you cook?',
        options: [
          { value: 'cant_cook', label: 'Barely' },
          { value: 'basic', label: 'Basic' },
          { value: 'competent', label: 'Competent' },
          { value: 'good', label: 'Good' },
          { value: 'excellent', label: 'Very good' },
        ],
      },
      {
        key: '5.2', type: 'number', required: true, integer: true,
        label: 'Minutes you will spend cooking on a weekday',
        // The distinction that made the difference in testing.
        help: 'Being a good cook with 20 minutes is a different problem from being '
            + 'a beginner with an hour. Both get handled — but only if we know.',
      },
      { key: '5.3', type: 'number', required: true, integer: true, label: 'And at the weekend?' },
      { key: '5.4', type: 'number', required: true, integer: true, label: 'How many people are you cooking for?' },
      {
        key: '5.5', type: 'choice',
        label: 'Will you cook ahead?',
        options: [
          { value: 'eagerly', label: 'Happily' },
          { value: 'sometimes', label: 'Sometimes' },
          { value: 'no', label: 'No' },
        ],
      },
      {
        key: '5.9', type: 'multi',
        label: 'What is in your kitchen?',
        help: 'No food scale means recipes in cups and spoons rather than grams.',
        options: [
          { value: 'oven', label: 'Oven' }, { value: 'stovetop', label: 'Stovetop' },
          { value: 'microwave', label: 'Microwave' }, { value: 'air fryer', label: 'Air fryer' },
          { value: 'slow cooker', label: 'Slow cooker' }, { value: 'instant pot', label: 'Instant Pot' },
          { value: 'grill', label: 'Grill' }, { value: 'blender', label: 'Blender' },
          { value: 'food scale', label: 'Food scale' },
        ],
      },
      { key: '5.6', type: 'number', integer: true, label: 'Meals out or takeaway in a normal week' },
      {
        key: '5.7', type: 'number', integer: true,
        label: 'How many meals should your plan leave open?',
        help: 'For eating out. Ask for what you want — if it leaves no room for a '
            + 'plan at all, your coach will say so and suggest a number.',
      },
      {
        key: '5.8', type: 'choice',
        label: 'Does the grocery bill matter?',
        options: [
          { value: 'tight', label: 'Yes, keep it cheap' },
          { value: 'moderate', label: 'Somewhat' },
          { value: 'not_a_concern', label: 'Not really' },
        ],
      },
    ],
  },

  {
    id: '6',
    name: 'Training',
    blurb: 'Where you are starting from.',
    questions: [
      {
        key: '6.1', type: 'choice', required: true,
        label: 'How much lifting have you done?',
        options: [
          { value: 'never', label: 'None' },
          { value: 'beginner', label: 'Under a year' },
          { value: 'intermediate', label: 'One to three years' },
          { value: 'advanced', label: 'Three years or more' },
          { value: 'returning', label: 'A lot, but a while ago' },
        ],
      },
      {
        key: '6.2', type: 'choice', required: true,
        label: 'How often are you training right now?',
        options: [
          { value: 'not_at_all', label: 'Not at all' },
          { value: 'occasionally', label: 'Now and then' },
          { value: '1_2', label: 'Once or twice a week' },
          { value: '3_4', label: 'Three or four times' },
          { value: '5_plus', label: 'Five or more' },
        ],
      },
      {
        key: '6.8', type: 'multi', required: true,
        label: 'Cardio you will actually do',
        options: [
          { value: 'walking', label: 'Walking' }, { value: 'hiking', label: 'Hiking' },
          { value: 'running', label: 'Running' }, { value: 'treadmill', label: 'Treadmill' },
          { value: 'recumbent-bike', label: 'Recumbent bike' }, { value: 'upright-bike', label: 'Upright bike' },
          { value: 'cycling', label: 'Cycling' }, { value: 'rower', label: 'Rower' },
          { value: 'elliptical', label: 'Elliptical' }, { value: 'stair-machine', label: 'Stair machine' },
          { value: 'swimming', label: 'Swimming' }, { value: 'pickleball', label: 'Pickleball' },
          { value: 'tennis', label: 'Tennis' }, { value: 'fitness-class', label: 'Classes' },
        ],
      },
      {
        key: '6.9', type: 'multi',
        label: 'Cardio you refuse',
        help: 'These will not be prescribed. If your goal needs conditioning, your '
            + 'coach will find something from the list above instead.',
        options: [
          { value: 'running', label: 'Running' }, { value: 'rower', label: 'Rower' },
          { value: 'treadmill', label: 'Treadmill' }, { value: 'elliptical', label: 'Elliptical' },
          { value: 'stair-machine', label: 'Stair machine' }, { value: 'swimming', label: 'Swimming' },
          { value: 'fitness-class', label: 'Classes' },
        ],
      },
      {
        key: '6.3', type: 'longtext',
        label: 'Have you been in great shape before?',
        help: 'If something worked for you once, say what it was. Your coach will '
            + 'build on it rather than starting from scratch.',
      },
      {
        key: '6.4', type: 'choice',
        label: 'How strong would you say you are?',
        options: [
          { value: 'poor', label: 'Weak' }, { value: 'below_average', label: 'Below average' },
          { value: 'average', label: 'Average' }, { value: 'good', label: 'Good' },
          { value: 'strong', label: 'Strong' },
        ],
      },
      {
        key: '6.5', type: 'choice',
        label: 'And your cardio?',
        options: [
          { value: 'poor', label: 'Terrible' }, { value: 'below_average', label: 'Below average' },
          { value: 'average', label: 'Average' }, { value: 'good', label: 'Good' },
          { value: 'excellent', label: 'Very good' },
        ],
      },
      {
        key: '6.6', type: 'multi',
        label: 'Lifts you already know how to do',
        options: [
          { value: 'back-squat', label: 'Squat' }, { value: 'conventional-deadlift', label: 'Deadlift' },
          { value: 'barbell-bench-press', label: 'Bench press' }, { value: 'overhead-press', label: 'Overhead press' },
          { value: 'barbell-row', label: 'Barbell row' }, { value: 'pull-up', label: 'Pull-up' },
          { value: 'walking-lunge', label: 'Lunge' }, { value: 'barbell-hip-thrust', label: 'Hip thrust' },
          { value: 'romanian-deadlift', label: 'Romanian deadlift' },
        ],
      },
      {
        /*
         * What is actually in the home gym.
         *
         * Only matters if a day is marked "Home gym" in the availability grid — but asked of
         * everyone, because the grid comes later in onboarding and a conditional question that
         * depends on a later answer is worse than one extra tap.
         *
         * Six items rather than the library's 32 equipment tokens: twenty of those unlock one
         * exercise each, and a long checklist is where people tick everything without reading.
         * Ticking nothing is a real answer — bodyweight work at home — not a skipped question.
         */
        key: '6.12', type: 'multi',
        label: 'If you train at home, what have you got?',
        help: 'Leave it empty if the answer is nothing. Your plan will use bodyweight work '
            + 'on those days rather than asking you for a squat rack you do not own.',
        options: [
          { value: 'dumbbell', label: 'Dumbbells' },
          { value: 'bench', label: 'Bench' },
          { value: 'resistance_band', label: 'Resistance bands' },
          { value: 'pull_up_bar', label: 'Pull-up bar' },
          { value: 'kettlebell', label: 'Kettlebell' },
          { value: 'barbell', label: 'Barbell and rack' },
        ],
      },
      {
        key: '6.10', type: 'choice',
        label: 'Any preference for how the week is split?',
        options: [
          { value: 'no_preference', label: 'No preference' },
          { value: 'full_body', label: 'Full body each time' },
          { value: 'upper_lower', label: 'Upper and lower days' },
          { value: 'ppl', label: 'Push, pull, legs' },
        ],
      },
      { key: '6.11', type: 'longtext', label: 'How do you feel about cardio?' },
    ],
  },

  {
    id: '7',
    name: 'Your week',
    blurb: 'When you can train, and what you have access to on each day.',
    grid: true,   // bespoke control; see AvailabilityGrid
  },

  {
    id: '8',
    name: 'Day to day',
    blurb: 'Your normal, so a bad day is recognisable as one.',
    questions: [
      {
        key: '8.1', type: 'number', required: true,
        label: 'Hours of sleep on a normal night',
        help: 'These answers become your baseline. "Low energy" means nothing until '
            + 'we know what normal looks like for you.',
      },
      {
        key: '8.2', type: 'choice',
        label: 'How well do you sleep?',
        options: [
          { value: 'poor', label: 'Badly' }, { value: 'fair', label: 'Fair' },
          { value: 'good', label: 'Well' }, { value: 'great', label: 'Very well' },
        ],
      },
      {
        key: '8.3', type: 'choice', required: true,
        label: 'How active are you outside training?',
        options: [
          { value: 'sedentary', label: 'Desk-bound' },
          { value: 'light', label: 'Lightly active' },
          { value: 'moderate', label: 'Moderately active' },
          { value: 'very', label: 'On my feet all day' },
        ],
      },
      {
        key: '8.5', type: 'choice', required: true,
        label: 'Energy on a normal day',
        options: [
          { value: 'drained', label: 'Drained' }, { value: 'low', label: 'Low' },
          { value: 'ok', label: 'Fine' }, { value: 'good', label: 'Good' },
          { value: 'high', label: 'High' },
        ],
      },
      {
        key: '8.4', type: 'choice',
        label: 'Stress',
        options: [
          { value: 'low', label: 'Low' }, { value: 'moderate', label: 'Moderate' },
          { value: 'high', label: 'High' }, { value: 'very_high', label: 'Very high' },
        ],
      },
    ],
  },

  /*
   * There is no section 9.
   *
   * It was "How to talk to you": voice, explanation depth, nudge intensity and days, and two
   * privacy toggles. All six moved to the Profile's settings, because none of them was
   * really a question about the user. They are controls over how the app behaves, and asking
   * someone to choose a coaching voice before they have read a single word the coach writes
   * is asking them to guess. In the Profile they can change their mind after finding out.
   *
   * The gap in the numbering is deliberate and harmless: Quiz.jsx walks this array by
   * POSITION, never by parsing an id, and §10 renders as "Optional" rather than "Section 10".
   * Renumbering would invalidate every stored answer key.
   */

  {
    id: '10',
    name: 'Anything else',
    blurb: 'Optional, and the most useful section here. Skip it if you would rather.',
    optional: true,
    questions: [
      { key: '10.1', type: 'longtext', label: 'What has worked for you before?' },
      {
        key: '10.2', type: 'longtext',
        label: 'What has failed, and why do you think it failed?',
        help: '"I know what to do, I just do not do it" is a completely different '
            + 'problem from "I never knew where to start" — and it changes what '
            + 'your coach leans on.',
      },
      { key: '10.3', type: 'longtext', label: 'What would make you quit?' },
      { key: '10.4', type: 'longtext', label: 'Anything else?' },
    ],
  },
]

export const DAYS = [
  { n: 1, short: 'Mon', long: 'Monday' },
  { n: 2, short: 'Tue', long: 'Tuesday' },
  { n: 3, short: 'Wed', long: 'Wednesday' },
  { n: 4, short: 'Thu', long: 'Thursday' },
  { n: 5, short: 'Fri', long: 'Friday' },
  { n: 6, short: 'Sat', long: 'Saturday' },
  { n: 7, short: 'Sun', long: 'Sunday' },
]

export const ACCESS = [
  { value: 'full_gym', label: 'Full gym' },
  { value: 'home_gym', label: 'Home gym' },
  { value: 'bodyweight', label: 'Bodyweight only' },
  { value: 'outdoors', label: 'Outdoors' },
]

export const sectionById = (id) => SECTIONS.find((s) => s.id === String(id))
