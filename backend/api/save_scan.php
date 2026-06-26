<?php
// save_scan.php — Save uploaded/camera image + prediction results
// POST: image (base64 or file), source (upload|camera), predictions (JSON string)
require_once __DIR__ . '/../core/config.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(false, 'Invalid request method.');

$source      = in_array($_POST['source'] ?? '', ['upload','camera']) ? $_POST['source'] : 'upload';
$predictions = $_POST['predictions'] ?? null;   // JSON string from ML backend
$topBreed    = trim($_POST['top_breed']   ?? '');
$confidence  = floatval($_POST['confidence'] ?? 0);

// Ensure uploads directory exists
if (!is_dir(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0755, true);
}

$savedPath = null;

// ── Handle base64 image (camera capture) ──────────────────────────
if (!empty($_POST['image_base64'])) {
    $base64 = $_POST['image_base64'];
    // Strip data URI prefix if present
    if (strpos($base64, ',') !== false) {
        [, $base64] = explode(',', $base64, 2);
    }
    $imageData = base64_decode($base64);
    if (!$imageData) sendJSON(false, 'Invalid image data.');

    $filename  = 'cam_' . $_SESSION['user_id'] . '_' . time() . '.jpg';
    $filepath  = UPLOADS_DIR . $filename;
    if (file_put_contents($filepath, $imageData) === false) {
        sendJSON(false, 'Failed to save captured image.');
    }
    $savedPath = UPLOADS_URL . $filename;
}

// ── Handle file upload ─────────────────────────────────────────────
elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file    = $_FILES['image'];
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $mime    = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) sendJSON(false, 'Only JPG, PNG, WEBP images are allowed.');
    if ($file['size'] > 10 * 1024 * 1024) sendJSON(false, 'File size must be under 10 MB.');

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'up_' . $_SESSION['user_id'] . '_' . time() . '.' . strtolower($ext);
    $filepath = UPLOADS_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        sendJSON(false, 'Failed to save uploaded image.');
    }
    $savedPath = UPLOADS_URL . $filename;
}

else {
    sendJSON(false, 'No image provided.');
}

// ── Cleanup old scan images and database records older than 2 days ──
function cleanupOldScans($conn) {
    $stmt = $conn->prepare("SELECT id, image_path FROM scan_history WHERE created_at < NOW() - INTERVAL 2 DAY");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $imagePath = $row['image_path'];
            if (!empty($imagePath)) {
                $fullPath = __DIR__ . '/../../' . $imagePath;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }
        $stmt->close();
    }
    $conn->query("DELETE FROM scan_history WHERE created_at < NOW() - INTERVAL 2 DAY");
}

// ── Store record in DB ─────────────────────────────────────────────
$conn = getDB();
cleanupOldScans($conn);

$stmt = $conn->prepare("INSERT INTO scan_history (user_id, image_path, source, top_breed, confidence, predictions) VALUES (?, ?, ?, ?, ?, ?)");
$topBreedVal   = $topBreed   ?: null;
$confVal       = $confidence > 0 ? $confidence : null;
$predictionsVal= $predictions ?: null;
$stmt->bind_param("isssds", $_SESSION['user_id'], $savedPath, $source, $topBreedVal, $confVal, $predictionsVal);
$stmt->execute();
$scanId = $stmt->insert_id;
$stmt->close(); $conn->close();

sendJSON(true, 'Image saved.', [
    'scan_id'    => $scanId,
    'image_path' => $savedPath
]);
?>
