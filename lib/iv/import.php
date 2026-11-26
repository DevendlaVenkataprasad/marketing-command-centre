<?php
/**
 * Startup Discovery — students from Incubation Management.
 *
 * Students already registered through one of the organisation's Incubation
 * forms (for RTIH: "POLY TECHFEST 2026-27 - Details of Team Leads") are read
 * from inc_applications / inc_app_values and filed once each in iv_students
 * (iv_student_save() matches on PIN, then email, then phone), with their
 * college from the form's college answer and their department from the
 * branch in their PIN. Running it again updates; it never makes a second row.
 */

require_once __DIR__ . '/gem.php';     // iv_with_inc()

/** The organisation's Incubation forms with how many applications each has: [id, name, n]. */
function iv_inc_forms(int $org): array
{
    $t = $org > 0 ? 'inc_o' . $org . '_forms' : 'inc_forms';
    if (!fabo_one('SELECT 1 AS y FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', 's', [$t])) {
        return [];
    }
    return iv_with_inc($org, static fn() => inc_all('SELECT f.id, f.name, COUNT(a.id) AS n FROM inc_forms f LEFT JOIN inc_applications a ON a.form_id = f.id
                                                       GROUP BY f.id, f.name HAVING n > 0 ORDER BY n DESC'));
}

/** The questions of a form, with what each holds: uid => [label, guessed column]. Built-in name/email/phone come first. */
function iv_inc_fields(int $org, int $formId): array
{
    return iv_with_inc($org, static function () use ($formId) {
        $out = ['_name' => ['Applicant name (built in)', 'name'], '_email' => ['Applicant email (built in)', 'email'], '_phone' => ['Applicant phone (built in)', 'phone']];
        $rows = inc_all('SELECT field_uid, MAX(label) AS label FROM inc_app_values v JOIN inc_applications a ON a.id = v.application_id
                          WHERE a.form_id = ? GROUP BY field_uid ORDER BY MIN(v.id)', 'i', [$formId]);
        $taken = ['name' => true, 'email' => true, 'phone' => true];
        foreach ($rows as $r) {
            $col = iv_header_col((string) $r['label']);
            if (preg_match('/\b(pin|roll|hall ticket)\b/i', (string) $r['label'])) {
                $col = 'roll_no';
            } elseif (preg_match('/polytechnic|college|school|institut/i', (string) $r['label']) && !preg_match('/district/i', (string) $r['label'])) {
                $col = 'college';
            } elseif (preg_match('/district/i', (string) $r['label'])) {
                $col = 'district';
            }
            if (in_array($col, ['name', 'email', 'phone'], true)) {
                $col = '';      // the built-in columns already hold them
            }
            if ($col !== '' && isset($taken[$col])) {
                $col = '';
            }
            if ($col !== '') {
                $taken[$col] = true;
            }
            $out[(string) $r['field_uid']] = [(string) $r['label'], $col];
        }
        return $out;
    });
}

/** The columns an Incubation question can be filed as. */
function iv_inc_targets(): array
{
    return ['' => '— not used —', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'roll_no' => 'Roll number / PIN',
            'college' => 'College (code and name)', 'college_code' => 'College code', 'district' => 'District',
            'department' => 'Department', 'branch' => 'Branch code', 'year' => 'Year',
            'email_alt' => 'Second email (kept beside the first)', 'notes' => 'Remarks / notes'];
}

/**
 * Imports every application of one Incubation form. $map: field uid (or
 * _name/_email/_phone) => our column. $opts as iv_students_import().
 */
function iv_inc_import(int $org, int $formId, array $map, array $opts = []): array
{
    @set_time_limit(600);
    $map = array_filter($map, static fn($c) => $c !== '' && isset(iv_inc_targets()[$c]));
    if (!in_array('name', $map, true)) {
        throw new InvalidArgumentException('Choose which question holds the student\'s name.');
    }
    if (!array_intersect(['roll_no', 'email', 'phone'], $map)) {
        throw new InvalidArgumentException('Choose at least one of roll number, email or phone: it is how a student is told apart from another.');
    }
    $records = iv_with_inc($org, static function () use ($formId, $map) {
        $apps = inc_all('SELECT id, ref, applicant_name, applicant_email, applicant_phone FROM inc_applications WHERE form_id = ? ORDER BY id', 'i', [$formId]);
        $uids = array_values(array_filter(array_keys($map), static fn($u) => $u[0] !== '_'));
        $vals = [];
        if ($uids) {
            $in = implode(',', array_fill(0, count($uids), '?'));
            foreach (inc_all("SELECT v.application_id, v.field_uid, COALESCE(v.value_short, LEFT(v.value_text, 190)) AS v FROM inc_app_values v
                                JOIN inc_applications a ON a.id = v.application_id WHERE a.form_id = ? AND v.field_uid IN ($in)",
                             'i' . str_repeat('s', count($uids)), array_merge([$formId], $uids)) as $r) {
                $vals[(int) $r['application_id']][(string) $r['field_uid']] = (string) $r['v'];
            }
        }
        $out = [];
        foreach ($apps as $a) {
            $r = ['_ref' => 'inc:' . ($a['ref'] ?: $a['id'])];
            foreach ($map as $uid => $col) {
                $v = $uid === '_name' ? $a['applicant_name'] : ($uid === '_email' ? $a['applicant_email'] : ($uid === '_phone' ? $a['applicant_phone'] : ($vals[(int) $a['id']][$uid] ?? '')));
                if (($r[$col] ?? '') === '') {
                    $r[$col] = trim((string) $v);
                }
            }
            $out[] = $r;
        }
        return $out;
    });
    $res = iv_students_import($org, $records, ['make' => !empty($opts['make']), 'source' => 'incubation', 'kind' => (string) ($opts['kind'] ?? 'Polytechnic'),
                                               'label' => 'Incubation form #' . $formId], 1);
    $res['rows'] = count($records);
    return $res;
}
