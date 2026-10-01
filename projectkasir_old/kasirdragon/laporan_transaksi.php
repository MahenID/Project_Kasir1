<?php 
include 'koneksi.php';

// Security: Validate and sanitize input dates
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Set default date range (current month)
$start_date = isset($_GET['start_date']) && validateDate($_GET['start_date']) 
    ? $_GET['start_date'] 
    : date('Y-m-01');

$end_date = isset($_GET['end_date']) && validateDate($_GET['end_date']) 
    ? $_GET['end_date'] 
    : date('Y-m-d');

// Ensure start_date is not after end_date
if ($start_date > $end_date) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Escape dates for SQL queries
$safe_start_date = mysqli_real_escape_string($conn, $start_date);
$safe_end_date = mysqli_real_escape_string($conn, $end_date);

// Get additional filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Build WHERE clause with filters
$where_conditions = ["t.tgl_transaksi BETWEEN '$safe_start_date' AND '$safe_end_date'"];
if (!empty($search)) {
    $where_conditions[] = "(t.no_transaksi LIKE '%$search%' OR b.nama_barang LIKE '%$search%')";
}
$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Summary query with error handling
$summary_query = mysqli_query($conn, 
    "SELECT 
        COUNT(DISTINCT t.no_transaksi) as total_transactions,
        COALESCE(SUM(dt.jumlah_barang), 0) as total_items,
        COALESCE(SUM(dt.jumlah_harga), 0) as total_revenue,
        COALESCE(AVG(dt.jumlah_harga), 0) as avg_transaction
    FROM detail_transaksi dt
    JOIN transaksi t ON dt.no_transaksi = t.no_transaksi
    JOIN barang b ON dt.kode_barang = b.kode_barang
    $where_clause");

if (!$summary_query) {
    die("Error in summary query: " . mysqli_error($conn));
}

$summary = mysqli_fetch_assoc($summary_query);

// Top selling products
$top_products_query = mysqli_query($conn,
    "SELECT 
        b.nama_barang,
        SUM(dt.jumlah_barang) as total_sold,
        SUM(dt.jumlah_harga) as total_revenue
    FROM detail_transaksi dt
    JOIN transaksi t ON dt.no_transaksi = t.no_transaksi
    JOIN barang b ON dt.kode_barang = b.kode_barang
    $where_clause
    GROUP BY b.kode_barang, b.nama_barang
    ORDER BY total_sold DESC
    LIMIT 5");

// Daily revenue for chart
$daily_revenue_query = mysqli_query($conn,
    "SELECT 
        DATE(t.tgl_transaksi) as date,
        SUM(dt.jumlah_harga) as daily_total
    FROM detail_transaksi dt
    JOIN transaksi t ON dt.no_transaksi = t.no_transaksi
    JOIN barang b ON dt.kode_barang = b.kode_barang
    $where_clause
    GROUP BY DATE(t.tgl_transaksi)
    ORDER BY date ASC");

$daily_data = [];
if ($daily_revenue_query) {
    while ($row = mysqli_fetch_assoc($daily_revenue_query)) {
        $daily_data[] = $row;
    }
}

// Handle CSV export
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="laporan_transaksi_' . $start_date . '_to_' . $end_date . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, ['No', 'Tanggal', 'No Transaksi', 'Nama Barang', 'Harga Satuan', 'Jumlah', 'Total']);
    
    // Get data for CSV
    $csv_query = mysqli_query($conn, 
        "SELECT t.no_transaksi, t.tgl_transaksi, b.nama_barang, b.harga, dt.jumlah_barang, dt.jumlah_harga
         FROM transaksi t
         JOIN detail_transaksi dt ON t.no_transaksi = dt.no_transaksi
         JOIN barang b ON dt.kode_barang = b.kode_barang
         $where_clause
         ORDER BY t.tgl_transaksi DESC");
    
    $no = 1;
    while ($data = mysqli_fetch_assoc($csv_query)) {
        fputcsv($output, [
            $no++,
            date('d/m/Y', strtotime($data['tgl_transaksi'])),
            $data['no_transaksi'],
            $data['nama_barang'],
            $data['harga'],
            $data['jumlah_barang'],
            $data['jumlah_harga']
        ]);
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Transaksi - Kasir Dragon</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .main-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            margin: 20px;
            overflow: hidden;
        }

        .report-header {
            background: var(--primary-gradient);
            color: white;
            padding: 30px;
            position: relative;
        }

        .report-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .report-header h2 {
            position: relative;
            z-index: 1;
            margin: 0;
            font-weight: 600;
        }

        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--info-gradient);
        }

        .stat-card.success::before {
            background: var(--success-gradient);
        }

        .stat-card.warning::before {
            background: var(--warning-gradient);
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

        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .data-table {
            background: white;
            border-radius: 15px;
            margin: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .table-header {
            background: var(--primary-gradient);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .table th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            border: none;
            padding: 15px;
        }

        .table td {
            padding: 15px;
            vertical-align: middle;
            border-color: #f1f3f4;
        }

        .table tbody tr:hover {
            background: rgba(79, 172, 254, 0.05);
        }

        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-gradient::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-gradient:hover::before {
            left: 100%;
        }

        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .btn-success-gradient {
            background: var(--success-gradient);
        }

        .btn-warning-gradient {
            background: var(--warning-gradient);
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding-left: 45px;
            border-radius: 25px;
            border: 2px solid #e1e8ed;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
        }

        .search-box .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .top-products {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f3f4;
        }

        .product-item:last-child {
            border-bottom: none;
        }

        .product-name {
            font-weight: 500;
            color: #333;
        }

        .product-stats {
            text-align: right;
        }

        .product-sold {
            color: #4facfe;
            font-weight: bold;
        }

        .product-revenue {
            color: #6c757d;
            font-size: 0.9em;
        }

        .alert {
            border-radius: 10px;
            border: none;
            margin: 20px;
        }

        .alert-info {
            background: linear-gradient(135deg, rgba(79, 172, 254, 0.1) 0%, rgba(0, 242, 254, 0.1) 100%);
            color: #0c5460;
        }

        .print-only { display: none; }
        
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            body { 
                background: white !important;
                font-size: 12px;
                padding: 0;
                margin: 0;
            }
            .main-container {
                background: white !important;
                box-shadow: none !important;
                margin: 0 !important;
                border-radius: 0 !important;
            }
            .report-header {
                background: white !important;
                color: black !important;
                border-bottom: 2px solid #000;
            }
            .table th {
                background: #f8f9fa !important;
                -webkit-print-color-adjust: exact;
            }
            .chart-container,
            .top-products {
                page-break-inside: avoid;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                margin: 15px;
            }
            
            .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-responsive {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <div class="report-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h2><i class="fas fa-chart-line me-2"></i>Laporan Transaksi</h2>
                    <p class="mb-0 opacity-75">
                        Periode: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?>
                    </p>
                </div>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-light">
                        <i class="fas fa-print me-1"></i> Cetak
                    </button>
                </div>
            </div>
        </div>

        <!-- Print Header -->
        <div class="print-only text-center p-4">
            <h2>LAPORAN TRANSAKSI</h2>
            <h4>KASIR DRAGON</h4>
            <p>Periode: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <hr>
        </div>

        <!-- Filter Form -->
        <div class="filter-card no-print">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Laporan</h5>
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Dari Tanggal</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" 
                           value="<?= $start_date ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">Sampai Tanggal</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" 
                           value="<?= $end_date ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label for="search" class="form-label">Cari Transaksi/Barang</label>
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="No transaksi atau nama barang...">
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-gradient">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                    <a href="?" class="btn btn-outline-secondary">
                        <i class="fas fa-refresh"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($summary['total_transactions'] ?? 0) ?></div>
                <div class="stat-label"><i class="fas fa-receipt me-1"></i>Total Transaksi</div>
            </div>
            <div class="stat-card success">
                <div class="stat-number"><?= number_format($summary['total_items'] ?? 0) ?></div>
                <div class="stat-label"><i class="fas fa-boxes me-1"></i>Total Item Terjual</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number">Rp <?= number_format($summary['total_revenue'] ?? 0, 0, ',', '.') ?></div>
                <div class="stat-label"><i class="fas fa-money-bill-wave me-1"></i>Total Pendapatan</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">Rp <?= number_format($summary['avg_transaction'] ?? 0, 0, ',', '.') ?></div>
                <div class="stat-label"><i class="fas fa-chart-bar me-1"></i>Rata-rata per Item</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row no-print">
            <!-- Daily Revenue Chart -->
            <div class="col-lg-8">
                <div class="chart-container">
                    <h5 class="mb-3"><i class="fas fa-chart-area me-2"></i>Pendapatan Harian</h5>
                    <canvas id="dailyChart" height="100"></canvas>
                </div>
            </div>
            
            <!-- Top Products -->
            <div class="col-lg-4">
                <div class="top-products">
                    <h5 class="mb-3"><i class="fas fa-trophy me-2"></i>Produk Terlaris</h5>
                    <?php if ($top_products_query && mysqli_num_rows($top_products_query) > 0): ?>
                        <?php $rank = 1; while ($product = mysqli_fetch_assoc($top_products_query)): ?>
                            <div class="product-item">
                                <div>
                                    <span class="badge bg-primary me-2"><?= $rank++ ?></span>
                                    <span class="product-name"><?= htmlspecialchars($product['nama_barang']) ?></span>
                                </div>
                                <div class="product-stats">
                                    <div class="product-sold"><?= $product['total_sold'] ?> terjual</div>
                                    <div class="product-revenue">Rp <?= number_format($product['total_revenue'], 0, ',', '.') ?></div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">Tidak ada data produk</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Search Results Alert -->
        <?php if (!empty($search)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Menampilkan hasil pencarian untuk: "<strong><?= htmlspecialchars($search) ?></strong>"
                <a href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="float-end text-decoration-none">
                    <i class="fas fa-times"></i> Hapus filter
                </a>
            </div>
        <?php endif; ?>

        <!-- Data Table -->
        <div class="data-table">
            <div class="table-header">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i>Detail Transaksi</h5>
                <div class="no-print">
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" 
                       class="btn btn-success-gradient btn-sm">
                        <i class="fas fa-download me-1"></i> Export CSV
                    </a>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th width="12%">Tanggal</th>
                            <th width="15%">No Transaksi</th>
                            <th width="25%">Nama Barang</th>
                            <th width="12%">Harga Satuan</th>
                            <th width="8%">Jumlah</th>
                            <th width="15%">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Main data query
                        $query = mysqli_query($conn, 
                            "SELECT t.no_transaksi, t.tgl_transaksi, b.nama_barang, b.harga, dt.jumlah_barang, dt.jumlah_harga
                             FROM transaksi t
                             JOIN detail_transaksi dt ON t.no_transaksi = dt.no_transaksi
                             JOIN barang b ON dt.kode_barang = b.kode_barang
                             $where_clause
                             ORDER BY t.tgl_transaksi DESC, t.no_transaksi DESC");

                        if (!$query) {
                            echo "<tr><td colspan='7' class='text-center text-danger'>Error: " . mysqli_error($conn) . "</td></tr>";
                        } elseif (mysqli_num_rows($query) > 0) {
                            $no = 1;
                            while ($data = mysqli_fetch_assoc($query)) {
                                echo "<tr>
                                        <td><small class='text-muted'>".$no++."</small></td>
                                        <td><small>".date('d/m/Y', strtotime($data['tgl_transaksi']))."</small></td>
                                        <td><code>".$data['no_transaksi']."</code></td>
                                        <td>".htmlspecialchars($data['nama_barang'])."</td>
                                        <td><strong>Rp ".number_format($data['harga'] ?? 0, 0, ',', '.')."</strong></td>
                                        <td><span class='badge bg-light text-dark'>".$data['jumlah_barang']."</span></td>
                                        <td><strong class='text-success'>Rp ".number_format($data['jumlah_harga'] ?? 0, 0, ',', '.')."</strong></td>
                                      </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='text-center py-5'>
                                    <i class='fas fa-inbox fa-3x text-muted mb-3'></i>
                                    <br>Tidak ada data transaksi untuk periode ini
                                    <br><small class='text-muted'>Coba ubah filter tanggal atau kata kunci pencarian</small>
                                  </td></tr>";
                        }
                        ?>
                    </tbody>
                    <?php if ($query && mysqli_num_rows($query) > 0): ?>
                    <tfoot>
                        <tr class="table-light">
                            <th colspan="6" class="text-end">
                                <strong>TOTAL PENDAPATAN:</strong>
                            </th>
                            <th>
                                <strong class="text-success fs-5">
                                    Rp <?= number_format($summary['total_revenue'] ?? 0, 0, ',', '.') ?>
                                </strong>
                            </th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center p-4 no-print">
            <small class="text-muted">
                <i class="fas fa-clock me-1"></i>
                Laporan dibuat pada: <?= date('d M Y H:i:s') ?>
            </small>
        </div>

        <div class="print-only text-center mt-4">
            <small>Dicetak pada: <?= date('d M Y H:i:s') ?></small>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Date validation
        document.getElementById('start_date').addEventListener('change', function() {
            let endDate = document.getElementById('end_date');
            if (this.value > endDate.value) {
                endDate.value = this.value;
            }
        });

        document.getElementById('end_date').addEventListener('change', function() {
            let startDate = document.getElementById('start_date');
            if (this.value < startDate.value) {
                startDate.value = this.value;
            }
        });

        // Daily Revenue Chart
        <?php if (!empty($daily_data)): ?>
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const dailyChart = new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: [<?php foreach($daily_data as $day) echo "'" . date('d M', strtotime($day['date'])) . "',"; ?>],
                datasets: [{
                    label: 'Pendapatan Harian',
                    data: [<?php foreach($daily_data as $day) echo $day['daily_total'] . ","; ?>],
                    borderColor: '#4facfe',
                    backgroundColor: 'rgba(79, 172, 254, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4facfe',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Pendapatan: Rp ' + context.parsed.y.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                elements: {
                    point: {
                        hoverRadius: 8
                    }
                }
            }
        });
        <?php endif; ?>

        // Print function optimization
        window.addEventListener('beforeprint', function() {
            // Hide charts before printing to avoid rendering issues
            const charts = document.querySelectorAll('canvas');
            charts.forEach(chart => {
                chart.style.display = 'none';
            });
        });

        window.addEventListener('afterprint', function() {
            // Show charts after printing
            const charts = document.querySelectorAll('canvas');
            charts.forEach(chart => {
                chart.style.display = 'block';
            });
        });

        // Auto-submit form when date changes (optional UX enhancement)
        document.getElementById('start_date').addEventListener('change', function() {
            if (document.getElementById('end_date').value) {
                // Auto-submit after 1 second delay
                setTimeout(() => {
                    if (!document.getElementById('search').value) {
                        document.querySelector('form').submit();
                    }
                }, 1000);
            }
        });

        document.getElementById('end_date').addEventListener('change', function() {
            if (document.getElementById('start_date').value) {
                // Auto-submit after 1 second delay
                setTimeout(() => {
                    if (!document.getElementById('search').value) {
                        document.querySelector('form').submit();
                    }
                }, 1000);
            }
        });

        // Search functionality with debounce
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchValue = this.value.trim();
            
            if (searchValue.length >= 3 || searchValue.length === 0) {
                searchTimeout = setTimeout(() => {
                    document.querySelector('form').submit();
                }, 800);
            }
        });

        // Enhanced table row hover effects
        document.querySelectorAll('.table tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.01)';
                this.style.transition = 'transform 0.2s ease';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });

        window.addEventListener('load', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'all 0.5s ease';
                    
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 50);
                }, index * 100);
            });
        });

        document.querySelector('a[href*="export=csv"]')?.addEventListener('click', function(e) {
            const btn = this;
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Mengunduh...';
            btn.classList.add('disabled');
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.classList.remove('disabled');
            }, 3000);
        });

        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
<!-- Back to Home Button (Floating) -->
<a href="index.php" 
    class="btn btn-gradient position-fixed no-print" 
    style="bottom: 30px; right: 30px; z-index: 999; box-shadow: 0 8px 25px rgba(162, 0, 255, 0.2); display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
     <i class="fas fa-arrow-left"></i> Kembali ke Beranda
</a>