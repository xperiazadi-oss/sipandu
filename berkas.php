<?php
/* Berkas subdomain pembelajaran (surat tugas, materi, foto narasumber); tidak tersedia di Si PANDU */
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.
require __DIR__ . '/app/bootstrap.php';
if (sp_app() !== 'bl') { http_response_code(404); exit; }
require __DIR__ . '/app/bl_web.php';
bl_page_berkas();
