<?php
/**
 * Incubation Management — the application form builder and submission.
 *
 * A form has a purpose (walk-in, incubation, referral...). Creating one
 * loads the fields that purpose needs, and only those; the administrator
 * then adds, edits, reorders, activates and deactivates fields on a DRAFT
 * version. Publishing freezes the version. Every field carries a stable uid
 * that survives renames and new versions, and every answer is stored against
 * that uid, so history and reports never lose a question.
 *
 * Conditional visibility is one rule per field (or per section heading,
 * which then governs every field until the next heading):
 *   {"field": "<uid>", "op": "eq|neq|in|filled|empty", "value": "..."}
 * The same evaluation (inc_form_visible) runs in the browser for display and
 * on the server for validation and saving: a hidden field is never required
 * and its value is never stored.
 */

require_once __DIR__ . '/core.php';

function inc_field_types(): array
{
    return [
        'text'        => 'Short text',
        'textarea'    => 'Long text',
        'email'       => 'Email',
        'phone'       => 'Phone number',
        'number'      => 'Number',
        'date'        => 'Date',
        'select'      => 'Dropdown',
        'multiselect' => 'Multi-select',
        'radio'       => 'Radio buttons',
        'checkbox'    => 'Checkboxes',
        'yesno'       => 'Yes / No',
        'url'         => 'Website URL',
        'file'        => 'File upload',
        'section'     => 'Section heading',
    ];
}

/** The applicant type of this name, added to the list if it is new. */
function inc_applicant_type_id(string $name): int
{
    $row = inc_one('SELECT id FROM inc_masters WHERE kind = "applicant_type" AND name = ?', 's', [$name]);
    if ($row) {
        return (int) $row['id'];
    }
    $sort = (int) (inc_one('SELECT MAX(sort) AS n FROM inc_masters WHERE kind = "applicant_type"')['n'] ?? 0);
    return inc_exec('INSERT INTO inc_masters (kind, name, sort) VALUES ("applicant_type",?,?)', 'si', [$name, $sort + 1]);
}

/** Which application column a field may fill. One field per target in a form. */
function inc_field_targets(): array
{
    return [
        'applicant_name'  => 'Applicant name',
        'applicant_email' => 'Applicant email',
        'applicant_phone' => 'Applicant phone',
        'startup_name'    => 'Startup name',
        'website'         => 'Startup website',
        'spoke'           => 'Spoke',
        'sector'          => 'Sector',
        'dev_stage'       => 'Startup development stage',
        'applicant_type'  => 'Type of participant',
    ];
}

/** Choice fields can read their options from a portal list instead of their own. */
function inc_option_sources(): array
{
    return ['' => 'Options typed below', 'spoke' => 'Spokes list', 'sector' => 'Sectors list', 'dev_stage' => 'Development stages'];
}

function inc_is_choice(string $type): bool
{
    return in_array($type, ['select', 'multiselect', 'radio', 'checkbox'], true);
}

/* ================================================================ presets */

/**
 * The fields a purpose starts with. [uid, type, label, required, extra]
 * extra: maps_to, options, option_source, cond, help, rules.
 */
function inc_purpose_preset(string $purpose): array
{
    $applicant = [
        ['sec_applicant', 'section', 'Applicant details', 0, ['help' => 'Who is applying, and how we can reach you.']],
        ['applicant_name', 'text', 'Full name', 1, ['maps_to' => 'applicant_name']],
        ['applicant_email', 'email', 'Email address', 1, ['maps_to' => 'applicant_email']],
        ['applicant_phone', 'phone', 'Phone number', 1, ['maps_to' => 'applicant_phone']],
    ];
    $where = [
        ['spoke', 'select', 'Spoke', 1, ['maps_to' => 'spoke', 'option_source' => 'spoke']],
        ['sector', 'select', 'Sector', 1, ['maps_to' => 'sector', 'option_source' => 'sector']],
    ];
    switch ($purpose) {
        case 'walk_in':
            return array_merge($applicant, [
                ['sec_visit', 'section', 'About your visit', 0, []],
                ['startup_name', 'text', 'Startup or idea name', 0, ['maps_to' => 'startup_name']],
                ['visit_purpose', 'select', 'Purpose of your visit', 1, ['options' => ['Information about incubation', 'Interested in applying', 'Mentorship', 'Other']]],
                ['visit_other', 'text', 'Tell us more', 1, ['cond' => ['field' => 'visit_purpose', 'op' => 'eq', 'value' => 'Other']]],
                ['idea_brief', 'textarea', 'Brief description of your idea or startup', 0, ['rules' => ['maxlen' => 1500]]],
            ], $where);
        case 'incubation':
            return array_merge($applicant, [
                ['linkedin', 'url', 'LinkedIn profile', 0, []],
                ['cofounders', 'number', 'Number of co-founders (0 if you are a solo founder)', 1, ['rules' => ['min' => 0, 'max' => 20]]],
                ['founder_roles', 'textarea', 'Founder roles and relevant experience', 1, []],
                ['sec_startup', 'section', 'Startup details', 0, []],
                ['startup_name', 'text', 'Startup name', 1, ['maps_to' => 'startup_name']],
                ['website', 'url', 'Website', 0, ['maps_to' => 'website']],
                $where[0], $where[1],
                ['dev_stage', 'select', 'Current development stage', 1, ['maps_to' => 'dev_stage', 'option_source' => 'dev_stage']],
                ['registered', 'yesno', 'Is the company registered?', 1, []],
                ['reg_number', 'text', 'Registration number (CIN / LLPIN / Udyam)', 1, ['cond' => ['field' => 'registered', 'op' => 'eq', 'value' => 'Yes']]],
                ['reg_date', 'date', 'Date of incorporation', 1, ['cond' => ['field' => 'registered', 'op' => 'eq', 'value' => 'Yes']]],
                ['sec_problem', 'section', 'Problem and solution', 0, []],
                ['problem', 'textarea', 'What problem are you solving, and for whom?', 1, ['rules' => ['minlen' => 30]]],
                ['solution', 'textarea', 'Your proposed solution', 1, ['rules' => ['minlen' => 30]]],
                ['target_customers', 'textarea', 'Who are your target customers?', 1, []],
                ['sec_product', 'section', 'Product', 0, []],
                ['prototype', 'yesno', 'Do you have a prototype or MVP?', 1, []],
                ['prototype_details', 'textarea', 'Describe the prototype and how it has been tested', 1, ['cond' => ['field' => 'prototype', 'op' => 'eq', 'value' => 'Yes']]],
                ['prototype_evidence', 'file', 'Prototype evidence (screenshots, demo, test report)', 0, ['cond' => ['field' => 'prototype', 'op' => 'eq', 'value' => 'Yes']]],
                ['sec_market', 'section', 'Market and differentiation', 0, []],
                ['market_research', 'textarea', 'Market research you have done', 0, []],
                ['competitors', 'textarea', 'Existing alternatives and competitors', 1, ['help' => 'What do your customers use today instead?']],
                ['difference', 'textarea', 'What makes your solution different, and for whom?', 1, []],
                ['sec_business', 'section', 'Business model and finances', 0, []],
                ['business_model', 'textarea', 'How will the startup make money?', 1, []],
                ['revenue', 'select', 'Revenue in the last 12 months', 1, ['options' => ['Pre-revenue', 'Under ₹1 lakh', '₹1–10 lakh', '₹10–50 lakh', 'Over ₹50 lakh']]],
                ['funding', 'yesno', 'Have you received any funding?', 1, []],
                ['funding_details', 'textarea', 'Funding received (source, amount, date)', 1, ['cond' => ['field' => 'funding', 'op' => 'eq', 'value' => 'Yes']]],
                ['support_needed', 'checkbox', 'What support are you looking for?', 0, ['options' => ['Mentorship', 'Workspace', 'Funding access', 'Market access', 'Technical support', 'Legal and compliance']]],
                ['sec_docs', 'section', 'Supporting documents', 0, []],
                ['pitch_deck', 'file', 'Pitch deck', 0, ['rules' => ['accept' => 'pdf,ppt,pptx']]],
            ]);
        case 'program':
            return array_merge($applicant, [
                ['sec_program', 'section', 'Program', 0, []],
                ['program_name', 'text', 'Which program are you applying to?', 1, []],
                ['startup_name', 'text', 'Startup name', 1, ['maps_to' => 'startup_name']],
                ['dev_stage', 'select', 'Current development stage', 1, ['maps_to' => 'dev_stage', 'option_source' => 'dev_stage']],
                $where[0], $where[1],
                ['motivation', 'textarea', 'Why do you want to join this program?', 1, []],
            ]);
        case 'event':
            return array_merge($applicant, [
                ['sec_event', 'section', 'Event', 0, []],
                ['event_name', 'text', 'Event name', 1, []],
                ['participation', 'radio', 'How would you like to participate?', 1, ['options' => ['Attendee', 'Speaker', 'Exhibitor', 'Volunteer']]],
                ['startup_name', 'text', 'Startup name (if any)', 0, ['maps_to' => 'startup_name']],
                ['exhibit_details', 'textarea', 'What would you exhibit?', 1, ['cond' => ['field' => 'participation', 'op' => 'eq', 'value' => 'Exhibitor']]],
                $where[0],
            ]);
        case 'startup_referral':
            return [
                ['sec_referrer', 'section', 'About you (the referrer)', 0, []],
                ['referrer_name', 'text', 'Your name', 1, []],
                ['referrer_email', 'email', 'Your email', 1, []],
                ['referrer_relation', 'text', 'How do you know the startup?', 0, []],
                ['sec_referred', 'section', 'The startup you are referring', 0, []],
                ['startup_name', 'text', 'Startup name', 1, ['maps_to' => 'startup_name']],
                ['applicant_name', 'text', 'Founder name', 1, ['maps_to' => 'applicant_name']],
                ['applicant_email', 'email', 'Founder email', 1, ['maps_to' => 'applicant_email']],
                ['applicant_phone', 'phone', 'Founder phone', 0, ['maps_to' => 'applicant_phone']],
                $where[0], $where[1],
                ['referral_reason', 'textarea', 'Why are you referring them?', 1, []],
            ];
        case 'partner_referral':
            return [
                ['sec_partner', 'section', 'Referring institution', 0, []],
                ['institution', 'text', 'Institution or partner name', 1, []],
                ['institution_type', 'select', 'Type of institution', 1, ['options' => ['University or college', 'Corporate', 'Government body', 'Incubator or accelerator', 'Investor', 'Other']]],
                ['applicant_name', 'text', 'Contact person', 1, ['maps_to' => 'applicant_name']],
                ['applicant_email', 'email', 'Contact email', 1, ['maps_to' => 'applicant_email']],
                ['applicant_phone', 'phone', 'Contact phone', 0, ['maps_to' => 'applicant_phone']],
                ['sec_ref_startup', 'section', 'Referred startup', 0, []],
                ['startup_name', 'text', 'Startup name', 1, ['maps_to' => 'startup_name']],
                ['founder_contact', 'text', 'Founder name and contact', 1, []],
                $where[0], $where[1],
                ['partner_notes', 'textarea', 'Notes for the incubation team', 0, []],
            ];
        default:
            return array_merge($applicant, [
                ['sec_details', 'section', 'Details', 0, []],
                ['startup_name', 'text', 'Startup or organisation name', 0, ['maps_to' => 'startup_name']],
                ['subject', 'text', 'Subject', 1, []],
                ['details', 'textarea', 'Details', 1, []],
            ]);
    }
}

