<?php
/* Laporan Kegiatan – penyusun berkas Word (.docx, OOXML lewat ZipArchive): sampul (templat + foto + bingkai teks),
   Lembar Pengesahan, Daftar Isi (bidang TOC, diperbarui Word saat dibuka), bab/sub-bab berformat, dan lampiran. */

const BL_DX_PW = 11906, BL_DX_PH = 16838, BL_DX_ML = 1701, BL_DX_MR = 1417, BL_DX_MT = 1417, BL_DX_MB = 1417;   // twip (A4)
const BL_DX_EMU = 635;   // EMU per twip

function bl_docx_x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function bl_docx_run(string $t, bool $b = false, bool $i = false, int $sz = 24, bool $u = false, string $extra = ''): string
{
    return '<w:r><w:rPr>' . $extra . ($b ? '<w:b/>' : '') . ($i ? '<w:i/>' : '') . ($u ? '<w:u w:val="single"/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr><w:t xml:space="preserve">' . bl_docx_x($t) . '</w:t></w:r>';
}
function bl_docx_runs(array $runs): string
{
    $x = '';
    foreach ($runs as $r) $x .= isset($r['br']) ? '<w:r><w:br/></w:r>' : bl_docx_run($r['x'], (bool)$r['b'], (bool)$r['i'], 2 * ($r['s'] ?: BL_RT_DASAR), (bool)$r['u']);
    return $x;
}
function bl_docx_jc(string $al): string { return ['l' => 'left', 'c' => 'center', 'r' => 'right', 'j' => 'both'][$al] ?? 'both'; }
function bl_docx_p(string $runs, string $jc = 'both', int $left = 0, int $hanging = 0, int $after = 100, string $extra = ''): string
{
    $ind = $left || $hanging ? '<w:ind w:left="' . $left . '"' . ($hanging ? ' w:hanging="' . $hanging . '"' : '') . '/>' : '';
    return '<w:p><w:pPr>' . $extra . '<w:spacing w:after="' . $after . '" w:line="312" w:lineRule="auto"/>' . $ind . '<w:jc w:val="' . $jc . '"/></w:pPr>' . $runs . '</w:p>';
}
function bl_docx_hal(): string { return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'; }
/** Gambar sebaris (EMU) */
function bl_docx_img(string $rid, int $id, int $cx, int $cy): string
{
    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="Gambar ' . $id . '"/>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="g' . $id . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}
/** Gambar mengambang di belakang teks, posisi terhadap halaman (EMU); $crop = [l, t, r, b] dalam 1/1000 % */
function bl_docx_anchor(string $rid, int $id, int $x, int $y, int $cx, int $cy, int $z, array $crop = [0, 0, 0, 0]): string
{
    $src = array_filter($crop) ? '<a:srcRect l="' . $crop[0] . '" t="' . $crop[1] . '" r="' . $crop[2] . '" b="' . $crop[3] . '"/>' : '';
    return '<w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="' . $z . '" behindDoc="1" locked="1" layoutInCell="1" allowOverlap="1">'
        . '<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:posOffset>' . $x . '</wp:posOffset></wp:positionH><wp:positionV relativeFrom="page"><wp:posOffset>' . $y . '</wp:posOffset></wp:positionV>'
        . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/><wp:docPr id="' . $id . '" name="Gambar ' . $id . '"/><wp:cNvGraphicFramePr/>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="g' . $id . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="' . $rid . '"/>' . $src . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>';
}
/** Tabel dari blok bl_rt; $ind = indentasi kiri (twip), $w = lebar (twip) */
function bl_docx_tbl(array $rows, int $ind, int $w): string
{
    $pdf = new BlPdf();
    $cw = array_map(fn($v) => (int)round($v * 20), $pdf->colWidths($rows, $w / 20, 10));
    $sz = (int)round($pdf->lastSize * 2);
    $head = $rows && !array_filter($rows[0], fn($c) => !$c['h']);
    $x = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($cw) . '" w:type="dxa"/>' . ($ind ? '<w:tblInd w:w="' . $ind . '" w:type="dxa"/>' : '') . '<w:tblBorders>'
        . implode('', array_map(fn($e) => "<w:$e w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']))
        . '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="57" w:type="dxa"/><w:right w:w="57" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'
        . implode('', array_map(fn($v) => '<w:gridCol w:w="' . $v . '"/>', $cw)) . '</w:tblGrid>';
    foreach ($rows as $ri => $row) {
        $x .= '<w:tr>' . ($head && $ri === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        foreach ($row as $ci => $c) {
            [$t, $f] = BlPdf::sel($c);
            $runs = '';
            foreach ($c['r'] as $r) $runs .= isset($r['br']) ? '<w:r><w:br/></w:r>' : bl_docx_run($r['x'], $f === 'B' || (bool)$r['b'], (bool)$r['i'], $sz, (bool)$r['u']);
            $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw[$ci] . '" w:type="dxa"/>' . ($head && $ri === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="EBEBEB"/>' : '') . '</w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:before="20" w:after="20" w:line="240" w:lineRule="auto"/><w:jc w:val="' . ['l' => 'left', 'c' => 'center', 'r' => 'right', 'j' => 'both'][$c['al']] . '"/></w:pPr>' . $runs . '</w:p></w:tc>';
        }
        $x .= '</w:tr>';
    }
    return $x . '</w:tbl><w:p><w:pPr><w:spacing w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
}
/** Blok teks berformat; $base = indentasi kiri area isi (twip) */
function bl_docx_blok(array $blocks, int $base, int $w): string
{
    $x = '';
    foreach (bl_rt_marks($blocks) as $b) {
        if ($b['t'] === 'p') $x .= bl_docx_p(bl_docx_runs($b['r']), bl_docx_jc($b['al']), $base + $b['ind'] * 567);
        elseif ($b['t'] === 'li') { $l = $base + ($b['lv'] + 1) * 400; $x .= bl_docx_p(bl_docx_run($b['m']) . '<w:r><w:tab/></w:r>' . bl_docx_runs($b['r']), bl_docx_jc($b['al']), $l, 400, 40, '<w:tabs><w:tab w:val="left" w:pos="' . $l . '"/></w:tabs>'); }
        else $x .= bl_docx_tbl($b['rows'], $base, $w);
    }
    return $x;
}
/** Paragraf berbingkai pada posisi halaman (sampul): $xt/$yt/$wt dalam twip */
function bl_docx_bingkai(array $lines, int $xt, int $yt, int $wt, float $z, float $lead, bool $bold): string
{
    $x = '';
    foreach ($lines as $i => $l) {
        $x .= '<w:p><w:pPr><w:framePr w:w="' . $wt . '" w:hAnchor="page" w:vAnchor="page" w:x="' . $xt . '" w:y="' . $yt . '" w:wrap="around"/><w:spacing w:before="0" w:after="0" w:line="' . (int)round($lead * 20) . '" w:lineRule="exact"/></w:pPr>'
            . '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>' . ($bold ? '<w:b/>' : '') . '<w:color w:val="FFFFFF"/><w:sz w:val="' . (int)round($z * 2) . '"/><w:szCs w:val="' . (int)round($z * 2) . '"/></w:rPr><w:t xml:space="preserve">' . bl_docx_x($l) . '</w:t></w:r></w:p>';
    }
    return $x;
}

function bl_lap_docx(array $L, ?string $tujuan = null): ?string
{
    $W = BL_DX_PW - BL_DX_ML - BL_DX_MR;
    $media = []; $rels = []; $nid = 10;
    $addImg = function (string $path, string $ext) use (&$media, &$rels): string {
        $n = count($media) + 1; $rid = 'rIdM' . $n;
        $media["word/media/g$n.$ext"] = $path;
        $rels[$rid] = "media/g$n.$ext";
        return $rid;
    };
    $pdf = new BlPdf();
    $x = '';
    // 1. sampul: foto (dipotong ke area tengah) di bawah templat bertransparansi, lalu bingkai teks putih
    $s = $L['sampul'];
    $pEmu = BL_DX_PW * BL_DX_EMU; $hEmu = BL_DX_PH * BL_DX_EMU;
    $anch = '';
    $foto = !empty($s['foto']) ? bl_lap_path($s['foto']) : '';
    if ($foto !== '' && is_file($foto) && ($gi = @getimagesize($foto))) {
        $ry = (int)round($hEmu * BL_SAMPUL_FOTO[0] / BL_SAMPUL_H); $rh = (int)round($hEmu * (BL_SAMPUL_FOTO[1] - BL_SAMPUL_FOTO[0]) / BL_SAMPUL_H);
        $a = $gi[0] / $gi[1]; $r = $pEmu / $rh; $crop = [0, 0, 0, 0];
        if ($a > $r) { $c = (int)round((1 - $r / $a) / 2 * 100000); $crop = [$c, 0, $c, 0]; } else { $c = (int)round((1 - $a / $r) / 2 * 100000); $crop = [0, $c, 0, $c]; }
        $anch .= bl_docx_anchor($addImg($foto, 'jpg'), $nid++, 0, $ry, $pEmu, $rh, 1, $crop);
    }
    $anch .= bl_docx_anchor($addImg(__DIR__ . '/sampul.png', 'png'), $nid++, 0, 0, $pEmu, $hEmu, 2);
    $x .= '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>' . $anch . '</w:p>';
    $T = bl_lap_sampul_tata();
    $tw = fn(float $pt) => (int)round($pt * 20);
    foreach (['judulLaporan', 'judulKegiatan'] as $key) {
        $v = trim((string)($s[$key] ?? ''));
        if ($v === '') continue;
        [$px, $py, $pw, $z0, $min, $lh, $max] = $T[$key];
        [$z, $ls] = bl_lap_pas($pdf, $v, 'HB', $z0, $min, $pw, $max);
        $lines = array_map(fn($l) => mb_convert_encoding($l, 'UTF-8', 'CP1252'), $ls);
        $lead = $z * $lh;
        $x .= bl_docx_bingkai($lines, $tw($px), $tw($py - $lead + 0.212 * $z), $tw($pw + 6), $z, $lead, true);
    }
    $pen = bl_lap_penyusun_baris($s);
    if ($pen) {
        [$px, $py, $pw, $z0, $min, $lh, $max] = $T['penyusun'];
        for ($z = $z0; $z >= $min; $z -= 0.5) { $ls = []; foreach ($pen as $l) array_push($ls, ...$pdf->wrap(BlPdf::enc($l), 'H', $z, $pw)); if (count($ls) <= $max) break; }
        $lead = $z * $lh;
        $x .= bl_docx_bingkai(array_map(fn($l) => mb_convert_encoding($l, 'UTF-8', 'CP1252'), $ls), $tw($px), $tw($py - $lead + 0.212 * $z), $tw($pw + 6), $z, $lead, false);
    }
    // 2. Lembar Pengesahan
    $x .= bl_docx_hal();
    if (!empty($L['dok'])) $x .= bl_docx_p(bl_docx_run(bl_dok_label_sifat($L['dok']), true, false, 20), 'right', 0, 0, 0);
    $x .= bl_docx_p(bl_docx_run(t('lap.pengesahan'), true, false, 28), 'center', 0, 0, 360, '<w:spacing w:before="360"/>');
    $kv = [];
    if (!empty($L['dok'])) $kv[] = [t('dok.nomor'), $L['dok']['nomor']];
    $kv[] = [t('lap.judul_laporan'), trim($s['judulLaporan']) ?: '–'];
    $kv[] = [t('lap.judul_kegiatan'), trim($s['judulKegiatan']) ?: '–'];
    $kv[] = [t('lap.disusun_oleh'), implode(' ', bl_lap_penyusun_baris($s)) ?: '–'];
    $rows = '';
    $cellP = fn($runs) => '<w:p><w:pPr><w:spacing w:after="60"/></w:pPr>' . $runs . '</w:p>';
    foreach ($kv as [$lab, $val]) $rows .= '<w:tr><w:tc><w:tcPr><w:tcW w:w="2240" w:type="dxa"/></w:tcPr>' . $cellP(bl_docx_run($lab, true)) . '</w:tc><w:tc><w:tcPr><w:tcW w:w="260" w:type="dxa"/></w:tcPr>' . $cellP(bl_docx_run(':'))
        . '</w:tc><w:tc><w:tcPr><w:tcW w:w="' . ($W - 2500) . '" w:type="dxa"/></w:tcPr>' . $cellP(bl_docx_run($val)) . '</w:tc></w:tr>';
    $x .= '<w:tbl><w:tblPr><w:tblW w:w="' . $W . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="57" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="2240"/><w:gridCol w:w="260"/><w:gridCol w:w="' . ($W - 2500) . '"/></w:tblGrid>' . $rows . '</w:tbl>';
    $t = $L['ttd'];
    $cell = fn($lines, $w) => '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/></w:tcPr>' . implode('', $lines) . '</w:tc>';
    $pl = fn($s, $b = false, $u = false) => '<w:p><w:pPr><w:keepNext/><w:spacing w:after="0"/></w:pPr>' . bl_docx_run($s, $b, false, 24, $u) . '</w:p>';
    $gap = '<w:p><w:pPr><w:keepNext/><w:spacing w:after="0"/></w:pPr></w:p>';
    $half = (int)($W * 0.55);
    $x .= '<w:p><w:pPr><w:keepNext/><w:spacing w:before="480" w:after="0"/></w:pPr></w:p><w:tbl><w:tblPr><w:tblW w:w="' . $W . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="' . $half . '"/><w:gridCol w:w="' . ($W - $half) . '"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>'
        . $cell([$pl(trim($t['tempat'] . ', ' . $t['tanggal'], ', ')), $pl(t('lap.ttd_fasil')), $gap, $gap, $gap, $gap, $pl($t['fasil'], true, true)], $half)
        . $cell([$pl(t('lap.mengetahui')), $pl(t('lap.ttd_pembina')), $gap, $gap, $gap, $gap, $pl($t['pembina'] !== '' ? $t['pembina'] : str_repeat('.', 40), true, $t['pembina'] !== '')], $W - $half)
        . '</w:tr></w:tbl>';
    $qrFile = null;
    if (!empty($L['dok'])) {
        $qrFile = tempnam(sys_get_temp_dir(), 'qr');
        file_put_contents($qrFile, SpQr::encode(bl_dok_qr_url($L['dok']))->png(8));
        $rid = $addImg($qrFile, 'png'); $e = 1066800;
        $x .= '<w:tbl><w:tblPr><w:tblW w:w="' . $W . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="1900"/><w:gridCol w:w="' . ($W - 1900) . '"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>'
            . '<w:tc><w:tcPr><w:tcW w:w="1900" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:spacing w:before="480" w:after="0"/></w:pPr>' . bl_docx_img($rid, $nid++, $e, $e) . '</w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="' . ($W - 1900) . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . bl_docx_p(bl_docx_run(bl_dok_qr_teks($L['dok']), false, false, 19), 'left', 0, 0, 0, '<w:spacing w:before="480"/>') . '</w:tc></w:tr></w:tbl>';
    }
    // 3. Daftar Isi: bidang TOC (diisi awal dengan nomor halaman versi PDF; Word memperbarui saat dibuka)
    $hal = bl_lap_halaman($L);
    $x .= bl_docx_hal() . bl_docx_p(bl_docx_run(mb_strtoupper(t('lap.daftar_isi')), true, false, 28), 'center', 0, 0, 240);
    $x .= bl_docx_p(bl_docx_run(t('lap.hal')), 'right', 0, 0, 60);
    $toc = ''; $lamp = '';
    foreach (bl_lap_entri_isi($L) as $n => [$lv, $label, $key]) {
        if ($lv === 3) { $lamp .= bl_docx_p(bl_docx_run('•') . '<w:r><w:tab/></w:r>' . bl_docx_run($label), 'left', 760, 400, 60, '<w:tabs><w:tab w:val="left" w:pos="760"/></w:tabs>'); continue; }
        $toc .= '<w:p><w:pPr><w:pStyle w:val="TOC' . $lv . '"/><w:tabs><w:tab w:val="right" w:leader="dot" w:pos="' . $W . '"/></w:tabs></w:pPr>'
            . ($n === 0 ? '<w:r><w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \o "1-2" \h \z \u </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>' : '')
            . bl_docx_run($label) . '<w:r><w:tab/></w:r>' . bl_docx_run((string)($hal[$key] ?? '')) . '</w:p>';
    }
    $x .= $toc . '<w:p><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>' . $lamp;
    // 4. Isi bab
    $x .= bl_docx_hal();
    foreach ($L['bab'] as $i => $b) {
        $x .= '<w:p><w:pPr><w:pStyle w:val="Judul1"/>' . ($i ? '<w:spacing w:before="240"/>' : '') . '</w:pPr>' . bl_docx_run($b['judul'], true) . '</w:p>';
        $bb = bl_rt_parse($b['html']);
        if ($bb) $x .= bl_docx_blok($bb, 360, $W - 360);
        elseif (!$b['sub']) $x .= bl_docx_p(bl_docx_run(t('lap.belum_diisi')), 'both', 360);
        foreach ($b['sub'] as $j => $sb) {
            $x .= '<w:p><w:pPr><w:pStyle w:val="Judul2"/><w:tabs><w:tab w:val="left" w:pos="720"/></w:tabs><w:ind w:left="720" w:hanging="360"/></w:pPr>' . bl_docx_run(($j + 1) . '.', true) . '<w:r><w:tab/></w:r>' . bl_docx_run($sb['judul'], true) . '</w:p>';
            $sbb = bl_rt_parse($sb['html']);
            $x .= $sbb ? bl_docx_blok($sbb, 720, $W - 720) : bl_docx_p(bl_docx_run(t('lap.belum_diisi')), 'both', 720);
        }
    }
    // 5. Lampiran
    foreach ($L['lampiran'] as $j => $lp) {
        $x .= bl_docx_hal();
        if ($j === 0) $x .= '<w:p><w:pPr><w:pStyle w:val="Judul1"/><w:jc w:val="center"/></w:pPr>' . bl_docx_run(mb_strtoupper(t('lap.lampiran')), true, false, 28) . '</w:p>';
        $x .= bl_docx_p(bl_docx_run(mb_strtoupper(t('lap.lampiran')) . ' ' . bl_lap_romawi($j + 1) . '. ' . $lp['judul'], true), 'left', 0, 0, 160, '<w:keepNext/>');
        $maxH = BL_DX_PH - BL_DX_MT - BL_DX_MB - 1200;
        foreach ($lp['files'] as $f) foreach ($f['hal'] as $h) {
            $path = bl_lap_path($h['f']);
            if (!is_file($path)) continue;
            $sc = min($W / $h['w'], $maxH / $h['h']);
            $x .= '<w:p><w:pPr><w:spacing w:after="120"/><w:jc w:val="center"/></w:pPr>' . bl_docx_img($addImg($path, 'jpg'), $nid++, (int)round($h['w'] * $sc * BL_DX_EMU), (int)round($h['h'] * $sc * BL_DX_EMU)) . '</w:p>';
        }
    }
    $draf = !empty($L['draf']);
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'
        . $x . '<w:sectPr>' . ($draf ? '<w:headerReference w:type="default" r:id="rIdHdr"/><w:headerReference w:type="first" r:id="rIdHdr"/>' : '')
        . '<w:footerReference w:type="default" r:id="rIdFtr"/><w:footerReference w:type="first" r:id="rIdFtr1"/><w:pgSz w:w="' . BL_DX_PW . '" w:h="' . BL_DX_PH . '"/><w:pgMar w:top="' . BL_DX_MT . '" w:right="' . BL_DX_MR . '" w:bottom="' . BL_DX_MB . '" w:left="' . BL_DX_ML . '" w:header="567" w:footer="567" w:gutter="0"/><w:titlePg/></w:sectPr></w:body></w:document>';
    // catatan kaki: "Halaman n dari m" (kiri) + hak cipta (kanan); sampul tanpa catatan kaki
    $hl = t('lap.halaman', ['n' => "\x01", 'm' => "\x02"]);
    $fld = fn($f) => '<w:r><w:rPr><w:color w:val="737373"/><w:sz w:val="15"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:color w:val="737373"/><w:sz w:val="15"/></w:rPr><w:instrText xml:space="preserve"> ' . $f . ' </w:instrText></w:r><w:r><w:rPr><w:color w:val="737373"/><w:sz w:val="15"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:color w:val="737373"/><w:sz w:val="15"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:color w:val="737373"/><w:sz w:val="15"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>';
    $grey = fn($s, $i = false) => $s === '' ? '' : '<w:r><w:rPr>' . ($i ? '<w:i/>' : '') . '<w:color w:val="737373"/><w:sz w:val="15"/></w:rPr><w:t xml:space="preserve">' . bl_docx_x($s) . '</w:t></w:r>';
    $parts = preg_split('/(\x01|\x02)/', $hl, -1, PREG_SPLIT_DELIM_CAPTURE);
    $hr = '';
    foreach ($parts as $pt) $hr .= $pt === "\x01" ? $fld('PAGE') : ($pt === "\x02" ? $fld('NUMPAGES') : $grey($pt));
    $ftr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:tabs><w:tab w:val="right" w:pos="' . $W . '"/></w:tabs></w:pPr>' . $hr . '<w:r><w:tab/></w:r>' . $grey(t('app.hak_cipta'), true) . '</w:p></w:ftr>';
    $ftr1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p/></w:ftr>';
    $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="B42318"/><w:sz w:val="36"/></w:rPr><w:t>' . bl_docx_x(t('dok.draf_cap')) . '</w:t></w:r></w:p></w:hdr>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $ns . '><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman" w:eastAsia="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="id-ID"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Judul1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="120" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Judul2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="160" w:after="80"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:before="120" w:after="60"/></w:pPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="TOC2"><w:name w:val="toc 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:spacing w:after="60"/><w:ind w:left="360"/></w:pPr></w:style></w:styles>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings ' . $ns . '><w:updateFields w:val="true"/><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>';
    $relx = '';
    foreach ($rels as $rid => $target) $relx .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' . $target . '"/>';
    $out = $tujuan ?? tempnam(sys_get_temp_dir(), 'lap');
    $z = new ZipArchive();
    $z->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
        . ($draf ? '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>' : '') . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdSty" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdSet" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/><Relationship Id="rIdFtr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/><Relationship Id="rIdFtr1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer2.xml"/>'
        . ($draf ? '<Relationship Id="rIdHdr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>' : '') . $relx . '</Relationships>');
    $z->addFromString('word/document.xml', $doc);
    $z->addFromString('word/styles.xml', $styles);
    $z->addFromString('word/settings.xml', $settings);
    $z->addFromString('word/footer1.xml', $ftr);
    $z->addFromString('word/footer2.xml', $ftr1);
    if ($draf) $z->addFromString('word/header1.xml', $hdr);
    foreach ($media as $name => $path) { $z->addFile($path, $name); $z->setCompressionName($name, ZipArchive::CM_STORE); }
    $z->close();
    if ($qrFile) @unlink($qrFile);
    if ($tujuan !== null) return null;
    $bin = (string)file_get_contents($out);
    @unlink($out);
    return $bin;
}
