<?php
/* Laporan Kegiatan – teks berformat dari penyunting (HTML terbatas).
   bl_rt_parse(): HTML bebas (hasil penyunting/tempel dari Word) → blok datar yang aman:
     ['t'=>'p',  'al'=>l|c|r|j, 'ind'=>0..6, 'r'=>runs]
     ['t'=>'li', 'k'=>ol|ul, 'lv'=>0..3, 'g'=>grup daftar, 'al', 'r'=>runs]
     ['t'=>'tbl','rows'=>[[['al','r'=>runs,'h'=>bool], ...], ...]]
   run: ['x'=>teks, 'b','i','u'=>bool, 's'=>ukuran pt|0] atau ['br'=>1]
   bl_rt_html(): blok → HTML baku yang disimpan & ditampilkan kembali di penyunting. */

const BL_RT_UKURAN = [10, 11, 12, 14, 16];
const BL_RT_DASAR = 12;
const BL_RT_MAX = 300000;   // panjang HTML per bagian

function bl_rt_size(float $pt): int
{
    $best = BL_RT_DASAR; $d = 99;
    foreach (BL_RT_UKURAN as $s) if (abs($s - $pt) < $d) { $d = abs($s - $pt); $best = $s; }
    return $best === BL_RT_DASAR ? 0 : $best;
}
/** gaya CSS sebaris → [properti => nilai] */
function bl_rt_css(string $style): array
{
    $o = [];
    foreach (explode(';', $style) as $p) {
        $kv = explode(':', $p, 2);
        if (count($kv) === 2) $o[strtolower(trim($kv[0]))] = strtolower(trim($kv[1]));
    }
    return $o;
}
function bl_rt_len(string $v): float
{
    if (!preg_match('/^(-?[\d.]+)\s*(px|pt|em|rem|cm|mm|in)?/', $v, $m)) return 0;
    $n = (float)$m[1];
    return match ($m[2] ?? 'px') { 'pt' => $n * 4 / 3, 'em', 'rem' => $n * 16, 'cm' => $n * 37.8, 'mm' => $n * 3.78, 'in' => $n * 96, default => $n };
}
function bl_rt_align(array $css, ?DOMElement $el = null): ?string
{
    $a = $css['text-align'] ?? ($el ? strtolower($el->getAttribute('align')) : '');
    return ['left' => 'l', 'start' => 'l', 'center' => 'c', 'right' => 'r', 'end' => 'r', 'justify' => 'j'][$a] ?? null;
}

final class BlRtParser
{
    public array $out = [];
    private array $runs = [];
    private ?array $para = null;   // paragraf yang sedang diisi: ['t'=>'p'|'li', ...]
    private int $grp = 0;

