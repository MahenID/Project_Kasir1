<?php 
include 'koneksi.php';

function shiftToNumber($shiftName) {
    switch($shiftName) {
        case 'Pagi': return 1;
        case 'Sore': return 2;
        case 'Malam': return 3;
        default: return 1;
    }
}

function numberToShift($shiftNumber) {
    switch($shiftNumber) {
        case 1: return 'Pagi';
        case 2: return 'Sore';
        case 3: return 'Malam';
        default: return 'Pagi';
    }
}

if ($_POST) {
    $shift_number = shiftToNumber($_POST['shift']);
    $id = mysqli_real_escape_string($conn, $_POST['id']);
    
    $query = "UPDATE kasir SET shift='$shift_number' WHERE id_kasir='$id'";
    
    if (mysqli_query($conn, $query)) {
        echo "<script>
                alert('Shift berhasil diupdate!');
                location.reload();
              </script>";
    } else {
        echo "<script>alert('Error: " . mysqli_error($conn) . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Shift Pegawai</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .shift-table {
            margin-top: 20px;
        }
        .shift-pagi { background-color: #fff3cd; }
        .shift-sore { background-color: #d1ecf1; }
        .shift-malam { background-color: #d4edda; }
        .random-btn {
            margin-bottom: 15px;
        }
    </style>
</head>
<body class="p-4">
    <div class="container">
        <h2 class="mb-4">Shift Pegawai</h2>
        
        <div class="random-btn">
            <button class="btn btn-success" onclick="randomizeAllShifts()">
                🎲 Random Semua Shift
            </button>
            <button class="btn btn-info" onclick="randomizeTable()">
                🔄 Acak Urutan Pegawai
            </button>
        </div>
        
        <div class="table-responsive shift-table">
            <table class="table table-striped table-hover" id="shiftTable">
                <thead class="table-dark">
                    <tr>
                        <th>Nama Pegawai</th>
                        <th>Shift Saat Ini</th>
                        <th>Ubah Shift</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $q = mysqli_query($conn, "SELECT * FROM kasir");
                    while ($d = mysqli_fetch_assoc($q)) {
                        $currentShift = numberToShift($d['shift']);
                        $rowClass = 'shift-' . strtolower($currentShift);
                        
                        echo "<tr class='$rowClass'>
                                <form method='POST' class='shift-form'>
                                    <td><strong>".$d['nama_kasir']."</strong></td>
                                    <td>
                                        <span class='badge bg-primary'>$currentShift</span>
                                    </td>
                                    <td>
                                        <select name='shift' class='form-select form-select-sm' required>
                                            <option value=''>Pilih Shift</option>
                                            <option value='Pagi'" . ($currentShift == 'Pagi' ? ' selected' : '') . ">🌅 Pagi</option>
                                            <option value='Sore'" . ($currentShift == 'Sore' ? ' selected' : '') . ">🌇 Sore</option>
                                            <option value='Malam'" . ($currentShift == 'Malam' ? ' selected' : '') . ">🌙 Malam</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type='hidden' name='id' value='".$d['id_kasir']."'>
                                        <button type='submit' class='btn btn-warning btn-sm'>Update</button>
                                        <button type='button' class='btn btn-secondary btn-sm' onclick='randomShift(this)'>Random</button>
                                    </td>
                                </form>
                            </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <a href="index.php" class="btn btn-outline-primary">
                ← Kembali ke Beranda
            </a>
        </div>
        <div class="mt-3">
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6>🌅 Shift Pagi</h6>
                            <span id="countPagi" class="badge bg-warning">0</span> orang
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6>🌇 Shift Sore</h6>
                            <span id="countSore" class="badge bg-info">0</span> orang
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6>🌙 Shift Malam</h6>
                            <span id="countMalam" class="badge bg-success">0</span> orang
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Random shift for single employee
        function randomShift(button) {
            const shifts = ['Pagi', 'Sore', 'Malam'];
            const randomIndex = Math.floor(Math.random() * shifts.length);
            const randomShiftValue = shifts[randomIndex];
            
            const select = button.parentElement.querySelector('select[name="shift"]');
            select.value = randomShiftValue;
            
            // Visual feedback
            button.innerHTML = '🎲 ' + randomShiftValue;
            setTimeout(() => {
                button.innerHTML = 'Random';
            }, 2000);
        }
        
        // Random all shifts
        function randomizeAllShifts() {
            const shifts = ['Pagi', 'Sore', 'Malam'];
            const selects = document.querySelectorAll('select[name="shift"]');
            
            selects.forEach(select => {
                const randomIndex = Math.floor(Math.random() * shifts.length);
                select.value = shifts[randomIndex];
            });
            
            updateShiftCounts();
            alert('Semua shift telah dirandom! Klik Update untuk menyimpan perubahan.');
        }
        
        // Randomize table order
        function randomizeTable() {
            const tbody = document.querySelector('#shiftTable tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Shuffle array
            for (let i = rows.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [rows[i], rows[j]] = [rows[j], rows[i]];
            }
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        }
        
        // Update shift counts
        function updateShiftCounts() {
            const selects = document.querySelectorAll('select[name="shift"]');
            let countPagi = 0, countSore = 0, countMalam = 0;
            
            selects.forEach(select => {
                switch(select.value) {
                    case 'Pagi': countPagi++; break;
                    case 'Sore': countSore++; break;
                    case 'Malam': countMalam++; break;
                }
            });
            
            document.getElementById('countPagi').textContent = countPagi;
            document.getElementById('countSore').textContent = countSore;
            document.getElementById('countMalam').textContent = countMalam;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            updateShiftCounts();
            
            document.querySelectorAll('select[name="shift"]').forEach(select => {
                select.addEventListener('change', updateShiftCounts);
            });
        });
        
        function enableAutoSubmit() {
            document.querySelectorAll('select[name="shift"]').forEach(select => {
                select.addEventListener('change', function() {
                    if(confirm('Auto-submit perubahan shift?')) {
                        this.closest('form').submit();
                    }
                });
            });
        }
        
    </script>
</body>
</html>