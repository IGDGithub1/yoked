<?php
declare(strict_types=1);

/**
 * Settings and preference tests (SPEC-safety §6).
 *
 * No --live half: nothing here calls a model. Every assertion is structural, and the ones
 * that matter are attempts to break the tier rule — switch off a hard constraint, reach one
 * by id, reach another user's, downgrade one to soft on the way past.
 *
 * SPEC-safety §6 says constraints change only through deliberate profile edits and gives the
 * reason: a limit that two taps can remove is not a limit. A settings screen is exactly the
 * kind of place that quietly grows an off switch for everything, so the refusal is asserted
 * rather than assumed.
 *
 *   php bin/test-settings.php
 *   php bin/test-settings.php --keep     leave the fixtures behind
 */

require __DIR__ . '/../src/bootstrap_cli.php';
require YK_SRC . '/lib/Response.php';
require YK_SRC . '/lib/RateLimit.php';
require YK_SRC . '/lib/Goals.php';
require YK_SRC . '/lib/Claude.php';
require YK_SRC . '/lib/PlanSchema.php';
require YK_SRC . '/lib/ConstraintLabel.php';
require YK_SRC . '/lib/BuddySchedule.php';   // Safety::checkAvailability reads it
require YK_SRC . '/lib/Safety.php';
require YK_SRC . '/lib/Plans.php';
require YK_SRC . '/lib/Nutrition.php';
require YK_SRC . '/lib/Onboarding.php';
require YK_SRC . '/lib/Settings.php';
require YK_SRC . '/lib/Drift.php';         // BuddyAbsence reads lastLoggedDate
require YK_SRC . '/lib/BuddyAbsence.php';  // Plans::gatherContext reads it

$keep = in_array('--keep', array_slice($argv, 1), true);

$pass = 0;
$fail = 0;

function t(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true) {
            printf("  ok    %s\n", $label);
            $pass++;
        } elseif ($r === null) {
            printf("  skip  %s\n", $label);
        } else {
            printf("  FAIL  %s — %s\n", $label, is_string($r) ? $r : 'false');
            $fail++;
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s — %s\n", $label, $e->getMessage());
        $fail++;
    }
}

DB::run("DELETE FROM users WHERE username LIKE 'settest_%'");

/** A user with one hard constraint and one soft one. */
function seedUser(string $suffix): array
{
    $userId = DB::insert(
        'INSERT INTO users (username, display_name, email, password_hash, onboarding_state)
         VALUES (?, ?, ?, "x", "active")',
        ['settest_' . $suffix, 'Set ' . $suffix, "settest_{$suffix}@example.test"]
    );
    DB::run(
        'INSERT INTO profiles (user_id, timezone, checkin_weekday, checkin_hour,
                               plan_generation_weekday, plan_generation_hour, review_hour)
         VALUES (?, "UTC", 6, 18, 7, 18, 20)',
        [$userId]
    );

    $hard = (int) DB::insert(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "food", "hard", "peanuts", "anaphylaxis", "onboarding")',
        [$userId]
    );
    // Soft, and specifically veto_promotion: that is the row the Veto form promises can be
    // switched off, so it is the one worth testing.
    $soft = (int) DB::insert(
        'INSERT INTO user_constraints (user_id, kind, tier, subject, reason, source)
         VALUES (?, "food", "soft", "salmon", "turned it down", "veto_promotion")',
        [$userId]
    );

    return ['id' => $userId, 'hard' => $hard, 'soft' => $soft];
}

echo "Settings and preference tests\n\n";

// ---------------------------------------------------------------------------
echo "1. reading\n";

$u = seedUser('read');

t('the settings come back with the fields the quiz never asked', function () use ($u) {
    $s = Settings::forUser($u['id']);
    foreach (['coaching_paused', 'checkin', 'plan', 'review_hour'] as $k) {
        if (!array_key_exists($k, $s)) {
            return "missing {$k}";
        }
    }
    // The timezone rides along because "18:00" is meaningless without it.
    return array_key_exists('timezone', $s) ?: 'no timezone for the times to be read against';
});

t('review_hour 0 survives as a real value, not a missing one', function () {
    // 0 means the Next Day Review is off. A truthiness check anywhere in the chain would
    // turn "off" back into the default.
    $p = seedUser('reviewoff');
    DB::run('UPDATE profiles SET review_hour = 0 WHERE user_id = ?', [$p['id']]);
    $s = Settings::forUser($p['id']);
    return $s['review_hour'] === 0
        ?: 'review_hour came back as ' . var_export($s['review_hour'], true);
});