    public function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '') return [];
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><html><body><div id="rt-akar">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $dom->getElementById('rt-akar') ?? $dom->getElementsByTagName('body')->item(0);
        if (!$root) return [];
        $this->blocks($root, 0, null, null, ['b' => false, 'i' => false, 'u' => false, 's' => 0]);
        $this->flush();
        return $this->out;
    }
    private function flush(): void
    {
        if ($this->para === null) return;
        $r = self::trimRuns($this->runs);
        $p = $this->para; unset($p['keep']);
        if ($r || $p['t'] === 'li' || ($this->para['keep'] ?? false)) $this->out[] = $p + ['r' => $r];
        $this->para = null; $this->runs = [];
    }
    /** buang spasi di awal/akhir paragraf dan <br> berlebih di ujung */
    public static function trimRuns(array $r): array
    {
        while ($r && isset(end($r)['br'])) array_pop($r);
        while ($r && isset($r[0]['br'])) array_shift($r);
        $out = [];
        foreach ($r as $x) {
            if (isset($x['br'])) { $out[] = $x; continue; }
            if ($x['x'] === '') continue;
            $n = count($out);
            if ($n && !isset($out[$n - 1]['br']) && $out[$n - 1]['b'] === $x['b'] && $out[$n - 1]['i'] === $x['i'] && $out[$n - 1]['u'] === $x['u'] && $out[$n - 1]['s'] === $x['s']) $out[$n - 1]['x'] .= $x['x'];
            else $out[] = $x;
        }
        // rapikan spasi ganda antar-run dan di tepi baris
        $prevSpace = true;
        foreach ($out as $i => $x) {
            if (isset($x['br'])) { $prevSpace = true; if ($i > 0 && !isset($out[$i - 1]['br'])) $out[$i - 1]['x'] = rtrim($out[$i - 1]['x'], ' '); continue; }
            if ($prevSpace) $x['x'] = ltrim($x['x'], ' ');
            $out[$i] = $x;
            if ($x['x'] !== '') $prevSpace = str_ends_with($x['x'], ' ');
        }
        $n = count($out);
        if ($n && !isset($out[$n - 1]['br'])) $out[$n - 1]['x'] = rtrim($out[$n - 1]['x'], ' ');
        return array_values(array_filter($out, fn($x) => isset($x['br']) || $x['x'] !== ''));
    }
    private function startPara(array $p): void { $this->flush(); $this->para = $p; $this->runs = []; }
    private function ensurePara(int $ind, ?string $al): void { if ($this->para === null) $this->para = ['t' => 'p', 'al' => $al ?? 'j', 'ind' => $ind]; }

    /** $list = ['k'=>ol|ul, 'lv'=>n] bila sedang di dalam daftar */
    private function blocks(DOMNode $node, int $ind, ?string $al, ?array $list, array $st): void
    {
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMText) {
                $txt = preg_replace('/[\s\x{00A0}]+/u', ' ', $c->data);
                if (trim($txt) === '' && $this->para === null) continue;
                $this->ensurePara($ind, $al);
                $this->runs[] = ['x' => $txt] + $st;
                continue;
            }
            if (!$c instanceof DOMElement) continue;
            $tag = strtolower($c->tagName);
            if (in_array($tag, ['script', 'style', 'head', 'title', 'meta', 'link', 'object', 'iframe', 'svg', 'math', 'img', 'video', 'audio', 'canvas', 'input', 'select', 'textarea', 'button', 'template'], true)) continue;
            $css = bl_rt_css($c->getAttribute('style'));
            if ($tag === 'br') { $this->ensurePara($ind, $al); $this->runs[] = ['br' => 1]; continue; }
            if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'section', 'article', 'header', 'footer', 'center', 'address', 'figure', 'figcaption', 'dd', 'dt', 'dl', 'blockquote', 'body', 'html', 'main', 'aside', 'nav'], true)) {
                $add = $tag === 'blockquote' ? 1 : (int)round(max(bl_rt_len($css['margin-left'] ?? '0'), bl_rt_len($css['padding-left'] ?? '0')) / 40);
                $nind = min(6, $ind + max(0, $add));
                $nal = bl_rt_align($css, $c) ?? ($tag === 'center' ? 'c' : $al);
                $nst = self::inlineStyle($c, $css, $st);
                if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) $nst['b'] = true;
                if ($list !== null && $this->para !== null && $this->para['t'] === 'li' && !$this->runs) {
                    // <li><p>…</p></li>: isi paragraf pertama menjadi isi butir
                    if ($nal) $this->para['al'] = $nal;
                    $this->blocks($c, $nind, $nal, $list, $nst);
                    continue;
                }
                $blok = false;
                foreach ($c->childNodes as $cc) if ($cc instanceof DOMElement && strtolower($cc->tagName) !== 'br' && !in_array(strtolower($cc->tagName), ['b', 'strong', 'i', 'em', 'u', 'span', 'font', 'a', 'sub', 'sup', 'small', 'big', 'mark', 's', 'strike', 'code'], true)) $blok = true;
                $this->startPara(['t' => 'p', 'al' => $nal ?? 'j', 'ind' => $nind, 'keep' => !$blok]);
                $this->blocks($c, $nind, $nal, null, $nst);
                $this->flush();
                continue;
            }
            if ($tag === 'ol' || $tag === 'ul') {
                $this->flush();
                if ($list === null) $this->grp++;
                $lv = $list === null ? 0 : min(3, $list['lv'] + 1);
                $this->blocks($c, $ind, $al, ['k' => $tag, 'lv' => $lv], $st);
                $this->flush();
                continue;
            }
            if ($tag === 'li') {
                $lst = $list ?? ['k' => 'ul', 'lv' => 0];
                if ($list === null) $this->grp++;
                $this->startPara(['t' => 'li', 'k' => $lst['k'], 'lv' => $lst['lv'], 'g' => $this->grp, 'al' => bl_rt_align($css, $c) ?? 'j']);
                $this->blocks($c, $ind, $al, $lst, self::inlineStyle($c, $css, $st));
                $this->flush();
                continue;
            }
            if ($tag === 'table') {
                $this->flush();
                $rows = [];
                foreach ($c->getElementsByTagName('tr') as $tr) {
                    // hanya baris milik tabel ini (bukan tabel bersarang)
                    $own = $tr->parentNode; while ($own && !($own instanceof DOMElement && strtolower($own->tagName) === 'table')) $own = $own->parentNode;
                    if ($own !== $c) continue;
                    $cells = [];
                    foreach ($tr->childNodes as $td) {
                        if (!$td instanceof DOMElement || !in_array(strtolower($td->tagName), ['td', 'th'], true)) continue;
                        $p = new BlRtParser();
                        $p->blocks($td, 0, null, null, ['b' => false, 'i' => false, 'u' => false, 's' => 0]);
                        $p->flush();
                        $r = [];
                        foreach ($p->out as $bi => $b) {
                            if ($bi) $r[] = ['br' => 1];
                            if ($b['t'] === 'tbl') continue;
                            if ($b['t'] === 'li') $r[] = ['x' => ($b['k'] === 'ol' ? '' : '• '), 'b' => false, 'i' => false, 'u' => false, 's' => 0];
                            array_push($r, ...$b['r']);
                        }
                        $tcss = bl_rt_css($td->getAttribute('style'));
                        $cells[] = ['h' => strtolower($td->tagName) === 'th', 'al' => bl_rt_align($tcss, $td) ?? 'l', 'r' => self::trimRuns($r)];
                        if (count($cells) >= 20) break;
                    }
                    if ($cells) $rows[] = $cells;
                    if (count($rows) >= 500) break;
                }
                if ($rows) {
                    $n = max(array_map('count', $rows));
                    foreach ($rows as &$row) while (count($row) < $n) $row[] = ['h' => false, 'al' => 'l', 'r' => []];
                    unset($row);
                    $this->out[] = ['t' => 'tbl', 'rows' => $rows];
                }
                continue;
            }
            if (in_array($tag, ['thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col'], true)) { $this->blocks($c, $ind, $al, $list, $st); continue; }
            // elemen sebaris (b, i, u, span, font, a, sup, …)
            $this->blocks($c, $ind, $al, $list, self::inlineStyle($c, $css, $st));
        }
    }
    private static function inlineStyle(DOMElement $c, array $css, array $st): array
    {
        $st += ['b' => false, 'i' => false, 'u' => false, 's' => 0];
        $tag = strtolower($c->tagName);
        if (in_array($tag, ['b', 'strong'], true)) $st['b'] = true;
        if (in_array($tag, ['i', 'em', 'cite', 'var'], true)) $st['i'] = true;
        if ($tag === 'u' || $tag === 'ins') $st['u'] = true;
        if (isset($css['font-weight'])) { $fw = $css['font-weight']; $st['b'] = $fw === 'bold' || $fw === 'bolder' || (ctype_digit($fw) && (int)$fw >= 600) ? true : ($fw === 'normal' || (ctype_digit($fw) && (int)$fw < 600) ? false : $st['b']); }
        if (isset($css['font-style'])) $st['i'] = $css['font-style'] === 'italic' || $css['font-style'] === 'oblique';
        if (isset($css['text-decoration']) || isset($css['text-decoration-line'])) $st['u'] = str_contains(($css['text-decoration'] ?? '') . ($css['text-decoration-line'] ?? ''), 'underline');
        if (isset($css['font-size'])) { $px = bl_rt_len($css['font-size']); if ($px > 0) $st['s'] = bl_rt_size($px * 0.75); }
        if ($tag === 'font' && $c->hasAttribute('size')) $st['s'] = bl_rt_size([1 => 8, 2 => 10, 3 => 12, 4 => 14, 5 => 18, 6 => 24, 7 => 36][max(1, min(7, (int)$c->getAttribute('size')))]);
        return $st;
    }
}

