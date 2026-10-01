<?php
// Database Configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "kasirdragon";

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id = isset($_GET['id']) ? $_GET['id'] : null;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $kode = mysqli_real_escape_string($conn, trim($_POST['kode_jenis_barang']));
    $nama = mysqli_real_escape_string($conn, trim($_POST['nama_jenis_barang']));
    
    // Validation
    if (empty($kode) || empty($nama)) {
        $error = "Semua field harus diisi!";
    } else {
        if ($action == 'add') {
            // Check if code already exists
            $check_sql = "SELECT kode_jenis_barang FROM jenis_barang WHERE kode_jenis_barang='$kode'";
            $check_result = mysqli_query($conn, $check_sql);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error = "Kode jenis barang sudah ada! Gunakan kode yang berbeda.";
            } else {
                $sql = "INSERT INTO jenis_barang (kode_jenis_barang, nama_jenis_barang) VALUES ('$kode', '$nama')";
                if (mysqli_query($conn, $sql)) {
                    $success = "Data berhasil ditambahkan!";
                    $action = 'list';
                } else {
                    $error = "Error: " . mysqli_error($conn);
                }
            }
        } elseif ($action == 'edit' && $id) {
            // Check if new code already exists (excluding current record)
            $check_sql = "SELECT kode_jenis_barang FROM jenis_barang WHERE kode_jenis_barang='$kode' AND kode_jenis_barang != '$id'";
            $check_result = mysqli_query($conn, $check_sql);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error = "Kode jenis barang sudah ada! Gunakan kode yang berbeda.";
            } else {
                $sql = "UPDATE jenis_barang SET kode_jenis_barang='$kode', nama_jenis_barang='$nama' WHERE kode_jenis_barang='$id'";
                if (mysqli_query($conn, $sql)) {
                    $success = "Data berhasil diperbarui!";
                    $action = 'list';
                } else {
                    $error = "Error: " . mysqli_error($conn);
                }
            }
        }
    }
} elseif ($action == 'delete' && $id) {
    $sql = "DELETE FROM jenis_barang WHERE kode_jenis_barang='$id'";
    if (mysqli_query($conn, $sql)) {
        $success = "Data berhasil dihapus!";
    } else {
        $error = "Error: " . mysqli_error($conn);
    }
    $action = 'list';
}

// Get data for edit form
if ($action == 'edit' && $id) {
    $result = mysqli_query($conn, "SELECT * FROM jenis_barang WHERE kode_jenis_barang='$id'");
    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        $action = 'list';
        $error = "Data tidak ditemukan!";
    }
}

