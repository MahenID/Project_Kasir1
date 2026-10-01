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
    <title>Transaksi Penjualan - DRAGONMART</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .navbar {
            background: rgba(255, 255, 255, 0.95) !important;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }
        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        .total-section {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
        }
        .receipt {
            background: white;
            padding: 20px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            border: 2px dashed #667eea;
        }
        .alert {
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a href="index.php" class="navbar-brand fw-bold text-primary"><i class="fas fa-dragon me-2"></i>DRAGONMART</a>
            <div class="ms-auto">
                <a href="index.php" class="btn btn-outline-primary me-2">Dashboard</a>
                <span class="me-3"><i class="fas fa-user-circle me-1"></i><?php echo $_SESSION['username']; ?></span>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <!-- Form Transaksi -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4><i class="fas fa-cash-register me-2"></i>Transaksi Penjualan</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label>Pilih Barang:</label>
                                <select name="barang" class="form-select" required>
                                    <option value="">-- Pilih Barang --</option>
                                    <?php 
                                    $q = mysqli_query($conn, "SELECT * FROM barang WHERE stok > 0 ORDER BY nama_barang"); 
                                    if ($q && mysqli_num_rows($q) > 0) {
                                        while ($d = mysqli_fetch_assoc($q)) {
                                            echo "<option value='".$d['kode_barang']."'>".$d['nama_barang']." - Rp ".number_format($d['harga_barang'])." (Stok: ".$d['stok'].")</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label>Jumlah:</label>
                                <input name="jumlah" type="number" min="1" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-shopping-cart me-2"></i>Proses Transaksi
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-history me-2"></i>Transaksi Hari Ini</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>No Transaksi</th>
                                        <th>Waktu</th>
                                        <th>Barang</th>
                                        <th>Jumlah</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $today = date('Y-m-d');

                                    $riwayat = mysqli_query($conn, "SELECT t.*, b.nama_barang, dt.jumlah_barang
                                                                   FROM transaksi t 
                                                                   LEFT JOIN barang b ON t.kode_barang = b.kode_barang
                                                                   LEFT JOIN detail_transaksi dt ON t.no_transaksi = dt.no_transaksi
                                                                   WHERE DATE(t.tgl_transaksi) = '$today' 
                                                                   ORDER BY t.tgl_transaksi DESC 
                                                                   LIMIT 10");
                                    
                                    if ($riwayat && mysqli_num_rows($riwayat) > 0) {
                                        while ($row = mysqli_fetch_assoc($riwayat)) {
                                            echo "<tr>";
                                            echo "<td>".($row['no_transaksi'] ?? 'N/A')."</td>";
                                            echo "<td>".date('H:i:s', strtotime($row['tgl_transaksi']))."</td>";
                                            echo "<td>".($row['nama_barang'] ?? 'Unknown')."</td>";
                                            echo "<td>".($row['jumlah_barang'] ?? 1)."</td>"; 
                                            echo "<td>Rp ".number_format($row['total_biaya'] ?? 0)."</td>";
                                            echo "</tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='5' class='text-center'>Belum ada transaksi hari ini</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <?php
                $no_transaksi = '';
                $total = 0;
                $h = null;

                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    if (isset($_POST['barang']) && isset($_POST['jumlah']) && 
                        !empty($_POST['barang']) && !empty($_POST['jumlah'])) {
                        
                        $kode = mysqli_real_escape_string($conn, $_POST['barang']);
                        $jumlah = (int)$_POST['jumlah'];

                        $q = mysqli_query($conn, "SELECT * FROM barang WHERE kode_barang='$kode'");
                        
                        if ($q && mysqli_num_rows($q) > 0) {
                            $h = mysqli_fetch_assoc($q);
                            
                            if ($h['stok'] >= $jumlah) {
                                $total = $h['harga_barang'] * $jumlah;
                                $new_stock = $h['stok'] - $jumlah;

                                // Generate nomor transaksi
                                $result = mysqli_query($conn, "SELECT MAX(CAST(SUBSTRING(no_transaksi, 3) AS UNSIGNED)) as max_no FROM transaksi WHERE no_transaksi LIKE 'TR%'");
                                if ($result && mysqli_num_rows($result) > 0) {
                                    $row = mysqli_fetch_assoc($result);
                                    $next_no = ($row['max_no'] ?? 0) + 1;
                                } else {
                                    $next_no = 1;
                                }
                                $no_transaksi = 'TR' . str_pad($next_no, 4, '0', STR_PAD_LEFT);

                                mysqli_begin_transaction($conn);
                                
                                try {
                                    // Insert ke tabel transaksi
                                    $query_transaksi = "INSERT INTO transaksi(no_transaksi, tgl_transaksi, total_biaya, kode_barang, id_pelanggan, id_pegawai) 
                                                       VALUES('$no_transaksi', NOW(), '$total', '$kode', '1', '1')";
                                    
                                    if (!mysqli_query($conn, $query_transaksi)) {
                                        throw new Exception("Error insert transaksi: " . mysqli_error($conn));
                                    }

                                    // Insert ke tabel detail_transaksi dengan variabel yang benar
                                    $jumlah_harga = $total; // Total harga untuk item ini
                                    $nama_barang = mysqli_real_escape_string($conn, $h['nama_barang']); // Ambil nama barang
                                    $query_detail = "INSERT INTO detail_transaksi (no_transaksi, kode_barang, nama_barang, jumlah_barang, jumlah_harga) 
                                                    VALUES ('$no_transaksi', '$kode', '$nama_barang', '$jumlah', '$jumlah_harga')";
                                    
                                    if (!mysqli_query($conn, $query_detail)) {
                                        throw new Exception("Error insert detail transaksi: " . mysqli_error($conn));
                                    }

                                    // Update stok barang
                                    $query_update = "UPDATE barang SET stok='$new_stock' WHERE kode_barang='$kode'";
                                    if (!mysqli_query($conn, $query_update)) {
                                        throw new Exception("Error update stok: " . mysqli_error($conn));
                                    }

                                    mysqli_commit($conn);

                                    echo "<div class='card mt-4'>
                                            <div class='card-header bg-success text-white'>
                                                <h5><i class='fas fa-check-circle me-2'></i>Transaksi Berhasil</h5>
                                            </div>
                                            <div class='card-body'>
                                                <div class='receipt'>
                                                    <div class='text-center mb-3'>
                                                        <h6><strong>KASIR DRAGON</strong></h6>
                                                        <p>Jl. Contoh No. 123</p>
                                                        <hr>
                                                    </div>
                                                    <p><strong>No Transaksi:</strong> $no_transaksi</p>
                                                    <p><strong>Tanggal:</strong> ".date('d/m/Y H:i:s')."</p>
                                                    <p><strong>Kasir:</strong> ".$_SESSION['username']."</p>
                                                    <hr>
                                                    <p><strong>Nama Barang:</strong> ".$h['nama_barang']."</p>
                                                    <p><strong>Harga Satuan:</strong> Rp ".number_format($h['harga_barang'])."</p>
                                                    <p><strong>Jumlah:</strong> ".$jumlah."</p>
                                                    <hr>
                                                    <p><strong>Total Bayar:</strong> <span class='fs-5'>Rp ".number_format($total)."</span></p>
                                                    <p><strong>Sisa Stok:</strong> ".$new_stock."</p>
                                                    <hr>
                                                    <div class='text-center'>
                                                        <p><small>Terima kasih atas kunjungan Anda!</small></p>
                                                    </div>
                                                </div>
                                                <div class='text-center mt-3'>
                                                    <button onclick='window.print()' class='btn btn-outline-primary btn-sm'>
                                                        <i class='fas fa-print me-1'></i>Print Struk
                                                    </button>
                                                </div>
                                            </div>
                                          </div>";

                                } catch (Exception $e) {
                                    mysqli_rollback($conn);
                                    echo "<div class='alert alert-danger mt-4'>
                                            <i class='fas fa-exclamation-triangle me-2'></i>
                                            Transaksi gagal: " . $e->getMessage() . "
                                          </div>";
                                }
                                
                            } else {
                                echo "<div class='alert alert-warning mt-4'>
                                        <i class='fas fa-exclamation-triangle me-2'></i>
                                        Stok tidak mencukupi! Stok tersedia: ".$h['stok']."
                                      </div>";
                            }
                        } else {
                            echo "<div class='alert alert-danger mt-4'>
                                    <i class='fas fa-times-circle me-2'></i>
                                    Barang tidak ditemukan!
                                  </div>";
                        }
                    } else {
                        echo "<div class='alert alert-warning mt-4'>
                                <i class='fas fa-exclamation-triangle me-2'></i>
                                Mohon lengkapi semua field!
                              </div>";
                    }
                }
                        echo "<div class='text-center mt-4'>
                            <a href='index.php' class='btn btn-secondary'>
                                <i class='fas fa-arrow-left me-2'></i>Kembali ke Dashboard
                            </a>
                            </div>";
                // Summary hari ini
                $today = date('Y-m-d');
                $summary = mysqli_query($conn, "SELECT COUNT(*) as total_transaksi, SUM(total_biaya) as total_pendapatan 
                                               FROM transaksi 
                                               WHERE DATE(tgl_transaksi) = '$today'");
                
                if ($summary && mysqli_num_rows($summary) > 0) {
                    $sum = mysqli_fetch_assoc($summary);
                } else {
                    $sum = ['total_transaksi' => 0, 'total_pendapatan' => 0];
                }
                ?>

                <div class="card mt-4">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="fas fa-chart-line me-2"></i>Ringkasan Hari Ini</h5>
                    </div>
                    <div class="card-body">
                        <div class="total-section">
                            <h6>Total Transaksi</h6>
                            <h4><?php echo $sum['total_transaksi'] ?? 0; ?></h4>
                        </div>
                        <div class="total-section mt-3">
                            <h6>Total Pendapatan</h6>
                            <h4>Rp <?php echo number_format($sum['total_pendapatan'] ?? 0); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>