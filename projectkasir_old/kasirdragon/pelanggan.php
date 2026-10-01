<?php
include 'koneksi.php';

// Pagination settings
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$whereClause = '';
if (!empty($search)) {
    $whereClause = "WHERE nama LIKE '%$search%' OR alamat LIKE '%$search%' OR no_tlp LIKE '%$search%' OR email LIKE '%$search%'";
}

// Get total records for pagination
$countQuery = "SELECT COUNT(*) as total FROM pelanggan $whereClause";
$countResult = mysqli_query($conn, $countQuery);
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $limit);

// Get data with pagination
$query = "SELECT * FROM pelanggan $whereClause ORDER BY id_pelanggan DESC LIMIT $limit OFFSET $offset";
$result = mysqli_query($conn, $query);

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    // Fixed: Use correct column name for delete query
    $deleteQuery = "DELETE FROM pelanggan WHERE id_pelanggan = $deleteId";
    if (mysqli_query($conn, $deleteQuery)) {
        $success_message = "Data pelanggan berhasil dihapus!";
        // Redirect to avoid resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=deleted");
        exit();
    } else {
        $error_message = "Error: " . mysqli_error($conn);
    }
}

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 'deleted') {
    $success_message = "Data pelanggan berhasil dihapus!";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .main-container {
            margin: 20px auto;
            max-width: 1200px;
        }
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
        }
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .btn-action {
            margin: 2px;
            padding: 5px 10px;
            border-radius: 5px;
        }
        .search-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        .gender-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .gender-male {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        .gender-female {
            background-color: #fce4ec;
            color: #c2185b;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 30px;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .action-buttons {
            white-space: nowrap;
        }
        .quick-actions {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.9rem;
            }
            .btn-action {
                padding: 3px 6px;
                font-size: 0.8rem;
            }
            .stats-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid main-container">
        <!-- Header Section -->
        <div class="header-section fade-in">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-users"></i> Manajemen Data Pelanggan
                    </h1>
                    <p class="mb-0 opacity-75">Kelola data pelanggan dengan mudah dan efisien</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="d-inline-block">
                        <div class="stats-card d-inline-block me-3" style="min-width: 120px;">
                            <div class="stats-number"><?php echo $totalRecords; ?></div>
                            <div class="text-muted">Total Pelanggan</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions fade-in">
            <div class="row">
                <div class="col-md-6">
                    <h5 class="mb-3"><i class="fas fa-bolt"></i> Aksi Cepat</h5>
                    <a href="tambah_pelanggan.php" class="btn btn-primary me-2 mb-2">
                        <i class="fas fa-plus"></i> Tambah Pelanggan
                    </a>
                    <button class="btn btn-outline-success me-2 mb-2" onclick="exportData()">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                    <button class="btn btn-outline-warning me-2 mb-2" onclick="printData()">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                </div>
                <div class="col-md-6">
                    <h5 class="mb-3"><i class="fas fa-chart-bar"></i> Statistik</h5>
                    <div class="row">
                        <div class="col-6">
                            <div class="text-center">
                                <div class="h4 text-primary mb-0">
                                    <?php 
                                    $maleQuery = "SELECT COUNT(*) as total FROM pelanggan WHERE jenis_kelamin = 'L'";
                                    $maleResult = mysqli_query($conn, $maleQuery);
                                    $maleCount = mysqli_fetch_assoc($maleResult)['total'];
                                    echo $maleCount;
                                    ?>
                                </div>
                                <small class="text-muted">Laki-laki</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <div class="h4 text-danger mb-0">
                                    <?php 
                                    $femaleQuery = "SELECT COUNT(*) as total FROM pelanggan WHERE jenis_kelamin = 'P'";
                                    $femaleResult = mysqli_query($conn, $femaleQuery);
                                    $femaleCount = mysqli_fetch_assoc($femaleResult)['total'];
                                    echo $femaleCount;
                                    ?>
                                </div>
                                <small class="text-muted">Perempuan</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Section -->
        <div class="search-section fade-in">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-8">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               name="search" 
                               placeholder="Cari berdasarkan nama, alamat, telepon, atau email..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-grid gap-2 d-md-flex">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Cari
                        </button>
                        <?php if (!empty($search)): ?>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Table Section -->
        <div class="table-container fade-in">
            <?php if (mysqli_num_rows($result) > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-table"></i> Daftar Pelanggan
                    <?php if (!empty($search)): ?>
                    <small class="text-muted">(Hasil pencarian: "<?php echo htmlspecialchars($search); ?>")</small>
                    <?php endif; ?>
                </h5>
                <div class="text-muted">
                    Menampilkan <?php echo min($limit, $totalRecords - $offset); ?> dari <?php echo $totalRecords; ?> data
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-striped" id="pelangganTable">
                    <thead class="table-dark">
                        <tr>
                            <th width="5%">No</th>
                            <th width="15%">Nama</th>
                            <th width="10%">Jenis Kelamin</th>
                            <th width="25%">Alamat</th>
                            <th width="15%">No. Telepon</th>
                            <th width="15%">Email</th>
                            <th width="15%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = $offset + 1;
                        while ($row = mysqli_fetch_assoc($result)): 
                        ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($row['nama']); ?></strong>
                            </td>
                            <td>
                                <?php if ($row['jenis_kelamin'] == 'L'): ?>
                                <span class="gender-badge gender-male">
                                    <i class="fas fa-mars"></i> Laki-laki
                                </span>
                                <?php else: ?>
                                <span class="gender-badge gender-female">
                                    <i class="fas fa-venus"></i> Perempuan
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($row['alamat']); ?></small>
                            </td>
                            <td>
                                <a href="tel:<?php echo $row['no_tlp']; ?>" class="text-decoration-none">
                                    <i class="fas fa-phone text-success"></i> 
                                    <?php echo htmlspecialchars($row['no_tlp']); ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($row['email'])): ?>
                                <a href="mailto:<?php echo $row['email']; ?>" class="text-decoration-none">
                                    <i class="fas fa-envelope text-primary"></i> 
                                    <?php echo htmlspecialchars($row['email']); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted"><i>Tidak ada</i></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="detail_pelanggan.php?id=<?php echo $row['id_pelanggan']; ?>" 
                                       class="btn btn-sm btn-info btn-action" 
                                       title="Lihat Detail">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit_pelanggan.php?id=<?php echo $row['id_pelanggan']; ?>" 
                                       class="btn btn-sm btn-warning btn-action" 
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button class="btn btn-sm btn-danger btn-action" 
                                            title="Hapus"
                                            onclick="confirmDelete(<?php echo $row['id_pelanggan']; ?>, '<?php echo htmlspecialchars($row['nama']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <nav aria-label="Page navigation">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo ($page-1); ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">
                                <i class="fas fa-chevron-left"></i> Sebelumnya
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo ($page+1); ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">
                                Selanjutnya <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <h4>Tidak Ada Data Pelanggan</h4>
                <?php if (!empty($search)): ?>
                <p>Tidak ditemukan hasil untuk pencarian "<strong><?php echo htmlspecialchars($search); ?></strong>"</p>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-list"></i> Lihat Semua Data
                </a>
                <?php else: ?>
                <p>Belum ada data pelanggan yang tersimpan dalam sistem</p>
                <a href="tambah_pelanggan.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Tambah Pelanggan Pertama
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i> Konfirmasi Hapus
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus data pelanggan:</p>
                    <div class="alert alert-warning">
                        <strong id="customerName"></strong>
                    </div>
                    <p class="text-danger">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Peringatan:</strong> Data yang dihapus tidak dapat dikembalikan!
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Ya, Hapus
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete(id, name) {
            document.getElementById('customerName').textContent = name;
            document.getElementById('confirmDeleteBtn').href = '?delete=' + id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        function exportData() {
            // Implementasi export data
            alert('Fitur export akan segera tersedia');
        }

        function printData() {
            window.print();
        }

        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });

        const backBtn = document.createElement('a');
        backBtn.href = 'index.php';
        backBtn.className = 'btn btn-lg btn-gradient position-fixed d-flex align-items-center justify-content-center shadow';
        backBtn.style = `
            bottom: 30px;
            right: 30px;
            z-index: 1050;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border-radius: 50px;
            padding: 10px 20px;
            font-weight: bold;
            box-shadow: 0 8px 24px rgba(102,126,234,0.18);
            transition: background 0.2s, transform 0.2s;
        `;
        backBtn.innerHTML = '<i class="fas fa-arrow-left me-2"></i> Kembali ke Beranda';
        backBtn.onmouseover = function() {
            backBtn.style.background = 'linear-gradient(135deg, #764ba2 0%, #667eea 100%)';
            backBtn.style.transform = 'translateY(-3px) scale(1.03)';
        };
        backBtn.onmouseout = function() {
            backBtn.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
            backBtn.style.transform = 'none';
        };
        document.body.appendChild(backBtn);
        document.addEventListener('DOMContentLoaded', function() {
            const buttons = document.querySelectorAll('button[type="submit"], .btn');
            buttons.forEach(function(button) {
                button.addEventListener('click', function() {
                    if (this.type === 'submit') {
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                        this.disabled = true;
                    }
                });
            });
        });
    </script>
</body>
</html>