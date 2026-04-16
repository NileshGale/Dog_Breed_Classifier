<?php
// setup_listings.php — Automated table creator for dog_listings
require_once __DIR__ . '/../core/config.php';

$conn = getDB();

$sql = "CREATE TABLE IF NOT EXISTS dog_listings (
    id            INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT(11) UNSIGNED NOT NULL,
    name          VARCHAR(150)  NOT NULL,
    breed         VARCHAR(150)  NOT NULL,
    age_label     VARCHAR(100),
    gender        VARCHAR(20),
    size          VARCHAR(20),
    weight        VARCHAR(50),
    location      VARCHAR(255),
    description   TEXT,
    photo_path    VARCHAR(500),
    traits        TEXT,              -- JSON string
    special_needs TEXT,
    is_urgent     TINYINT(1) DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql) === TRUE) {
    echo "<div style='font-family:sans-serif;padding:20px;background:#eafaf1;color:#1e8449;border-radius:10px;margin:20px'>
            <h2>✅ Success!</h2>
            <p>'dog_listings' table created or already exists.</p>
            <p>You can now use the 'My Dog History' feature.</p>
            <a href='../../frontend/detect.html' style='color:#1e8449;font-weight:bold'>Go to Dashboard →</a>
          </div>";
} else {
    echo "<div style='font-family:sans-serif;padding:20px;background:#ffeaea;color:#c0392b;border-radius:10px;margin:20px'>
            <h2>❌ Error</h2>
            <p>Could not create table: " . $conn->error . "</p>
          </div>";
}

$conn->close();
?>
