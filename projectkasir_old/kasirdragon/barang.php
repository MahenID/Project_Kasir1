<?php 
include 'koneksi.php'; 
?>
<!DOCTYPE html>
<html>
<head>
    <title>Input Barang - Kasir Dragon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h2>Input Barang</h2>
        
        <?php 
        if ($_POST) {
            if (isset($_POST['kode_barang']) && isset($_POST['jenis']) && isset($_POST['nama']) && 
                isset($_POST['harga']) && isset($_POST['stok']) && isset($_POST['supplier']) &&
                !empty($_POST['kode_barang']) && !empty($_POST['jenis']) && !empty($_POST['nama']) && 
                !empty($_POST['harga']) && !empty($_POST['stok']) && !empty($_POST['supplier'])) {
                
                $kode_barang = mysqli_real_escape_string($conn, $_POST['kode_barang']);
                $jenis = mysqli_real_escape_string($conn, $_POST['jenis']);
                $nama = mysqli_real_escape_string($conn, $_POST['nama']);
                $harga = mysqli_real_escape_string($conn, $_POST['harga']);
                $stok = mysqli_real_escape_string($conn, $_POST['stok']);
                $supplier = mysqli_real_escape_string($conn, $_POST['supplier']);
                

                $check_kode = mysqli_query($conn, "SELECT kode_barang FROM barang WHERE kode_barang = '$kode_barang'");
                if (mysqli_num_rows($check_kode) > 0) {
                    echo "<div class='alert alert-danger'>Error: Kode barang sudah ada! Gunakan kode lain.</div>";
                } else {
   
                    $check_supplier = mysqli_query($conn, "SELECT id_supplier FROM supplier WHERE id_supplier = '$supplier'");
                    if (mysqli_num_rows($check_supplier) > 0) {
                        
                        $query = "INSERT INTO barang(kode_barang, kode_jenis_barang, nama_barang, harga_barang, stok, id_supplier) VALUES ('$kode_barang', '$jenis', '$nama', '$harga', '$stok', '$supplier')";
                        
                        if (mysqli_query($conn, $query)) {
                            echo "<div class='alert alert-success'>Data barang berhasil disimpan!</div>";

                            echo "<script>
                                setTimeout(function() {
                                    document.querySelector('form').reset();
                                }, 2000);
                            </script>";
                        } else {
                            echo "<div class='alert alert-danger'>Error: " . mysqli_error($conn) . "</div>";
                        }
                    } else {
                        echo "<div class='alert alert-danger'>Error: Supplier tidak ditemukan! Pilih supplier yang valid.</div>";
                    }
                }
            } else {
                echo "<div class='alert alert-warning'>Semua field harus diisi!</div>";
            }
        } 
        ?>
        
        <form method="POST" class="mt-4">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Kode Barang <span class="text-danger">*</span></label>
                        <input name="kode_barang" placeholder="Contoh: BRG001" required class="form-control" maxlength="10">
                        <small class="form-text text-muted">Kode barang harus unik</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Kode Jenis Barang <span class="text-danger">*</span></label>
                        <input name="jenis" placeholder="Contoh: MAKANAN" required class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Nama Barang <span class="text-danger">*</span></label>
                        <input name="nama" placeholder="Nama Barang" required class="form-control">
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Harga Jual <span class="text-danger">*</span></label>
                        <input name="harga" placeholder="0" type="number" min="0" step="0.01" required class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Stok <span class="text-danger">*</span></label>
                        <input name="stok" placeholder="0" type="number" min="0" required class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select name="supplier" class="form-select" required>
                            <option value="">-- Pilih Supplier --</option>
                            <?php 
                            $q = mysqli_query($conn, "SELECT * FROM supplier ORDER BY nama_supplier ASC");
                            if (mysqli_num_rows($q) > 0) {
                                while ($d = mysqli_fetch_assoc($q)) {
                                    echo "<option value='" . $d['id_supplier'] . "'>" . $d['nama_supplier'] . "</option>";
                                }
                            } else {
                                echo "<option value=''>Tidak ada supplier tersedia</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Simpan Barang
                </button>
                <button type="reset" class="btn btn-secondary ms-2">
                    <i class="bi bi-arrow-clockwise"></i> Reset
                </button>
                <a href="index.php" class="btn btn-outline-primary ms-2">
                    <i class="bi bi-house"></i> Kembali ke Menu
                </a>
            </div>
        </form>
        
        <div class="mt-5">
            <h4>Data Barang</h4>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>No</th>
                            <th>Kode Barang</th>
                            <th>Jenis</th>
                            <th>Nama Barang</th>
                            <th>Harga Barang</th>
                            <th>Stok</th>
                            <th>Supplier</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $query_tampil = "SELECT b.*, s.nama_supplier 
                                       FROM barang b 
                                       LEFT JOIN supplier s ON b.id_supplier = s.id_supplier 
                                       ORDER BY b.kode_barang ASC";
                        $result = mysqli_query($conn, $query_tampil);
                        
                        if (mysqli_num_rows($result) > 0) {
                            $no = 1;
                            while ($row = mysqli_fetch_assoc($result)) {
                                echo "<tr>";
                                echo "<td>" . $no++ . "</td>";
                                echo "<td>" . $row['kode_barang'] . "</td>";
                                echo "<td>" . $row['kode_jenis_barang'] . "</td>";
                                echo "<td>" . $row['nama_barang'] . "</td>";
                                echo "<td>Rp " . number_format($row['harga_barang'], 0, ',', '.') . "</td>";
                                echo "<td>" . $row['stok'] . "</td>";
                                echo "<td>" . ($row['nama_supplier'] ?? 'Supplier tidak ditemukan') . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='text-center'>Belum ada data barang</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>