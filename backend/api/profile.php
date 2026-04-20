<?php
// profile.php — Profile CRUD + Email Change with OTP
// Actions: get | update | request_email_change | verify_email_otp | confirm_new_email
require_once __DIR__ . '/../core/config.php';

requireLogin();

$action = trim($_POST['action'] ?? $_GET['action'] ?? 'get');

// ── GET profile ────────────────────────────────────────────────────
if ($action === 'get') {
    $conn = getDB();
    $stmt = $conn->prepare("SELECT id, full_name, email, mobile, age, gender FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();
    $stmt->close(); $conn->close();
    sendJSON(true, 'OK', ['user' => $user]);
}

// ── UPDATE profile (name, mobile, age, gender) ────────────────────
if ($action === 'update') {
    $fullName = trim($_POST['full_name'] ?? '');
    $mobile   = trim($_POST['mobile']    ?? '');
    $age      = intval($_POST['age']     ?? 0);
    $gender   = trim($_POST['gender']    ?? '');

    if (strlen($fullName) < 2) sendJSON(false, 'Full name must be at least 2 characters.');
    $allowedGenders = ['male','female','other','prefer_not_to_say',''];
    if (!in_array($gender, $allowedGenders)) sendJSON(false, 'Invalid gender value.');

    $ageVal    = ($age >= 1 && $age <= 120) ? $age : null;
    $mobileVal = !empty($mobile) ? $mobile : null;
    $genderVal = !empty($gender) ? $gender : null;

    $conn = getDB();
    $stmt = $conn->prepare("UPDATE users SET full_name=?, mobile=?, age=?, gender=? WHERE id=?");
    $stmt->bind_param("ssisi", $fullName, $mobileVal, $ageVal, $genderVal, $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close(); $conn->close();

    $_SESSION['user_name'] = $fullName;
    sendJSON(true, 'Profile updated successfully!');
}

// ── REQUEST email change — send OTP to CURRENT email ─────────────
if ($action === 'request_email_change') {
    $conn = getDB();
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $conn->close();

    $currentEmail = $user['email'];
    $otp          = generateOTP();
    $otpHash      = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt    = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $conn = getDB();
    $del  = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'change_email'");
    $del->bind_param("s", $currentEmail); $del->execute(); $del->close();

    $ins = $conn->prepare("INSERT INTO otp_verifications (email, otp_hash, purpose, expires_at) VALUES (?, ?, 'change_email', ?)");
    $ins->bind_param("sss", $currentEmail, $otpHash, $expiresAt);
    $ins->execute(); $ins->close(); $conn->close();

    $_SESSION['email_change_step'] = 'verify_current';

    if (!sendOTPEmail($currentEmail, $otp, 'Email Change Verification OTP', 'authorise the change of your registered email')) {
        sendJSON(false, 'Failed to send OTP. Please check SMTP settings.');
    }
    sendJSON(true, 'OTP sent to your current email address.', ['current_email' => $currentEmail]);
}

// ── VERIFY OTP for current email, then ask for new email ─────────
if ($action === 'verify_current_otp') {
    $conn = getDB();
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $user  = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $conn->close();
    $currentEmail = $user['email'];

    $otp = trim($_POST['otp'] ?? '');
    if (!preg_match('/^\d{6}$/', $otp)) sendJSON(false, 'OTP must be 6 digits.');

    $conn = getDB();
    $stmt = $conn->prepare("SELECT otp_hash, expires_at FROM otp_verifications WHERE email = ? AND purpose = 'change_email' ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $currentEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) sendJSON(false, 'No OTP request found. Please start again.');
    $row = $result->fetch_assoc();
    $stmt->close(); $conn->close();

    if (strtotime($row['expires_at']) < time()) sendJSON(false, 'OTP expired. Please start again.');
    if (!password_verify($otp, $row['otp_hash'])) sendJSON(false, 'Invalid OTP.');

    $_SESSION['email_change_step'] = 'enter_new_email';
    sendJSON(true, 'Current email verified! Please enter your new email address.');
}

// ── SEND OTP to NEW email ─────────────────────────────────────────
if ($action === 'send_new_email_otp') {
    if (($_SESSION['email_change_step'] ?? '') !== 'enter_new_email')
        sendJSON(false, 'Please complete the previous step first.');

    $newEmail = filter_input(INPUT_POST, 'new_email', FILTER_SANITIZE_EMAIL);
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) sendJSON(false, 'Invalid email format.');

    $conn = getDB();
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($newEmail === $user['email']) sendJSON(false, 'New email must be different from current email.');

    // Check not already taken
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $chk->bind_param("s", $newEmail);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) sendJSON(false, 'That email is already registered.');
    $chk->close();

    $currentEmail = $user['email'];
    $otp          = generateOTP();
    $otpHash      = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt    = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Store new_email in otp record (keyed by current email)
    $del = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'change_email'");
    $del->bind_param("s", $currentEmail); $del->execute(); $del->close();

    $ins = $conn->prepare("INSERT INTO otp_verifications (email, otp_hash, purpose, new_email, expires_at) VALUES (?, ?, 'change_email', ?, ?)");
    $ins->bind_param("ssss", $currentEmail, $otpHash, $newEmail, $expiresAt);
    $ins->execute(); $ins->close(); $conn->close();

    if (!sendOTPEmail($newEmail, $otp, 'Verify New Email OTP', 'confirm your new email address')) {
        sendJSON(false, 'Failed to send OTP to new email. Please check it is valid.');
    }

    $_SESSION['email_change_step'] = 'verify_new_email';
    sendJSON(true, 'OTP sent to your new email. Please verify it.', ['new_email' => $newEmail]);
}

// ── VERIFY OTP on new email → update email ────────────────────────
if ($action === 'verify_new_email_otp') {
    $otp = trim($_POST['otp'] ?? '');
    if (!preg_match('/^\d{6}$/', $otp)) sendJSON(false, 'OTP must be 6 digits.');

    $conn = getDB();
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $currentEmail = $user['email'];

    $stmt = $conn->prepare("SELECT otp_hash, new_email, expires_at FROM otp_verifications WHERE email = ? AND purpose = 'change_email' ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $currentEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) sendJSON(false, 'No OTP request found. Please start again.');
    $row = $result->fetch_assoc();
    $stmt->close();

    if (strtotime($row['expires_at']) < time()) sendJSON(false, 'OTP expired. Please start again.');
    if (!password_verify($otp, $row['otp_hash'])) sendJSON(false, 'Invalid OTP.');

    $newEmail = $row['new_email'];

    // Update email
    $upd = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
    $upd->bind_param("si", $newEmail, $_SESSION['user_id']);
    $upd->execute(); $upd->close();

    // Cleanup
    $del = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'change_email'");
    $del->bind_param("s", $currentEmail); $del->execute(); $del->close();
    $conn->close();

    $_SESSION['user_email'] = $newEmail;
    unset($_SESSION['email_change_step']);
    sendJSON(true, 'Email updated successfully!', ['new_email' => $newEmail]);
}

// ── SAVE dog listing ───────────────────────────────────────────────
if ($action === 'save_listing') {
    $name     = trim($_POST['name'] ?? '');
    $breed    = trim($_POST['breed'] ?? '');
    $age      = trim($_POST['age_label'] ?? '');
    $gender   = trim($_POST['gender'] ?? '');
    $size     = trim($_POST['size'] ?? '');
    $weight   = trim($_POST['weight'] ?? '');
    $loc      = trim($_POST['location'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $needs    = trim($_POST['special_needs'] ?? '');
    $urgent   = ($_POST['urgent'] === 'true' || $_POST['urgent'] === '1') ? 1 : 0;
    
    // traits JSON
    $traits = json_encode([
        'vaccinated'     => ($_POST['vaccinated'] ?? 'false') === 'true',
        'neutered'       => ($_POST['neutered'] ?? 'false') === 'true',
        'good_with_kids' => ($_POST['good_with_kids'] ?? 'false') === 'true',
        'good_with_pets' => ($_POST['good_with_pets'] ?? 'false') === 'true',
        'housed'         => ($_POST['housed'] ?? 'false') === 'true',
        'microchipped'   => ($_POST['microchipped'] ?? 'false') === 'true'
    ]);

    if (!$name || !$breed) sendJSON(false, 'Name and Breed are required.');

    $savedPath = null;
    if (!empty($_POST['photo'])) {
        $base64 = $_POST['photo'];
        if (strpos($base64, ',') !== false) [, $base64] = explode(',', $base64, 2);
        $imageData = base64_decode($base64);
        if ($imageData) {
            if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
            $filename  = 'list_' . $_SESSION['user_id'] . '_' . time() . '.jpg';
            $filepath  = UPLOADS_DIR . $filename;
            if (file_put_contents($filepath, $imageData) !== false) {
                $savedPath = UPLOADS_URL . $filename;
            }
        }
    }

    $conn = getDB();
    $stmt = $conn->prepare("INSERT INTO dog_listings (user_id, name, breed, age_label, gender, size, weight, location, description, photo_path, traits, special_needs, is_urgent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssssssssi", $_SESSION['user_id'], $name, $breed, $age, $gender, $size, $weight, $loc, $desc, $savedPath, $traits, $needs, $urgent);
    
    if ($stmt->execute()) {
        $stmt->close(); $conn->close();
        sendJSON(true, 'Listing submitted successfully!');
    } else {
        $err = $stmt->error; $stmt->close(); $conn->close();
        sendJSON(false, 'Database error: ' . $err);
    }
}

// ── GET dog history ───────────────────────────────────────────────
if ($action === 'get_history') {
    $conn = getDB();
    $stmt = $conn->prepare("SELECT id, name, breed, photo_path, created_at, is_urgent FROM dog_listings WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $history = [];
    while ($row = $res->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close(); $conn->close();
    sendJSON(true, 'OK', ['history' => $history]);
}

// ── GET all adoption listings (Public) ──────────────────────────
if ($action === 'get_public_listings') {
    $conn = getDB();
    // Join with users to get owner details
    $sql = "SELECT d.*, u.full_name as owner_name, u.email as owner_email, u.mobile as owner_mobile 
            FROM dog_listings d 
            JOIN users u ON d.user_id = u.id 
            ORDER BY d.created_at DESC";
            
    $res = $conn->query($sql);
    $listings = [];
    while ($row = $res->fetch_assoc()) {
        $listings[] = $row;
    }
    $conn->close();
    sendJSON(true, 'OK', ['listings' => $listings]);
}

// ── DELETE dog listing ─────────────────────────────────────────────
if ($action === 'delete_listing') {
    $id = intval($_POST['listing_id'] ?? 0);
    if ($id <= 0) sendJSON(false, 'Invalid listing ID.');

    $conn = getDB();
    // 1. Fetch photo_path and user_id to verify ownership
    $stmt = $conn->prepare("SELECT user_id, photo_path FROM dog_listings WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close(); $conn->close();
        sendJSON(false, 'Listing not found.');
    }
    $row = $res->fetch_assoc();
    $stmt->close();

    // 2. Ownership check
    if (intval($row['user_id']) !== intval($_SESSION['user_id'])) {
        $conn->close();
        sendJSON(false, 'Unauthorized access.');
    }

    // 3. Delete file
    if ($row['photo_path']) {
        $fullPath = __DIR__ . '/../../' . $row['photo_path'];
        if (file_exists($fullPath)) @unlink($fullPath);
    }

    // 4. Delete from DB
    $del = $conn->prepare("DELETE FROM dog_listings WHERE id = ?");
    $del->bind_param("i", $id);
    
    if ($del->execute()) {
        $del->close(); $conn->close();
        sendJSON(true, 'Listing deleted successfully!');
    } else {
        $err = $del->error; $del->close(); $conn->close();
        sendJSON(false, 'Database error: ' . $err);
    }
}

sendJSON(false, 'Invalid action.');
?>
