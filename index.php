<?php
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// 1. ERROR REPORTING
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * 2. CONFIG LOAD
 */
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

function get_config($key, $default = null) {
    $val = getenv($key);
    if ($val !== false) return $val;
    return $_ENV[$key] ?? $default;
}

$host      = get_config('DB_HOST', 'localhost');
$dbName    = get_config('DB_NAME', 'db_inventory');
$user      = get_config('DB_USER');
$pass      = get_config('DB_PASS');
$awsRegion = get_config('AWS_REGION', 'us-east-1');
$awsBucket = get_config('AWS_BUCKET');

/**
 * 3. INITIALIZATION
 */
try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS barang (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_barang VARCHAR(100) NOT NULL,
        jumlah INT NOT NULL DEFAULT 0,
        harga DECIMAL(15, 2) NOT NULL DEFAULT 0,
        s3_key VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $s3Client = new S3Client(['version' => 'latest', 'region' => $awsRegion]);
} catch (Exception $e) {
    die("Koneksi Error: " . $e->getMessage());
}

/**
 * 4. CRUD LOGIC
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action == 'create' || $action == 'update') {
            $nama = $_POST['nama_barang'];
            $jumlah = $_POST['jumlah'];
            $harga = $_POST['harga'];
            $s3_key = $_POST['old_s3_key'] ?? '';

            if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                $new_key = 'barang/' . time() . '-' . $_FILES['foto']['name'];
                $s3Client->putObject([
                    'Bucket' => $awsBucket,
                    'Key'    => $new_key,
                    'SourceFile' => $_FILES['foto']['tmp_name']
                ]);
                
                if ($action == 'update' && !empty($s3_key)) {
                    $s3Client->deleteObject(['Bucket' => $awsBucket, 'Key' => $s3_key]);
                }
                $s3_key = $new_key;
            }

            if ($action == 'create') {
                $stmt = $pdo->prepare("INSERT INTO barang (nama_barang, jumlah, harga, s3_key) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nama, $jumlah, $harga, $s3_key]);
            } else {
                $stmt = $pdo->prepare("UPDATE barang SET nama_barang=?, jumlah=?, harga=?, s3_key=? WHERE id=?");
                $stmt->execute([$nama, $jumlah, $harga, $s3_key, $_POST['id']]);
            }
        }

        if ($action == 'delete') {
            if (!empty($_POST['s3_key'])) {
                $s3Client->deleteObject(['Bucket' => $awsBucket, 'Key' => $_POST['s3_key']]);
            }
            $pdo->prepare("DELETE FROM barang WHERE id = ?")->execute([$_POST['id']]);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/**
 * 5. FETCH DATA & TOTALS
 */