function bl_rt_parse(string $html): array { return (new BlRtParser())->parse($html); }

function bl_rt_runs_html(array $runs): string
{
    $h = '';
    foreach ($runs as $x) {
        if (isset($x['br'])) { $h .= '<br>'; continue; }
        $s = htmlspecialchars($x['x'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($x['s']) $s = '<span style="font-size:' . (int)$x['s'] . 'pt">' . $s . '</span>';
        if ($x['u']) $s = '<u>' . $s . '</u>';
        if ($x['i']) $s = '<i>' . $s . '</i>';
        if ($x['b']) $s = '<b>' . $s . '</b>';
        $h .= $s;
    }
    return $h;
}
function bl_rt_style(string $al, int $ind, string $def = 'j'): string
{
    $s = [];
    if ($al !== $def) $s[] = 'text-align:' . ['l' => 'left', 'c' => 'center', 'r' => 'right', 'j' => 'justify'][$al];
    if ($ind > 0) $s[] = 'margin-left:' . ($ind * 40) . 'px';
    return $s ? ' style="' . implode(';', $s) . '"' : '';
}
function bl_rt_html(array $blocks): string
{
    $h = ''; $stack = [];   // daftar terbuka: [k, lv]; butir terakhir setiap tingkat masih terbuka
    $closeAll = function () use (&$h, &$stack) { while ($stack) $h .= '</li></' . array_pop($stack)[0] . '>'; };
    $lastG = null;
    foreach ($blocks as $b) {
        if ($b['t'] !== 'li') { $closeAll(); $lastG = null; }
        if ($b['t'] === 'p') { $h .= '<p' . bl_rt_style($b['al'], $b['ind']) . '>' . ($b['r'] ? bl_rt_runs_html($b['r']) : '<br>') . '</p>'; continue; }
        if ($b['t'] === 'tbl') {
            $h .= '<table>';
            foreach ($b['rows'] as $row) {
                $h .= '<tr>';
                foreach ($row as $c) { $tg = $c['h'] ? 'th' : 'td'; $h .= '<' . $tg . bl_rt_style($c['al'], 0, 'l') . '>' . ($c['r'] ? bl_rt_runs_html($c['r']) : '<br>') . '</' . $tg . '>'; }
                $h .= '</tr>';
            }
            $h .= '</table>';
            continue;
        }
        if ($lastG !== null && $lastG !== $b['g']) $closeAll();
        $lastG = $b['g'];
        $lv = $b['lv'];
        while ($stack && end($stack)[1] > $lv) $h .= '</li></' . array_pop($stack)[0] . '>';
        if ($stack && end($stack)[1] === $lv) {
            if (end($stack)[0] !== $b['k']) $h .= '</li></' . array_pop($stack)[0] . '>';
            else $h .= '</li>';
        }
        while (($nl = $stack ? end($stack)[1] + 1 : 0) <= $lv) {
            if ($nl < $lv) { $h .= '<ul><li>'; $stack[] = ['ul', $nl]; }
            else { $h .= '<' . $b['k'] . '>'; $stack[] = [$b['k'], $nl]; }
        }
        $h .= '<li' . bl_rt_style($b['al'], 0) . '>' . bl_rt_runs_html($b['r']);
    }
    $closeAll();
    return $h;
}
function bl_rt_clean(string $html): string { return bl_rt_html(bl_rt_parse($html)); }

/** Teks polos isian lama ("1. …", "- …") → HTML */
function bl_rt_dari_teks(string $text): string
{
    $b = []; $g = 0; $inList = false; $lastNum = false;
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
        $l = trim($line);
        if ($l === '') { $inList = false; continue; }
        $run = [['x' => $l, 'b' => false, 'i' => false, 'u' => false, 's' => 0]];
        if (preg_match('/^\d{1,2}[\.\)]\s+(.*)$/u', $l, $m)) {
            if (!$inList) { $g++; $inList = true; }
            $run[0]['x'] = $m[1]; $b[] = ['t' => 'li', 'k' => 'ol', 'lv' => 0, 'g' => $g, 'al' => 'j', 'r' => $run]; $lastNum = true;
        } elseif (preg_match('/^[-•*–]\s+(.*)$/u', $l, $m)) {
            if (!$inList) { $g++; $inList = true; $lastNum = false; }
            $run[0]['x'] = $m[1]; $b[] = ['t' => 'li', 'k' => 'ul', 'lv' => $lastNum ? 1 : 0, 'g' => $g, 'al' => 'j', 'r' => $run];
        } else { $inList = false; $lastNum = false; $b[] = ['t' => 'p', 'al' => 'j', 'ind' => 0, 'r' => $run]; }
    }
    return bl_rt_html($b);
}

/** Tanda butir daftar: 1. a. i. / • – • */
function bl_rt_marks(array $blocks): array
{
    $cnt = []; $g = null;
    foreach ($blocks as $i => $b) {
        if ($b['t'] !== 'li') { $cnt = []; $g = null; continue; }
        if ($g !== $b['g']) { $cnt = []; $g = $b['g']; }
        foreach (array_keys($cnt) as $lv) if ($lv > $b['lv']) unset($cnt[$lv]);
        $cnt[$b['lv']] = ($cnt[$b['lv']] ?? 0) + 1;
        $n = $cnt[$b['lv']];
        if ($b['k'] === 'ul') $m = $b['lv'] % 2 ? '–' : '•';
        else $m = match ($b['lv'] % 3) { 1 => chr(96 + (($n - 1) % 26) + 1) . '.', 2 => strtolower(bl_rt_romawi($n)) . '.', default => $n . '.' };
        $blocks[$i]['m'] = $m;
    }
    return $blocks;
}
function bl_rt_romawi(int $n): string
{
    $r = ''; foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $s => $v) while ($n >= $v) { $r .= $s; $n -= $v; }
    return $r;
}
function bl_rt_teks(array $runs): string
{
    return trim(implode('', array_map(fn($x) => isset($x['br']) ? "\n" : $x['x'], $runs)));
}