/**
 * Adds preset questions ([uid, type, label, required, extra]) to a draft
 * version, after any it already has. extra['card'] is the short label the
 * answer shows under on the board card.
 */
function inc_form_fields_insert(int $versionId, array $preset): void
{
    $sort = (int) (inc_one('SELECT COALESCE(MAX(sort),0) AS n FROM inc_form_fields WHERE version_id = ?', 'i', [$versionId])['n'] ?? 0);
    foreach ($preset as [$uid, $type, $label, $req, $x]) {
        $sort += 10;
        $rules = ($x['rules'] ?? []) + (isset($x['card']) ? ['card' => $x['card']] : []);
        inc_exec('INSERT INTO inc_form_fields (version_id, uid, type, label, help, required, sort, options_json, option_source, rules_json, cond_json, maps_to)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', 'issssiisssss',
                  [$versionId, $uid, $type, $label, $x['help'] ?? null, $req, $sort,
                   isset($x['options']) ? json_encode($x['options'], JSON_UNESCAPED_UNICODE) : null, $x['option_source'] ?? null,
                   $rules ? json_encode($rules, JSON_UNESCAPED_UNICODE) : null, isset($x['cond']) ? json_encode($x['cond']) : null, $x['maps_to'] ?? null]);
    }
}

/* ================================================================== forms */

function inc_form(int $id): ?array
{
    return inc_one('SELECT * FROM inc_forms WHERE id = ?', 'i', [$id]);
}

function inc_form_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    return inc_one('SELECT * FROM inc_forms WHERE public_token = ? AND archived = 0', 's', [$token]);
}

function inc_form_version(int $id): ?array
{
    return inc_one('SELECT * FROM inc_form_versions WHERE id = ?', 'i', [$id]);
}

function inc_form_published(int $formId): ?array
{
    return inc_one("SELECT * FROM inc_form_versions WHERE form_id = ? AND status = 'published'", 'i', [$formId]);
}

function inc_form_draft_version(int $formId): ?array
{
    return inc_one("SELECT * FROM inc_form_versions WHERE form_id = ? AND status = 'draft'", 'i', [$formId]);
}

function inc_forms_list(bool $withArchived = false): array
{
    return inc_all('SELECT f.*,
                       (SELECT version_no FROM inc_form_versions v WHERE v.form_id = f.id AND v.status = "published") AS published_no,
                       (SELECT version_no FROM inc_form_versions v WHERE v.form_id = f.id AND v.status = "draft") AS draft_no,
                       (SELECT COUNT(*) FROM inc_applications a WHERE a.form_id = f.id) AS app_count
                     FROM inc_forms f WHERE f.id NOT IN (SELECT g.invite_form_id FROM inc_gems g WHERE g.invite_form_id IS NOT NULL)'
                   // A purpose's question template is not a form anybody applies on.
                   . (($tpl = inc_purpose_template_ids()) ? ' AND f.id NOT IN (' . implode(',', $tpl) . ')' : '')
                   . (inc_kind() === 'eo' ? ' AND f.id IN (SELECT form_id FROM inc_ev_forms)' : '')
                   . ($withArchived ? '' : ' AND f.archived = 0') . ' ORDER BY f.archived, f.name');
}

