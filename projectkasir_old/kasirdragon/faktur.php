<?php 
include 'koneksi.php';

// Error handling for database connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Prepared statement for security
$query = "SELECT no_faktur, tgl_faktur, id_pembeli FROM faktur ORDER BY tgl_faktur DESC";
$result = mysqli_query($conn, $query);

// Check if query was successful
if (!$result) {
    die("Query failed: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Faktur - Invoice Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: 300;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .content {
            padding: 30px;
        }

        .stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            gap: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            flex: 1;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.3s ease;
        }

        tr:hover td {
            background-color: #f8f9ff;
        }

        tr:nth-child(even) {
            background-color: #fafafa;
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: #666;
            font-size: 1.1rem;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #ddd;
        }

        .actions {
            margin-top: 30px;
            text-align: center;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin: 0 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        .invoice-id {
            font-weight: bold;
            color: #2c3e50;
        }

        .date {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .customer-id {
            background: linear-gradient(135deg, #74b9ff, #0984e3);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .stats {
                flex-direction: column;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            table {
                font-size: 0.9rem;
            }
            
            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Data Faktur</h1>
            <p>Invoice Management System</p>
        </div>
        
        <div class="content">
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo mysqli_num_rows($result); ?></div>
                    <div class="stat-label">Total Faktur</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo date('d'); ?></div>
                    <div class="stat-label">Hari Ini</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo date('M Y'); ?></div>
                    <div class="stat-label">Periode</div>
                </div>
            </div>

            <div class="table-container">
                <?php if (mysqli_num_rows($result) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>🔢 No. Faktur</th>
                                <th>📅 Tanggal Faktur</th>
                                <th>👤 ID Pembeli</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = mysqli_fetch_assoc($result)) { ?>
                            <tr>
                                <td class="invoice-id"><?php echo htmlspecialchars($row["no_faktur"]); ?></td>
                                <td class="date"><?php echo date('d/m/Y', strtotime($row["tgl_faktur"])); ?></td>
                                <td><span class="customer-id"><?php echo htmlspecialchars($row["id_pembeli"]); ?></span></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <div class="no-data">
                        <div style="font-size: 3rem; margin-bottom: 20px;">📄</div>
                        <p>Tidak ada data faktur yang tersedia</p>
                    </div>
                <?php } ?>
            </div>

            <div class="actions">
                <a href="#" class="btn">➕ Tambah Faktur</a>
                <a href="#" class="btn">📊 Laporan</a>
                <a href="#" class="btn">🔄 Refresh</a>
            </div>
        </div>
    </div>

    <script>
        // Add some interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading animation
            const table = document.querySelector('table');
            if (table) {
                table.style.opacity = '0';
                setTimeout(() => {
                    table.style.transition = 'opacity 0.5s ease';
                    table.style.opacity = '1';
                }, 100);
            }

            // Add click effect to buttons
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Create ripple effect
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
                    ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
                    ripple.style.position = 'absolute';
                    ripple.style.borderRadius = '50%';
                    ripple.style.background = 'rgba(255,255,255,0.6)';
                    ripple.style.transform = 'scale(0)';
                    ripple.style.animation = 'ripple 0.6s linear';
                    ripple.style.pointerEvents = 'none';
                    
                    this.style.position = 'relative';
                    this.style.overflow = 'hidden';
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
        });

        // Add CSS for ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(2);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>

<?php
// Close database connection
mysqli_close($conn);
?>