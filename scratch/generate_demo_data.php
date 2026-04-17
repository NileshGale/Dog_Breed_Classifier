<?php
require_once __DIR__ . '/../backend/core/config.php';

$conn = getDB();

// Check if we have any listings
$chk = $conn->query("SELECT id FROM dog_listings LIMIT 1");
if ($chk->num_rows > 0) {
    echo "Database already has listings. Skipping demo data creation.\n";
    exit;
}

// Get the first user ID to associate listings with
$userRes = $conn->query("SELECT id FROM users LIMIT 1");
if ($userRes->num_rows === 0) {
    echo "No users found. Please register a user first.\n";
    exit;
}
$userId = $userRes->fetch_assoc()['id'];

$demoDogs = [
    [
        'name' => 'Charlie',
        'breed' => 'Golden Retriever',
        'age_label' => '2 years',
        'gender' => 'Male',
        'size' => 'Large',
        'weight' => '25 kg',
        'location' => 'Mumbai, MH',
        'description' => 'Charlie is a happy-go-lucky Golden Retriever who loves to play fetch and is great with kids. He is fully vaccinated and looking for a loving home with a backyard.',
        'traits' => json_encode(['vaccinated' => true, 'neutered' => true, 'good_with_kids' => true, 'good_with_pets' => true]),
        'is_urgent' => 0
    ],
    [
        'name' => 'Luna',
        'breed' => 'Siberian Husky',
        'age_label' => '1.5 years',
        'gender' => 'Female',
        'size' => 'Medium',
        'weight' => '18 kg',
        'location' => 'Pune, MH',
        'description' => 'Luna is a beautiful Husky with striking blue eyes. She is energetic and needs an active owner. She is house-trained and loves long walks.',
        'traits' => json_encode(['vaccinated' => true, 'housed' => true, 'good_with_pets' => true]),
        'is_urgent' => 1
    ],
    [
        'name' => 'Cooper',
        'breed' => 'Beagle Mix',
        'age_label' => '4 years',
        'gender' => 'Male',
        'size' => 'Small',
        'weight' => '10 kg',
        'location' => 'Bangalore, KA',
        'description' => 'Cooper is a calm and affectionate Beagle mix. He is perfectly happy lounging on the sofa but also enjoys his daily sniff-walks. Great for apartment living.',
        'traits' => json_encode(['vaccinated' => true, 'neutered' => true, 'housed' => true]),
        'is_urgent' => 0
    ],
    [
        'name' => 'Bella',
        'breed' => 'Indie',
        'age_label' => '6 months',
        'gender' => 'Female',
        'size' => 'Medium',
        'weight' => '8 kg',
        'location' => 'Delhi, DL',
        'description' => 'Bella is a sweet Indie pup found near a park. She is very intelligent and quick to learn. She is looking for her forever family who will give her lots of cuddles.',
        'traits' => json_encode(['vaccinated' => false, 'good_with_kids' => true, 'good_with_pets' => true]),
        'is_urgent' => 0
    ]
];

$stmt = $conn->prepare("INSERT INTO dog_listings (user_id, name, breed, age_label, gender, size, weight, location, description, traits, is_urgent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($demoDogs as $dog) {
    $stmt->bind_param("isssssssssi", 
        $userId, 
        $dog['name'], 
        $dog['breed'], 
        $dog['age_label'], 
        $dog['gender'], 
        $dog['size'], 
        $dog['weight'], 
        $dog['location'], 
        $dog['description'], 
        $dog['traits'], 
        $dog['is_urgent']
    );
    $stmt->execute();
}

$stmt->close();
$conn->close();

echo "Demo data populated successfully!\n";
?>