$barang = $pdo->query("SELECT * FROM barang ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$total_stok = 0;
$total_nilai = 0;

foreach ($barang as &$b) {
    $total_stok += $b['jumlah'];
    $total_nilai += ($b['jumlah'] * $b['harga']);
    if (!empty($b['s3_key'])) {
        try {
            $cmd = $s3Client->getCommand('GetObject', ['Bucket' => $awsBucket, 'Key' => $b['s3_key']]);
            $b['temp_url'] = (string)$s3Client->createPresignedRequest($cmd, '+15 minutes')->getUri();
        } catch (Exception $e) { $b['temp_url'] = ''; }
    }
}
unset($b);
?>

<!DOCTYPE html>
<html lang="id" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOKO ORANGE STOCK</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --orange-p: #fd7e14; --dark-b: #0d1117; --dark-c: #161b22; }
        body { background-color: var(--dark-b); color: #e6edf3; padding-top: 50px; }
        
        .header-orange {
            background-color: var(--orange-p);
            padding: 30px 20px;
            border-top-left-radius: 40px;
            border-top-right-radius: 40px;
            box-shadow: 0 -5px 15px rgba(253, 126, 20, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .title-toko, .title-stock { color: #000000; font-weight: 900; margin: 0; }
        .title-orange { color: #ffffff; font-weight: 900; margin: 0; }
        .header-logo { font-size: 3rem; color: #000000; }

        .card-main { background-color: var(--dark-c); border: 1px solid #30363d; border-radius: 0 0 15px 15px; border-top: none; }
        .text-orange { color: var(--orange-p) !important; }
        .btn-orange { background-color: var(--orange-p); color: white; border: none; font-weight: bold; }
        .btn-orange:hover { background-color: #e86b00; color: white; }
        
        .img-barang { width: 60px; height: 60px; object-fit: cover; border-radius: 10px; border: 2px solid var(--orange-p); }
        .img-preview-edit { width: 100%; max-height: 200px; object-fit: contain; border-radius: 10px; border: 1px solid var(--orange-p); margin-bottom: 15px; }
        
        .summary-bar { background-color: rgba(253, 126, 20, 0.1); border: 1px solid var(--orange-p); border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .table { --bs-table-bg: transparent; color: #e6edf3; }
        .form-label { font-size: 0.85rem; font-weight: bold; color: var(--orange-p); }
    </style>
</head>
<body>

<div class="container" style="max-width: 1000px;">
    <div class="header-orange">
        <i class="bi bi-box-seam header-logo"></i> <h1 class="display-5">
            <span class="title-toko">TOKO</span> 
            <span class="title-orange">ORANGE</span> 
            <span class="title-stock">STOCK</span>
        </h1>
    </div>

    <div class="card-main p-4 shadow">
        <div class="row summary-bar text-center g-2">
            <div class="col-6 border-end border-secondary">
                <small class="d-block opacity-75">TOTAL STOK</small>
                <h4 class="mb-0 fw-bold"><?= number_format($total_stok, 0, ',', '.') ?> <span class="fs-6">Unit</span></h4>
            </div>
            <div class="col-6 text-orange">
                <small class="d-block opacity-75">TOTAL NILAI BARANG</small>
                <h4 class="mb-0 fw-bold">Rp <?= number_format($total_nilai, 0, ',', '.') ?></h4>
            </div>
        </div>

        <form action="" method="POST" enctype="multipart/form-data" class="row g-2 mb-4 align-items-end">
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <label class="form-label">Nama Barang</label>
                <input type="text" name="nama_barang" class="form-control" required>
            </div>
            <div class="col-md-1">
                <label class="form-label">Qty</label>
                <input type="number" name="jumlah" class="form-control text-center" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Harga Satuan</label>
                <input type="number" name="harga" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Foto Barang (S3 Upload)</label>
                <input type="file" name="foto" class="form-control" accept="image/*" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-orange w-100">SIMPAN</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr class="text-secondary small">
                        <th>FOTO</th>
                        <th>NAMA BARANG</th>
                        <th class="text-center">STOK</th>
                        <th class="text-end">HARGA</th>
                        <th class="text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($barang as $b): ?>
                    <tr>
                        <td><img src="<?= $b['temp_url'] ?: 'https://via.placeholder.com/60' ?>" class="img-barang"></td>
                        <td class="fw-bold"><?= htmlspecialchars($b['nama_barang']) ?></td>
                        <td class="text-center"><span class="badge bg-dark border border-warning"><?= $b['jumlah'] ?></span></td>
                        <td class="text-end">Rp <?= number_format($b['harga'], 0, ',', '.') ?></td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1">
                                <button class="btn btn-sm btn-outline-warning" onclick='openEditModal(<?= json_encode($b) ?>)'><i class="bi bi-pencil"></i></button>
                                <form action="" method="POST" onsubmit="return confirm('Hapus?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <input type="hidden" name="s3_key" value="<?= $b['s3_key'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog">
        <form action="" method="POST" enctype="multipart/form-data" class="modal-content" style="background-color: var(--dark-c); border: 2px solid var(--orange-p);">
            <div class="modal-header border-secondary">
                <h5 class="modal-title text-orange fw-bold">Edit Stok Barang</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit-id">
                <input type="hidden" name="old_s3_key" id="edit-s3-key">
                
                <div class="text-center mb-3">
                    <img id="edit-preview" src="" class="img-preview-edit">
                </div>

                <div class="mb-3">
                    <label class="form-label">Nama Barang</label>
                    <input type="text" name="nama_barang" id="edit-nama" class="form-control" required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label">Stok</label>
                        <input type="number" name="jumlah" id="edit-jumlah" class="form-control" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label">Harga Satuan</label>
                        <input type="number" name="harga" id="edit-harga" class="form-control" required>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label">Ganti Foto (Opsional)</label>
                    <input type="file" name="foto" class="form-control" accept="image/*">
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="submit" class="btn btn-orange w-100">UPDATE DATA</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const modalEdit = new bootstrap.Modal('#modalEdit');
    function openEditModal(data) {
        document.getElementById('edit-id').value = data.id;
        document.getElementById('edit-nama').value = data.nama_barang;
        document.getElementById('edit-jumlah').value = data.jumlah;
        document.getElementById('edit-harga').value = data.harga;
        document.getElementById('edit-s3-key').value = data.s3_key;
        document.getElementById('edit-preview').src = data.temp_url || 'https://via.placeholder.com/150';
        modalEdit.show();
    }
</script>
</body>
</html>