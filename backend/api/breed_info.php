<?php
// breed_info.php — Returns breed-specific food, vaccine, and behavior data
// GET: ?breed=golden_retriever
require_once __DIR__ . '/../core/config.php';

requireLogin();

$breedParam = trim($_GET['breed'] ?? '');
if (!$breedParam) sendJSON(false, 'No breed specified.');

// Load breed data
$dataFile = __DIR__ . '/../../assets/data/breed_info_data.json';
if (!file_exists($dataFile)) sendJSON(false, 'Breed data file not found.');

$allBreeds = json_decode(file_get_contents($dataFile), true);
if (!$allBreeds) sendJSON(false, 'Failed to parse breed data.');

// Normalize breed name for lookup: lowercase, replace spaces with underscores
$breedKey = strtolower(trim($breedParam));
$breedKey = preg_replace('/[\s\-]+/', '_', $breedKey);

// Try exact match first
$info = $allBreeds[$breedKey] ?? null;

// Fuzzy match: try partial matching if exact not found
if (!$info) {
    foreach ($allBreeds as $key => $data) {
        if ($key === '_default') continue;
        // Check if the search term is contained in the key or vice versa
        if (strpos($key, $breedKey) !== false || strpos($breedKey, $key) !== false) {
            $info = $data;
            $breedKey = $key;
            break;
        }
    }
}

// Try matching individual words
if (!$info) {
    $searchWords = explode('_', $breedKey);
    $bestMatch = null;
    $bestScore = 0;
    foreach ($allBreeds as $key => $data) {
        if ($key === '_default') continue;
        $score = 0;
        foreach ($searchWords as $word) {
            if (strlen($word) > 2 && strpos($key, $word) !== false) {
                $score++;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $data;
            $breedKey = $key;
        }
    }
    if ($bestScore > 0) $info = $bestMatch;
}

// Fallback to default
if (!$info) {
    $info = $allBreeds['_default'] ?? null;
    if (!$info) sendJSON(false, 'No breed information available.');
}

$displayName = ucwords(str_replace('_', ' ', $breedKey));

sendJSON(true, 'Breed info retrieved.', [
    'breed_key'  => $breedKey,
    'breed_name' => $displayName,
    'food'       => $info['food'],
    'vaccines'   => $info['vaccines'],
    'behavior'   => $info['behavior'],
]);
?>
