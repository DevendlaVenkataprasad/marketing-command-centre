<?php
/**
 * Incubation Management — the scoring engine.
 *
 * One pure function, no database: everything it needs is passed in, so the
 * same inputs always give the same result, it can be tested on its own, and
 * a saved result can be re-derived from the saved inputs.
 *
 * THE RULES
 *
 *   Response statuses stay distinct: Rated (1–5), N/A, Pending, and
 *   Insufficient Evidence. Only Rated responses carry a number. Pending and
 *   Insufficient Evidence are never zero and never N/A.
 *
 *   Pillar rating = arithmetic mean of the pillar's Rated responses.
 *
 *   A pillar is
 *     rated     — at least one Rated response;
 *     na        — every applicable question was answered N/A (or the pillar
 *                 has no question for this development stage);
 *     unrated   — otherwise: it still has Pending or Insufficient responses.
 *
 *   Final Score = ( Σ pillar rating × pillar weight / Σ pillar weight ) × 20,
 *   both sums over RATED pillars only. N/A pillars leave the denominator
 *   (their weight is normalised away); unrated pillars are not guessed at —
 *   they reduce coverage instead, and the completion rules decide whether
 *   the result may be official.
 *
 *   Coverage = Σ weight of rated pillars / Σ weight of all active pillars × 100.
 *
 *   No rated pillar at all → no score (null), never zero.
 */

/**
 * @param array $pillars   [['code','name','weight'], ...] — the effective weights for the stage
 * @param array $questions [['id','pillar_code','required','evidence','text'], ...] — applicable to the stage
 * @param array $responses [question id => ['status','rating','evidence_url','evidence_file_id']]
 * @param array $rules     completion rules (min_coverage, require_all_pillars, enforce_required, enforce_evidence)
 * @return array
 */