function inc_form_create(array $in): int
{
    inc_need('configure');
    $name = inc_str($in['name'] ?? '', 160, true, 'Form name');
    $purpose = (string) ($in['purpose'] ?? '');
    if (!isset(inc_purposes(true)[$purpose])) {
        throw new InvalidArgumentException('Choose what the form is for.');
    }
    $desc = inc_str($in['description'] ?? '', 2000);
    $evLabel = inc_purpose_event_label($purpose);
    $event = $evLabel !== '' ? inc_str($in['event_name'] ?? '', 190, true, $evLabel) : null;
    // The team whose desk gets every application from this form, as it arrives (as well as the main pipeline).
    $deptId = inc_int_or_null($in['dept_team_id'] ?? null);
    if ($deptId && !isset(inc_teams(true)[$deptId])) {
        $deptId = null;
    }
    return inc_tx(static function () use ($name, $purpose, $desc, $event, $deptId) {
        $fid = inc_exec('INSERT INTO inc_forms (name, purpose, event_name, dept_team_id, description, public_token, created_by) VALUES (?,?,?,?,?,?,?)',
                         'sssisss', [$name, $purpose, $event, $deptId, $desc, bin2hex(random_bytes(16)), inc_actor()]);
        $vid = inc_exec("INSERT INTO inc_form_versions (form_id, version_no, status, created_by) VALUES (?,1,'draft',?)", 'is', [$fid, inc_actor()]);
        // A purpose with its own questions (a template, edited in the builder) starts from those.
        $tplVer = inc_purpose_template_version($purpose);
        if ($tplVer) {
            inc_exec('INSERT INTO inc_form_fields (version_id, uid, type, label, placeholder, help, required, active, sort, options_json, option_source, rules_json, cond_json, maps_to)
                      SELECT ?, uid, type, label, placeholder, help, required, active, sort, options_json, option_source, rules_json, cond_json, maps_to
                        FROM inc_form_fields WHERE version_id = ?', 'ii', [$vid, $tplVer]);
        } else {
            inc_form_fields_insert($vid, inc_purpose_preset(inc_purpose_base($purpose)));
        }
        inc_audit('create', 'form', $fid, $name . ' (' . inc_purpose_text($purpose, $event) . ')');
        return $fid;
    });
}

function inc_form_settings_save(int $formId, array $in): void
{
    inc_need('configure');
    $f = inc_form($formId);
    if (!$f) {
        throw new InvalidArgumentException('That form no longer exists.');
    }
    $name = inc_str($in['name'] ?? '', 160, true, 'Form name');
    $desc = inc_str($in['description'] ?? '', 2000);
    $src = inc_int_or_null($in['default_source_id'] ?? null);
    if ($src && !isset(inc_masters('source', false)[$src])) {
        $src = null;
    }
    $type = inc_int_or_null($in['default_type_id'] ?? null);
    if ($type && !isset(inc_masters('applicant_type', false)[$type])) {
        $type = null;
    }
    $evLabel = inc_purpose_event_label((string) $f['purpose']);
    $event = $evLabel !== '' ? inc_str($in['event_name'] ?? '', 190, true, $evLabel) : null;
    $after = ['name' => $name, 'event_name' => $event, 'description' => $desc, 'public_enabled' => !empty($in['public_enabled']) ? 1 : 0,
              'block_duplicates' => !empty($in['block_duplicates']) ? 1 : 0, 'default_source_id' => $src, 'archived' => !empty($in['archived']) ? 1 : 0];
    $gemId = inc_int_or_null($in['gem_id'] ?? null);
    if ($gemId && !isset(inc_gems()[$gemId])) {
        $gemId = null;
    }
    $after['gem_id'] = $gemId;
    // Whose form it is: the incubation team's (none), or a department's -- whose desk then gets every submission.
    $deptId = inc_int_or_null($in['dept_team_id'] ?? null);
    if ($deptId && !isset(inc_teams(true)[$deptId])) {
        $deptId = null;
    }
    $after['dept_team_id'] = $deptId;
    if ($after['public_enabled'] && !inc_form_published($formId)) {
        throw new InvalidArgumentException('Publish the form before opening its public link.');
    }
    inc_exec('UPDATE inc_forms SET name = ?, event_name = ?, description = ?, public_enabled = ?, block_duplicates = ?, default_source_id = ?, archived = ?, gem_id = ?, dept_team_id = ? WHERE id = ?',
              'sssiiiiiii', [$name, $event, $desc, $after['public_enabled'], $after['block_duplicates'], $src, $after['archived'], $gemId, $deptId, $formId]);
    // One section per step, for a long form (assets/inc.js).
    $after['stepwise'] = !empty($in['stepwise']) ? 1 : 0;
    inc_exec('UPDATE inc_forms SET stepwise = ? WHERE id = ?', 'ii', [$after['stepwise'], $formId]);
    // Who applies through it (Student, Startup, Mentor...), recorded on each application it receives.
    $after['default_type_id'] = $type;
    inc_exec('UPDATE inc_forms SET default_type_id = ? WHERE id = ?', 'ii', [$type, $formId]);
    // Applications this form already received go to the department's desk too (quietly: no email for the backlog).
    if ($deptId && (int) ($f['dept_team_id'] ?? 0) !== $deptId) {
        require_once __DIR__ . '/depts.php';
        inc_dept_sync($deptId);
    }
    // Applications received before the form had a name take it; ones already named keep theirs.
    if ($event !== null) {
        inc_exec('UPDATE inc_applications SET event_name = ? WHERE form_id = ? AND (event_name IS NULL OR event_name = "")', 'si', [$event, $formId]);
    }
    inc_audit('update', 'form', $formId, $name, array_intersect_key($f, $after), $after);
}

/** The editable draft of a form, created from the published version when there is none. */
function inc_form_draft(int $formId): array
{
    inc_need('configure');
    $d = inc_form_draft_version($formId);
    if ($d) {
        return $d;
    }
    $pub = inc_form_published($formId) ?: inc_one('SELECT * FROM inc_form_versions WHERE form_id = ? ORDER BY version_no DESC LIMIT 1', 'i', [$formId]);
    if (!$pub) {
        throw new InvalidArgumentException('That form has no versions.');
    }
    return inc_tx(static function () use ($formId, $pub) {
        $no = (int) inc_one('SELECT MAX(version_no) AS n FROM inc_form_versions WHERE form_id = ?', 'i', [$formId])['n'] + 1;
        $vid = inc_exec("INSERT INTO inc_form_versions (form_id, version_no, status, created_by) VALUES (?,?,'draft',?)", 'iis', [$formId, $no, inc_actor()]);
        inc_exec('INSERT INTO inc_form_fields (version_id, uid, type, label, placeholder, help, required, active, sort, options_json, option_source, rules_json, cond_json, maps_to)
                   SELECT ?, uid, type, label, placeholder, help, required, active, sort, options_json, option_source, rules_json, cond_json, maps_to
                   FROM inc_form_fields WHERE version_id = ?', 'ii', [$vid, (int) $pub['id']]);
        inc_audit('new_version', 'form', $formId, 'Draft version ' . $no);
        return inc_form_version($vid);
    });
}

function inc_form_fields(int $versionId, bool $activeOnly = false): array
{
    $rows = inc_all('SELECT * FROM inc_form_fields WHERE version_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort, id', 'i', [$versionId]);
    foreach ($rows as &$r) {
        $r['options'] = json_decode((string) $r['options_json'], true) ?: [];
        $r['rules'] = json_decode((string) $r['rules_json'], true) ?: [];
        $r['cond'] = json_decode((string) $r['cond_json'], true) ?: null;
    }
    return $rows;
}

/** The choices a field offers, value => label. */
function inc_field_choices(array $f): array
{
    if ($f['type'] === 'yesno') {
        return ['Yes' => 'Yes', 'No' => 'No'];
    }
    switch ((string) $f['option_source']) {
        case 'spoke':
        case 'sector':
            $out = [];
            foreach (inc_masters((string) $f['option_source']) as $id => $name) {
                $out[(string) $id] = $name;
            }
            return $out;
        case 'dev_stage':
            return inc_dev_stages(true);
    }
    $out = [];
    foreach ((array) $f['options'] as $o) {
        $out[(string) $o] = (string) $o;
    }
    return $out;
}

function inc_draft_guard(int $versionId): array
{
    $v = inc_form_version($versionId);
    if (!$v) {
        throw new InvalidArgumentException('That form version no longer exists.');
    }
    if ($v['status'] !== 'draft') {
        throw new InvalidArgumentException('Published versions cannot be changed. Edit the draft version instead.');
    }
    return $v;
}

function inc_form_field_save(int $versionId, ?int $fieldId, array $in): int
{
    inc_need('configure');
    $v = inc_draft_guard($versionId);
    $type = (string) ($in['type'] ?? '');
    if (!isset(inc_field_types()[$type])) {
        throw new InvalidArgumentException('Choose a field type.');
    }
    $label = inc_str($in['label'] ?? '', 255, true, 'Label');
    $placeholder = inc_str($in['placeholder'] ?? '', 255);
    $help = inc_str($in['help'] ?? '', 2000);
    $required = $type === 'section' ? 0 : (!empty($in['required']) ? 1 : 0);
    $active = !empty($in['active']) ? 1 : 0;
    $source = (string) ($in['option_source'] ?? '');
    if (!inc_is_choice($type) || !isset(inc_option_sources()[$source])) {
        $source = '';
    }
    $options = [];
    if (inc_is_choice($type) && $source === '') {
        foreach (preg_split('/\r\n|\r|\n/', (string) ($in['options'] ?? '')) as $o) {
            $o = trim($o);
            if ($o !== '' && !in_array($o, $options, true)) {
                $options[] = mb_substr($o, 0, 190);
            }
        }
        if (!$options) {
            throw new InvalidArgumentException('Add at least one option, one per line.');
        }
    }
    $rules = [];
    foreach (['min', 'max'] as $k) {
        $val = trim((string) ($in['rule_' . $k] ?? ''));
        if ($val === '') {
            continue;
        }
        if ($type === 'date') {
            $rules[$k] = inc_date($val, false, 'The ' . $k . ' date');
        } elseif ($type === 'number') {
            if (!is_numeric($val)) {
                throw new InvalidArgumentException('Minimum and maximum must be numbers.');
            }
            $rules[$k] = (float) $val;
        }
    }
    foreach (['minlen', 'maxlen', 'max_mb'] as $k) {
        $val = trim((string) ($in['rule_' . $k] ?? ''));
        if ($val !== '') {
            if (!ctype_digit($val)) {
                throw new InvalidArgumentException('Lengths and sizes must be whole numbers.');
            }
            $rules[$k] = (int) $val;
        }
    }
    if (isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max']) {
        throw new InvalidArgumentException('The minimum is larger than the maximum.');
    }
    if (isset($rules['minlen'], $rules['maxlen']) && $rules['minlen'] > $rules['maxlen']) {
        throw new InvalidArgumentException('The minimum length is larger than the maximum length.');
    }
    $accept = strtolower(preg_replace('/[^a-z0-9,]/i', '', (string) ($in['rule_accept'] ?? '')));
    if ($type === 'file' && $accept !== '') {
        $rules['accept'] = $accept;
    }
    $pattern = trim((string) ($in['rule_pattern'] ?? ''));
    if ($pattern !== '' && in_array($type, ['text', 'phone'], true)) {
        if (@preg_match('~^(?:' . $pattern . ')$~u', '') === false) {
            throw new InvalidArgumentException('The pattern is not a valid regular expression.');
        }
        $rules['pattern'] = $pattern;
    }
    // A short label to show the answer under on the board card (expertise, sectors...).
    $card = inc_str($in['card_label'] ?? '', 40);
    if ($card !== '' && !in_array($type, ['section', 'file'], true)) {
        $rules['card'] = $card;
    }
    $mapsTo = (string) ($in['maps_to'] ?? '');
    if ($mapsTo !== '' && !isset(inc_field_targets()[$mapsTo])) {
        $mapsTo = '';
    }
    if ($mapsTo !== '') {
        $clash = inc_one('SELECT label FROM inc_form_fields WHERE version_id = ? AND maps_to = ? AND id <> ?', 'isi', [$versionId, $mapsTo, (int) $fieldId]);
        if ($clash) {
            throw new InvalidArgumentException('"' . $clash['label'] . '" already fills ' . inc_field_targets()[$mapsTo] . '.');
        }
    }
    $fields = inc_form_fields($versionId);
    $cond = null;
    $cf = (string) ($in['cond_field'] ?? '');
    if ($cf !== '') {
        $op = (string) ($in['cond_op'] ?? 'eq');
        if (!in_array($op, ['eq', 'neq', 'in', 'filled', 'empty'], true)) {
            throw new InvalidArgumentException('Choose how the condition compares.');
        }
        $target = null;
        $selfSort = null;
        foreach ($fields as $f) {
            if ($f['uid'] === $cf) {
                $target = $f;
            }
            if ($fieldId && (int) $f['id'] === $fieldId) {
                $selfSort = (int) $f['sort'];
            }
        }
        if (!$target || $target['type'] === 'section' || $target['type'] === 'file') {
            throw new InvalidArgumentException('A condition can only depend on another answer field in this form.');
        }
        if ($fieldId && (int) $target['id'] === $fieldId) {
            throw new InvalidArgumentException('A field cannot depend on itself.');
        }
        if ($selfSort !== null && (int) $target['sort'] >= $selfSort) {
            throw new InvalidArgumentException('A field can only depend on a field above it.');
        }
        $cond = ['field' => $cf, 'op' => $op, 'value' => inc_str($in['cond_value'] ?? '', 190)];
        if (in_array($op, ['eq', 'neq', 'in'], true) && $cond['value'] === '') {
            throw new InvalidArgumentException('Give the value the condition compares with.');
        }
    }
    $row = [$type, $label, $placeholder ?: null, $help ?: null, $required, $active, $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            $source ?: null, $rules ? json_encode($rules) : null, $cond ? json_encode($cond) : null, $mapsTo ?: null];
    if ($fieldId) {
        $before = inc_one('SELECT * FROM inc_form_fields WHERE id = ? AND version_id = ?', 'ii', [$fieldId, $versionId]);
        if (!$before) {
            throw new InvalidArgumentException('That field is not in this version.');
        }
        inc_exec('UPDATE inc_form_fields SET type = ?, label = ?, placeholder = ?, help = ?, required = ?, active = ?, options_json = ?, option_source = ?, rules_json = ?, cond_json = ?, maps_to = ?
                   WHERE id = ?', 'ssssiisssssi', array_merge($row, [$fieldId]));
        inc_audit('update', 'form_field', $fieldId, $label . ' (' . $before['uid'] . ')', $before, $row);
    } else {
        $uid = 'f_' . bin2hex(random_bytes(4));
        $sort = (int) (inc_one('SELECT COALESCE(MAX(sort),0) + 10 AS n FROM inc_form_fields WHERE version_id = ?', 'i', [$versionId])['n'] ?? 10);
        $fieldId = inc_exec('INSERT INTO inc_form_fields (version_id, uid, type, label, placeholder, help, required, active, options_json, option_source, rules_json, cond_json, maps_to, sort)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'isssssiisssssi', array_merge([$versionId, $uid], $row, [$sort]));
        inc_audit('create', 'form_field', $fieldId, $label . ' (' . $uid . ')');
    }
    return $fieldId;
}

function inc_form_field_move(int $fieldId, int $dir): void
{
    inc_need('configure');
    $f = inc_one('SELECT * FROM inc_form_fields WHERE id = ?', 'i', [$fieldId]);
    if (!$f) {
        throw new InvalidArgumentException('That field no longer exists.');
    }
    inc_draft_guard((int) $f['version_id']);
    $ids = array_map(static fn($r) => (int) $r['id'], inc_form_fields((int) $f['version_id']));
    $pos = array_search($fieldId, $ids, true);
    $swap = $pos + ($dir < 0 ? -1 : 1);
    if ($swap < 0 || $swap >= count($ids)) {
        return;
    }
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    inc_tx(static function () use ($ids) {
        foreach ($ids as $i => $id) {
            inc_exec('UPDATE inc_form_fields SET sort = ? WHERE id = ?', 'ii', [($i + 1) * 10, $id]);
        }
    });
}

function inc_form_field_delete(int $fieldId): void
{
    inc_need('configure');
    $f = inc_one('SELECT * FROM inc_form_fields WHERE id = ?', 'i', [$fieldId]);
    if (!$f) {
        return;
    }
    inc_draft_guard((int) $f['version_id']);
    $dep = inc_one('SELECT label FROM inc_form_fields WHERE version_id = ? AND cond_json LIKE ?', 'is', [(int) $f['version_id'], '%"' . $f['uid'] . '"%']);
    if ($dep) {
        throw new InvalidArgumentException('"' . $dep['label'] . '" depends on this field. Change its condition first.');
    }
    inc_exec('DELETE FROM inc_form_fields WHERE id = ?', 'i', [$fieldId]);
    inc_audit('delete', 'form_field', $fieldId, $f['label'] . ' (' . $f['uid'] . ')', $f);
}

/** Problems that stop a version from being published. */
function inc_form_problems(int $versionId): array
{
    $fields = inc_form_fields($versionId, true);
    $out = [];
    $answerable = array_filter($fields, static fn($f) => $f['type'] !== 'section');
    if (!$answerable) {
        $out[] = 'The form has no active question.';
    }
    $seen = [];
    $pos = [];
    foreach ($fields as $i => $f) {
        $pos[$f['uid']] = $i;
    }
    foreach ($fields as $i => $f) {
        if ($f['maps_to']) {
            if (isset($seen[$f['maps_to']])) {
                $out[] = 'Two fields fill ' . inc_field_targets()[$f['maps_to']] . '.';
            }
            $seen[$f['maps_to']] = true;
        }
        if (inc_is_choice($f['type']) && !$f['option_source'] && !$f['options']) {
            $out[] = '"' . $f['label'] . '" has no options.';
        }
        if ($f['cond']) {
            $t = $f['cond']['field'] ?? '';
            if (!isset($pos[$t])) {
                $out[] = '"' . $f['label'] . '" depends on a field that is not active in this version.';
            } elseif ($pos[$t] >= $i) {
                $out[] = '"' . $f['label'] . '" depends on a field below it.';
            }
        }
    }
    if (!isset($seen['applicant_name']) && !isset($seen['startup_name'])) {
        $out[] = 'One field must fill the applicant name or the startup name, so the application can be identified.';
    }
    return $out;
}

function inc_form_publish(int $formId): int
{
    inc_need('configure');
    $draft = inc_form_draft_version($formId);
    if (!$draft) {
        throw new InvalidArgumentException('There is no draft to publish.');
    }
    $problems = inc_form_problems((int) $draft['id']);
    if ($problems) {
        throw new InvalidArgumentException('Cannot publish yet: ' . implode(' ', $problems));
    }
    inc_tx(static function () use ($formId, $draft) {
        inc_exec("UPDATE inc_form_versions SET status = 'retired' WHERE form_id = ? AND status = 'published'", 'i', [$formId]);
        inc_exec("UPDATE inc_form_versions SET status = 'published', published_at = NOW(), published_by = ? WHERE id = ?", 'si', [inc_actor(), (int) $draft['id']]);
    });
    inc_audit('publish', 'form', $formId, 'Version ' . $draft['version_no'] . ' published');
    return (int) $draft['id'];
}

/* ============================================================ visibility */

/** Does one condition hold for the given answers? */
function inc_cond_holds(?array $cond, array $values): bool
{
    if (!$cond || empty($cond['field'])) {
        return true;
    }
    $v = $values[$cond['field']] ?? null;
    $vals = is_array($v) ? array_map('strval', $v) : ($v === null || $v === '' ? [] : [(string) $v]);
    $want = (string) ($cond['value'] ?? '');
    switch ($cond['op'] ?? 'eq') {
        case 'filled': return (bool) $vals;
        case 'empty':  return !$vals;
        case 'neq':    return !in_array($want, $vals, true);
        case 'in':     return (bool) array_intersect($vals, array_map('trim', explode('|', $want)));
        default:       return in_array($want, $vals, true);
    }
}

/**
 * Which fields are visible for these answers. A field is hidden when its own
 * condition fails, when its section is hidden, or when the field it depends
 * on is itself hidden — so a chain of dependencies collapses together.
 * Returns uid => true for visible fields.
 */
function inc_form_visible(array $fields, array $values): array
{
    $visible = [];
    $sectionOn = true;
    foreach ($fields as $f) {
        if ((int) $f['active'] !== 1) {
            continue;
        }
        $dep = $f['cond']['field'] ?? null;
        $ok = inc_cond_holds($f['cond'], $values) && ($dep === null || isset($visible[$dep]));
        if ($f['type'] === 'section') {
            $sectionOn = $ok;
            if ($ok) {
                $visible[$f['uid']] = true;
            }
            continue;
        }
        if ($sectionOn && $ok) {
            $visible[$f['uid']] = true;
        }
    }
    return $visible;
}

/* ============================================================ validation */

/**
 * Validates posted answers against a version's fields.
 * $raw: uid => string|array. $files: uid => $_FILES entry (or missing).
 * Returns [values (uid => clean value), errors (uid => message), files (uid => upload)].
 * Hidden fields are dropped entirely.
 */
function inc_form_validate(array $fields, array $raw, array $files = []): array
{
    // First pass: rough values, so conditions can be evaluated.
    $rough = [];
    foreach ($fields as $f) {
        $v = $raw[$f['uid']] ?? null;
        $rough[$f['uid']] = is_array($v) ? array_values(array_filter(array_map('strval', $v), static fn($x) => $x !== '')) : trim((string) ($v ?? ''));
    }
    $visible = inc_form_visible($fields, $rough);
    $values = [];
    $errors = [];
    $keep = [];
    foreach ($fields as $f) {
        $uid = $f['uid'];
        if ($f['type'] === 'section' || !isset($visible[$uid])) {
            continue;
        }
        $label = $f['label'];
        $r = $f['rules'];
        $v = $rough[$uid];
        $empty = is_array($v) ? !$v : $v === '';
        if ($f['type'] === 'file') {
            $up = $files[$uid] ?? null;
            $has = $up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if (!$has) {
                if ((int) $f['required'] === 1) {
                    $errors[$uid] = 'Attach a file for "' . $label . '".';
                }
                continue;
            }
            $ext = strtolower(pathinfo((string) ($up['name'] ?? ''), PATHINFO_EXTENSION));
            if (!empty($r['accept']) && !in_array($ext, explode(',', $r['accept']), true)) {
                $errors[$uid] = '"' . $label . '" accepts ' . str_replace(',', ', ', $r['accept']) . ' files only.';
                continue;
            }
            if (!empty($r['max_mb']) && (int) ($up['size'] ?? 0) > $r['max_mb'] * 1024 * 1024) {
                $errors[$uid] = '"' . $label . '" must be ' . $r['max_mb'] . ' MB or smaller.';
                continue;
            }
            $keep[$uid] = $up;
            continue;
        }
        if ($empty) {
            if ((int) $f['required'] === 1) {
                $errors[$uid] = '"' . $label . '" is required.';
            }
            continue;
        }
        switch ($f['type']) {
            case 'email':
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $errors[$uid] = 'Enter a valid email address for "' . $label . '".';
                }
                $v = mb_strtolower($v);
                break;
            case 'phone':
                if (!preg_match('/^\+?[0-9 ()\-]{7,20}$/', $v) || strlen(preg_replace('/\D/', '', $v)) < 7) {
                    $errors[$uid] = 'Enter a valid phone number for "' . $label . '".';
                }
                break;
            case 'url':
                if (!preg_match('~^https?://~i', $v)) {
                    $v = 'https://' . $v;
                }
                if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('~^https?://[^/\s]+\.[^/\s]+~i', $v)) {
                    $errors[$uid] = 'Enter a valid web address for "' . $label . '".';
                }
                break;
            case 'number':
                if (!is_numeric($v)) {
                    $errors[$uid] = '"' . $label . '" must be a number.';
                } elseif (isset($r['min']) && (float) $v < (float) $r['min']) {
                    $errors[$uid] = '"' . $label . '" must be at least ' . $r['min'] . '.';
                } elseif (isset($r['max']) && (float) $v > (float) $r['max']) {
                    $errors[$uid] = '"' . $label . '" must be at most ' . $r['max'] . '.';
                }
                break;
            case 'date':
                $d = DateTime::createFromFormat('!Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) {
                    $errors[$uid] = '"' . $label . '" is not a valid date.';
                } elseif (!empty($r['min']) && $v < $r['min']) {
                    $errors[$uid] = '"' . $label . '" cannot be before ' . inc_fmt_date($r['min']) . '.';
                } elseif (!empty($r['max']) && $v > $r['max']) {
                    $errors[$uid] = '"' . $label . '" cannot be after ' . inc_fmt_date($r['max']) . '.';
                }
                break;
            case 'select':
            case 'radio':
            case 'yesno':
                if (is_array($v) || !array_key_exists($v, inc_field_choices($f))) {
                    $errors[$uid] = 'Choose one of the options for "' . $label . '".';
                }
                break;
            case 'multiselect':
            case 'checkbox':
                $v = is_array($v) ? $v : [$v];
                if (array_diff($v, array_map('strval', array_keys(inc_field_choices($f))))) {
                    $errors[$uid] = 'Choose from the options for "' . $label . '".';
                }
                break;
        }
        if (is_string($v) && in_array($f['type'], ['text', 'textarea'], true)) {
            $len = mb_strlen($v);
            $max = (int) ($r['maxlen'] ?? ($f['type'] === 'text' ? 500 : 10000));
            if (!empty($r['minlen']) && $len < $r['minlen']) {
                $errors[$uid] = '"' . $label . '" needs at least ' . $r['minlen'] . ' characters.';
            } elseif ($len > $max) {
                $errors[$uid] = '"' . $label . '" can be at most ' . $max . ' characters.';
            }
        }
        if (is_string($v) && !empty($r['pattern']) && !preg_match('~^(?:' . $r['pattern'] . ')$~u', $v)) {
            $errors[$uid] = '"' . $label . '" is not in the expected format.';
        }
        if (!isset($errors[$uid])) {
            $values[$uid] = $v;
        }
    }
    return [$values, $errors, $keep];
}

/* ============================================================ submission */

function inc_norm_name(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
    $s = preg_replace('/\b(private|pvt|limited|ltd|llp|inc|opc|the)\b/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function inc_norm_phone(?string $p): string
{
    $d = preg_replace('/\D/', '', (string) $p);
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

/**
 * Turns a valid submission into an application in Incubation Management.
 *
 * $ctx: channel (public|admin), source_id, ticket (one-time token; a second
 * submit carrying the same ticket returns the first application rather than
 * creating another), ip.
 *
 * Returns ['id' => app id, 'ref' => reference, 'duplicate_of' => ?int, 'repeat' => bool].
 * Throws InvalidArgumentException with the field errors (as JSON in
 * ->getMessage() prefixed "fields:") when validation fails.
 */
function inc_app_submit(array $form, array $version, array $raw, array $files, array $ctx): array
{
    if ($version['status'] !== 'published' || (int) $version['form_id'] !== (int) $form['id']) {
        throw new InvalidArgumentException('This form is not accepting applications right now.');
    }
    if ((int) $form['archived'] === 1) {
        throw new InvalidArgumentException('This form is closed.');
    }
    $channel = ($ctx['channel'] ?? 'admin') === 'public' ? 'public' : 'admin';
    if ($channel === 'admin') {
        // Staff entering it: the incubation team, or a department member filing one for their own department.
        $deptId = (int) ($ctx['dept_team_id'] ?? 0);
        if (!($deptId > 0 && function_exists('inc_dept_may_intake') && inc_dept_may_intake($deptId))) {
            inc_need('edit');
        }
    } elseif ((int) $form['public_enabled'] !== 1) {
        throw new InvalidArgumentException('This form is not open to the public.');
    }
    $ticket = (string) ($ctx['ticket'] ?? '');
    if ($ticket !== '' && preg_match('/^[0-9a-f]{32}$/', $ticket)) {
        $prev = inc_one('SELECT id, ref, dup_of_id FROM inc_applications WHERE submit_ticket = ?', 's', [$ticket]);
        if ($prev) {
            return ['id' => (int) $prev['id'], 'ref' => $prev['ref'], 'duplicate_of' => $prev['dup_of_id'] ? (int) $prev['dup_of_id'] : null, 'repeat' => true];
        }
    } else {
        $ticket = '';
    }
    $fields = inc_form_fields((int) $version['id'], true);
    [$values, $errors, $uploads] = inc_form_validate($fields, $raw, $files);
    if ($errors) {
        throw new InvalidArgumentException('fields:' . json_encode($errors));
    }
    $mapped = [];
    $byUid = [];
    foreach ($fields as $f) {
        $byUid[$f['uid']] = $f;
        if ($f['maps_to'] && isset($values[$f['uid']])) {
            $mapped[$f['maps_to']] = $values[$f['uid']];
        }
    }
    $name = mb_substr((string) ($mapped['applicant_name'] ?? ''), 0, 190);
    $email = isset($mapped['applicant_email']) ? mb_substr(mb_strtolower((string) $mapped['applicant_email']), 0, 190) : null;
    $phone = isset($mapped['applicant_phone']) ? mb_substr((string) $mapped['applicant_phone'], 0, 40) : null;
    $startupName = mb_substr((string) ($mapped['startup_name'] ?? ''), 0, 190);
    $spoke = isset($mapped['spoke']) ? (int) $mapped['spoke'] : null;
    $sector = isset($mapped['sector']) ? (int) $mapped['sector'] : null;
    $devStage = isset($mapped['dev_stage']) && isset(inc_dev_stages()[$mapped['dev_stage']]) ? (string) $mapped['dev_stage'] : null;
    $website = isset($mapped['website']) ? mb_substr((string) $mapped['website'], 0, 300) : null;
    $source = inc_int_or_null($ctx['source_id'] ?? null) ?? ($form['default_source_id'] ? (int) $form['default_source_id'] : null);
    if ($source && !isset(inc_masters('source', false)[$source])) {
        $source = null;
    }
    // Who the applicant is: chosen by staff entering it, else the form's default.
    $type = inc_int_or_null($ctx['applicant_type_id'] ?? null) ?? (!empty($form['default_type_id']) ? (int) $form['default_type_id'] : null);
    if ($type && !isset(inc_masters('applicant_type', false)[$type])) {
        $type = null;
    }
    // Or what the applicant answered, on a form that asks: new answers join the list.
    if (isset($mapped['applicant_type']) && is_string($mapped['applicant_type']) && trim($mapped['applicant_type']) !== '') {
        $type = inc_applicant_type_id(mb_substr(trim($mapped['applicant_type']), 0, 120));
    }

    // A form that refuses duplicates: one live application per email per form.
    if ((int) $form['block_duplicates'] === 1 && $email) {
        $live = inc_one('SELECT a.ref FROM inc_applications a JOIN inc_stages s ON s.id = a.stage_id
                          WHERE a.form_id = ? AND a.applicant_email = ? AND s.is_terminal = 0 AND a.dup_status <> "confirmed" LIMIT 1',
                         'is', [(int) $form['id'], $email]);
        if ($live) {
            throw new InvalidArgumentException(inc_words('An application from ' . $email . ' to this form is already being processed (reference ' . $live['ref'] . '). Please contact the incubation team to update it.'));
        }
    }

    $stored = [];
    try {
        foreach ($uploads as $uid => $up) {
            require_once __DIR__ . '/../uploads.php';
            [$ok, $msg, $meta] = fabo_store_upload($up);
            if (!$ok) {
                throw new InvalidArgumentException('fields:' . json_encode([$uid => '"' . $byUid[$uid]['label'] . '": ' . $msg]));
            }
            $stored[$uid] = $meta;
        }
        $res = inc_tx(static function () use ($form, $version, $fields, $values, $stored, $channel, $source, $name, $email, $phone,
                                              $startupName, $spoke, $sector, $devStage, $website, $ticket, $ctx, $type) {
            $stage = inc_first_stage('crm');
            // An existing startup is suggested, never silently merged into.
            $startupId = null;
            $suggest = null;
            if ($startupName !== '') {
                $norm = inc_norm_name($startupName);
                $match = inc_one('SELECT id FROM inc_startups WHERE (name_norm = ? AND name_norm <> "")' . ($email ? ' OR email = ?' : '') . ' ORDER BY id LIMIT 1',
                                  $email ? 'ss' : 's', $email ? [$norm, $email] : [$norm]);
                if ($match) {
                    $suggest = (int) $match['id'];
                } else {
                    $startupId = inc_exec('INSERT INTO inc_startups (name, name_norm, email, phone, website, spoke_id, sector_id, dev_stage, dev_stage_at, created_by)
                                            VALUES (?,?,?,?,?,?,?,?,' . ($devStage ? 'NOW()' : 'NULL') . ',?)',
                                           'sssssiiss', [$startupName, $norm, $email, $phone, $website, $spoke, $sector, $devStage,
                                                         $channel === 'public' ? 'applicant' : inc_actor()]);
                    if ($devStage) {
                        inc_exec('INSERT INTO inc_dev_history (startup_id, from_stage, to_stage, actor, reason) VALUES (?,NULL,?,?,?)',
                                  'isss', [$startupId, $devStage, $channel === 'public' ? 'applicant' : inc_actor(), 'Stated on application']);
                    }
                }
            }
            // Routing: a spoke covered by exactly one team goes to that team.
            $team = null;
            if ($spoke) {
                $teams = inc_all('SELECT t.id FROM inc_teams t JOIN inc_team_spokes s ON s.team_id = t.id WHERE t.active = 1 AND s.spoke_id = ?', 'i', [$spoke]);
                if (count($teams) === 1) {
                    $team = (int) $teams[0]['id'];
                }
            }
            $dup = inc_find_duplicate(null, $email, $phone, $startupName);
            $id = inc_exec('INSERT INTO inc_applications (form_id, form_version_id, purpose, event_name, channel, source_id, applicant_name, applicant_email, applicant_phone,
                                startup_name, startup_id, startup_suggest_id, spoke_id, sector_id, stage_id, dup_status, dup_of_id, team_id, submitted_by, submit_ip, submit_ticket)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iisssisssssiiiisiisss',
                            [(int) $form['id'], (int) $version['id'], $form['purpose'], ($form['event_name'] ?? '') !== '' ? $form['event_name'] : null, $channel, $source, $name, $email, $phone, $startupName,
                             $startupId, $suggest, $spoke, $sector, (int) $stage['id'], $dup ? 'suspected' : 'none', $dup, $team,
                             $channel === 'public' ? 'applicant' : inc_actor(), mb_substr((string) ($ctx['ip'] ?? ''), 0, 45) ?: null, $ticket ?: null]);
            $ref = inc_setting('ref_prefix') . '-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
            inc_exec('UPDATE inc_applications SET ref = ? WHERE id = ?', 'si', [$ref, $id]);
            if ($type) {
                inc_exec('UPDATE inc_applications SET applicant_type_id = ? WHERE id = ?', 'ii', [$type, $id]);
            }
            foreach ($fields as $f) {
                $uid = $f['uid'];
                if (isset($stored[$uid])) {
                    $m = $stored[$uid];
                    $fid = inc_exec('INSERT INTO inc_files (entity, entity_id, label, stored_name, original_name, mime, size, uploaded_by) VALUES ("application",?,?,?,?,?,?,?)',
                                     'issssis', [$id, $f['label'], $m['stored_name'], $m['original_name'], $m['mime'], $m['size'], $channel === 'public' ? 'applicant' : inc_actor()]);
                    inc_exec('INSERT INTO inc_app_values (application_id, field_uid, label, type, value_text, value_short, file_id) VALUES (?,?,?,?,?,?,?)',
                              'isssssi', [$id, $uid, $f['label'], 'file', $m['original_name'], mb_substr($m['original_name'], 0, 191), $fid]);
                    continue;
                }
                if (!array_key_exists($uid, $values)) {
                    continue;
                }
                $v = $values[$uid];
                $text = is_array($v) ? json_encode(array_values($v), JSON_UNESCAPED_UNICODE) : (string) $v;
                $short = is_array($v) ? mb_substr(implode(', ', $v), 0, 191) : mb_substr((string) $v, 0, 191);
                inc_exec('INSERT INTO inc_app_values (application_id, field_uid, label, type, value_text, value_short) VALUES (?,?,?,?,?,?)',
                          'isssss', [$id, $uid, $f['label'], $f['type'], $text, $short]);
            }
            inc_exec('INSERT INTO inc_stage_history (pipeline, entity_id, from_stage_id, to_stage_id, actor, reason) VALUES ("crm",?,NULL,?,?,?)',
                      'iiss', [$id, (int) $stage['id'], $channel === 'public' ? 'applicant' : inc_actor(), 'Application received']);
            inc_activity($id, null, 'submitted', 'Application ' . $ref . ' received through "' . $form['name'] . '" (version ' . $version['version_no'] . ', ' . ($channel === 'public' ? 'public link' : 'entered by staff') . ').');
            if ($dup) {
                inc_activity($id, null, 'duplicate', 'Suspected duplicate of ' . (inc_one('SELECT ref FROM inc_applications WHERE id = ?', 'i', [$dup])['ref'] ?? '#' . $dup) . '. Review it on the application.');
            }
            if ($suggest) {
                inc_activity($id, null, 'startup', 'A startup with a matching name or email already exists. Confirm the link on the application.');
            }
            return ['id' => $id, 'ref' => $ref, 'duplicate_of' => $dup, 'repeat' => false];
        });
    } catch (Throwable $e) {
        // The rows were rolled back; the files must not be left behind either.
        require_once __DIR__ . '/../uploads.php';
        foreach ($stored as $m) {
            fabo_delete_stored($m['stored_name']);
        }
        throw $e;
    }
    $app = inc_one('SELECT * FROM inc_applications WHERE id = ?', 'i', [$res['id']]);
    inc_notify('submitted', 'app' . $res['id'], 0, [
        'ref' => $res['ref'], 'applicant' => $app['applicant_name'], 'startup' => $app['startup_name'] ?: '—',
        'form' => $form['name'], 'date' => inc_fmt_datetime($app['submitted_at']),
    ], $res['id'], null, inc_admin_link('application', ['id' => $res['id']]));
    // A department's form: straight onto that department's desk. The pipeline stage stays at New.
    if (!empty($form['dept_team_id'])) {
        try {
            require_once __DIR__ . '/depts.php';
            inc_dept_route_app((int) $res['id'], (int) $form['dept_team_id'], 'Form: ' . $form['name'], true);
        } catch (Throwable $e) {
            error_log('FABO incubation form routing: ' . $e->getMessage());   // never fails the applicant's submission
        }
    }
    // The applicant's acknowledgement (with the Gem 1 link, and on to its stage, when set up).
    require_once __DIR__ . '/gems.php';
    inc_app_acknowledge((int) $res['id']);
    return $res;
}

/**
 * The earliest application in the look-back window that shares an email,
 * phone or normalised startup name — or null.
 */
function inc_find_duplicate(?int $exceptId, ?string $email, ?string $phone, string $startupName): ?int
{
    $days = max(1, (int) inc_setting('dup_window_days'));
    $where = [];
    $types = '';
    $params = [];
    if ($email) {
        $where[] = 'a.applicant_email = ?';
        $types .= 's';
        $params[] = $email;
    }
    $p = inc_norm_phone($phone);
    if (strlen($p) >= 7) {
        $where[] = "RIGHT(REGEXP_REPLACE(COALESCE(a.applicant_phone,''), '[^0-9]', ''), 10) = ?";
        $types .= 's';
        $params[] = $p;
    }
    $norm = inc_norm_name($startupName);
    if ($norm !== '') {
        $where[] = 'a.startup_id IN (SELECT id FROM inc_startups WHERE name_norm = ?)';
        $types .= 's';
        $params[] = $norm;
        $where[] = 'a.startup_suggest_id IN (SELECT id FROM inc_startups WHERE name_norm = ?)';
        $types .= 's';
        $params[] = $norm;
    }
    if (!$where) {
        return null;
    }
    $sql = 'SELECT a.id FROM inc_applications a WHERE (' . implode(' OR ', $where) . ') AND a.submitted_at >= NOW() - INTERVAL ' . $days . ' DAY'
         . ($exceptId ? ' AND a.id <> ' . (int) $exceptId : '') . ' AND a.dup_status <> "confirmed" ORDER BY a.id LIMIT 1';
    $r = inc_one($sql, $types, $params);
    return $r ? (int) $r['id'] : null;
}

/** An application's answers, keyed by uid: ['label','type','value','file_id']. */
function inc_app_values(int $appId): array
{
    $out = [];
    foreach (inc_all('SELECT * FROM inc_app_values WHERE application_id = ? ORDER BY id', 'i', [$appId]) as $r) {
        $v = $r['value_text'];
        if (in_array($r['type'], ['multiselect', 'checkbox'], true)) {
            $v = json_decode((string) $v, true) ?: [];
        }
        $out[$r['field_uid']] = ['label' => $r['label'], 'type' => $r['type'], 'value' => $v, 'file_id' => $r['file_id'] ? (int) $r['file_id'] : null];
    }
    return $out;
}

/** A stored answer as display text, using the field's choices where it has them. */
function inc_value_text(?array $field, $value): string
{
    if (is_array($value)) {
        if ($field) {
            $c = inc_field_choices($field);
            $value = array_map(static fn($v) => $c[$v] ?? $v, $value);
        }
        return implode(', ', $value);
    }
    if ($field && in_array($field['type'], ['select', 'radio'], true) && $field['option_source']) {
        return (string) (inc_field_choices($field)[(string) $value] ?? $value);
    }
    if ($field && $field['type'] === 'date') {
        return inc_fmt_date((string) $value);
    }
    return (string) $value;
}

/* ===================================================== purpose templates */

/** The ids of every purpose's question template, to keep them out of the form lists. */
function inc_purpose_template_ids(): array
{
    return array_values(array_filter(array_map(static fn($d) => (int) ($d['template'] ?? 0), inc_purpose_defs())));
}

/**
 * A purpose of the organisation's own keeps its starting questions in a
 * hidden form (archived, never public) edited in the ordinary builder. New
 * forms of that purpose start from a copy of them. Returns its id -- made from
 * the purpose's "start from" questions when $create and it has none yet.
 */
function inc_purpose_template(string $code, bool $create): int
{
    $d = inc_purpose_defs()[$code] ?? null;
    if (!$d) {
        return 0;
    }
    if (!empty($d['template']) && inc_form((int) $d['template'])) {
        return (int) $d['template'];
    }
    if (!$create) {
        return 0;
    }
    inc_need('configure');
    $fid = inc_tx(static function () use ($code, $d) {
        $fid = inc_exec('INSERT INTO inc_forms (name, purpose, description, public_token, public_enabled, archived, created_by) VALUES (?,?,?,?,0,1,?)',
                        'sssss', [$d['label'] . ' — starting questions', $code, 'The questions every new "' . $d['label'] . '" form starts with.',
                                  bin2hex(random_bytes(16)), inc_actor()]);
        $vid = inc_exec("INSERT INTO inc_form_versions (form_id, version_no, status, created_by) VALUES (?,1,'draft',?)", 'is', [$fid, inc_actor()]);
        inc_form_fields_insert($vid, inc_purpose_preset($d['base']));
        return $fid;
    });
    $saved = json_decode(inc_setting('purposes'), true);
    $saved = is_array($saved) ? $saved : [];
    $saved[$code] = (is_array($saved[$code] ?? null) ? $saved[$code] : []) + ['label' => $d['label']];
    $saved[$code]['template'] = $fid;
    inc_setting_set('purposes', json_encode($saved, JSON_UNESCAPED_UNICODE));
    return $fid;
}

/** The version a new form of $purpose copies its questions from (the template's draft, else its published one), or 0. */
function inc_purpose_template_version(string $purpose): int
{
    $tpl = inc_purpose_template($purpose, false);
    if (!$tpl) {
        return 0;
    }
    $v = inc_form_draft_version($tpl) ?: inc_form_published($tpl);
    return $v ? (int) $v['id'] : 0;
}

/** The purpose whose question template this form is, or ''. */
function inc_purpose_of_template(int $formId): string
{
    foreach (inc_purpose_defs() as $code => $d) {
        if ($formId > 0 && (int) ($d['template'] ?? 0) === $formId) {
            return $code;
        }
    }
    return '';
}

/* ============================================ RTIH registration presets */

/** The sectors the RTIH registration forms offer. */
function inc_rtih_sectors(): array
{
    return ['MedTech', 'Deep Tech', 'AgriTech', 'FinTech', 'EdTech', 'Manufacturing', 'Clean Energy', 'Consumer / D2C', 'SaaS', 'GovTech', 'Others'];
}

/**
 * The RTIH Mentor Registration Form, as questions ([uid, type, label, required,
 * extra] like inc_purpose_preset()): only what profiling and matching use.
 * The uids are those of the original onboarding form, so answers line up.
 * extra['card'] puts the answer on the board card.
 */
function inc_preset_mentor_onboarding(): array
{
    $when = static fn(string $uid, string $value = 'Yes') => ['cond' => ['field' => $uid, 'op' => 'eq', 'value' => $value]];
    return [
        ['sec_a', 'section', 'About you', 0, ['help' => 'Takes about 5 minutes. Your information is used only for mentor profiling, matching and programme administration by RTIH.']],
        ['full_name', 'text', 'Full name', 1, ['maps_to' => 'applicant_name']],
        ['mobile', 'phone', 'Mobile number', 1, ['maps_to' => 'applicant_phone']],
        ['email', 'email', 'Email address', 1, ['maps_to' => 'applicant_email']],
        ['linkedin', 'url', 'LinkedIn profile URL', 0, []],
        ['city', 'text', 'City / base location', 1, []],
        ['hub_spoke', 'select', 'RTIH Hub or Spoke you are registering with', 1, ['maps_to' => 'spoke', 'option_source' => 'spoke']],

        ['sec_b', 'section', 'Professional background', 0, []],
        ['org_designation', 'text', 'Current organisation and designation', 1, ['card' => 'Role']],
        ['experience_years', 'number', 'Total years of professional experience', 1, ['card' => 'Years', 'rules' => ['min' => 0, 'max' => 70]]],
        ['expertise_sectors', 'multiselect', 'Sector(s) of expertise', 1, ['card' => 'Sectors', 'options' => inc_rtih_sectors()]],
        ['expertise_sectors_other', 'text', 'Other sector(s) — please specify', 1, $when('expertise_sectors', 'Others')],
        ['functional_areas', 'multiselect', 'Functional area(s) of expertise', 1, ['card' => 'Expertise', 'options' => ['Product & Technology', 'Fundraising & Finance',
            'Sales & GTM', 'Marketing & Branding', 'Operations & Supply Chain', 'HR & Talent', 'Legal & Compliance', 'Design / UX', 'Public Policy', 'IP & Patents', 'Others']]],
        ['functional_areas_other', 'text', 'Other functional area(s) — please specify', 1, $when('functional_areas', 'Others')],
        ['pro_summary', 'textarea', 'Brief professional summary', 1, ['help' => 'A few lines: your background and what you can help founders with.', 'rules' => ['maxlen' => 1200]]],
        ['founder_exp', 'yesno', 'Have you founded or co-founded a startup yourself?', 0, []],
        ['mentored_before', 'yesno', 'Have you mentored or advised startups before?', 0, []],

        ['sec_d', 'section', 'Mentoring preferences', 0, []],
        ['pref_stages', 'checkbox', 'Startup stage(s) you prefer to mentor', 1, ['card' => 'Stages', 'options' => ['Ideation / Pre-incubation', 'Early-stage / MVP', 'Growth-stage', 'Scale-up']]],
        ['mentoring_mode', 'radio', 'Preferred mode of mentoring', 1, ['options' => ['In-person', 'Virtual', 'Hybrid']]],
        ['monthly_hours', 'select', 'Time you can give each month', 1, ['options' => ['2 hours', '3–4 hours', '5–8 hours', 'More than 8 hours']]],
        ['languages', 'checkbox', 'Language(s) you can mentor in', 1, ['options' => ['English', 'Telugu', 'Hindi', 'Other']]],
        ['beyond_mentoring', 'checkbox', 'Would you also like to help with', 0, ['options' => ['Workshops', 'Demo Day judging', 'Pitch deck reviews', 'Guest lectures', 'Investor / industry introductions']]],
        ['profile_doc', 'file', 'Profile or CV (optional)', 0, ['rules' => ['accept' => 'pdf,doc,docx', 'max_mb' => 5]]],

        ['sec_g', 'section', 'Declaration', 0, []],
        ['decl_consent', 'checkbox', 'Declaration and consent', 1, ['options' => ['The information above is accurate. I agree to the RTIH Mentor Policy and Code of Conduct, understand that mentoring is voluntary and unpaid, and consent to RTIH using my details for mentor profiling and matching.']]],
    ];
}

/** The RTIH Startup Registration Form: founder, startup, idea, traction and the support wanted. */
function inc_preset_startup_registration(): array
{
    $when = static fn(string $uid, string $value = 'Yes') => ['cond' => ['field' => $uid, 'op' => 'eq', 'value' => $value]];
    return [
        ['sec_founder', 'section', 'Founder details', 0, ['help' => 'Takes about 10 minutes. The incubation team will contact you about the next steps.']],
        ['applicant_name', 'text', 'Founder name', 1, ['maps_to' => 'applicant_name']],
        ['applicant_email', 'email', 'Email address', 1, ['maps_to' => 'applicant_email']],
        ['applicant_phone', 'phone', 'Mobile number', 1, ['maps_to' => 'applicant_phone']],
        ['linkedin', 'url', 'LinkedIn profile', 0, []],
        ['team_size', 'number', 'Team size, including founders', 1, ['card' => 'Team', 'rules' => ['min' => 1, 'max' => 500]]],

        ['sec_startup', 'section', 'Startup details', 0, []],
        ['startup_name', 'text', 'Startup name', 1, ['maps_to' => 'startup_name']],
        ['website', 'url', 'Website or app link', 0, ['maps_to' => 'website']],
        ['spoke', 'select', 'RTIH Hub or Spoke nearest to you', 1, ['maps_to' => 'spoke', 'option_source' => 'spoke']],
        ['sector', 'select', 'Sector', 1, ['card' => 'Sector', 'options' => inc_rtih_sectors()]],
        ['dev_stage', 'select', 'Current stage', 1, ['maps_to' => 'dev_stage', 'option_source' => 'dev_stage', 'card' => 'Stage']],
        ['registered', 'yesno', 'Is the company registered?', 1, []],
        ['reg_number', 'text', 'Registration number (CIN / LLPIN / Udyam)', 1, $when('registered')],
        ['dpiit', 'yesno', 'DPIIT recognised?', 0, $when('registered')],

        ['sec_idea', 'section', 'Problem and solution', 0, []],
        ['problem', 'textarea', 'What problem are you solving, and for whom?', 1, ['rules' => ['minlen' => 30, 'maxlen' => 1500]]],
        ['solution', 'textarea', 'Your solution, and what makes it different', 1, ['rules' => ['minlen' => 30, 'maxlen' => 1500]]],
        ['business_model', 'textarea', 'How will the startup make money?', 0, ['rules' => ['maxlen' => 1000]]],

        ['sec_traction', 'section', 'Traction and support', 0, []],
        ['revenue', 'select', 'Revenue in the last 12 months', 1, ['card' => 'Revenue', 'options' => ['Pre-revenue', 'Under ₹1 lakh', '₹1–10 lakh', '₹10–50 lakh', 'Over ₹50 lakh']]],
        ['funding', 'yesno', 'Have you raised any funding or grants?', 1, []],
        ['funding_details', 'textarea', 'Funding received (source, amount, year)', 1, $when('funding')],
        ['support_needed', 'checkbox', 'What support are you looking for?', 1, ['card' => 'Needs', 'options' => ['Mentorship', 'Workspace', 'Funding access',
            'Market access / pilots', 'Technical support / labs', 'Legal and compliance', 'Branding and marketing']]],
        ['pitch_deck', 'file', 'Pitch deck (optional)', 0, ['rules' => ['accept' => 'pdf,ppt,pptx', 'max_mb' => 10]]],

        ['sec_decl', 'section', 'Declaration', 0, []],
        ['decl_consent', 'checkbox', 'Declaration and consent', 1, ['options' => ['The information above is accurate, and I consent to RTIH using it to assess and support my startup.']]],
    ];
}

/** The RTIH Partner Registration Form: the organisation, its contact, and how it wants to work with RTIH. */
function inc_preset_partner_registration(): array
{
    return [
        ['sec_org', 'section', 'Organisation', 0, ['help' => 'For corporates, institutions, investors and others who would like to partner with RTIH.']],
        ['org_name', 'text', 'Organisation name', 1, ['card' => 'Organisation']],
        ['org_type', 'select', 'Type of organisation', 1, ['card' => 'Type', 'options' => ['Corporate', 'MSME / Industry', 'Academic institution', 'Government body',
            'Incubator / Accelerator', 'Investor / VC / Angel network', 'Industry association', 'NGO / Foundation', 'Other']]],
        ['org_type_other', 'text', 'Please specify', 1, ['cond' => ['field' => 'org_type', 'op' => 'eq', 'value' => 'Other']]],
        ['org_website', 'url', 'Website', 0, []],
        ['org_city', 'text', 'City', 1, ['card' => 'City']],

        ['sec_contact', 'section', 'Contact person', 0, []],
        ['applicant_name', 'text', 'Full name', 1, ['maps_to' => 'applicant_name']],
        ['designation', 'text', 'Designation', 1, []],
        ['applicant_email', 'email', 'Email address', 1, ['maps_to' => 'applicant_email']],
        ['applicant_phone', 'phone', 'Mobile number', 1, ['maps_to' => 'applicant_phone']],

        ['sec_partner', 'section', 'Partnership', 0, []],
        ['partner_areas', 'checkbox', 'How would you like to partner with RTIH?', 1, ['card' => 'Offers', 'options' => ['Mentoring and expert sessions', 'Funding / investment',
            'Pilots and market access', 'CSR / grants', 'Infrastructure and labs', 'Internships and hiring', 'Events and hackathons', 'Training and capacity building',
            'Technology / cloud credits', 'Other']]],
        ['focus_sectors', 'multiselect', 'Sectors of interest', 0, ['card' => 'Sectors', 'options' => inc_rtih_sectors()]],
        ['engagement', 'radio', 'Preferred engagement', 1, ['options' => ['One-time / single event', 'Short-term (under 6 months)', 'Long-term / MoU']]],
        ['proposal', 'textarea', 'Tell us about the partnership you have in mind', 1, ['rules' => ['minlen' => 20, 'maxlen' => 1500]]],
        ['org_profile', 'file', 'Organisation profile or brochure (optional)', 0, ['rules' => ['accept' => 'pdf,ppt,pptx,doc,docx', 'max_mb' => 10]]],

        ['sec_decl', 'section', 'Consent', 0, []],
        ['decl_consent', 'checkbox', 'Consent', 1, ['options' => ['I consent to RTIH storing these details and contacting me about the partnership.']]],
    ];
}

