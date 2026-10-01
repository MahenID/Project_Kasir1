<?php
include 'koneksi.php';

$message = '';
$message_type = '';

if (isset($_POST['add_supplier'])) {
    $id_supplier = mysqli_real_escape_string($conn, $_POST['id_supplier']);
    $nama_supplier = mysqli_real_escape_string($conn, $_POST['nama_supplier']);
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat']);
    $telepon = mysqli_real_escape_string($conn, $_POST['no_tlp']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    $query = "INSERT INTO supplier (id_supplier, nama_supplier, alamat, no_tlp, email) VALUES ('$id_supplier', '$nama_supplier', '$alamat', '$telepon', '$email')";
    
    if (mysqli_query($conn, $query)) {
        $message = "Supplier berhasil ditambahkan!";
        $message_type = "success";
    } else {
        $message = "Error: " . mysqli_error($conn);
        $message_type = "danger";
    }
}

if (isset($_GET['delete'])) {
    $id = mysqli_real_escape_string($conn, $_GET['delete']);
    $query = "DELETE FROM supplier WHERE id_supplier = '$id'";
    
    if (mysqli_query($conn, $query)) {
        $message = "Supplier berhasil dihapus!";
        $message_type = "success";
    } else {
        $message = "Error: " . mysqli_error($conn);
        $message_type = "danger";
    }
}

if (isset($_POST['edit_supplier'])) {
    $id_supplier = mysqli_real_escape_string($conn, $_POST['id_supplier']);
    $nama_supplier = mysqli_real_escape_string($conn, $_POST['nama_supplier']);
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat']);
    $telepon = mysqli_real_escape_string($conn, $_POST['no_tlp']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $old_id = mysqli_real_escape_string($conn, $_POST['old_id']);
    
    $query = "UPDATE supplier SET id_supplier='$id_supplier', nama_supplier='$nama_supplier', alamat='$alamat', no_tlp='$telepon', email='$email' WHERE id_supplier='$old_id'";
    
    if (mysqli_query($conn, $query)) {
        $message = "Supplier berhasil diupdate!";
        $message_type = "success";
    } else {
        $message = "Error: " . mysqli_error($conn);
        $message_type = "danger";
    }
}

$suppliers = mysqli_query($conn, "SELECT * FROM supplier ORDER BY nama_supplier ASC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Supplier - DRAGONMART</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .header-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 10px,
                rgba(255,255,255,0.1) 10px,
                rgba(255,255,255,0.1) 20px
            );
            animation: slide 20s linear infinite;
        }
        
        @keyframes slide {
            0% { transform: translateX(-50px); }
            100% { transform: translateX(50px); }
        }
        
        .header-content {
            position: relative;
            z-index: 2;
        }
        
        .form-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 20px rgba(102, 126, 234, 0.3);
            outline: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-home {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
        }
        
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(40, 167, 69, 0.3);
            color: white;
            text-decoration: none;
        }
        
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .table thead th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1rem;
            font-weight: 600;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
        }
        
        .table tbody td {
            padding: 1rem;
            border: none;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            vertical-align: middle;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .navigation-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .nav-left {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .nav-right {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .navigation-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .nav-left, .nav-right {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="main-container mx-auto" style="max-width: 1200px;">
            <div class="header-section">
                <div class="header-content">
                    <h1 class="mb-0">
                        <i class="fas fa-truck me-3"></i>
                        Kelola Supplier
                    </h1>
                    <p class="mb-0 mt-2 opacity-75">Tambah, Edit, dan Hapus Data Supplier</p>
                </div>
            </div>
            
            <div class="p-4">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="mb-4">
                    <div class="navigation-section">
                        <div class="nav-left">
                            <a href="index.php" class="btn btn-home">
                                <i class="fas fa-home me-2"></i>Kembali ke Beranda
                            </a>
                        </div>
                        <div class="nav-right">
                            <a href="laporan_supplier.php" class="btn btn-outline-primary me-2">
                                <i class="fas fa-chart-bar me-1"></i> Lihat Laporan
                            </a>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                                <i class="fas fa-plus me-1"></i> Tambah Supplier
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Suppliers Table -->
                <div class="table-container">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th><i class="fas fa-hashtag me-2"></i>No</th>
                                <th><i class="fas fa-id-card me-2"></i>ID Supplier</th>
                                <th><i class="fas fa-building me-2"></i>Nama Supplier</th>
                                <th><i class="fas fa-map-marker-alt me-2"></i>Alamat</th>
                                <th><i class="fas fa-phone me-2"></i>Telepon</th>
                                <th><i class="fas fa-envelope me-2"></i>Email</th>
                                <th><i class="fas fa-cogs me-2"></i>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (mysqli_num_rows($suppliers) > 0) {
                                $no = 1;
                                while ($supplier = mysqli_fetch_assoc($suppliers)) {
                                    echo "<tr>
                                            <td><strong>$no</strong></td>
                                            <td><span class='badge bg-primary'>" . htmlspecialchars($supplier['id_supplier']) . "</span></td>
                                            <td><strong>" . htmlspecialchars($supplier['nama_supplier']) . "</strong></td>
                                            <td>" . htmlspecialchars($supplier['alamat'] ?? '-') . "</td>
                                            <td>" . htmlspecialchars($supplier['telepon'] ?? '-') . "</td>
                                            <td>" . htmlspecialchars($supplier['email'] ?? '-') . "</td>
                                            <td>
                                                <button class='btn btn-warning btn-sm me-1' onclick='editSupplier(" . json_encode($supplier) . ")'>
                                                    <i class='fas fa-edit'></i>
                                                </button>
                                                <a href='?delete=" . urlencode($supplier['id_supplier']) . "' 
                                                   class='btn btn-danger btn-sm' 
                                                   onclick='return confirm(\"Yakin ingin menghapus supplier ini?\")'>
                                                    <i class='fas fa-trash'></i>
                                                </a>
                                            </td>
                                          </tr>";
                                    $no++;
                                }
                            } else {
                                echo "<tr><td colspan='7' class='text-center py-4'>
                                        <i class='fas fa-inbox fa-3x mb-3 text-muted'></i><br>
                                        <h5>Belum ada data supplier</h5>
                                        <p>Klik tombol 'Tambah Supplier' untuk menambah data.</p>
                                      </td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addSupplierModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Tambah Supplier Baru
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-id-card me-1"></i>ID Supplier *
                                </label>
                                <input type="text" class="form-control" name="id_supplier" required 
                                       placeholder="SUP001" pattern="[A-Za-z0-9]+" 
                                       title="Hanya huruf dan angka yang diperbolehkan">
                                <div class="form-text">Format: SUP001, SUP002, dll</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-building me-1"></i>Nama Supplier *
                                </label>
                                <input type="text" class="form-control" name="nama_supplier" required 
                                       placeholder="PT. Supplier Indonesia">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt me-1"></i>Alamat
                            </label>
                            <textarea class="form-control" name="alamat" rows="3" 
                                      placeholder="Jl. Contoh No. 123, Jakarta"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-phone me-1"></i>Telepon
                                </label>
                                <input type="tel" class="form-control" name="telepon" 
                                       placeholder="021-12345678">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-envelope me-1"></i>Email
                                </label>
                                <input type="email" class="form-control" name="email" 
                                       placeholder="supplier@email.com">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_supplier" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Simpan Supplier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editSupplierModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Supplier
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="old_id" id="edit_old_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-id-card me-1"></i>ID Supplier *
                                </label>
                                <input type="text" class="form-control" name="id_supplier" id="edit_id_supplier" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-building me-1"></i>Nama Supplier *
                                </label>
                                <input type="text" class="form-control" name="nama_supplier" id="edit_nama_supplier" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt me-1"></i>Alamat
                            </label>
                            <textarea class="form-control" name="alamat" id="edit_alamat" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-phone me-1"></i>Telepon
                                </label>
                                <input type="tel" class="form-control" name="telepon" id="edit_telepon">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-envelope me-1"></i>Email
                                </label>
                                <input type="email" class="form-control" name="email" id="edit_email">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit_supplier" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Update Supplier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editSupplier(supplier) {
            document.getElementById('edit_old_id').value = supplier.id_supplier;
            document.getElementById('edit_id_supplier').value = supplier.id_supplier;
            document.getElementById('edit_nama_supplier').value = supplier.nama_supplier;
            document.getElementById('edit_alamat').value = supplier.alamat || '';
            document.getElementById('edit_no_tlp').value = supplier.telepon || '';
            document.getElementById('edit_email').value = supplier.email || '';
            
            new bootstrap.Modal(document.getElementById('editSupplierModal')).show();
        }
    </script>
</body>
</html>