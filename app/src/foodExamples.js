/**
 * Search placeholders that match the meal you are actually logging.
 *
 * "6oz chicken and a cup of broccoli" as the breakfast placeholder is the kind of
 * detail that tells a user the app was assembled rather than written — nobody
 * eats that at 7am. The placeholder is also doing real teaching work here: the
 * search handles quantities and multiple foods in one query, and the example is
 * the only place that gets demonstrated.
 *
 * Rotating them per render would be worse than a fixed string — a placeholder
 * that changes while you look at it reads as a bug. So the example is chosen
 * from the slot's list by the DATE, which means it varies day to day and holds
 * still within a day.
 */

const EXAMPLES = {
  breakfast: [
    'three scrambled eggs and two rashers of bacon',
    'a bowl of porridge with honey and a banana',
    'greek yoghurt, blueberries and a spoon of granola',
    'two slices of toast with peanut butter',
    'a four-egg omelette with cheese and mushrooms',
  ],
  lunch: [
    'a chicken caesar wrap and a packet of crisps',
    '200g leftover chilli with rice',
    'a tuna baguette and an apple',
    'a big salad with feta, olives and grilled halloumi',
    'two slices of pepperoni pizza',
  ],
  dinner: [
    '8oz sirloin, mashed potato and green beans',
    'salmon fillet with roast vegetables',
    'a chicken curry with naan',
    'spaghetti bolognese, about two cups',
    'stir-fried beef and noodles',
  ],
  snack_am: [
    'a flat white and a croissant',
    'a handful of almonds',
    'a protein bar',
    'an apple and some cheese',
  ],
  snack_pm: [
    'a protein shake with milk',
    'hummus and carrot sticks',
    'a banana and a coffee',
    'a bag of popcorn',
  ],
  snack_eve: [
    'two squares of dark chocolate',
    'a bowl of ice cream',
    'cheese and crackers',
    'a glass of red wine',
  ],
}

// Anything unslotted — and the honest fallback if a slot is ever added to the
// server enum before it is added here.
const GENERIC = [
  'a chicken sandwich and a coffee',
  '200g of greek yoghurt',
  'a handful of cashews',
]

/**
 * A placeholder for one slot on one date.
 *
 * Deterministic in (slot, date): stable all day, different tomorrow. The date is
 * summed rather than parsed into a Date — this only needs to vary, not to mean
 * anything, and Date parsing here would be pure ceremony.
 */
export function foodPlaceholder(slot, date) {
  const list = EXAMPLES[slot] || GENERIC
  let n = 0
  for (const ch of String(date || '')) n += ch.charCodeAt(0)
  return list[n % list.length]
}
