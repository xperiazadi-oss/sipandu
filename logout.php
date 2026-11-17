<?php
// Tanpa strict_types: nilai dari basis data selalu berupa string dan dikonversi otomatis oleh PHP.
require __DIR__ . '/app/bootstrap.php';
sp_session_start();
if (csrf_ok($_GET['t'] ?? null) && current_user()) {
    audit('logout');
    $_SESSION = [];
    session_regenerate_id(true);
}
header('Location: ./');
