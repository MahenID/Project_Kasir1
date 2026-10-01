
<?php
include 'koneksi.php';

$result = mysqli_query($conn, "SELECT * FROM kasir");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Data Kasir</title>
</head>
<body>
    <h2>Data Kasir</h2>
    <table border="1">
        <tr>
            <th>id_kasir</th><th>nama_kasir</th><th>shift</th><th>credit</th>
        </tr>
        <?php while($row = mysqli_fetch_assoc($result)) { ?>
        <tr>
            <td><?php echo $row["id_kasir"]; ?></td><td><?php echo $row["nama_kasir"]; ?></td><td><?php echo $row["shift"]; ?></td><td><?php echo $row["credit"]; ?></td>
        </tr>
        <?php } ?>
    </table>
</body>
</html>
