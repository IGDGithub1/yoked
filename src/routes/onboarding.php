<?php
declare(strict_types=1);

/**
 * The onboarding quiz.
 *
 * Save/resume per section, projected into the typed tables on every write so an
 * abandoned quiz still leaves a usable partial profile.
 */

/**
 * GET /api/onboarding — the whole quiz state: answers, progress, next step.
 *
 * One call rather than three because the SPA needs all of it to render a
 * resumable form, and three round trips on app boot is three chances to be
 * half-loaded.
 */
$router->add('GET', 'onboarding', function (): void {
    $user = Auth::require();
    $userId = (int) $user['id'];

    Response::json([
        'sections' => Onboarding::SECTIONS,
        'blocking' => Onboarding::BLOCKING_SECTIONS,
        'answers'  => Onboarding::answers($userId),
        'progress' => Onboarding::progress($userId),
        'next'     => Onboarding::nextStep($userId, (string) $user['onboarding_state']),
    ]);
});

/**
 * PUT /api/onboarding — save answers.
 *
 * Body: {"answers": {"1.1": "1974-03-02", "2.1": "lose_fat", ...}}
 *
 * Accepts a partial set, so the SPA can save per-question as the user types or
 * per-section on continue. Either way nothing is lost if they close the tab.
 */
$router->add('PUT', 'onboarding', function (): void {
    $user = Auth::require();
    $userId = (int) $user['id'];

    // Editing after the quiz is allowed and expected — a user should be able to
    // correct a constraint. But re-answering during the baseline re-projects
    // constraints, which is exactly what we want, so no gate here.
    $b = Response::body();
    $answers = $b['answers'] ?? null;

    if (!is_array($answers) || $answers === []) {
        Response::error('Send an "answers" object with at least one question.', 422);
    }
    if (count($answers) > 100) {
        Response::error('Too many answers in one request.', 413);
    }

    $result = Onboarding::saveAnswers($userId, $answers);

    if (!$result['ok']) {
        Response::error('Some answers could not be saved.', 422, [
            'errors'   => $result['errors'],
            'progress' => $result['progress'],
        ]);
    }

    Response::json([
        'ok'       => true,
        'progress' => $result['progress'],
        // Non-empty when an answer was accepted but warrants a second look —
        // currently a soft-tiered injury described in clinical terms. The SPA
        // should surface this as a question, not an error: the user's answer
        // stands unless they change it.
        'confirm'  => $result['confirm'],
        'next'     => Onboarding::nextStep($userId, (string) $user['onboarding_state']),
    ]);
});

/**
 * POST /api/onboarding/confirm-tier — resolve a tier-check prompt.
 *
 * Body: {"subject": "left knee", "tier": "hard"}
 *
 * The confirmation from a soft-tiered serious-sounding injury. Answering "yes,
 * keep it soft" needs no call — the stored answer already says soft. This exists
 * for the case where the user looks again and decides to upgrade.
 */
$router->add('POST', 'onboarding/confirm-tier', function (): void {
    $user = Auth::require();
    $userId = (int) $user['id'];

    $b = Response::body();
    $subject = Validate::str($b['subject'] ?? null, 1, 120);
    $tier    = Validate::enum($b['tier'] ?? null, ['hard', 'soft']);

    if ($subject === null || $tier === null) {
        Response::error('Send a "subject" and a "tier" of hard or soft.', 422);
    }

    $constraint = DB::one(
        'SELECT id, tier, progression FROM user_constraints
         WHERE user_id = ? AND kind = "movement" AND subject = ? AND active = 1',
        [$userId, strtolower($subject)]
    );
    if ($constraint === null) {
        Response::notFound('No such constraint.');
    }

    if ($constraint['tier'] === $tier) {
        Response::json(['ok' => true, 'changed' => false, 'tier' => $tier]);
    }

    DB::tx(function () use ($constraint, $tier, $userId): void {
        // Upgrading to hard clears any progression target: "work up to this" is
        // incoherent for something never to be prescribed.
        $progression = $tier === 'hard' ? null : $constraint['progression'];

        DB::run(
            'UPDATE user_constraints SET tier = ?, progression = ?, source = "user_edit"
             WHERE id = ?',
            [$tier, $progression, (int) $constraint['id']]
        );

        // Audited: any plan can be explained after the fact, and a tier change
        // is exactly the kind of thing worth being able to explain.
        DB::run(
            'INSERT INTO user_constraint_audit
             (constraint_id, user_id, action, old_value, new_value)
             VALUES (?, ?, "update", ?, ?)',
            [
                (int) $constraint['id'], $userId,
                json_encode(['tier' => $constraint['tier']]),
                json_encode(['tier' => $tier, 'via' => 'onboarding tier check']),
            ]
        );
    });

    Response::json(['ok' => true, 'changed' => true, 'tier' => $tier]);
});

/**
 * GET /api/onboarding/constraints — what the answers produced.
 *
 * Worth exposing: the safety model only works if the user can see what the app
 * believes about them, and a mis-tiered constraint is easier to spot in a list
 * than by recalling a form.
 */
$router->add('GET', 'onboarding/constraints', function (): void {
    $user = Auth::require();

    $rows = DB::all(
        'SELECT kind, tier, subject, reason, guidance, floor_value, progression, source
         FROM user_constraints
         WHERE user_id = ? AND active = 1
         ORDER BY FIELD(tier, "hard", "soft"), kind, subject',
        [(int) $user['id']]
    );

    foreach ($rows as &$r) {
        $r['progression'] = $r['progression'] !== null
            ? json_decode((string) $r['progression'], true) : null;

        // Readable name and what sort of thing it is. Added beside the subject rather than
        // over it: the client posts the subject back to confirm-tier, and Safety matches on
        // the raw string to expand food categories.
        $r['label'] = ConstraintLabel::of((string) $r['kind'], (string) $r['subject']);
        $r['facet'] = ConstraintLabel::facet((string) $r['kind'], (string) $r['subject']);

        // Say plainly what it means, per facet as well as tier: "never prescribed" is the
        // wrong sentence for a condition, which is a modifier rather than a ban.
        $r['meaning'] = ConstraintLabel::meaning($r['facet'], (string) $r['tier']);
    }
    unset($r);

    Response::json(['constraints' => $rows]);
});

/**
 * POST /api/onboarding/start-baseline — begin the two-week baseline.
 *
 * A deliberate act rather than a side-effect of answering the last question:
 * the user is told what the fortnight is for and agrees to start it.
 */
$router->add('POST', 'onboarding/start-baseline', function (): void {
    $user = Auth::require();
    $userId = (int) $user['id'];

    $result = Onboarding::startBaseline($userId);
    if (!$result['ok']) {
        Response::error((string) $result['error'], 409, ['progress' => $result['progress']]);
    }

    Response::json([
        'ok'               => true,
        'onboarding_state' => 'baseline',
        'message'          => 'Baseline started. Log normally for two weeks — the first '
            . 'week is pure observation, and a provisional plan arrives after it.',
    ]);
});
