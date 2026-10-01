<?php
include 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama = mysqli_real_escape_string($conn, $_POST['nama']);
    $jenis_kelamin = mysqli_real_escape_string($conn, $_POST['jenis_kelamin']);
    $alamat = mysqli_real_escape_string($conn, $_POST['alamat']);
    $no_tlp = mysqli_real_escape_string($conn, $_POST['no_tlp']);
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    
    // Validasi dan sanitasi email
    if (!empty($email)) {
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Format email tidak valid";
        }
    }
    
    // Perbaikan logika jenis kelamin
    $errors = [];
    if (empty($nama)) $errors[] = "Nama harus diisi";
    if (empty($jenis_kelamin)) {
        $errors[] = "Jenis kelamin harus dipilih";
    } else {
        $jenis_kelamin = ($jenis_kelamin == '0') ? 'L' : 'P';
    }
    if (empty($alamat)) $errors[] = "Alamat harus diisi";
    if (empty($no_tlp)) $errors[] = "Nomor telepon harus diisi";
    

    if (!empty($no_tlp) && !preg_match('/^[0-9+\-\s()]+$/', $no_tlp)) {
        $errors[] = "Format nomor telepon tidak valid";
    }
    

    if (empty($errors)) {
        $insertQuery = "INSERT INTO pelanggan (nama, jenis_kelamin, alamat, no_tlp, email) 
                        VALUES (?, ?, ?, ?, ?)";
        

        $stmt = mysqli_prepare($conn, $insertQuery);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sssss", $nama, $jenis_kelamin, $alamat, $no_tlp, $email);
            
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Data pelanggan berhasil ditambahkan!";
                $new_id = mysqli_insert_id($conn);
                $_POST = array(); // Reset form
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Error preparing statement: " . mysqli_error($conn);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .form-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .gender-option {
            padding: 10px;
            border-radius: 8px;
            margin: 5px 0;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .gender-option:hover {
            background-color: #f8f9fa;
            border-color: #dee2e6;
        }
        .gender-option input[type="radio"]:checked + label {
            font-weight: bold;
            color: #0d6efd;
        }
        .gender-option:has(input[type="radio"]:checked) {
            background-color: #e7f3ff;
            border-color: #0d6efd;
        }
        .btn-back {
            margin-bottom: 20px;
        }
        .form-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        .required {
            color: #dc3545;
        }
        .form-floating {
            margin-bottom: 20px;
        }
        .character-count {
            font-size: 0.875rem;
            color: #6c757d;
            text-align: right;
        }
        .success-animation {
            animation: bounceIn 0.6s ease-out;
        }
        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="form-container">
            <button class="btn btn-secondary btn-back" onclick="window.location.href='index.php'">
                <i class="fas fa-arrow-left"></i> Kembali ke Data Pelanggan
            </button>
            
            <div class="form-header">
                <h2><i class="fas fa-user-plus"></i> Tambah Pelanggan Baru</h2>
                <p class="text-muted">Lengkapi semua data untuk menambahkan pelanggan baru</p>
            </div>

            <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show success-animation">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                <?php if (isset($new_id)): ?>
                <br><small>ID Pelanggan baru: <strong><?php echo $new_id; ?></strong></small>
                <div class="mt-2">
                    <a href="detail_pelanggan.php?id=<?php echo $new_id; ?>" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-eye"></i> Lihat Detail
                    </a>
                    <a href="edit_pelanggan.php?id=<?php echo $new_id; ?>" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-edit"></i> Edit Data
                    </a>
                </div>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Perhatian:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <form method="POST" id="tambahForm">
                <div class="form-floating mb-3">
                    <input type="text" 
                           class="form-control" 
                           id="nama" 
                           name="nama" 
                           placeholder="Nama Lengkap"
                           value="<?php echo isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : ''; ?>" 
                           maxlength="100"
                           required>
                    <label for="nama">
                        <i class="fas fa-user"></i> Nama Lengkap <span class="required">*</span>
                    </label>
                    <div class="character-count">
                        <span id="namaCount">0</span>/100 karakter
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-venus-mars"></i> Jenis Kelamin <span class="required">*</span>
                    </label>
                    <div class="gender-option">
                        <div class="form-check">
                            <input class="form-check-input" 
                                   type="radio" 
                                   name="jenis_kelamin" 
                                   id="laki_laki" 
                                   value="2" 
                                   <?php echo (isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] == '2') ? 'checked' : ''; ?>
                                   required>
                            <label class="form-check-label w-100" for="laki_laki">
                                <i class="fas fa-mars text-primary"></i> Laki-laki
                                <small class="text-muted float-end">Pilih jika laki-laki</small>
                            </label>
                        </div>
                    </div>
                    <div class="gender-option">
                        <div class="form-check">
                            <input class="form-check-input" 
                                   type="radio" 
                                   name="jenis_kelamin" 
                                   id="perempuan" 
                                   value="1" 
                                   <?php echo (isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] == '1') ? 'checked' : ''; ?>
                                   required>
                            <label class="form-check-label w-100" for="perempuan">
                                <i class="fas fa-venus text-danger"></i> Perempuan
                                <small class="text-muted float-end">Pilih jika perempuan</small>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-floating mb-3">
                    <textarea class="form-control" 
                              id="alamat" 
                              name="alamat" 
                              placeholder="Alamat Lengkap"
                              style="height: 100px;"
                              maxlength="255"
                              required><?php echo isset($_POST['alamat']) ? htmlspecialchars($_POST['alamat']) : ''; ?></textarea>
                    <label for="alamat">
                        <i class="fas fa-map-marker-alt"></i> Alamat Lengkap <span class="required">*</span>
                    </label>
                    <div class="character-count">
                        <span id="alamatCount">0</span>/255 karakter
                    </div>
                </div>

                <div class="form-floating mb-3">
                    <input type="tel" 
                           class="form-control" 
                           id="no_tlp" 
                           name="no_tlp" 
                           placeholder="Nomor Telepon"
                           value="<?php echo isset($_POST['no_tlp']) ? htmlspecialchars($_POST['no_tlp']) : ''; ?>"
                           maxlength="20"
                           required>
                    <label for="no_tlp">
                        <i class="fas fa-phone"></i> Nomor Telepon <span class="required">*</span>
                    </label>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Contoh: 081234567890, +62812-3456-7890
                    </div>
                </div>

                <div class="form-floating mb-4">
                    <input type="email" 
                           class="form-control" 
                           id="email" 
                           name="email" 
                           placeholder="Email (Opsional)"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                           maxlength="100">
                    <label for="email">
                        <i class="fas fa-envelope"></i> Email (Opsional)
                    </label>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Contoh: nama@email.com
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="button" 
                            class="btn btn-secondary me-md-2" 
                            onclick="resetForm()">
                        <i class="fas fa-eraser"></i> Reset Form
                    </button>
                    <button type="button" 
                            class="btn btn-info me-md-2" 
                            onclick="previewData()">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                    <button type="submit" 
                            class="btn btn-primary" 
                            id="submitBtn">
                        <i class="fas fa-save"></i> Simpan Data
                    </button>
                </div>
            </form>

            <div class="mt-4 pt-3 border-top">
                <div class="row text-center">
                    <div class="col-md-6">
                        <button class="btn btn-outline-success w-100" onclick="generateDummyData()">
                            <i class="fas fa-magic"></i> Isi Data Contoh
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button class="btn btn-outline-info w-100" onclick="window.location.href='index.php'">
                            <i class="fas fa-list"></i> Lihat Semua Data
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye"></i> Preview Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="previewContent">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary" onclick="submitFormFromPreview()">
                        <i class="fas fa-save"></i> Simpan Data
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Character counting
        document.getElementById('nama').addEventListener('input', function() {
            document.getElementById('namaCount').textContent = this.value.length;
        });
        
        document.getElementById('alamat').addEventListener('input', function() {
            document.getElementById('alamatCount').textContent = this.value.length;
        });
        
        // Initial count
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('namaCount').textContent = document.getElementById('nama').value.length;
            document.getElementById('alamatCount').textContent = document.getElementById('alamat').value.length;
        });

        function resetForm() {
            if (confirm('Apakah Anda yakin ingin mereset semua data?')) {
                document.getElementById('tambahForm').reset();
                document.getElementById('namaCount').textContent = '0';
                document.getElementById('alamatCount').textContent = '0';
            }
        }

        function previewData() {
            const nama = document.getElementById('nama').value;
            const jenisKelamin = document.querySelector('input[name="jenis_kelamin"]:checked');
            const alamat = document.getElementById('alamat').value;
            const noTlp = document.getElementById('no_tlp').value;
            const email = document.getElementById('email').value;

            if (!nama || !jenisKelamin || !alamat || !noTlp) {
                alert('Harap lengkapi semua field yang wajib diisi!');
                return;
            }

            const jenisKelaminText = jenisKelamin.value === '0' ? 'Laki-laki' : 'Perempuan';
            
            const previewContent = `
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <tr>
                            <td><strong><i class="fas fa-user"></i> Nama</strong></td>
                            <td>${nama}</td>
                        </tr>
                        <tr>
                            <td><strong><i class="fas fa-venus-mars"></i> Jenis Kelamin</strong></td>
                            <td>${jenisKelaminText}</td>
                        </tr>
                        <tr>
                            <td><strong><i class="fas fa-map-marker-alt"></i> Alamat</strong></td>
                            <td>${alamat}</td>
                        </tr>
                        <tr>
                            <td><strong><i class="fas fa-phone"></i> No. Telepon</strong></td>
                            <td>${noTlp}</td>
                        </tr>
                        <tr>
                            <td><strong><i class="fas fa-envelope"></i> Email</strong></td>
                            <td>${email || '<em class="text-muted">Tidak diisi</em>'}</td>
                        </tr>
                    </table>
                </div>
            `;

            document.getElementById('previewContent').innerHTML = previewContent;
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }

        function submitFormFromPreview() {
            document.getElementById('tambahForm').submit();
        }

        function generateDummyData() {
            if (confirm('Ini akan mengisi form dengan data contoh. Lanjutkan?')) {
                document.getElementById('nama').value = 'John Doe';
                document.getElementById('laki_laki').checked = true;
                document.getElementById('alamat').value = 'Jl. Contoh No. 123, Jakarta';
                document.getElementById('no_tlp').value = '081234567890';
                document.getElementById('email').value = 'john@example.com';
                
                document.getElementById('namaCount').textContent = document.getElementById('nama').value.length;
                document.getElementById('alamatCount').textContent = document.getElementById('alamat').value.length;
            }
        }
    </script>
</body>
</html>