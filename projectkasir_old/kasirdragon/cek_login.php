<?php
session_start();
include 'koneksi.php';

$username = $_POST['username'];
$password = $_POST['password'];

$query_admin = "SELECT * FROM user WHERE username='$username' && password='$password'";
$result_admin = mysqli_query($conn, $query_admin);

$query_pegawai = "SELECT * FROM pegawai WHERE nama_pegawai='$username' && password='$password'";
$result_pegawai = mysqli_query($conn, $query_pegawai);

if (mysqli_num_rows($result_admin) > 0) {
    $_SESSION['username'] = $username;
    $_SESSION['role'] = 'admin';
    header("Location: index.php");
} else if (mysqli_num_rows($result_pegawai) > 0) {
    $_SESSION['username'] = $username;
    $_SESSION['role'] = 'pegawai';
    header("Location: index.php");
} else {
    header("Location: login.php?error=Username atau Password salah!");
}
?>
