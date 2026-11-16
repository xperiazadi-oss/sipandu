<?php
require __DIR__ . '/engine_tests.php';
$r = sipandu_engine_tests();
echo "pemeriksaan: {$r['checks']} (Excel: {$r['excel_checks']}), gagal: {$r['fail']}\n";
foreach (array_slice($r['messages'], 0, 20) as $m) echo $m, "\n";
exit($r['fail'] ? 1 : 0);
