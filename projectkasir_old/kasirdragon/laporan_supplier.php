<?php
include 'koneksi.php';

function getTableColumns($conn, $table) {
    $columns = [];
    $result = mysqli_query($conn, "SHOW COLUMNS FROM $table");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}
$barang_columns = getTableColumns($conn, 'barang');
$supplier_columns = getTableColumns($conn, 'supplier');

$possible_supplier_fk = ['id_supplier', 'supplier_id', 'id_tp', 'kode_supplier', 'supplier'];
$supplier_fk_column = null;

foreach ($possible_supplier_fk as $col) {
    if (in_array($col, $barang_columns)) {
        $supplier_fk_column = $col;
        break;
    }
}
$possible_supplier_pk = ['id', 'id_supplier', 'id_tp', 'kode_supplier', 'supplier_id'];
$supplier_pk_column = null;

foreach ($possible_supplier_pk as $col) {
    if (in_array($col, $supplier_columns)) {
        $supplier_pk_column = $col;
        break;
    }
}

$possible_name_cols = ['nama_supplier', 'name', 'supplier_name', 'nama'];
$supplier_name_column = 'nama_supplier'; 

foreach ($possible_name_cols as $col) {
    if (in_array($col, $supplier_columns)) {
        $supplier_name_column = $col;
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Barang & Supplier - DRAGONMART</title>
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
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        
        .custom-table {
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
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            transform: scale(1.02);
        }
        
        .table tbody td {
            padding: 1rem;
            border: none;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            vertical-align: middle;
        }
        
        .badge-supplier {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .search-box {
            position: relative;
            margin-bottom: 2rem;
        }
        
        .search-input {
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            padding: 0.75rem 1rem 0.75rem 3rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 20px rgba(102, 126, 234, 0.3);
            outline: none;
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .error-alert {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            border: none;
            border-radius: 15px;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="main-container mx-auto" style="max-width: 1200px;">
            <div class="header-section">
                <div class="header-content">
                    <h1 class="mb-0">
                        <i class="fas fa-boxes me-3"></i>
                        Laporan Barang & Supplier
                    </h1>
                    <p class="mb-0 mt-2 opacity-75">DRAGONMART Management System</p>
                </div>
            </div>
            
            <div class="p-4">
                <?php
                $total_barang = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM barang"));
                $total_supplier = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM supplier"));
                
                $connection_status = ($supplier_fk_column && $supplier_pk_column) ? 'Berhasil' : 'Gagal';
                ?>
                
                <div class="stats-cards">
                    <div class="stat-card">
                        <i class="fas fa-box fa-2x mb-2"></i>
                        <h3><?php echo $total_barang; ?></h3>
                        <p class="mb-0">Total Barang</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-truck fa-2x mb-2"></i>
                        <h3><?php echo $total_supplier; ?></h3>
                        <p class="mb-0">Total Supplier</p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-link fa-2x mb-2"></i>
                        <h3><?php echo $connection_status; ?></h3>
                        <p class="mb-0">Relation Status</p>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="search-box flex-grow-1 me-3">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="form-control search-input" placeholder="Cari barang atau supplier...">
                    </div>
                    <button class="btn btn-print" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Laporan
                    </button>
                </div>
                
                <!-- Data Table -->
                <div class="custom-table">
                    <?php if (!$supplier_fk_column || !$supplier_pk_column): ?>
                        <div class="alert error-alert m-4">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Error Database Structure</h5>
                            
                            <?php if (!$supplier_fk_column): ?>
                                <p class="mb-2"><strong>❌ Foreign Key tidak ditemukan di tabel 'barang'</strong></p>
                                <p class="mb-2"><strong>Kolom yang tersedia di tabel barang:</strong> <?php echo implode(', ', $barang_columns); ?></p>
                            <?php endif; ?>
                            
                            <?php if (!$supplier_pk_column): ?>
                                <p class="mb-2"><strong>❌ Primary Key tidak ditemukan di tabel 'supplier'</strong></p>
                                <p class="mb-2"><strong>Kolom yang tersedia di tabel supplier:</strong> <?php echo implode(', ', $supplier_columns); ?></p>
                            <?php endif; ?>
                            
                            <hr class="my-3">
                            <h6>💡 Solusi:</h6>
                            <ol class="mb-0">
                                <li>Pastikan tabel 'barang' memiliki kolom foreign key (id_supplier, supplier_id, dll)</li>
                                <li>Pastikan tabel 'supplier' memiliki kolom primary key (id, id_supplier, dll)</li>
                                <li>Atau buat tabel dengan struktur yang benar</li>
                            </ol>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info m-4">
                            <h6><i class="fas fa-info-circle me-2"></i>Database Connection Info</h6>
                            <p class="mb-1"><strong>Foreign Key:</strong> barang.<?php echo $supplier_fk_column; ?></p>
                            <p class="mb-1"><strong>Primary Key:</strong> supplier.<?php echo $supplier_pk_column; ?></p>
                            <p class="mb-0"><strong>Supplier Name:</strong> supplier.<?php echo $supplier_name_column; ?></p>
                        </div>
                        
                        <table class="table mb-0" id="dataTable">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-hashtag me-2"></i>No</th>
                                    <th><i class="fas fa-box me-2"></i>Nama Barang</th>
                                    <th><i class="fas fa-truck me-2"></i>Supplier</th>
                                    <th><i class="fas fa-info-circle me-2"></i>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $query = "SELECT barang.*, supplier.$supplier_name_column 
                                         FROM barang 
                                         LEFT JOIN supplier ON barang.$supplier_fk_column = supplier.$supplier_pk_column 
                                         ORDER BY barang.nama_barang ASC";
                                
                                $result = mysqli_query($conn, $query);
                                
                                if (!$result) {
                                    echo "<tr><td colspan='4' class='text-center text-danger'>
                                            <i class='fas fa-exclamation-triangle fa-2x mb-2'></i><br>
                                            <strong>Query Error:</strong><br>
                                            " . mysqli_error($conn) . "<br><br>
                                            <strong>Query yang dijalankan:</strong><br>
                                            <code>$query</code>
                                          </td></tr>";
                                } else if (mysqli_num_rows($result) == 0) {
                                    echo "<tr><td colspan='4' class='no-data'>
                                            <i class='fas fa-inbox fa-3x mb-3 text-muted'></i><br>
                                            <h5>Tidak ada data</h5>
                                            <p>Belum ada data barang dan supplier yang tersedia.</p>
                                          </td></tr>";
                                } else {
                                    $no = 1;
                                    while ($data = mysqli_fetch_assoc($result)) {
                                        $supplier_name = $data[$supplier_name_column] ?? 'Tidak ada supplier';
                                        $status = $data[$supplier_name_column] ? 'Berhasil' : 'Gagal';
                                        $status_class = $data[$supplier_name_column] ? 'success' : 'warning';
                                        
                                        echo "<tr class='data-row'>
                                                <td><strong>$no</strong></td>
                                                <td>
                                                    <div class='d-flex align-items-center'>
                                                        <i class='fas fa-cube me-2 text-primary'></i>
                                                        <strong>" . htmlspecialchars($data['nama_barang']) . "</strong>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class='badge-supplier'>
                                                        <i class='fas fa-building me-1'></i>
                                                        " . htmlspecialchars($supplier_name) . "
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class='badge bg-$status_class'>
                                                        <i class='fas fa-" . ($data[$supplier_name_column] ? 'check-circle' : 'exclamation-circle') . " me-1'></i>
                                                        $status
                                                    </span>
                                                </td>
                                              </tr>";
                                        $no++;
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                

                <div class="text-center mt-4 text-muted">
                    <p class="mb-0">© 2023 DRAGONMART. All rights reserved.</p>
                    <p class="mb-0">Developed by Dragon<a href="
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.data-row');
            
            rows.forEach(row => {
                const barangName = row.cells[1].textContent.toLowerCase();
                const supplierName = row.cells[2].textContent.toLowerCase();
                
                if (barangName.includes(searchTerm) || supplierName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                body { background: white !important; }
                .main-container { box-shadow: none !important; }
                .btn-print, .search-box { display: none !important; }
                .header-section::before { display: none !important; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>