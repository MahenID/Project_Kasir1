
<?php
include 'koneksi.php';

$result = mysqli_query($conn, "SELECT * FROM `order`");

?>

<!DOCTYPE html>
<html>
<head>
    <title>Data Order</title>
</head>
<body>
    <h2>Data Order</h2>
    <table border="1">
        <tr>
            <th>kode_order</th><th>jenis_order</th><th>kode_barang</th>
        </tr>
        <?php while($row = mysqli_fetch_assoc($result)) { ?>
        <tr><?php 
include 'koneksi.php';

if (!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

$query = "SELECT * FROM `order` ORDER BY kode_order ASC";
$result = mysqli_query($conn, $query);

if (!$result) {
    die("Query gagal: " . mysqli_error($conn));
}

$totalData = mysqli_num_rows($result);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Order - Sistem Manajemen</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 0;
            text-align: center;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .info-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .total-info {
            font-weight: bold;
            color: #667eea;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
        }
        
        th:first-child {
            border-top-left-radius: 8px;
        }
        
        th:last-child {
            border-top-right-radius: 8px;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.3s ease;
        }
        
        tr:hover {
            background-color: #f8f9ff;
        }
        
        tr:nth-child(even) {
            background-color: #fafafa;
        }
        
        tr:nth-child(even):hover {
            background-color: #f0f2ff;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
        
        .actions {
            margin-top: 20px;
            text-align: center;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin: 0 10px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            font-weight: 500;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #666;
            border-top: 1px solid #eee;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .info-bar {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 8px 6px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Data Order</h1>
            <p>Sistem Manajemen Order Terintegrasi</p>
        </div>
        
        <div class="info-bar">
            <div class="total-info">
                📊 Total Data: <?php echo $totalData; ?> Order
            </div>
            <div style="color: #666;">
                📅 <?php echo date('d F Y, H:i'); ?> WIB
            </div>
        </div>
        
        <div class="table-container">
            <?php if ($totalData > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>🔢 Kode Order</th>
                            <th>📦 Jenis Order</th>
                            <th>🏷️ Kode Barang</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row["kode_order"] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row["jenis_order"] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row["kode_barang"] ?? '-'); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <h3>📭 Tidak Ada Data</h3>
                    <p>Belum ada data order yang tersedia.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="actions">
            <a href="#" class="btn">➕ Tambah Order</a>
            <a href="#" class="btn">📊 Export Data</a>
            <a href="#" class="btn">🔄 Refresh</a>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Sistem Manajemen Order. All rights reserved.</p>
        </div>
    </div>
    
    <script>
        // Auto refresh every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
        
        // Add click sound effect for buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                // Add your navigation logic here
                console.log('Button clicked:', this.textContent);
            });
        });
    </script>
</body>
</html>

<?php
// Close database connection
mysqli_close($conn);
?>
            <td><?php echo $row["kode_order"]; ?></td><td><?php echo $row["jenis_order"]; ?></td><td><?php echo $row["kode_barang"]; ?></td>
        </tr>
        <?php } ?>
    </table>
</body>
</html>
