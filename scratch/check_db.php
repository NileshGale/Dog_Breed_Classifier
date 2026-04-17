<?php
require_once __DIR__ . '/../backend/core/config.php';
$conn = getDB();
$res = $conn->query("SELECT id, name, photo_path FROM dog_listings");
while($row = $res->fetch_assoc()) {
    echo "ID: " . $row['id'] . " | Name: " . $row['name'] . " | Path: " . $row['photo_path'] . "\n";
}
$conn->close();
?>
