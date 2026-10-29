<?php
/**
 * Admin & Facilities — supplies: housekeeping, pantry and stationery stock.
 * Receive, issue or correct a count; items at or below their reorder level
 * are flagged here and on the dashboard. Every movement is kept.
 */

$editId = (int) ($_GET['edit'] ?? 0);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = inc_action(static function () use ($a) {
        $id = inc_int_or_null($_POST['id'] ?? null);
        if ($a === 'item') {
            $row = [inc_str($_POST['name'] ?? '', 160, true, 'Item'), inc_str($_POST['category'] ?? '', 80) ?: null, inc_str($_POST['unit'] ?? '', 30) ?: 'pcs', (float) ($_POST['reorder_level'] ?? 0)];
            try {
                if ($id) {
                    it_exec('UPDATE it_stock SET name = ?, category = ?, unit = ?, reorder_level = ?, active = ? WHERE id = ?', 'sssdii', array_merge($row, [!empty($_POST['active']) ? 1 : 0, $id]));
                    return 'Item saved.';
                }
                $new = it_exec('INSERT INTO it_stock (name, category, unit, reorder_level) VALUES (?,?,?,?)', 'sssd', $row);
                if ((float) ($_POST['qty'] ?? 0) > 0) {
                    it_exec('UPDATE it_stock SET qty = ? WHERE id = ?', 'di', [(float) $_POST['qty'], $new]);
                    it_exec("INSERT INTO it_stock_moves (item_id, qty, kind, note, actor) VALUES (?,?,'in','Opening stock',?)", 'ids', [$new, (float) $_POST['qty'], it_actor()]);
                }
                return 'Item added.';
            } catch (mysqli_sql_exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    throw new InvalidArgumentException('An item called "' . $row[0] . '" already exists.');
                }
                throw $e;
            }
        }
        if ($a === 'move') {
            $kind = in_array($_POST['kind'] ?? '', ['in', 'out', 'adjust'], true) ? $_POST['kind'] : 'out';
            $qty = (float) ($_POST['qty'] ?? 0);
            $item = it_one('SELECT * FROM it_stock WHERE id = ?', 'i', [(int) $id]) ?? throw new InvalidArgumentException('That item no longer exists.');
            if ($qty <= 0 && $kind !== 'adjust') {
                throw new InvalidArgumentException('Enter a quantity above 0.');
            }
            $new = $kind === 'in' ? $item['qty'] + $qty : ($kind === 'out' ? $item['qty'] - $qty : $qty);
            if ($new < 0) {
                throw new InvalidArgumentException('Only ' . rtrim(rtrim(number_format((float) $item['qty'], 2), '0'), '.') . ' ' . $item['unit'] . ' in stock.');
            }
            it_exec('UPDATE it_stock SET qty = ? WHERE id = ?', 'di', [$new, (int) $id]);
            it_exec('INSERT INTO it_stock_moves (item_id, qty, kind, note, actor) VALUES (?,?,?,?,?)', 'idsss',
                    [(int) $id, $kind === 'adjust' ? $new - $item['qty'] : $qty, $kind, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null, it_actor()]);
            return ['in' => 'Received', 'out' => 'Issued', 'adjust' => 'Count corrected'][$kind] . ' — ' . $item['name'] . ' now ' . rtrim(rtrim(number_format($new, 2), '0'), '.') . ' ' . $item['unit'] . '.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, it_url('stock'));
}

$items = it_all('SELECT * FROM it_stock ORDER BY active DESC, qty > reorder_level, category, name');
$low = array_filter($items, static fn($i) => (int) $i['active'] === 1 && (float) $i['qty'] <= (float) $i['reorder_level']);
$n = static fn($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
it_layout_start('Supplies', count($items) . ' items · ' . count($low) . ' at or below reorder level', '<a class="btn btn-primary" href="' . e(it_url('stock', ['edit' => 'new'])) . '">+ Add item</a>', $flash);

if ($editId || ($_GET['edit'] ?? '') === 'new') {
    $r = $editId ? (it_one('SELECT * FROM it_stock WHERE id = ?', 'i', [$editId]) ?? []) : [];
    echo '<section class="card"><h2>' . ($editId ? 'Edit ' . e($r['name'] ?? '') : 'Add an item') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="item">'
       . ($editId ? '<input type="hidden" name="id" value="' . $editId . '">' : '') . '<div class="inc-row">'
       . inc_field('Item', '<input type="text" name="name" maxlength="160" required value="' . e($r['name'] ?? '') . '" placeholder="e.g. Hand wash (5 L)">', '', true, 'grow')
       . inc_field('Category', inc_select('category', ['Housekeeping' => 'Housekeeping', 'Pantry' => 'Pantry', 'Stationery' => 'Stationery', 'Maintenance' => 'Maintenance', 'Other' => 'Other'], $r['category'] ?? '', 'Choose…'))
       . inc_field('Unit', '<input type="text" name="unit" maxlength="30" value="' . e($r['unit'] ?? 'pcs') . '" class="inc-w">')
       . inc_field('Reorder at', '<input type="number" name="reorder_level" min="0" step="0.01" value="' . e((string) ($r['reorder_level'] ?? '0')) . '" class="inc-w">')
       . (!$editId ? inc_field('Opening stock', '<input type="number" name="qty" min="0" step="0.01" value="0" class="inc-w">') : '') . '</div>'
       . ($editId ? '<label class="check"><input type="checkbox" name="active" value="1"' . ((int) ($r['active'] ?? 1) === 1 ? ' checked' : '') . '> In use</label><br>' : '')
       . '<button class="btn btn-primary">Save</button> <a class="btn" href="' . e(it_url('stock')) . '">Cancel</a></form></section>';
}

if (!$items) {
    echo inc_empty('No supplies yet.', 'Add cleaning supplies, pantry items and stationery with + Add item.');
} else {
    echo '<div class="scrollx"><table class="data stack inc-table"><thead><tr><th>Item</th><th>Category</th><th class="num">In stock</th><th class="num">Reorder at</th><th>Receive · issue · correct</th></tr></thead><tbody>';
    foreach ($items as $i) {
        $isLow = (int) $i['active'] === 1 && (float) $i['qty'] <= (float) $i['reorder_level'];
        echo '<tr' . ((int) $i['active'] !== 1 ? ' class="muted"' : '') . '><td data-l="Item"><a href="' . e(it_url('stock', ['edit' => $i['id']])) . '"><strong>' . e($i['name']) . '</strong></a>' . ($isLow ? ' ' . inc_badge('Reorder', 'red') : '') . '</td>'
           . '<td data-l="Category">' . e($i['category'] ?: '—') . '</td><td data-l="In stock" class="num' . ($isLow ? ' tone-risk' : '') . '"><strong>' . e($n($i['qty'])) . '</strong> ' . e($i['unit']) . '</td>'
           . '<td data-l="Reorder at" class="num">' . e($n($i['reorder_level'])) . '</td><td data-l="Move">'
           . inc_form_open('class="inc-inline it-move"') . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . (int) $i['id'] . '">'
           . inc_select('kind', ['out' => 'Issue', 'in' => 'Receive', 'adjust' => 'Set count to'], 'out') . '<input type="number" name="qty" min="0" step="0.01" required class="inc-w">'
           . '<input type="text" name="note" maxlength="255" placeholder="To / from (optional)"><button class="btn btn-sm">Save</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
    $moves = it_all('SELECT m.*, s.name, s.unit FROM it_stock_moves m JOIN it_stock s ON s.id = m.item_id ORDER BY m.created_at DESC, m.id DESC LIMIT 30');
    if ($moves) {
        echo '<details class="card inc-runs"><summary>Recent movements (' . count($moves) . ')</summary><ul class="small">';
        foreach ($moves as $m) {
            echo '<li>' . e(inc_fmt_datetime($m['created_at'])) . ' — ' . e(['in' => 'Received', 'out' => 'Issued', 'adjust' => 'Corrected by'][$m['kind']]) . ' ' . e($n(abs((float) $m['qty']))) . ' ' . e($m['unit']) . ' ' . e($m['name'])
               . ($m['note'] ? ' · ' . e($m['note']) : '') . ' · ' . e($m['actor']) . '</li>';
        }
        echo '</ul></details>';
    }
}

it_layout_end();