t('constraints come back with ids and a switchable flag', function () use ($u) {
    $rows = Settings::constraints($u['id']);
    if (count($rows) !== 2) {
        return count($rows) . ' constraints, expected 2';
    }
    foreach ($rows as $r) {
        if (($r['id'] ?? 0) <= 0) {
            return 'a row came back with no id';
        }
        // The client renders its control from this rather than reading the tier itself.
        $want = $r['tier'] === 'soft';
        if ($r['switchable'] !== $want) {
            return "{$r['subject']} ({$r['tier']}) has switchable=" . var_export($r['switchable'], true);
        }
    }
    return true;
});

// ---------------------------------------------------------------------------
echo "\n2. a hard constraint cannot be switched off\n";

t('switching off a hard constraint is refused', function () {
    /*
     * The load-bearing assertion of this file. SPEC-safety §6: "an LLM that can be argued out
     * of a constraint has no constraints", and a UI that can be tapped out of one is the same
     * failure with a different input device.
     */
    $p = seedUser('hardoff');
    $r = Settings::setConstraintActive($p['id'], $p['hard'], false);
    if ($r['ok'] !== false) {
        return 'a hard constraint was switched off';
    }
    $c = DB::one('SELECT active, tier FROM user_constraints WHERE id = ?', [$p['hard']]);
    if ((int) $c['active'] !== 1) {
        return 'the hard constraint is no longer active';
    }
    if ((string) $c['tier'] !== 'hard') {
        return 'the hard constraint was downgraded to ' . (string) $c['tier'];
    }
    // The refusal has to point somewhere. One with no route forward reads as a bug.
    return str_contains(strtolower((string) $r['error']), 'profile')
        ?: 'the refusal does not say what to do instead: ' . (string) $r['error'];
});

t('the refusal is in the library, not just the route', function () {
    // So it holds for every caller. A check that lives only in settings.php is one route
    // away from being bypassed.
    $src = (string) file_get_contents(YK_SRC . '/lib/Settings.php');
    return str_contains($src, "if ((string) \$c['tier'] !== 'soft')")
        ?: 'setConstraintActive does not gate on the tier';
});

t('nothing here can change a tier', function () {
    /*
     * The other half of the boundary. Switching a preference off is a state change; turning
     * a hard limit into a soft one is a change of KIND, and this file must not be able to do
     * it even as a side effect.
     */
    $src = (string) file_get_contents(YK_SRC . '/lib/Settings.php');
    if (preg_match('/UPDATE user_constraints[^\']*tier\s*=/i', $src)) {
        return 'Settings.php writes to tier';
    }
    return !str_contains($src, 'DELETE FROM user_constraints')
        ?: 'Settings.php deletes constraints';
});

t("another user's preference cannot be touched", function () {
    $a = seedUser('owner');
    $b = seedUser('stranger');
    $r = Settings::setConstraintActive($b['id'], $a['soft'], false);
    if ($r['ok'] !== false) {
        return "switched off another user's preference";
    }
    $c = DB::one('SELECT active FROM user_constraints WHERE id = ?', [$a['soft']]);
    return (int) $c['active'] === 1 ?: "the owner's preference was deactivated";
});

// ---------------------------------------------------------------------------
echo "\n3. a soft preference can be switched off and back on\n";

t('switching off a soft preference works and is audited', function () {
    $p = seedUser('softoff');
    $r = Settings::setConstraintActive($p['id'], $p['soft'], false);
    if (!$r['ok']) {
        return (string) $r['error'];
    }
    $c = DB::one('SELECT active FROM user_constraints WHERE id = ?', [$p['soft']]);
    if ((int) $c['active'] !== 0) {
        return 'still active';
    }
    $a = DB::one(
        'SELECT action FROM user_constraint_audit WHERE constraint_id = ? ORDER BY id DESC LIMIT 1',
        [$p['soft']]
    );
    return ($a !== null && (string) $a['action'] === 'deactivate')
        ?: 'the change was not audited as a deactivate';
});

t('it is reversible', function () {
    $p = seedUser('softback');
    Settings::setConstraintActive($p['id'], $p['soft'], false);
    $r = Settings::setConstraintActive($p['id'], $p['soft'], true);
    if (!$r['ok']) {
        return (string) $r['error'];
    }
    $c = DB::one('SELECT active FROM user_constraints WHERE id = ?', [$p['soft']]);
    return (int) $c['active'] === 1 ?: 'did not come back on';
});