// Get all data for listing with search functionality
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
if ($action == 'list') {
    $where_clause = "";
    if (!empty($search)) {
        $where_clause = "WHERE kode_jenis_barang LIKE '%$search%' OR nama_jenis_barang LIKE '%$search%'";
    }
    $result = mysqli_query($conn, "SELECT * FROM jenis_barang $where_clause ORDER BY kode_jenis_barang");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Jenis Barang - Kasir Dragon</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .content {
            padding: 30px;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #e1e8ed;
            border-radius: 50px;
            padding: 0;
            transition: all 0.3s ease;
            flex: 1;
            max-width: 400px;
        }

        .search-box:focus-within {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
        }

        .search-box input {
            border: none;
            outline: none;
            padding: 12px 20px;
            font-size: 16px;
            background: transparent;
            flex: 1;
            border-radius: 50px;
        }

        .search-box button {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: white;
            padding: 12px 20px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 2px;
        }

        .search-box button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(79, 172, 254, 0.3);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .btn:active {
            transform: translateY(0);
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: middle;
        }

        tr:hover {
            background: linear-gradient(135deg, rgba(79, 172, 254, 0.05) 0%, rgba(0, 242, 254, 0.05) 100%);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 8px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        .form-container {
            max-width: 600px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(17, 153, 142, 0.1) 0%, rgba(56, 239, 125, 0.1) 100%);
            color: #0f5132;
            border: 1px solid rgba(17, 153, 142, 0.2);
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(252, 70, 107, 0.1) 0%, rgba(63, 94, 251, 0.1) 100%);
            color: #842029;
            border: 1px solid rgba(252, 70, 107, 0.2);
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .no-data i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .no-data h3 {
            margin-bottom: 10px;
            color: #495057;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #4facfe;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #6c757d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #6c757d;
        }

        .breadcrumb a {
            color: #4facfe;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .content {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                max-width: none;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                justify-content: center;
            }
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #4facfe;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-boxes"></i> Manajemen Jenis Barang</h1>
            <p>Kelola data jenis barang dengan mudah dan efisien</p>
        </div>

        <div class="content">
            <div class="breadcrumb">
                <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Jenis Barang</span>
            </div>

            <?php if ($action == 'list'): ?>
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo mysqli_num_rows($result); ?></div>
                        <div class="stat-label">Total Jenis Barang</div>
                    </div>
                </div>

                <div class="toolbar">
                    <form method="GET" class="search-box">
                        <input type="text" name="search" placeholder="Cari berdasarkan kode atau nama..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                    
                    <a href="?action=add" class="btn btn-success">
                        <i class="fas fa-plus"></i>
                        Tambah Jenis Barang
                    </a>
                </div>

                <?php if (!empty($search)): ?>
                    <div style="margin-bottom: 20px;">
                        <span class="badge">Hasil pencarian untuk: "<strong><?php echo htmlspecialchars($search); ?></strong>"</span>
                        <a href="?" style="margin-left: 10px; color: #4facfe;">
                            <i class="fas fa-times"></i> Hapus filter
                        </a>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-barcode"></i> Kode Jenis Barang</th>
                                        <th><i class="fas fa-tag"></i> Nama Jenis Barang</th>
                                        <th><i class="fas fa-cogs"></i> Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row["kode_jenis_barang"]); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row["nama_jenis_barang"]); ?></td>
                                        <td class="action-buttons">
                                            <a href="?action=edit&id=<?php echo urlencode($row['kode_jenis_barang']); ?>" 
                                               class="btn btn-warning btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="?action=delete&id=<?php echo urlencode($row['kode_jenis_barang']); ?>" 
                                               class="btn btn-danger btn-sm" 
                                               onclick="return confirm('⚠️ Apakah Anda yakin ingin menghapus jenis barang \'<?php echo htmlspecialchars($row['nama_jenis_barang']); ?>\'?\n\nData yang sudah dihapus tidak dapat dikembalikan!')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-inbox"></i>
                            <h3><?php echo !empty($search) ? 'Tidak ada hasil yang ditemukan' : 'Belum ada data jenis barang'; ?></h3>
                            <p><?php echo !empty($search) ? 'Coba kata kunci yang berbeda' : 'Mulai dengan menambahkan jenis barang baru'; ?></p>
                            <?php if (empty($search)): ?>
                                <a href="?action=add" class="btn btn-success" style="margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Tambah Jenis Barang Pertama
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            
            <?php elseif ($action == 'add' || $action == 'edit'): ?>
                <div class="breadcrumb">
                    <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="?">Jenis Barang</a>
                    <i class="fas fa-chevron-right"></i>
                    <span><?php echo $action == 'add' ? 'Tambah' : 'Edit'; ?></span>
                </div>

                <div class="card">
                    <div style="padding: 30px;">
                        <h2><i class="fas fa-<?php echo $action == 'add' ? 'plus' : 'edit'; ?>"></i> 
                            <?php echo $action == 'add' ? 'Tambah' : 'Edit'; ?> Jenis Barang</h2>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="?action=<?php echo $action; ?><?php echo $id ? '&id='.urlencode($id) : ''; ?>" class="form-container">
                            <div class="form-group">
                                <label for="kode">
                                    <i class="fas fa-barcode"></i> Kode Jenis Barang
                                </label>
                                <input type="text" 
                                       id="kode" 
                                       name="kode_jenis_barang" 
                                       class="form-control"
                                       value="<?php echo isset($row) ? htmlspecialchars($row['kode_jenis_barang']) : ''; ?>" 
                                       required 
                                       placeholder="Masukkan kode jenis barang"
                                       maxlength="20">
                                <small style="color: #6c757d; font-size: 12px;">
                                    <i class="fas fa-info-circle"></i> Kode harus unik dan tidak boleh sama dengan yang sudah ada
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="nama">
                                    <i class="fas fa-tag"></i> Nama Jenis Barang
                                </label>
                                <input type="text" 
                                       id="nama" 
                                       name="nama_jenis_barang" 
                                       class="form-control"
                                       value="<?php echo isset($row) ? htmlspecialchars($row['nama_jenis_barang']) : ''; ?>" 
                                       required 
                                       placeholder="Masukkan nama jenis barang"
                                       maxlength="100">
                            </div>
                            
                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 30px;">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action == 'add' ? 'Simpan' : 'Perbarui'; ?>
                                </button>
                                <a href="?" class="btn btn-primary">
                                    <i class="fas fa-arrow-left"></i> Kembali
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>

        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 500);
                }, 5000);
            });


            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                        submitBtn.disabled = true;
                    }
                });
            });


            const deleteLinks = document.querySelectorAll('a[onclick*="confirm"]');
            deleteLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const itemName = this.getAttribute('onclick').match(/'([^']+)'/)[1];
                    
                    if (confirm(`⚠️ Apakah Anda yakin ingin menghapus jenis barang '${itemName}'?\n\nData yang sudah dihapus tidak dapat dikembalikan!`)) {

                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menghapus...';
                        this.style.pointerEvents = 'none';
                        
                        window.location.href = this.href;
                    }
                });
            });
        });

        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
?>