<?php
include 'koneksi.php';

$query = "SELECT dt.*, b.nama_barang as nama_barang_asli, b.harga as harga_satuan 
          FROM detail_transaksi dt 
          LEFT JOIN barang b ON dt.kode_barang = b.kode_barang 
          ORDER BY dt.no_transaksi DESC";
$result = mysqli_query($conn, $query);

$total_query = mysqli_query($conn, "SELECT 
                                    COUNT(*) as total_item, 
                                    SUM(jumlah_barang) as total_qty, 
                                    SUM(jumlah_harga) as total_nilai 
                                    FROM detail_transaksi");
$total_data = mysqli_fetch_assoc($total_query);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Detail Transaksi - Kasir Dragon</title>
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
            backdrop-filter: blur(10px);
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        .badge-custom {
            padding: 8px 12px;
            border-radius: 20px;
        }
        .stats-card {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a href="index.php" class="navbar-brand fw-bold text-primary">
                <i class="fas fa-dragon me-2"></i>Kasir Dragon
            </a>
            <div class="ms-auto">
                <a href="index.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-home me-1"></i>Dashboard
                </a>
                <a href="transaksi.php" class="btn btn-outline-success">
                    <i class="fas fa-cash-register me-1"></i>Transaksi
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <h2 class="card-title text-primary">
                            <i class="fas fa-list-alt me-2"></i>Data Detail Transaksi
                        </h2>
                        <p class="card-text text-muted">Rincian semua item yang terjual</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card">
                    <h3><?php echo number_format($total_data['total_item'] ?? 0); ?></h3>
                    <p><i class="fas fa-receipt me-2"></i>Total Transaksi</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3><?php echo number_format($total_data['total_qty'] ?? 0); ?></h3>
                    <p><i class="fas fa-boxes me-2"></i>Total Barang Terjual</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3>Rp <?php echo number_format($total_data['total_nilai'] ?? 0); ?></h3>
                    <p><i class="fas fa-money-bill-wave me-2"></i>Total Nilai Penjualan</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-0">
                            <i class="fas fa-table me-2"></i>Detail Transaksi
                        </h5>
                    </div>
                    <div class="col-auto">
                        <button onclick="window.print()" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <button onclick="exportToCSV()" class="btn btn-outline-light btn-sm ms-2">
                            <i class="fas fa-download me-1"></i>Export CSV
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0" id="dataTable">
                        <thead class="table-dark">
                             <tr>
                                <th>No</th>
                                <th>No Transaksi</th>
                                <th>Kode Barang</th>
                                <th>Nama Barang</th>
                                <th>Harga Satuan</th> 
                                <th>Jumlah</th>
                                <th>Total Harga</th>
                                </tr>
                            </thead>

                        <tbody>
                                <?php 
                                if (mysqli_num_rows($result) > 0) {
                                $no = 1;
                                $grand_total = 0;
                                while($row = mysqli_fetch_assoc($result)) { 
                                    $grand_total += $row['jumlah_harga'];
                            ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= $no++; ?></span></td>
                                <td><strong class="text-primary"><?= htmlspecialchars($row["no_transaksi"]); ?></strong></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($row["kode_barang"]); ?></span></td>
                                <td><strong><?= htmlspecialchars($row["nama_barang_asli"] ?? 'Nama tidak ditemukan'); ?></strong></td>
                                <td><span class="badge bg-light text-dark">Rp <?= number_format($row["harga_satuan"] ?? 0); ?></span></td> <!-- Tambahan -->
                                <td><span class="badge badge-custom bg-warning text-dark"><?= number_format($row["jumlah_barang"]); ?> pcs</span></td>
                                <td><strong class="text-success">Rp <?= number_format($row["jumlah_harga"]); ?></strong></td>
                            </tr>
                            <?php 
                                }  
                            ?>

                            <tr class="table-primary">
                                <td colspan="5" class="text-end"><strong>GRAND TOTAL:</strong></td>
                                <td><strong><?= number_format($total_data['total_qty'] ?? 0); ?> pcs</strong></td>
                                <td><strong class="text-success fs-5">Rp <?= number_format($grand_total); ?></strong></td>
                            </tr>
                            <?php 
                            } else { 

                            ?>
                            <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                    <h5>Belum ada data transaksi</h5>
                                    <p>Silakan lakukan transaksi terlebih dahulu</p>
                                    <a href="transaksi.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i>Buat Transaksi
                                    </a>
                                </div>
                                </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Data diperbarui secara real-time • 
                            <i class="fas fa-calendar me-1"></i>
                            <?php echo date('d F Y, H:i:s'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>

        function exportToCSV() {
            const table = document.getElementById('dataTable');
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length - 1; i++) { 
                const row = [], cols = rows[i].querySelectorAll('td, th');
                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/,/g, '');
                    row.push('"' + data + '"');
                }
                csv.push(row.join(','));
            }
            
            const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
            const downloadLink = document.createElement('a');
            downloadLink.download = 'detail_transaksi_' + new Date().toISOString().slice(0,10) + '.csv';
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        // Auto refresh setiap 30 detik
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>

    <style media="print">
        .no-print { display: none !important; }
        body { background: white !important; }
        .card { box-shadow: none !important; }
    </style>
</body>
</html>