function inc_score_calculate(array $pillars, array $questions, array $responses, array $rules): array
{
    $rules += ['min_coverage' => 0, 'require_all_pillars' => 1, 'enforce_required' => 1, 'enforce_evidence' => 1];
    $byPillar = [];
    foreach ($questions as $q) {
        $byPillar[(string) $q['pillar_code']][] = $q;
    }
    $counts = ['total' => 0, 'rated' => 0, 'pending' => 0, 'insufficient' => 0, 'na' => 0];
    $requiredPending = [];
    $evidenceMissing = [];
    $outPillars = [];
    $totalWeight = 0.0;
    $ratedWeight = 0.0;
    $applicableWeight = 0.0;
    $numerator = 0.0;

    foreach ($pillars as $p) {
        $code = (string) $p['code'];
        $w = (float) $p['weight'];
        if ($w <= 0) {
            continue;
        }
        $totalWeight += $w;
        $pc = ['total' => 0, 'rated' => 0, 'pending' => 0, 'insufficient' => 0, 'na' => 0];
        $sum = 0;
        foreach ($byPillar[$code] ?? [] as $q) {
            $r = $responses[(int) $q['id']] ?? ['status' => 'pending'];
            $status = (string) ($r['status'] ?? 'pending');
            if (!in_array($status, ['rated', 'na', 'pending', 'insufficient'], true)) {
                $status = 'pending';
            }
            $rating = $r['rating'] ?? null;
            if ($status === 'rated') {
                $rating = (int) $rating;
                if ($rating < 1 || $rating > 5) {
                    $status = 'pending';    // a malformed rating is not a rating
                }
            }
            $pc['total']++;
            $pc[$status]++;
            if ($status === 'rated') {
                $sum += $rating;
                if (($q['evidence'] ?? 'optional') === 'required'
                    && trim((string) ($r['evidence_url'] ?? '')) === '' && empty($r['evidence_file_id'])) {
                    $evidenceMissing[] = (int) $q['id'];
                }
            }
            if ($status === 'pending' && (int) ($q['required'] ?? 0) === 1) {
                $requiredPending[] = (int) $q['id'];
            }
        }
        foreach ($pc as $k => $n) {
            $counts[$k] += $n;
        }
        if ($pc['rated'] > 0) {
            $state = 'rated';
            $rating = $sum / $pc['rated'];
            $ratedWeight += $w;
            $applicableWeight += $w;
            $numerator += $rating * $w;
        } elseif ($pc['total'] === 0 || $pc['na'] === $pc['total']) {
            $state = 'na';
            $rating = null;
        } else {
            $state = 'unrated';
            $rating = null;
            $applicableWeight += $w;
        }
        $outPillars[$code] = [
            'code'   => $code,
            'name'   => (string) ($p['name'] ?? $code),
            'weight' => $w,
            'state'  => $state,
            'rating' => $rating,
            'counts' => $pc,
            'contribution' => $rating === null ? null : $rating * $w,
        ];
    }

    $score = $ratedWeight > 0 ? ($numerator / $ratedWeight) * 20 : null;
    $coverage = $totalWeight > 0 ? $ratedWeight / $totalWeight * 100 : 0.0;

    $reasons = [];
    if ($ratedWeight <= 0) {
        $reasons[] = 'No applicable pillar has a rating yet.';
    }
    $unrated = array_values(array_map(static fn($p) => $p['name'], array_filter($outPillars, static fn($p) => $p['state'] === 'unrated')));
    if ($unrated && !empty($rules['require_all_pillars'])) {
        $reasons[] = 'Not yet rated: ' . implode(', ', $unrated) . '.';
    }
    if ($ratedWeight > 0 && round($coverage, 4) < (float) $rules['min_coverage']) {
        $reasons[] = 'Rated pillars cover ' . number_format($coverage, 1) . '% of the weight; at least ' . rtrim(rtrim(number_format((float) $rules['min_coverage'], 2), '0'), '.') . '% is needed.';
    }
    if ($requiredPending && !empty($rules['enforce_required'])) {
        $reasons[] = count($requiredPending) . ' required question' . (count($requiredPending) === 1 ? ' is' : 's are') . ' still pending.';
    }
    if ($evidenceMissing && !empty($rules['enforce_evidence'])) {
        $reasons[] = count($evidenceMissing) . ' rated question' . (count($evidenceMissing) === 1 ? ' needs' : 's need') . ' supporting evidence.';
    }

    return [
        'calc_version'      => defined('INC_CALC_VERSION') ? INC_CALC_VERSION : 1,
        'pillars'           => $outPillars,
        'counts'            => $counts,
        'total_weight'      => $totalWeight,
        'applicable_weight' => $applicableWeight,
        'rated_weight'      => $ratedWeight,
        'numerator'         => $numerator,
        'score'             => $score,
        'coverage'          => $coverage,
        'eligible'          => !$reasons,
        'reasons'           => $reasons,
        'required_pending'  => $requiredPending,
        'evidence_missing'  => $evidenceMissing,
        'uniqueness'        => isset($outPillars['uniqueness']) ? $outPillars['uniqueness']['rating'] : null,
        'rated_pillars'     => count(array_filter($outPillars, static fn($p) => $p['state'] === 'rated')),
    ];
}

/** Validates a set of weights: each 0–100, at least one positive, totalling exactly 100. Returns problems. */
function inc_weights_problems(array $weights, string $label = 'Pillar weights'): array
{
    $out = [];
    $sum = 0.0;
    $pos = 0;
    foreach ($weights as $code => $w) {
        if (!is_numeric($w) || $w < 0 || $w > 100) {
            $out[] = $label . ': ' . $code . ' must be between 0 and 100.';
            continue;
        }
        $sum += (float) $w;
        if ((float) $w > 0) {
            $pos++;
        }
    }
    if ($pos === 0) {
        $out[] = $label . ': at least one pillar needs a weight.';
    }
    if (abs($sum - 100) > 0.001) {
        $out[] = $label . ' total ' . rtrim(rtrim(number_format($sum, 2), '0'), '.') . '%; they must total exactly 100%.';
    }
    return $out;
}
