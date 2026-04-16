<?php
// predict.php — Calls Python CNN script directly to predict dog breed
// No Flask server needed — uses exec() to run dog_breed_detector.py
require_once __DIR__ . '/../core/config.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(false, 'Invalid request method.');

$imagePath = trim($_POST['image_path'] ?? '');
$scanId    = intval($_POST['scan_id']   ?? 0);

if (!$imagePath) sendJSON(false, 'No image path provided.');

// ── Resolve absolute path on server ──────────────────────────────────
$absPath = __DIR__ . '/../../' . ltrim($imagePath, '/');
if (!file_exists($absPath)) sendJSON(false, 'Image file not found: ' . $imagePath);

// ── Call Python CNN script ───────────────────────────────────────────
$pythonCmd = 'py -3.13';   // Use Python 3.13 via launcher to get tensorflow support
$scriptPath = __DIR__ . '/../scripts/dog_breed_detector.py';
$escapedImgPath = escapeshellarg($absPath);

$command = "$pythonCmd \"$scriptPath\" $escapedImgPath 2>&1";
$output = shell_exec($command);

if (!$output) {
    sendJSON(false, 'Python script returned no output. Make sure Python and TensorFlow are installed.', [
        'command' => $command
    ]);
}

// ── Parse the JSON output from Python ────────────────────────────────
// The Python script may output TF warnings before JSON — find the JSON line
$lines = explode("\n", trim($output));
$jsonLine = null;
// Search from the end to find the JSON output (last line with JSON)
for ($i = count($lines) - 1; $i >= 0; $i--) {
    $trimmed = trim($lines[$i]);
    if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
        $jsonLine = $trimmed;
        break;
    }
}

if (!$jsonLine) {
    sendJSON(false, 'Could not parse CNN output.', [
        'raw_output' => substr($output, 0, 500)
    ]);
}

$result = json_decode($jsonLine, true);
if (!$result || !isset($result['success'])) {
    sendJSON(false, 'Invalid JSON from CNN script.', [
        'raw_json' => $jsonLine
    ]);
}

if (!$result['success']) {
    sendJSON(false, $result['message'] ?? 'Prediction failed.');
}

// ── Update scan_history with prediction results ──────────────────────
if ($scanId > 0 && !empty($result['breed'])) {
    $conn = getDB();
    $stmt = $conn->prepare("UPDATE scan_history SET top_breed = ?, confidence = ?, predictions = ? WHERE id = ? AND user_id = ?");
    $breed      = $result['breed'];
    $confidence = $result['confidence'] ?? 0;
    $top3Json   = json_encode($result['top3'] ?? []);
    $stmt->bind_param("sdsii", $breed, $confidence, $top3Json, $scanId, $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// ── Return results to frontend ───────────────────────────────────────
sendJSON(true, 'Breed detected successfully.', [
    'breed'          => $result['breed'] ?? null,
    'confidence'     => $result['confidence'] ?? null,
    'confidence_pct' => $result['confidence_pct'] ?? '',
    'is_dog'         => $result['is_dog'] ?? true,
    'top3'           => $result['top3'] ?? [],
]);
?>