t('the row survives, so the record of what was said survives', function () {
    // Deactivation, never deletion: a preference switched off last month is still the answer
    // to "why did you stop suggesting salmon".
    $p = seedUser('survives');
    Settings::setConstraintActive($p['id'], $p['soft'], false);
    $rows = Settings::constraints($p['id']);
    $found = null;
    foreach ($rows as $r) {
        if ($r['id'] === $p['soft']) { $found = $r; }
    }
    if ($found === null) {
        return 'the switched-off preference vanished from the list';
    }
    return $found['active'] === false ?: 'it is listed as still active';
});

t('a switched-off preference stops reaching the plan prompt', function () {
    // The whole point. Safety::forUser filters on active, so this asserts the two agree.
    $p = seedUser('reaches');
    $before = Safety::forUser($p['id']);
    $softBefore = count($before['soft'] ?? []);
    Settings::setConstraintActive($p['id'], $p['soft'], false);
    $after = Safety::forUser($p['id']);
    $softAfter = count($after['soft'] ?? []);

    if ($softAfter >= $softBefore) {
        return "soft constraints went {$softBefore} -> {$softAfter}";
    }
    // And the hard one is untouched by any of it.
    return count($after['hard'] ?? []) === count($before['hard'] ?? [])
        ?: 'the hard constraints changed too';
});

t('a switched-off veto promotion stops the VETO reaching the prompt too', function () {
    /*
     * A gap found while building this, not a hypothetical.
     *
     * An accepted standing veto reaches the generator through Plans::standingVetoes as
     * "These are permanent. Do not prescribe them again", entirely separately from the
     * constraint it created. Without a join on the constraint's active flag, switching the
     * preference off would silence the constraint and leave the veto still saying never
     * again: the switch would look like it worked and would not have.
     */
    $p = seedUser('vetojoin');
    DB::insert(
        'INSERT INTO vetoes (user_id, subject_type, subject_id, reason_code, reason_text,
                             scope, outcome, promoted_constraint_id)
         VALUES (?, "meal", 1, "dont_like", "no more salmon ever", "standing", "accepted", ?)',
        [$p['id'], $p['soft']]
    );

    $ref = new ReflectionMethod(Plans::class, 'standingVetoes');
    $ref->setAccessible(true);

    if (count($ref->invoke(null, $p['id'])) !== 1) {
        return 'the standing veto was not visible to begin with';
    }
    Settings::setConstraintActive($p['id'], $p['soft'], false);
    $after = $ref->invoke(null, $p['id']);
    return count($after) === 0
        ?: 'the veto still reaches the prompt after its preference was switched off';
});

t('a veto that promoted nothing is unaffected', function () {
    // Null promoted_constraint_id means there is no switch for the user to have thrown, so
    // the LEFT JOIN must keep the row rather than drop it.
    $p = seedUser('vetonull');
    DB::insert(
        'INSERT INTO vetoes (user_id, subject_type, subject_id, reason_code, reason_text,
                             scope, outcome)
         VALUES (?, "session", 1, "cant_do", "knee", "standing", "accepted")',
        [$p['id']]
    );
    $ref = new ReflectionMethod(Plans::class, 'standingVetoes');
    $ref->setAccessible(true);
    return count($ref->invoke(null, $p['id'])) === 1
        ?: 'a veto with no promotion was dropped by the active join';
});

// ---------------------------------------------------------------------------
echo "\n4. saving settings\n";

t('the pause can be turned on and off', function () {
    $p = seedUser('pause');
    Settings::save($p['id'], ['coaching_paused' => true]);
    $on = (int) DB::one('SELECT coaching_paused FROM profiles WHERE user_id = ?',
        [$p['id']])['coaching_paused'];
    Settings::save($p['id'], ['coaching_paused' => false]);
    $off = (int) DB::one('SELECT coaching_paused FROM profiles WHERE user_id = ?',
        [$p['id']])['coaching_paused'];
    return ($on === 1 && $off === 0) ?: "on={$on} off={$off}";
});

