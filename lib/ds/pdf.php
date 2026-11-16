<?php
/**
 * Digital Signatures — reading a PDF and adding to it without disturbing it.
 *
 * WHY INCREMENTAL. A PDF signature covers the exact bytes of the file as it
 * was when signed (its /ByteRange). Rewriting the file breaks every
 * signature already in it, so everything this module adds — a signature
 * field, a drawn-signature stamp, an approval stamp — is appended as an
 * incremental update: the original bytes stay byte-for-byte, and the new
 * objects and a new cross-reference section follow them. Earlier signatures
 * keep verifying; a validator sees exactly what was added after each one.
 *
 * READING. Classic xref tables, cross-reference streams (PDF 1.5) with PNG
 * predictors, hybrid files and object streams are all read. Encrypted PDFs
 * are refused: a password-protected file cannot be signed by anyone but its
 * author's tools.
 *
 * Values: null/bool/int/float are PHP scalars; arrays are PHP lists; the
 * rest are the small classes below.
 */

final class DsName
{
    public function __construct(public string $n) {}
}

final class DsStr
{
    public function __construct(public string $s, public bool $hex = false) {}
}

final class DsRef
{
    public function __construct(public int $num, public int $gen = 0) {}
}

class DsDict
{
    public function __construct(public array $d = []) {}

    public function get(string $k)
    {
        return $this->d[$k] ?? null;
    }

    public function name(string $k): ?string
    {
        $v = $this->d[$k] ?? null;
        return $v instanceof DsName ? $v->n : null;
    }
}

final class DsStream extends DsDict
{
    public function __construct(array $d = [], public string $data = '') { parent::__construct($d); }
}

/** Bytes written as they are (placeholders). */
final class DsRaw
{
    public function __construct(public string $raw) {}
}

final class DsPdf
{
    public string $b;
    /** num => ['t' => 1, 'off', 'gen'] | ['t' => 2, 'stm', 'idx'] */
    public array $xref = [];
    public DsDict $trailer;
    public string $xrefType = 'table';
    public int $lastXref = 0;
    public bool $repaired = false;
    /** Each cross-reference section, newest first: ['off' => offset, 'nums' => [object numbers it defines]]. */
    public array $sections = [];
    private array $cache = [];
    private array $stmCache = [];

    public function __construct(string $bytes)
    {
        $this->b = $bytes;
        if (strncmp(ltrim(substr($bytes, 0, 1024)), '%PDF-', 5) !== 0 && !str_contains(substr($bytes, 0, 1024), '%PDF-')) {
            throw new InvalidArgumentException('This is not a PDF file.');
        }
        try {
            $this->readXrefChain();
        } catch (Throwable $e) {
            $this->rebuildXref();
        }
        if (!$this->trailer->get('Root')) {
            $this->rebuildXref();
        }
        if ($this->trailer->get('Encrypt')) {
            throw new InvalidArgumentException('This PDF is password-protected or encrypted. Remove the protection (save an unprotected copy) and upload that.');
        }
    }

    /* ------------------------------------------------------------ tokens */

    private static function isWs(string $c): bool
    {
        return $c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0";
    }

    private static function isDelim(string $c): bool
    {
        return str_contains('()<>[]{}/%', $c);
    }

    private function skip(int &$p): void
    {
        $n = strlen($this->b);
        while ($p < $n) {
            $c = $this->b[$p];
            if (self::isWs($c)) {
                $p++;
            } elseif ($c === '%') {
                while ($p < $n && $this->b[$p] !== "\n" && $this->b[$p] !== "\r") {
                    $p++;
                }
            } else {
                break;
            }
        }
    }

    private function word(int &$p): string
    {
        $n = strlen($this->b);
        $s = $p;
        while ($p < $n && !self::isWs($this->b[$p]) && !self::isDelim($this->b[$p])) {
            $p++;
        }
        return substr($this->b, $s, $p - $s);
    }

