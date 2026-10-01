<?php 
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
include 'koneksi.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>DRAGONMART - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: rgba(255, 255, 255, 0.95) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        .feature-card {
            cursor: pointer;
            border-radius: 20px;
            padding: 2rem;
            text-align: center;
            height: 200px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            margin-bottom: 20px;
        }
        .feature-card i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .welcome-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 2rem;
            margin-bottom: 30px;
        }
        .sidebar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 1.5rem;
            height: fit-content;
        }
        .menu-item {
            display: block;
            padding: 12px 15px;
            margin: 5px 0;
            border-radius: 10px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
        }
        .menu-item:hover {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            transform: translateX(5px);
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary">
                <i class="fas fa-dragon me-2"></i>DRAGONMART
            </a>
            <div class="ms-auto d-flex align-items-center">
                <span class="me-3">
                    <i class="fas fa-user-circle me-1"></i>
                    <?php echo $_SESSION['username']; ?> 
                    <span class="badge bg-<?php echo $_SESSION['role'] == 'admin' ? 'danger' : 'info'; ?>">
                        <?php echo ucfirst($_SESSION['role']); ?>
                    </span>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="row">
            <!-- Sidebar Menu -->
            <div class="col-md-3">
                <div class="sidebar">
                    <h5 class="mb-3"><i class="fas fa-bars me-2"></i>Menu Utama</h5>
                    
                    <?php if ($_SESSION['role'] == 'admin') { ?>
                        <a href="transaksi.php" class="menu-item">
                            <i class="fas fa-cash-register me-2"></i>Transaksi Penjualan
                        </a>
                        <a href="barang.php" class="menu-item">
                            <i class="fas fa-box me-2"></i>Kelola Barang
                        </a>
                        <a href="supplier.php" class="menu-item">
                            <i class="fas fa-truck me-2"></i>Data Supplier
                        </a>
                        <a href="pegawai.php" class="menu-item">
                            <i class="fas fa-users me-2"></i>Manajemen Pegawai
                        </a>
                        <a href="pelanggan.php" class="menu-item">
                            <i class="fas fa-user-friends me-2"></i>Data Pelanggan
                        </a>
                        <a href="laporan_transaksi.php" class="menu-item">
                            <i class="fas fa-chart-line me-2"></i>Laporan Transaksi
                        </a>
                        <a href="laporan_supplier.php" class="menu-item">
                            <i class="fas fa-file-alt me-2"></i>Laporan Supplier
                        </a>
                        <a href="jenis_barang.php" class="menu-item">
                            <i class="fas fa-tags me-2"></i>Jenis Barang
                        </a>
                    <?php } else { ?>
                        <a href="transaksi.php" class="menu-item">
                            <i class="fas fa-cash-register me-2"></i>Transaksi Penjualan
                        </a>
                        <a href="order.php" class="menu-item">
                            <i class="fas fa-shopping-cart me-2"></i>Data Order
                        </a>
                        <a href="pelanggan.php" class="menu-item">
                            <i class="fas fa-user-friends me-2"></i>Data Pelanggan
                        </a>
                        <a href="profile.php" class="menu-item">
                            <i class="fas fa-user-cog me-2"></i>Profil Saya
                        </a>
                    <?php } ?>
                </div>

                <!-- Quick Stats -->
                <div class="card mt-4">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Statistik Cepat</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-12 mb-2">
                                <div class="bg-success text-white p-2 rounded">
                                    <strong><?php 
                                    $q = mysqli_query($conn, "SELECT COUNT(*) FROM barang"); 
                                    $d = mysqli_fetch_array($q); 
                                    echo $d[0]; 
                                    ?> Barang</strong>
                                </div>
                            </div>
                            <div class="col-12 mb-2">
                                <div class="bg-info text-white p-2 rounded">
                                    <strong><?php 
                                    $q = mysqli_query($conn, "SELECT COUNT(*) FROM transaksi"); 
                                    $d = mysqli_fetch_array($q); 
                                    echo $d[0]; 
                                    ?> Transaksi</strong>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="bg-warning text-white p-2 rounded">
                                    <strong><?php 
                                    $q = mysqli_query($conn, "SELECT COUNT(*) FROM supplier"); 
                                    $d = mysqli_fetch_array($q); 
                                    echo $d[0]; 
                                    ?> Supplier</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9">
                <!-- Welcome Card -->
                <div class="card welcome-card">
                    <h2><i class="fas fa-home me-2"></i>Selamat Datang, <?php echo $_SESSION['username']; ?>!</h2>
                    <p class="mb-0">Kelola sistem kasir Anda dengan mudah dan efisien</p>
                </div>

                <!-- Feature Cards -->
                <div class="row">
                    <!-- Transaksi Card -->
                    <div class="col-md-4">
                        <div class="card feature-card bg-gradient" style="background: linear-gradient(45deg, #FF6B6B, #FF8E8E);" onclick="location.href='transaksi.php'">
                            <i class="fas fa-cash-register text-white"></i>
                            <h5 class="text-white">Transaksi Baru</h5>
                            <p class="text-white">Proses penjualan barang</p>
                        </div>
                    </div>

                    <?php if ($_SESSION['role'] == 'admin') { ?>
                    <!-- Barang Card -->
                    <div class="col-md-4">
                        <div class="card feature-card" style="background: linear-gradient(45deg, #4ECDC4, #44A08D);" onclick="location.href='barang.php'">
                            <i class="fas fa-box text-white"></i>
                            <h5 class="text-white">Kelola Barang</h5>
                            <p class="text-white">Tambah & edit barang</p>
                        </div>
                    </div>

                    <!-- Laporan Card -->
                    <div class="col-md-4">
                        <div class="card feature-card" style="background: linear-gradient(45deg, #667eea, #764ba2);" onclick="location.href='laporan_transaksi.php'">
                            <i class="fas fa-chart-line text-white"></i>
                            <h5 class="text-white">Laporan</h5>
                            <p class="text-white">Lihat laporan penjualan</p>
                        </div>
                    </div>

                    <!-- Supplier Card -->
                    <div class="col-md-4">
                        <div class="card feature-card" style="background: linear-gradient(45deg, #FFA726, #FB8C00);" onclick="location.href='supplier.php'">
                            <i class="fas fa-truck text-white"></i>
                            <h5 class="text-white">Supplier</h5>
                            <p class="text-white">Kelola data supplier</p>
                        </div>
                    </div>

                    <!-- Pegawai Card -->
                    <div class="col-md-4">
                        <div class="card feature-card" style="background: linear-gradient(45deg, #AB47BC, #8E24AA);" onclick="location.href='pegawai.php'">
                            <i class="fas fa-users text-white"></i>
                            <h5 class="text-white">Pegawai</h5>
                            <p class="text-white">Manajemen shift pegawai</p>
                        </div>
                    </div>

                    <!-- Pelanggan Card -->
                    <div class="col-md-4">
                        <div class="card feature-card" style="background: linear-gradient(45deg, #26C6DA, #00ACC1);" onclick="location.href='pelanggan.php'">
                            <i class="fas fa-user-friends text-white"></i>
                            <h5 class="text-white">Pelanggan</h5>
                            <p class="text-white">Data pelanggan</p>
                        </div>
                    </div>
                    <?php } else { ?>
                    <!-- Order Card for Pegawai -->
                    <div class="col-md-4">
                        <div class="card feature-card" style="background: linear-gradient(45deg, #4ECDC4, #44A08D);" onclick="location.href='order.php'">
                            <i class="fas fa-shopping-cart text-white"></i>
                            <h5 class="text-white">Data Order</h5>
                            <p class="text-white">Kelola pesanan</p>
                        </div>
                    </div>

                    <!-- Profile Card for Pegawai -->
                    <div class="col-md-4">
                        <div class="card feature-card" style="background: linear-gradient(45deg, #667eea, #764ba2);" onclick="location.href='profile.php'">
                            <i class="fas fa-user-cog text-white"></i>
                            <h5 class="text-white">Profil Saya</h5>
                            <p class="text-white">Lihat profil pengguna</p>
                        </div>
                    </div>
                    <?php } ?>
                </div>

                <!-- Recent Activity -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Aktivitas Terbaru</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-shopping-bag me-2"></i>Transaksi Terakhir</h6>
                                <div class="list-group list-group-flush">
                                    <?php 
                                    $q = mysqli_query($conn, "SELECT t.*, b.nama_barang FROM transaksi t 
                                                           JOIN barang b ON t.kode_barang = b.kode_barang 
                                                           ORDER BY t.tgl_transaksi DESC LIMIT 5");
                                    while ($d = mysqli_fetch_assoc($q)) {
                                        echo "<div class='list-group-item border-0 px-0'>
                                                <div class='d-flex justify-content-between'>
                                                    <span>{$d['nama_barang']}</span>
                                                    <span class='text-success fw-bold'>Rp " . number_format($d['total_biaya']) . "</span>
                                                </div>
                                                <small class='text-muted'>{$d['tgl_transaksi']}</small>
                                              </div>";
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-chart-pie me-2"></i>Ringkasan Hari Ini</h6>
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="bg-light p-3 rounded">
                                            <h4 class="text-primary mb-0">
                                                <?php 
                                                $q = mysqli_query($conn, "SELECT COUNT(*) FROM transaksi WHERE DATE(tgl_transaksi) = CURDATE()"); 
                                                $d = mysqli_fetch_array($q); 
                                                echo $d[0]; 
                                                ?>
                                            </h4>
                                            <small>Transaksi Hari Ini</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="bg-light p-3 rounded">
                                            <h4 class="text-success mb-0">
                                                Rp <?php 
                                                $q = mysqli_query($conn, "SELECT SUM(total_biaya) FROM transaksi WHERE DATE(tgl_transaksi) = CURDATE()"); 
                                                $d = mysqli_fetch_array($q); 
                                                echo number_format($d[0] ?? 0); 
                                                ?>
                                            </h4>
                                            <small>Pendapatan Hari Ini</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.feature-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>