t('a partial save leaves everything else alone', function () {
    /*
     * The onboarding projectors update every column of their section unconditionally, which
     * is fine for a whole-section submit and wrong here. A user toggling the pause must not
     * silently reset the day their check-in opens.
     */
    $p = seedUser('partial');
    DB::run('UPDATE profiles SET checkin_weekday = 5, review_hour = 21
             WHERE user_id = ?', [$p['id']]);
    Settings::save($p['id'], ['coaching_paused' => true]);
    $r = DB::one('SELECT checkin_weekday, review_hour FROM profiles WHERE user_id = ?',
        [$p['id']]);
    return ((int) $r['checkin_weekday'] === 5 && (int) $r['review_hour'] === 21)
        ?: 'a partial save clobbered other fields';
});

t('the check-in must open before the plan is written', function () {
    /*
     * §4: the check-in opens 24 hours before generation so there is time to answer it. A user
     * who moved generation earlier would get plans built from data not yet collected, and
     * would never work out why their answers seemed ignored.
     */
    $p = seedUser('order');
    // Check-in Sunday 20:00, generation already Sunday 18:00 — after, so refused.
    $r = Settings::save($p['id'], ['checkin_weekday' => 7, 'checkin_hour' => 20]);
    if ($r['ok'] !== false) {
        return 'a check-in after the plan generation was accepted';
    }
    $c = DB::one('SELECT checkin_weekday, checkin_hour FROM profiles WHERE user_id = ?',
        [$p['id']]);
    return ((int) $c['checkin_weekday'] === 6 && (int) $c['checkin_hour'] === 18)
        ?: 'the refused change was written anyway';
});

t('the rule is checked against the merged state, not just what was sent', function () {
    // Either slot can move on its own, so validating only the submitted fields would miss a
    // new value colliding with a stored one.
    $p = seedUser('merged');
    // Move GENERATION earlier than the stored check-in. Nothing about the check-in is sent.
    $r = Settings::save($p['id'], ['plan_generation_weekday' => 6,
                                   'plan_generation_hour' => 9]);
    return $r['ok'] === false
        ?: 'moving generation before the stored check-in was allowed';
});

t('a legal reschedule is accepted', function () {
    $p = seedUser('legal');
    // Friday evening check-in, Saturday evening plan. Earlier than the default, still ordered.
    $r = Settings::save($p['id'], [
        'checkin_weekday' => 5, 'checkin_hour' => 19,
        'plan_generation_weekday' => 6, 'plan_generation_hour' => 19,
    ]);
    if (!$r['ok']) {
        return (string) $r['error'];
    }
    $c = DB::one('SELECT checkin_weekday, plan_generation_weekday FROM profiles
                  WHERE user_id = ?', [$p['id']]);
    return ((int) $c['checkin_weekday'] === 5 && (int) $c['plan_generation_weekday'] === 6)
        ?: 'the legal change did not stick';
});

t('review_hour 0 is accepted as "off"', function () {
    $p = seedUser('revoff');
    $r = Settings::save($p['id'], ['review_hour' => 0]);
    if (!$r['ok']) {
        return (string) $r['error'];
    }
    return (int) DB::one('SELECT review_hour FROM profiles WHERE user_id = ?',
        [$p['id']])['review_hour'] === 0 ?: 'review_hour did not save as 0';
});

t('out-of-range values are refused', function () {
    $p = seedUser('range');
    foreach ([
        ['checkin_weekday' => 0], ['checkin_weekday' => 8],
        ['checkin_hour' => 24], ['checkin_hour' => -1],
        ['review_hour' => 24],
    ] as $i => $bad) {
        if (Settings::save($p['id'], $bad)['ok'] !== false) {
            return 'accepted ' . json_encode($bad);
        }
    }
    return true;
});

t('an empty body changes nothing', function () {
    $p = seedUser('empty');
    return Settings::save($p['id'], [])['ok'] === false ?: 'an empty save reported success';
});

t('core work is no longer a setting', function () {
    /*
     * §3.3b: "Built in by default, not asked as a preference." A dial with an 'off' position
     * contradicted that twice over — it let a user switch core work off, and it made a
     * structural programming decision into a preference.
     *
     * Asserted from both ends: the save path must refuse the field, and the read must not
     * offer it. A column left in the schema with nothing reading it is fine; a control that
     * quietly still writes is not.
     */
    $p = seedUser('nocore');
    if (Settings::save($p['id'], ['core_emphasis' => 'heavy'])['ok'] !== false) {
        return 'core_emphasis is still writable through settings';
    }
    return !array_key_exists('core_emphasis', Settings::forUser($p['id']))
        ?: 'core_emphasis is still reported by forUser';
});

t('generation always prescribes core work, with no way to turn it off', function () {
    // The other half: removing the dial must not have removed the RULE. Read the prompt
    // rather than the source, so this fails if the rule stops reaching the model.
    $ref = new ReflectionMethod(Plans::class, 'rules');
    $ref->setAccessible(true);
    $rules = (string) $ref->invoke(null, 4);
    if (!str_contains($rules, 'CORE ON EVERY STRENGTH DAY')) {
        return 'the core rule is no longer in the prompt';
    }
    return str_contains($rules, '8-12 minutes')
        ?: 'the core rule no longer states 8-12 minutes';
});

// ---------------------------------------------------------------------------
echo "\n5. the settings that used to be section 9\n";

t('the six former quiz fields are readable and writable', function () {
    $p = seedUser('sec9');
    foreach ([
        ['tone', 'sarcastic_hardass'],
        ['explanation_depth', 'explain'],
        ['nudge_intensity', 'relentless'],
        ['nudge_after_days', 7],
        ['hide_photos', false],
        ['hide_measurements', false],
    ] as [$key, $val]) {
        $r = Settings::save($p['id'], [$key => $val]);
        if (!$r['ok']) {
            return "{$key}: " . (string) $r['error'];
        }
        $back = Settings::forUser($p['id'])[$key];
        if ($back !== $val) {
            return "{$key} came back as " . var_export($back, true)
                 . ', expected ' . var_export($val, true);
        }
    }
    return true;
});

t('section 9 is gone from onboarding, both list and required keys', function () {
    // Shipping one side without the other is the failure mode: the client would never show
    // section 9, and startBaseline would 409 forever on a section nobody could reach.
    $ref = new ReflectionClass(Onboarding::class);
    $sections = $ref->getConstant('SECTIONS');
    $required = $ref->getConstant('REQUIRED_KEYS');
    if (isset($sections['9'])) {
        return 'SECTIONS still has a 9';
    }
    return !isset($required['9']) ?: 'REQUIRED_KEYS still has a 9';
});

t('a 9.x answer is no longer accepted by the quiz save path', function () {
    // isKnownKey is derived from SECTIONS, so this follows from the removal. Asserted
    // because it is what would break a Profile save if one were ever routed through
    // PUT /api/onboarding by mistake.
    $p = seedUser('nokey');
    $r = Onboarding::saveAnswers($p['id'], ['9.1' => 'sarcastic_hardass']);
    return ($r['ok'] ?? true) === false
        ?: 'the quiz still accepts a 9.1 answer';
});

t('the section list is 1 to 8 plus the optional 10', function () {
    $ref = new ReflectionClass(Onboarding::class);
    /*
     * Cast to string, and sort as strings. Two traps in one line.
     *
     * PHP turns numeric-looking ARRAY KEYS into integers, so array_keys returns [1, 10, 2…]
     * rather than ['1', '10', '2'…] and a === against string literals fails while printing
     * something that looks identical. And sort() compares numeric strings numerically unless
     * told otherwise, so the expected order depends on which sort you happened to use.
     */
    $sections = array_map('strval', array_keys($ref->getConstant('SECTIONS')));
    sort($sections, SORT_STRING);
    return $sections === ['1', '10', '2', '3', '4', '5', '6', '7', '8']
        ?: 'sections are now ' . implode(',', $sections);
});

t('out-of-range values for the moved fields are refused', function () {
    $p = seedUser('sec9bad');
    foreach ([
        ['tone' => 'shouty'],
        ['explanation_depth' => 'essay'],
        ['nudge_intensity' => 'nuclear'],
        ['nudge_after_days' => 0],
        ['nudge_after_days' => 31],
    ] as $bad) {
        if (Settings::save($p['id'], $bad)['ok'] !== false) {
            return 'accepted ' . json_encode($bad);
        }
    }
    return true;
});

// ---------------------------------------------------------------------------
echo "\n6. constraints read as English, not as database keys\n";

t('condition keys get their question label', function () {
    // A user saw "diabetes_t2" on their profile. This map has to agree with questions.js 3.2
    // and with the guidance map in Onboarding::extractConditions.
    foreach ([
        ['diabetes_t2', 'Type 2 diabetes'],
        ['heart', 'Heart condition'],
        ['gi', 'IBS or another gut condition'],
    ] as [$subject, $want]) {
        $got = ConstraintLabel::of('condition', $subject);
        if ($got !== $want) {
            return "{$subject} -> {$got}, expected {$want}";
        }
    }
    return true;
});

t('the dietary pattern prefix is unwrapped', function () {
    return ConstraintLabel::of('food', 'dietary_pattern:vegan') === 'Vegan'
        ?: 'got ' . ConstraintLabel::of('food', 'dietary_pattern:vegan');
});

t('hyphenated cardio values read properly', function () {
    return ConstraintLabel::of('cardio', 'stair-machine') === 'Stair machine'
        ?: 'got ' . ConstraintLabel::of('cardio', 'stair-machine');
});

t('free text is tidied rather than mangled', function () {
    // Allergies, injuries and anything a veto promoted have no closed vocabulary, so the
    // fallback has to be safe for arbitrary input.
    foreach ([
        ['peanuts', 'Peanuts'],
        ['left knee', 'Left knee'],
        ['', ''],
    ] as [$in, $want]) {
        $got = ConstraintLabel::of('food', $in);
        if ($got !== $want) {
            return var_export($in, true) . ' -> ' . var_export($got, true);
        }
    }
    return true;
});

t('the facet distinguishes a ban from a modifier', function () {
    /*
     * The reframing. Filing a condition under "never" told a user their diabetes was being
     * avoided, and a dietary pattern is how they eat rather than something kept from them.
     */
    foreach ([
        ['condition', 'diabetes_t2', 'manage'],
        ['food', 'dietary_pattern:vegan', 'eating'],
        ['target_floor', 'protein_g', 'floor'],
        ['food', 'peanuts', 'avoid'],
        ['movement', 'back squat', 'avoid'],
    ] as [$kind, $subject, $want]) {
        $got = ConstraintLabel::facet($kind, $subject);
        if ($got !== $want) {
            return "{$kind}/{$subject} -> {$got}, expected {$want}";
        }
    }
    return true;
});

t('a condition is never described as never prescribed', function () {
    $m = strtolower(ConstraintLabel::meaning('manage', 'hard'));
    return !str_contains($m, 'never prescribed')
        ?: 'a condition still reads as a ban: ' . $m;
});

t('the label rides beside the subject and never replaces it', function () {
    /*
     * THE HAZARD THIS GUARDS. Safety::promptBlock expands a food category into its members
     * by an exact lowercase key lookup: "shellfish" becomes shrimp, prawn, crab, because
     * validation rejects shrimp and so the prompt has to say shrimp. Relabel the subject
     * anywhere Safety can see it and that expansion silently stops happening.
     */
    $p = seedUser('labelsafe');
    DB::run('UPDATE user_constraints SET subject = "shellfish" WHERE id = ?', [$p['hard']]);

    $rows = Settings::constraints($p['id']);
    $row  = null;
    foreach ($rows as $r) {
        if ($r['id'] === $p['hard']) { $row = $r; }
    }
    if ($row === null) {
        return 'the constraint vanished';
    }
    if ($row['subject'] !== 'shellfish') {
        return 'the stored subject was rewritten to ' . $row['subject'];
    }
    if (($row['label'] ?? '') !== 'Shellfish') {
        return 'no label alongside: ' . var_export($row['label'] ?? null, true);
    }

    // And the prompt still gets the expansion, which is the thing that actually matters.
    $block = Safety::promptBlock($p['id']);
    return str_contains($block, 'shrimp')
        ?: 'the prompt no longer expands shellfish into its members';
});

t('nothing in the label path writes to the database', function () {
    $src = (string) file_get_contents(YK_SRC . '/lib/ConstraintLabel.php');
    foreach (['UPDATE ', 'INSERT ', 'DELETE ', 'DB::'] as $bad) {
        if (str_contains($src, $bad)) {
            return "ConstraintLabel touches the database: {$bad}";
        }
    }
    return true;
});

t('Safety does not know about labels at all', function () {
    // The boundary asserted from the other side: a label reaching Safety::forUser would leak
    // into both the prompt and the validator.
    $src = (string) file_get_contents(YK_SRC . '/lib/Safety.php');
    return !str_contains($src, 'ConstraintLabel')
        ?: 'Safety.php references ConstraintLabel';
});

// ---------------------------------------------------------------------------

if (!$keep) {
    DB::run("DELETE FROM users WHERE username LIKE 'settest_%'");
    echo "\nfixtures removed\n";
} else {
    echo "\nfixtures kept\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