    /** Parses one value at $p (moved past it). */
    public function value(int &$p, int $depth = 0)
    {
        if ($depth > 60) {
            throw new RuntimeException('PDF nesting too deep.');
        }
        $this->skip($p);
        $b = $this->b;
        $c = $b[$p] ?? '';
        if ($c === '/') {
            $p++;
            $w = $this->word($p);
            return new DsName(preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($m) => chr(hexdec($m[1])), $w));
        }
        if ($c === '<' && ($b[$p + 1] ?? '') === '<') {
            $p += 2;
            $d = [];
            while (true) {
                $this->skip($p);
                if (($b[$p] ?? '') === '>' && ($b[$p + 1] ?? '') === '>') {
                    $p += 2;
                    break;
                }
                if ($p >= strlen($b)) {
                    throw new RuntimeException('Unterminated dictionary.');
                }
                $k = $this->value($p, $depth + 1);
                if (!$k instanceof DsName) {
                    throw new RuntimeException('Dictionary key is not a name.');
                }
                $d[$k->n] = $this->value($p, $depth + 1);
            }
            return new DsDict($d);
        }
        if ($c === '<') {
            $e = strpos($b, '>', $p);
            if ($e === false) {
                throw new RuntimeException('Unterminated hex string.');
            }
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($b, $p + 1, $e - $p - 1));
            if (strlen($hex) % 2) {
                $hex .= '0';
            }
            $p = $e + 1;
            return new DsStr((string) hex2bin($hex), true);
        }
        if ($c === '(') {
            return new DsStr($this->literal($p));
        }
        if ($c === '[') {
            $p++;
            $a = [];
            while (true) {
                $this->skip($p);
                if (($b[$p] ?? '') === ']') {
                    $p++;
                    break;
                }
                if ($p >= strlen($b)) {
                    throw new RuntimeException('Unterminated array.');
                }
                $a[] = $this->value($p, $depth + 1);
            }
            return $a;
        }
        $w = $this->word($p);
        if ($w === '') {
            throw new RuntimeException('Unexpected character in PDF at ' . $p . '.');
        }
        if ($w === 'true' || $w === 'false') {
            return $w === 'true';
        }
        if ($w === 'null') {
            return null;
        }
        if (is_numeric($w)) {
            if (ctype_digit($w)) {
                // "12 0 R" is a reference.
                $q = $p;
                $this->skip($q);
                $g = $this->word($q);
                if ($g !== '' && ctype_digit($g)) {
                    $this->skip($q);
                    if (($b[$q] ?? '') === 'R' && (self::isWs($b[$q + 1] ?? ' ') || self::isDelim($b[$q + 1] ?? ' '))) {
                        $p = $q + 1;
                        return new DsRef((int) $w, (int) $g);
                    }
                }
                return (int) $w;
            }
            return (float) $w;
        }
        return new DsName($w);    // a stray keyword; kept so nothing is lost
    }

    private function literal(int &$p): string
    {
        $b = $this->b;
        $n = strlen($b);
        $p++;
        $depth = 1;
        $out = '';
        while ($p < $n) {
            $c = $b[$p++];
            if ($c === '\\') {
                $d = $b[$p++] ?? '';
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($map[$d])) {
                    $out .= $map[$d];
                } elseif ($d >= '0' && $d <= '7') {
                    $o = $d;
                    for ($i = 0; $i < 2 && ($b[$p] ?? '') >= '0' && ($b[$p] ?? '') <= '7'; $i++) {
                        $o .= $b[$p++];
                    }
                    $out .= chr(octdec($o) & 0xff);
                } elseif ($d === "\r") {
                    if (($b[$p] ?? '') === "\n") {
                        $p++;
                    }
                } elseif ($d !== "\n") {
                    $out .= $d;
                }
            } elseif ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    break;
                }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------- objects */

    /** The indirect object at byte $off: [num, gen, value]. Streams come back as DsStream. */
    public function objectAt(int $off): array
    {
        $p = $off;
        $this->skip($p);
        $num = $this->word($p);
        $this->skip($p);
        $gen = $this->word($p);
        $this->skip($p);
        $kw = $this->word($p);
        if (!ctype_digit($num) || !ctype_digit($gen) || $kw !== 'obj') {
            throw new RuntimeException('No object at offset ' . $off . '.');
        }
        $v = $this->value($p);
        $q = $p;
        $this->skip($q);
        if ($v instanceof DsDict && substr($this->b, $q, 6) === 'stream') {
            $q += 6;
            if (($this->b[$q] ?? '') === "\r") {
                $q++;
            }
            if (($this->b[$q] ?? '') === "\n") {
                $q++;
            }
            $len = $v->get('Length');
            if ($len instanceof DsRef) {
                try {
                    $len = $this->get($len);
                } catch (Throwable $e) {
                    $len = null;
                }
            }
            if (!is_int($len) || $len < 0 || substr($this->b, $q + $len, 40) === '' || !preg_match('/^\s*endstream/', substr($this->b, $q + $len, 40))) {
                $e = strpos($this->b, 'endstream', $q);
                if ($e === false) {
                    throw new RuntimeException('Unterminated stream.');
                }
                $len = $e - $q;
                // Drop the end-of-line that precedes "endstream".
                if ($len > 0 && $this->b[$q + $len - 1] === "\n") {
                    $len--;
                }
                if ($len > 0 && $this->b[$q + $len - 1] === "\r") {
                    $len--;
                }
            }
            $v = new DsStream($v->d, substr($this->b, $q, $len));
        }
        return [(int) $num, (int) $gen, $v];
    }

    /** Resolves a reference (or returns a direct value as it is). */
    public function get($v)
    {
        if (!$v instanceof DsRef) {
            return $v;
        }
        $num = $v->num;
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $e = $this->xref[$num] ?? null;
        if (!$e || $e['t'] === 0) {
            return $this->cache[$num] = null;
        }
        if ($e['t'] === 1) {
            [, , $val] = $this->objectAt($e['off']);
        } else {
            $val = $this->fromObjStm($e['stm'], $e['idx'], $num);
        }
        return $this->cache[$num] = $val;
    }

    private function fromObjStm(int $stmNum, int $idx, int $num)
    {
        if (!isset($this->stmCache[$stmNum])) {
            $e = $this->xref[$stmNum] ?? null;
            if (!$e || $e['t'] !== 1) {
                throw new RuntimeException('Object stream ' . $stmNum . ' is missing.');
            }
            [, , $s] = $this->objectAt($e['off']);
            if (!$s instanceof DsStream) {
                throw new RuntimeException('Object stream ' . $stmNum . ' is not a stream.');
            }
            $data = self::decode($s, $this);
            $n = (int) $this->get($s->get('N'));
            $first = (int) $this->get($s->get('First'));
            preg_match_all('/\d+/', substr($data, 0, $first), $m);
            $pairs = array_chunk(array_map('intval', $m[0]), 2);
            $this->stmCache[$stmNum] = ['data' => $data, 'first' => $first, 'pairs' => array_slice($pairs, 0, $n)];
        }
        $st = $this->stmCache[$stmNum];
        $pair = $st['pairs'][$idx] ?? null;
        if (!$pair || $pair[0] !== $num) {
            foreach ($st['pairs'] as $pp) {
                if ($pp[0] === $num) {
                    $pair = $pp;
                    break;
                }
            }
        }
        if (!$pair) {
            return null;
        }
        $sub = self::over($st['data']);
        $p = $st['first'] + $pair[1];
        return $sub->value($p);
    }

    /** A bare reader over arbitrary bytes (an object stream's contents): values only, no xref. */
    public static function over(string $data): DsPdf
    {
        $o = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $o->b = $data;
        return $o;
    }

    /* ------------------------------------------------------------- filters */

    /** A stream's decoded bytes (FlateDecode with PNG predictors, ASCIIHex). */
    public static function decode(DsStream $s, ?DsPdf $pdf = null): string
    {
        $r = static fn($v) => $pdf ? $pdf->get($v) : $v;
        $f = $r($s->get('Filter'));
        $filters = $f === null ? [] : (is_array($f) ? $f : [$f]);
        $parms = $r($s->get('DecodeParms'));
        $parmsList = is_array($parms) ? $parms : [$parms];
        $data = $s->data;
        foreach ($filters as $i => $fn) {
            $fn = $r($fn);
            $name = $fn instanceof DsName ? $fn->n : '';
            $pm = $r($parmsList[$i] ?? null);
            if ($name === 'FlateDecode' || $name === 'Fl') {
                $out = @gzuncompress($data);
                if ($out === false) {
                    $out = @gzinflate(substr($data, 2));
                }
                if ($out === false) {
                    throw new RuntimeException('A compressed stream could not be decompressed.');
                }
                $data = $out;
                if ($pm instanceof DsDict) {
                    $pred = (int) $r($pm->get('Predictor'));
                    if ($pred >= 10) {
                        $data = self::unpredict($data, max(1, (int) ($r($pm->get('Columns')) ?? 1)), max(1, (int) ($r($pm->get('Colors')) ?? 1)),
                                                max(1, (int) ($r($pm->get('BitsPerComponent')) ?? 8)));
                    }
                }
            } elseif ($name === 'ASCIIHexDecode' || $name === 'AHx') {
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', strstr($data . '>', '>', true));
                $data = (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
            } else {
                throw new RuntimeException('Unsupported PDF filter ' . $name . '.');
            }
        }
        return $data;
    }

    private static function unpredict(string $data, int $cols, int $colors, int $bpc): string
    {
        $bpp = max(1, intdiv($colors * $bpc, 8));
        $row = intdiv($cols * $colors * $bpc + 7, 8);
        $out = '';
        $prev = str_repeat("\0", $row);
        $n = strlen($data);
        for ($p = 0; $p + $row < $n + 1 && $p < $n; $p += $row + 1) {
            $type = ord($data[$p]);
            $cur = substr($data, $p + 1, $row);
            $cur = str_pad($cur, $row, "\0");
            $res = '';
            for ($i = 0; $i < $row; $i++) {
                $x = ord($cur[$i]);
                $a = $i >= $bpp ? ord($res[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                switch ($type) {
                    case 1: $x += $a; break;
                    case 2: $x += $b; break;
                    case 3: $x += intdiv($a + $b, 2); break;
                    case 4:
                        $pp = $a + $b - $c;
                        $pa = abs($pp - $a);
                        $pb = abs($pp - $b);
                        $pc = abs($pp - $c);
                        $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        break;
                }
                $res .= chr($x & 0xff);
            }
            $out .= $res;
            $prev = $res;
        }
        return $out;
    }

    /* ---------------------------------------------------------------- xref */

    private function readXrefChain(): void
    {
        $tail = substr($this->b, -2048);
        $pos = strrpos($tail, 'startxref');
        if ($pos === false) {
            throw new RuntimeException('No startxref.');
        }
        $p = strlen($this->b) - strlen($tail) + $pos + 9;
        $this->skip($p);
        $off = (int) $this->word($p);
        $this->lastXref = $off;
        $seen = [];
        $first = true;
        while ($off > 0 && !isset($seen[$off]) && count($seen) < 500) {
            $seen[$off] = true;
            [$type, $trailer, $nums] = $this->readSection($off);
            if ($first) {
                $this->xrefType = $type;
                $this->trailer = $trailer;
                $first = false;
            }
            $this->sections[] = ['off' => $off, 'nums' => $nums];
            $prev = $trailer->get('Prev');
            $off = is_int($prev) ? $prev : 0;
        }
        if ($first) {
            throw new RuntimeException('No cross-reference section.');
        }
    }

    /** Reads one section; entries already known (from a newer section) are kept. */
    private function readSection(int $off): array
    {
        $p = $off;
        $this->skip($p);
        $nums = [];
        if (substr($this->b, $p, 4) === 'xref') {
            $p += 4;
            while (true) {
                $this->skip($p);
                if (substr($this->b, $p, 7) === 'trailer') {
                    $p += 7;
                    break;
                }
                $start = $this->word($p);
                $this->skip($p);
                $count = $this->word($p);
                if (!ctype_digit($start) || !ctype_digit($count)) {
                    throw new RuntimeException('Bad xref table.');
                }
                $this->skip($p);
                for ($i = 0; $i < (int) $count; $i++) {
                    if (!preg_match('/\G(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->b, $m, 0, $p)) {
                        throw new RuntimeException('Bad xref entry.');
                    }
                    $p += strlen($m[0]);
                    while (isset($this->b[$p]) && self::isWs($this->b[$p])) {
                        $p++;
                    }
                    $num = (int) $start + $i;
                    $nums[] = $num;
                    if (!isset($this->xref[$num]) && $m[3] === 'n') {
                        $this->xref[$num] = ['t' => 1, 'off' => (int) $m[1], 'gen' => (int) $m[2]];
                    } elseif (!isset($this->xref[$num])) {
                        $this->xref[$num] = ['t' => 0];
                    }
                }
            }
            $trailer = $this->value($p);
            if (!$trailer instanceof DsDict) {
                throw new RuntimeException('Bad trailer.');
            }
            $stm = $trailer->get('XRefStm');
            if (is_int($stm) && $stm > 0) {
                try {
                    $this->readSection($stm);
                } catch (Throwable $e) {
                    // a hybrid file's stream half is optional for readers that know the table
                }
            }
            return ['table', $trailer, $nums];
        }
        [$num, , $s] = $this->objectAt($off);
        if (!$s instanceof DsStream || $s->name('Type') !== 'XRef') {
            throw new RuntimeException('No cross-reference at ' . $off . '.');
        }
        $data = self::decode($s, $this);
        $w = array_map('intval', (array) $s->get('W'));
        $size = (int) $s->get('Size');
        $index = $s->get('Index');
        $index = is_array($index) ? array_map('intval', $index) : [0, $size];
        $rowLen = array_sum($w);
        $q = 0;
        for ($k = 0; $k + 1 < count($index); $k += 2) {
            for ($i = 0; $i < $index[$k + 1]; $i++) {
                if ($q + $rowLen > strlen($data)) {
                    break 2;
                }
                $f = [];
                $c = $q;
                foreach ($w as $j => $wid) {
                    $v = 0;
                    for ($x = 0; $x < $wid; $x++) {
                        $v = ($v << 8) | ord($data[$c++]);
                    }
                    $f[$j] = $wid === 0 ? ($j === 0 ? 1 : 0) : $v;
                }
                $q += $rowLen;
                $n = $index[$k] + $i;
                $nums[] = $n;
                if (isset($this->xref[$n])) {
                    continue;
                }
                if ($f[0] === 1) {
                    $this->xref[$n] = ['t' => 1, 'off' => $f[1], 'gen' => $f[2] ?? 0];
                } elseif ($f[0] === 2) {
                    $this->xref[$n] = ['t' => 2, 'stm' => $f[1], 'idx' => $f[2] ?? 0];
                } else {
                    $this->xref[$n] = ['t' => 0];
                }
            }
        }
        if (!isset($this->xref[$num])) {
            $this->xref[$num] = ['t' => 1, 'off' => $off, 'gen' => 0];
        }
        return ['stream', $s, $nums];
    }

    /** Last resort: finds every "n g obj" in the file. The file then needs a clean rewrite before signing. */
    private function rebuildXref(): void
    {
        $this->repaired = true;
        $this->xref = [];
        $this->cache = [];
        $this->sections = [];
        preg_match_all('/(?<![0-9])(\d{1,10})\s+(\d{1,5})\s+obj\b/', $this->b, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $whole) {
            $this->xref[(int) $m[1][$i][0]] = ['t' => 1, 'off' => $whole[1], 'gen' => (int) $m[2][$i][0]];
        }
        $root = null;
        $info = null;
        // The catalog: a trailer dictionary that names it, or the object typed /Catalog.
        if (preg_match_all('/trailer\s*(<<)/', $this->b, $tm, PREG_OFFSET_CAPTURE)) {
            $p = end($tm[1])[1];
            try {
                $t = $this->value($p);
                $root = $t instanceof DsDict ? $t->get('Root') : null;
                $info = $t instanceof DsDict ? $t->get('Info') : null;
            } catch (Throwable $e) {
            }
        }
        foreach ($this->xref as $num => $e) {
            try {
                [, , $v] = $this->objectAt($e['off']);
            } catch (Throwable $ex) {
                unset($this->xref[$num]);
                continue;
            }
            if ($v instanceof DsStream && $v->name('Type') === 'ObjStm') {
                // Their contents become reachable too.
                $n = (int) $v->get('N');
                try {
                    $data = self::decode($v, $this);
                    preg_match_all('/\d+/', substr($data, 0, (int) $v->get('First')), $mm);
                    foreach (array_chunk(array_map('intval', $mm[0]), 2) as $idx => $pair) {
                        if ($idx < $n && !isset($this->xref[$pair[0]])) {
                            $this->xref[$pair[0]] = ['t' => 2, 'stm' => $num, 'idx' => $idx];
                        }
                    }
                } catch (Throwable $ex) {
                }
            }
            if (!$root && $v instanceof DsDict && $v->name('Type') === 'Catalog') {
                $root = new DsRef($num, $e['gen']);
            }
        }
        if (!$root) {
            throw new InvalidArgumentException('This PDF is damaged: its document catalogue cannot be found.');
        }
        $d = ['Root' => $root, 'Size' => (max(array_keys($this->xref) ?: [0]) + 1)];
        if ($info) {
            $d['Info'] = $info;
        }
        $this->trailer = new DsDict($d);
        $this->xrefType = 'table';
        $this->lastXref = 0;
    }

    /* --------------------------------------------------------------- pages */

    public function catalog(): DsDict
    {
        $c = $this->get($this->trailer->get('Root'));
        if (!$c instanceof DsDict) {
            throw new InvalidArgumentException('This PDF has no document catalogue.');
        }
        return $c;
    }

    /**
     * Every page in order: ref, dict, box (the visible area: CropBox within
     * MediaBox, as [x0 y0 x1 y1]) and rotation.
     */
    public function pages(): array
    {
        $out = [];
        $walk = function ($ref, array $inh, int $depth) use (&$walk, &$out) {
            if ($depth > 40 || count($out) > 5000) {
                return;
            }
            $node = $this->get($ref);
            if (!$node instanceof DsDict) {
                return;
            }
            foreach (['MediaBox', 'CropBox', 'Rotate', 'Resources'] as $k) {
                if ($node->get($k) !== null) {
                    $inh[$k] = $node->get($k);
                }
            }
            $type = $node->name('Type');
            $kids = $this->get($node->get('Kids'));
            if ($type === 'Pages' || (is_array($kids) && $type !== 'Page')) {
                foreach ((array) $kids as $k) {
                    $walk($k, $inh, $depth + 1);
                }
                return;
            }
            $mb = array_map(fn($v) => (float) $this->get($v), (array) $this->get($inh['MediaBox'] ?? [0, 0, 612, 792]));
            if (count($mb) !== 4) {
                $mb = [0, 0, 612, 792];
            }
            $cb = $this->get($inh['CropBox'] ?? null);
            $box = is_array($cb) && count($cb) === 4 ? array_map(fn($v) => (float) $this->get($v), $cb) : $mb;
            $box = [min($box[0], $box[2]), min($box[1], $box[3]), max($box[0], $box[2]), max($box[1], $box[3])];
            $rot = ((int) $this->get($inh['Rotate'] ?? 0) % 360 + 360) % 360;
            $out[] = ['ref' => $ref instanceof DsRef ? $ref : null, 'dict' => $node, 'box' => $box, 'rotate' => $rot];
        };
        $walk($this->catalog()->get('Pages'), [], 0);
        if (!$out) {
            throw new InvalidArgumentException('This PDF has no pages.');
        }
        return $out;
    }

    /** The object numbers defined by sections at or after byte $from (what was added after a revision). */
    public function numsSince(int $from): array
    {
        $n = [];
        foreach ($this->sections as $s) {
            if ($s['off'] >= $from) {
                foreach ($s['nums'] as $x) {
                    $n[$x] = true;
                }
            }
        }
        // Objects inside an object stream added later count as added later.
        foreach ($this->xref as $num => $e) {
            if ($e['t'] === 2 && isset($n[$e['stm']])) {
                $n[$num] = true;
            }
        }
        unset($n[0]);
        return array_keys($n);
    }

    /* ======================================================= serializing */

    public static function ser($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            $s = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
            return $s === '-0' || $s === '' ? '0' : $s;
        }
        if ($v instanceof DsName) {
            return '/' . preg_replace_callback('/[^!-~]|[#()<>\[\]{}\/%]/', static fn($m) => sprintf('#%02X', ord($m[0])), $v->n);
        }
        if ($v instanceof DsStr) {
            if ($v->hex || preg_match('/[^\x20-\x7e]/', $v->s)) {
                return '<' . strtoupper(bin2hex($v->s)) . '>';
            }
            return '(' . strtr($v->s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }
        if ($v instanceof DsRef) {
            return $v->num . ' ' . $v->gen . ' R';
        }
        if ($v instanceof DsRaw) {
            return $v->raw;
        }
        if (is_array($v)) {
            return '[' . implode(' ', array_map([self::class, 'ser'], $v)) . ']';
        }
        if ($v instanceof DsStream) {
            $d = $v->d;
            $d['Length'] = strlen($v->data);
            return self::ser(new DsDict($d)) . "\nstream\n" . $v->data . "\nendstream";
        }
        if ($v instanceof DsDict) {
            $h = '<<';
            foreach ($v->d as $k => $x) {
                $h .= self::ser(new DsName((string) $k)) . ' ' . self::ser($x) . ' ';
            }
            return rtrim($h) . '>>';
        }
        throw new RuntimeException('Cannot write this PDF value.');
    }

    /**
     * A clean, whole-file copy (every object rewritten, object streams
     * unpacked, one classic xref). Used only for a damaged upload, before
     * anything is signed: it changes the bytes, never the content.
     */
    public function rewrite(): string
    {
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        ksort($this->xref);
        foreach ($this->xref as $num => $e) {
            if ($e['t'] === 0 || $num === 0) {
                continue;
            }
            try {
                $v = $this->get(new DsRef($num));
            } catch (Throwable $ex) {
                continue;
            }
            if ($v instanceof DsStream && in_array($v->name('Type'), ['XRef', 'ObjStm'], true)) {
                continue;
            }
            $offs[$num] = strlen($out);
            $out .= $num . " 0 obj\n" . self::ser($v) . "\nendobj\n";
        }
        $max = $offs ? max(array_keys($offs)) : 0;
        $x = strlen($out);
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offs[$i]) ? sprintf("%010d 00000 n \n", $offs[$i]) : "0000000000 65535 f \n";
        }
        $t = ['Size' => $max + 1, 'Root' => $this->trailer->get('Root')];
        if ($this->trailer->get('Info')) {
            $t['Info'] = $this->trailer->get('Info');
        }
        return $out . 'trailer' . "\n" . self::ser(new DsDict($t)) . "\nstartxref\n" . $x . "\n%%EOF\n";
    }
}

/**
 * One incremental update: objects to add or replace, written after the
 * original bytes with their own cross-reference section.
 */
final class DsPdfUpdate
{
    private array $objs = [];        // num => [gen, value]
    private int $next;
    public ?int $sigNum = null;
    private array $fonts = [];
    private array $imgCache = [];

    public function __construct(public DsPdf $pdf)
    {
        if ($pdf->repaired) {
            throw new InvalidArgumentException('This PDF must be rebuilt before it can be signed.');
        }
        $size = (int) $pdf->trailer->get('Size');
        $max = $pdf->xref ? max(array_keys($pdf->xref)) + 1 : 1;
        $this->next = max($size, $max, 1);
    }

    public function add($v): DsRef
    {
        $n = $this->next++;
        $this->objs[$n] = [0, $v];
        return new DsRef($n);
    }

    public function set(DsRef $r, $v): void
    {
        $this->objs[$r->num] = [$r->gen, $v];
    }

    /** The latest value of an object: as changed in this update, else as in the file. */
    public function cur($v)
    {
        if ($v instanceof DsRef && isset($this->objs[$v->num])) {
            return $this->objs[$v->num][1];
        }
        return $this->pdf->get($v);
    }

    /** The shared Helvetica for appearances. */
    public function font(bool $bold = false): DsRef
    {
        $k = $bold ? 'b' : 'r';
        return $this->fonts[$k] ??= $this->add(new DsDict(['Type' => new DsName('Font'), 'Subtype' => new DsName('Type1'),
            'BaseFont' => new DsName($bold ? 'Helvetica-Bold' : 'Helvetica'), 'Encoding' => new DsName('WinAnsiEncoding')]));
    }

    /** Adds an annotation to a page's /Annots (page index from 0). */
    public function addAnnot(int $pageIdx, DsRef $annot): DsRef
    {
        $pages = $this->pdf->pages();
        $pg = $pages[$pageIdx] ?? end($pages);
        if (!$pg['ref']) {
            throw new InvalidArgumentException('This PDF\'s page cannot be annotated.');
        }
        $page = $this->cur($pg['ref']);
        $annots = $page->get('Annots');
        if ($annots instanceof DsRef) {
            $arr = (array) $this->cur($annots);
            $arr[] = $annot;
            $this->set($annots, array_values($arr));
        } else {
            $arr = is_array($annots) ? $annots : [];
            $arr[] = $annot;
            $d = $page->d;
            $d['Annots'] = $arr;
            $this->set($pg['ref'], new DsDict($d));
        }
        return $pg['ref'];
    }

    /** Puts a field on the AcroForm (making one when the file has none) and sets /SigFlags 3. */
    public function addField(DsRef $field): void
    {
        $rootRef = $this->pdf->trailer->get('Root');
        $cat = $this->cur($rootRef);
        $af = $cat->get('AcroForm');
        if ($af instanceof DsRef) {
            $form = $this->cur($af);
            $form = $form instanceof DsDict ? $form : new DsDict();
            $this->set($af, $this->formWith($form, $field));
            return;
        }
        $form = $af instanceof DsDict ? $af : new DsDict();
        $d = $cat->d;
        $d['AcroForm'] = $this->add($this->formWith($form, $field));
        $this->set($rootRef, new DsDict($d));
    }

    private function formWith(DsDict $form, DsRef $field): DsDict
    {
        $d = $form->d;
        $fields = $d['Fields'] ?? [];
        if ($fields instanceof DsRef) {
            $arr = (array) $this->cur($fields);
            $arr[] = $field;
            $this->set($fields, array_values($arr));
        } else {
            $fields = is_array($fields) ? $fields : [];
            $fields[] = $field;
            $d['Fields'] = $fields;
        }
        $d['SigFlags'] = 3;
        return new DsDict($d);
    }

    /** A form XObject for an annotation's appearance: lines of text, an optional image, a frame. */
    public function appearance(float $w, float $h, array $lines, ?array $image = null, int $rotate = 0, string $accent = '0.11 0.37 0.82', string $style = 'box'): DsRef
    {
        $c = "q\n";
        $res = ['Font' => new DsDict(['F1' => $this->font(false), 'F2' => $this->font(true)])];
        if ($style === 'text') {
            // A filled-in field: the words alone, as if typed on the page.
            [$text, , $bold] = ($lines[0] ?? ['']) + ['', 0, false];
            $size = max(5.0, min(12.0, $h * 0.62));
            $text = self::fitText((string) $text, $w - 2, $size);
            $c .= 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::f($size) . ' Tf 0.08 0.1 0.16 rg 1 ' . self::f(($h - $size * 0.72) / 2) . ' Td ' . self::pdfText((string) $text) . " Tj ET\nQ\n";
            return $this->formXObject($w, $h, $c, $res, $rotate);
        }
        if ($style === 'ink') {
            // A drawn mark: the ink itself on the page, a small caption beneath it.
            $cap = $lines ? 6.5 : 0;
            if ($image) {
                $ih = max(4, $h - $cap - 2);
                $iw = min($w, $ih * $image['w'] / max(1, $image['h']));
                $ih = $iw * $image['h'] / max(1, $image['w']);
                $c .= 'q ' . self::f($iw) . ' 0 0 ' . self::f($ih) . ' 0 ' . self::f($cap + ($h - $cap - $ih) / 2) . " cm /Im1 Do Q\n";
                $res['XObject'] = new DsDict(['Im1' => $image['ref']]);
            }
            if ($lines) {
                $c .= 'BT /F1 5.2 Tf 0.35 0.38 0.45 rg 0 1.2 Td ' . self::pdfText(self::fitText(implode(' · ', array_map(static fn($l) => $l[0], $lines)), $w, 5.2)) . " Tj ET\n";
            }
            return $this->formXObject($w, $h, $c . "Q\n", $res, $rotate);
        }
        $c .= "1 1 1 rg 0 0 " . self::f($w) . ' ' . self::f($h) . " re f\n";
        $c .= $accent . " RG 0.8 w 0.4 0.4 " . self::f($w - 0.8) . ' ' . self::f($h - 0.8) . " re S\n";
        $c .= $accent . " rg 0 0 3 " . self::f($h) . " re f\n";
        $textX = 9.0;
        if ($image) {
            $ih = $h - 10;
            $iw = min($w * 0.45, $ih * $image['w'] / max(1, $image['h']));
            $ih = $iw * $image['h'] / max(1, $image['w']);
            $c .= "q " . self::f($iw) . " 0 0 " . self::f($ih) . ' 7 ' . self::f(($h - $ih) / 2) . " cm /Im1 Do Q\n";
            $res['XObject'] = new DsDict(['Im1' => $image['ref']]);
            $textX = 7 + $iw + 6;
        }
        $y = $h - 6;
        foreach ($lines as $ln) {
            [$text, $size, $bold] = $ln + ['', 7.0, false];
            $y -= $size + 2;
            if ($y < 2) {
                break;
            }
            $avail = max(10.0, $w - $textX - 4);
            $text = self::fitText($text, $avail, (float) $size);
            $c .= 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::f((float) $size) . ' Tf ' . ($bold ? '0.1 0.13 0.19' : '0.27 0.3 0.36') . ' rg '
                . self::f($textX) . ' ' . self::f($y) . ' Td ' . self::pdfText($text) . " Tj ET\n";
        }
        $c .= "Q\n";
        return $this->formXObject($w, $h, $c, $res, $rotate);
    }

    private function formXObject(float $w, float $h, string $c, array $res, int $rotate): DsRef
    {
        $d = ['Type' => new DsName('XObject'), 'Subtype' => new DsName('Form'), 'BBox' => [0, 0, round($w, 2), round($h, 2)], 'Resources' => new DsDict($res)];
        if ($rotate) {
            $d['Matrix'] = match ($rotate) {
                90 => [0, 1, -1, 0, round($h, 2), 0],
                180 => [-1, 0, 0, -1, round($w, 2), round($h, 2)],
                270 => [0, -1, 1, 0, 0, round($w, 2)],
                default => [1, 0, 0, 1, 0, 0],
            };
        }
        return $this->add(new DsStream($d, $c));
    }

    /** An image XObject (with its transparency) from PNG/JPEG bytes. Null when GD is missing. */
    public function image(string $bytes): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w > 1200) {                         // a signature pad does not need more
            $nh = (int) round($h * 1200 / $w);
            $sc = imagecreatetruecolor(1200, $nh);
            imagealphablending($sc, false);
            imagesavealpha($sc, true);
            imagecopyresampled($sc, $im, 0, 0, 0, 0, 1200, $nh, $w, $h);
            imagedestroy($im);
            $im = $sc;
            $w = 1200;
            $h = $nh;
        }
        $rgb = '';
        $alpha = '';
        $hasAlpha = false;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 0x7f;
                $rgb .= chr(($c >> 16) & 0xff) . chr(($c >> 8) & 0xff) . chr($c & 0xff);
                $av = 255 - (int) round($a * 255 / 127);
                $alpha .= chr($av);
                if ($av !== 255) {
                    $hasAlpha = true;
                }
            }
        }
        imagedestroy($im);
        $d = ['Type' => new DsName('XObject'), 'Subtype' => new DsName('Image'), 'Width' => $w, 'Height' => $h,
              'ColorSpace' => new DsName('DeviceRGB'), 'BitsPerComponent' => 8, 'Filter' => new DsName('FlateDecode')];
        if ($hasAlpha) {
            $d['SMask'] = $this->add(new DsStream(['Type' => new DsName('XObject'), 'Subtype' => new DsName('Image'), 'Width' => $w, 'Height' => $h,
                'ColorSpace' => new DsName('DeviceGray'), 'BitsPerComponent' => 8, 'Filter' => new DsName('FlateDecode')], gzcompress($alpha, 9)));
        }
        return ['ref' => $this->add(new DsStream($d, gzcompress($rgb, 9))), 'w' => $w, 'h' => $h];
    }

    /**
     * Where a box goes, in PDF points, from a place chosen on screen:
     * page index, and the box's top-left and size as fractions of the
     * page as displayed (rotation applied). Returns [rect, rotate].
     */
    public function rectFor(int $pageIdx, float $u, float $v, float $wFrac, float $hFrac): array
    {
        $pages = $this->pdf->pages();
        $pg = $pages[$pageIdx] ?? end($pages);
        [$x0, $y0, $x1, $y1] = $pg['box'];
        $W = $x1 - $x0;
        $H = $y1 - $y0;
        $rot = $pg['rotate'];
        $pt = static function (float $uu, float $vv) use ($rot, $x0, $y0, $x1, $y1, $W, $H): array {
            return match ($rot) {
                90 => [$x0 + $vv * $W, $y0 + $uu * $H],
                180 => [$x1 - $uu * $W, $y0 + $vv * $H],
                270 => [$x1 - $vv * $W, $y1 - $uu * $H],
                default => [$x0 + $uu * $W, $y1 - $vv * $H],
            };
        };
        [$ax, $ay] = $pt($u, $v);
        [$bx, $by] = $pt($u + $wFrac, $v + $hFrac);
        return [[round(min($ax, $bx), 2), round(min($ay, $by), 2), round(max($ax, $bx), 2), round(max($ay, $by), 2)], $rot];
    }

    /** The display size of a page in points (rotation applied): [w, h]. */
    public function displaySize(int $pageIdx): array
    {
        $pages = $this->pdf->pages();
        $pg = $pages[$pageIdx] ?? end($pages);
        $W = $pg['box'][2] - $pg['box'][0];
        $H = $pg['box'][3] - $pg['box'][1];
        return in_array($pg['rotate'], [90, 270], true) ? [$H, $W] : [$W, $H];
    }

    /** A visible stamp (an approval or a drawn signature) on a page. */
    public function stamp(int $pageIdx, array $rect, int $rot, array $lines, ?string $png, string $name, string $accent = '0.11 0.37 0.82', string $style = 'box'): void
    {
        $w = $rect[2] - $rect[0];
        $h = $rect[3] - $rect[1];
        [$aw, $ah] = in_array($rot, [90, 270], true) ? [$h, $w] : [$w, $h];
        $img = $png !== null ? ($this->imgCache[md5($png)] ??= $this->image($png)) : null;
        $ap = $this->appearance($aw, $ah, $lines, $img, $rot, $accent, $style);
        $pages = $this->pdf->pages();
        $pg = $pages[$pageIdx] ?? end($pages);
        $annot = $this->add(new DsDict(['Type' => new DsName('Annot'), 'Subtype' => new DsName('Stamp'), 'Rect' => $rect, 'F' => 4 | 128,
            'NM' => new DsStr($name), 'Contents' => new DsStr(self::plain(implode(' — ', array_map(static fn($l) => $l[0], $lines)))),
            'M' => new DsStr(self::pdfDate()), 'P' => $pg['ref'], 'AP' => new DsDict(['N' => $ap])]));
        $this->addAnnot(min(max(0, $pageIdx), count($pages) - 1), $annot);
    }

    /**
     * A signature field with an empty signature: the dictionary carries a
     * fixed-width /ByteRange and a zero-filled /Contents of $reserve bytes,
     * filled in once the signature arrives. Visible when $rect is given.
     */
    public function signatureField(int $pageIdx, ?array $rect, int $rot, array $lines, string $fieldName, array $info, int $reserve = 16384,
                                   string $subFilter = 'adbe.pkcs7.detached'): void
    {
        $sig = ['Type' => new DsName('Sig'), 'Filter' => new DsName('Adobe.PPKLite'), 'SubFilter' => new DsName($subFilter),
                'ByteRange' => new DsRaw('[0 ' . str_repeat(' ', 10) . ' ' . str_repeat(' ', 10) . ' ' . str_repeat(' ', 10) . ']'),
                'Contents' => new DsRaw('<' . str_repeat('0', $reserve * 2) . '>'),
                'M' => new DsStr(self::pdfDate())];
        foreach (['Name', 'Reason', 'Location', 'ContactInfo'] as $k) {
            if (!empty($info[$k])) {
                $sig[$k] = new DsStr(self::plain((string) $info[$k]));
            }
        }
        $sigRef = $this->add(new DsDict($sig));
        $this->sigNum = $sigRef->num;
        $pages = $this->pdf->pages();
        $pg = $pages[$pageIdx] ?? end($pages);
        $wd = ['Type' => new DsName('Annot'), 'Subtype' => new DsName('Widget'), 'FT' => new DsName('Sig'), 'T' => new DsStr($fieldName),
               'V' => $sigRef, 'F' => 132, 'P' => $pg['ref'], 'Rect' => $rect ?? [0, 0, 0, 0]];
        if ($rect) {
            $w = $rect[2] - $rect[0];
            $h = $rect[3] - $rect[1];
            [$aw, $ah] = in_array($rot, [90, 270], true) ? [$h, $w] : [$w, $h];
            $wd['AP'] = new DsDict(['N' => $this->appearance($aw, $ah, $lines, null, $rot, '0.07 0.5 0.36')]);
        }
        $field = $this->add(new DsDict($wd));
        $this->addAnnot(min(max(0, $pageIdx), count($pages) - 1), $field);
        $this->addField($field);
    }

    /**
     * An empty signature field at a chosen place, for a signatory to sign in
     * their own PDF software (a DSC in Acrobat: "click to sign").
     */
    public function emptySignatureField(int $pageIdx, array $rect, int $rot, string $fieldName, string $label): void
    {
        $pages = $this->pdf->pages();
        $pg = $pages[$pageIdx] ?? end($pages);
        $w = $rect[2] - $rect[0];
        $h = $rect[3] - $rect[1];
        [$aw, $ah] = in_array($rot, [90, 270], true) ? [$h, $w] : [$w, $h];
        $field = $this->add(new DsDict(['Type' => new DsName('Annot'), 'Subtype' => new DsName('Widget'), 'FT' => new DsName('Sig'),
            'T' => new DsStr($fieldName), 'F' => 4, 'P' => $pg['ref'], 'Rect' => $rect,
            'AP' => new DsDict(['N' => $this->appearance($aw, $ah, [[$label, 7, true], ['Digital signature (DSC)', 6]], null, $rot, '0.07 0.5 0.36')])]));
        $this->addAnnot(min(max(0, $pageIdx), count($pages) - 1), $field);
        $this->addField($field);
    }

    /**
     * The updated file. With a signature field, also where its placeholder
     * sits: ['pdf', 'range' => [0, a, b, c], 'hex_at', 'hex_len'] with the
     * /ByteRange already written in.
     */
    public function build(): array
    {
        $out = $this->pdf->b;
        if (!str_ends_with($out, "\n") && !str_ends_with($out, "\r")) {
            $out .= "\n";
        }
        ksort($this->objs);
        $offs = [];
        $sigAt = null;
        foreach ($this->objs as $num => [$gen, $v]) {
            $offs[$num] = [strlen($out), $gen];
            $body = DsPdf::ser($v);
            if ($num === $this->sigNum) {
                $sigAt = strlen($out) + strlen($num . ' ' . $gen . " obj\n");
                $sigBody = $body;
            }
            $out .= $num . ' ' . $gen . " obj\n" . $body . "\nendobj\n";
        }
        $root = $this->pdf->trailer->get('Root');
        $info = $this->pdf->trailer->get('Info');
        $id = $this->pdf->trailer->get('ID');
        $newId = new DsStr(random_bytes(16), true);
        $ids = is_array($id) && isset($id[0]) ? [$id[0], $newId] : [$newId, $newId];
        $prev = $this->pdf->lastXref;
        if ($this->pdf->xrefType === 'stream') {
            $xnum = $this->next++;
            $xoff = strlen($out);
            $offs[$xnum] = [$xoff, 0];
            ksort($offs);
            [$index, $rows] = self::xrefRows($offs, true);
            $d = ['Type' => new DsName('XRef'), 'Size' => $this->next, 'W' => [1, 4, 2], 'Index' => $index, 'Root' => $root, 'ID' => $ids];
            if ($info) {
                $d['Info'] = $info;
            }
            if ($prev > 0) {
                $d['Prev'] = $prev;
            }
            $out .= $xnum . " 0 obj\n" . DsPdf::ser(new DsStream($d, $rows)) . "\nendobj\n";
            $out .= "startxref\n" . $xoff . "\n%%EOF\n";
        } else {
            $xoff = strlen($out);
            [$index, $rows] = self::xrefRows($offs, false);
            $out .= "xref\n" . $rows;
            $t = ['Size' => $this->next, 'Root' => $root, 'ID' => $ids];
            if ($info) {
                $t['Info'] = $info;
            }
            if ($prev > 0) {
                $t['Prev'] = $prev;
            }
            $out .= "trailer\n" . DsPdf::ser(new DsDict($t)) . "\nstartxref\n" . $xoff . "\n%%EOF\n";
        }
        $res = ['pdf' => $out];
        if ($sigAt !== null) {
            $br = strpos($out, '/ByteRange [0 ', $sigAt);
            $co = strpos($out, '/Contents <', $sigAt);
            if ($br === false || $co === false) {
                throw new RuntimeException('Signature placeholder not found.');
            }
            $hexAt = $co + 10;                                  // at '<'
            $hexEnd = strpos($out, '>', $hexAt) + 1;            // one past '>'
            $range = [0, $hexAt, $hexEnd, strlen($out) - $hexEnd];
            $txt = '[0 ' . str_pad((string) $range[1], 10) . ' ' . str_pad((string) $range[2], 10) . ' ' . str_pad((string) $range[3], 10) . ']';
            $out = substr_replace($out, $txt, $br + 11, strlen($txt));
            $res = ['pdf' => $out, 'range' => $range, 'hex_at' => $hexAt, 'hex_len' => $hexEnd - $hexAt - 2];
        }
        return $res;
    }

    private static function xrefRows(array $offs, bool $binary): array
    {
        $nums = array_keys($offs);
        sort($nums);
        $groups = [];
        foreach ($nums as $n) {
            $last = count($groups) - 1;
            if ($last >= 0 && end($groups[$last]) === $n - 1) {
                $groups[$last][] = $n;
            } else {
                $groups[] = [$n];
            }
        }
        $index = [];
        $rows = '';
        foreach ($groups as $g) {
            $index[] = $g[0];
            $index[] = count($g);
            if (!$binary) {
                $rows .= $g[0] . ' ' . count($g) . "\n";
            }
            foreach ($g as $n) {
                [$off, $gen] = $offs[$n];
                $rows .= $binary ? chr(1) . pack('N', $off) . pack('n', $gen) : sprintf("%010d %05d n \n", $off, $gen);
            }
        }
        return [$index, $rows];
    }

    /** Puts a DER signature into a built file's placeholder. */
    public static function embed(array $built, string $der): string
    {
        $hex = strtoupper(bin2hex($der));
        if (strlen($hex) > $built['hex_len']) {
            throw new RuntimeException('The signature (' . strlen($der) . ' bytes) is larger than the space reserved for it.');
        }
        return substr_replace($built['pdf'], str_pad($hex, $built['hex_len'], '0'), $built['hex_at'] + 1, $built['hex_len']);
    }

    /** The bytes a /ByteRange covers. */
    public static function covered(string $pdf, array $range): string
    {
        return substr($pdf, $range[0], $range[1]) . substr($pdf, $range[2], $range[3]);
    }

    /* ------------------------------------------------------------ text */

    public static function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** Text as the built-in fonts carry it (Windows-1252). */
    public static function plain(string $s): string
    {
        $s = strtr($s, ['₹' => 'Rs.', '—' => '-', '–' => '-', '·' => '-', '“' => '"', '”' => '"', '‘' => "'", '’' => "'"]);
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $c === false ? preg_replace('/[^\x20-\x7e]/', '?', $s) : $c;
    }

    private static function pdfText(string $s): string
    {
        return '(' . strtr(self::plain($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']) . ')';
    }

    private static function fitText(string $s, float $maxW, float $size): string
    {
        $limit = (int) floor($maxW / ($size * 0.5));
        return mb_strlen($s) > $limit ? mb_substr($s, 0, max(1, $limit - 1)) . '…' : $s;
    }

    public static function pdfDate(?int $t = null): string
    {
        $t = $t ?? time();
        $o = date('O', $t);
        return 'D:' . date('YmdHis', $t) . substr($o, 0, 3) . "'" . substr($o, 3, 2) . "'";
    }
}
