<?php
include 'koneksi.php';
session_start();
if(!isset($_SESSION['username'])){ header("Location: login.php"); exit; }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <h2>Dashboard</h2>
    <div class="row">
        <div class='col-md-3 bg-primary text-white p-3 m-2'>Total Barang:
            <?php 
            $q = mysqli_query($conn, "SELECT COUNT(*) FROM barang"); 
            $d = mysqli_fetch_array($q); 
            echo $d[0]; 
            ?>
        </div>
        <div class='col-md-3 bg-success text-white p-3 m-2'>Total Transaksi:
            <?php 
            $q = mysqli_query($conn, "SELECT COUNT(*) FROM transaksi"); 
            $d = mysqli_fetch_array($q); 
            echo $d[0]; 
            ?>
        </div>
        <div class='col-md-3 bg-warning text-white p-3 m-2'>Total Supplier:
            <?php 
            $q = mysqli_query($conn, "SELECT COUNT(*) FROM supplier"); 
            $d = mysqli_fetch_array($q); 
            echo $d[0]; 
            ?>
        </div>
    </div>
</body>
</html>
