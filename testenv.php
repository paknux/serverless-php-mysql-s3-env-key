<?php

require 'vendor/autoload.php';

/**
 * 1. LOAD ENVIRONMENT VARIABLES (HYBRID)
 * Memuat .env jika ada, tapi tetap memprioritaskan OS Environment
 */
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

/**
 * Helper untuk mengambil config
 * Prioritas: getenv() (OS) > $_ENV (.env file) > Default Value
 */
function get_config($key, $default = null) {
    $val = getenv($key);
    if ($val !== false) return $val;
    return $_ENV[$key] ?? $default;
}

// Mengambil variabel spesifik
$token = get_config('AWS_SESSION_TOKEN');
$accessKey = get_config('AWS_ACCESS_KEY_ID');
$region = get_config('AWS_REGION');

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Raw Debug Environment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #121212; color: #00ff00; font-family: 'Courier New', monospace; }
        .debug-card { background: #1e1e1e; border: 1px solid #333; padding: 20px; border-radius: 8px; }
        .token-box { background: #000; padding: 15px; border: 1px dashed #555; word-break: break-all; color: #ffcc00; }
        .label { color: #aaa; font-weight: bold; }
    </style>
</head>
<body>

<div class="container mt-5">
    <h2 class="mb-4">🛠 Raw Environment Debugger</h2>

    <div class="debug-card mb-4">
        <p><span class="label">AWS Region:</span> <?= $region ?: '<span class="text-danger">NOT FOUND</span>' ?></p>
        <p><span class="label">Access Key ID:</span> <?= $accessKey ?: '<span class="text-danger">NOT FOUND</span>' ?></p>

        <hr style="border-color: #444;">

        <p class="label">AWS Session Token:</p>
        <?php if ($token): ?>
            <div class="mb-2">
                <span class="badge bg-primary">Panjang Karakter: <?= strlen($token) ?></span>
                <span class="text-muted small">(Token AWS Academy biasanya > 350 karakter)</span>
            </div>
            <div class="token-box"><?= $token ?></div>
        <?php else: ?>
            <div class="alert alert-danger">TOKEN TIDAK TERDETEKSI!</div>
        <?php endif; ?>
    </div>

    <a href="index.php" class="btn btn-outline-info">Kembali ke CRUD</a>
</div>

</body>
</html>

