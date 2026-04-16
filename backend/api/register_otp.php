<?php
// register_otp.php — Registration + OTP verification (PHPMailer, no Composer)
// Actions: register | verify_otp | resend_otp
require_once __DIR__ . '/../core/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(false, 'Invalid request method.');
}

$action = trim($_POST['action'] ?? '');

// ══════════════════════════════════════════════════════
// STEP 1 — Register: validate, store pending, send OTP
// ══════════════════════════════════════════════════════
if ($action === 'register') {

    $fullName        = trim($_POST['full_name']       ?? '');
    $email           = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password        = $_POST['password']             ?? '';
    $confirmPassword = $_POST['confirmPassword']      ?? '';

    if (empty($fullName))        sendJSON(false, 'Full name is required.');
    if (strlen($fullName) < 2)   sendJSON(false, 'Full name must be at least 2 characters.');
    if (empty($email))           sendJSON(false, 'Email is required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sendJSON(false, 'Invalid email format.');
    if (strlen($password) < 8)   sendJSON(false, 'Password must be at least 8 characters.');
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password))
        sendJSON(false, 'Password must contain at least one special character (!@#$%^&*...).');
    if ($password !== $confirmPassword) sendJSON(false, 'Passwords do not match.');

    $conn = getDB();

    $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $chk->bind_param("s", $email);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) sendJSON(false, 'Email already registered. Please login or use a different email.');
    $chk->close();

    $otp        = generateOTP();
    $otpHash    = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt  = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $hashedPass = password_hash($password, PASSWORD_DEFAULT);

    // Store full_name in session for later insertion
    $_SESSION['pending_email']     = $email;
    $_SESSION['pending_full_name'] = $fullName;

    // Clean old records
    $del = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'register'");
    $del->bind_param("s", $email);
    $del->execute();
    $del->close();

    $ins = $conn->prepare("INSERT INTO otp_verifications (email, otp_hash, hashed_password, purpose, expires_at) VALUES (?, ?, ?, 'register', ?)");
    $ins->bind_param("ssss", $email, $otpHash, $hashedPass, $expiresAt);
    if (!$ins->execute()) sendJSON(false, 'Failed to store OTP. Please try again.');
    $ins->close();
    $conn->close();

    if (!sendOTPEmail($email, $otp, 'Email Verification OTP', 'verify your email and complete registration')) {
        sendJSON(false, 'Failed to send OTP email. Please check SMTP settings.');
    }

    sendJSON(true, 'OTP sent to your email. Please enter it to complete registration.', ['step' => 'verify_otp']);
}

// ══════════════════════════════════════════════════════
// STEP 2 — Verify OTP: confirm and create user
// ══════════════════════════════════════════════════════
if ($action === 'verify_otp') {

    $email    = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $otp      = trim($_POST['otp'] ?? '');
    $fullName = trim($_SESSION['pending_full_name'] ?? ($_POST['full_name'] ?? ''));

    if (empty($email) || empty($otp)) sendJSON(false, 'Email and OTP are required.');
    if (!preg_match('/^\d{6}$/', $otp)) sendJSON(false, 'OTP must be a 6-digit number.');

    $conn = getDB();

    $stmt = $conn->prepare("SELECT otp_hash, hashed_password, expires_at FROM otp_verifications WHERE email = ? AND purpose = 'register' ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) sendJSON(false, 'No pending verification found. Please register again.');

    $row = $result->fetch_assoc();
    $stmt->close();

    if (strtotime($row['expires_at']) < time()) {
        $d = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'register'");
        $d->bind_param("s", $email);
        $d->execute();
        sendJSON(false, 'OTP has expired. Please register again.');
    }

    if (!password_verify($otp, $row['otp_hash'])) sendJSON(false, 'Invalid OTP. Please try again.');

    // Create user
    $ins = $conn->prepare("INSERT INTO users (full_name, email, password, created_at) VALUES (?, ?, ?, NOW())");
    $ins->bind_param("sss", $fullName, $email, $row['hashed_password']);
    if (!$ins->execute()) sendJSON(false, 'Failed to create account. Email may already exist.');
    $userId = $ins->insert_id;
    $ins->close();

    // Cleanup
    $d = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'register'");
    $d->bind_param("s", $email);
    $d->execute();
    $conn->close();

    unset($_SESSION['pending_email'], $_SESSION['pending_full_name']);

    sendJSON(true, 'Registration complete! You can now login.', ['user_id' => $userId, 'email' => $email]);
}

// ══════════════════════════════════════════════════════
// Resend OTP
// ══════════════════════════════════════════════════════
if ($action === 'resend_otp') {

    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    if (empty($email)) sendJSON(false, 'Email is required.');

    $conn = getDB();
    $stmt = $conn->prepare("SELECT hashed_password FROM otp_verifications WHERE email = ? AND purpose = 'register' ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) sendJSON(false, 'No pending registration found. Please register again.');
    $stmt->close();

    $otp       = generateOTP();
    $otpHash   = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $upd = $conn->prepare("UPDATE otp_verifications SET otp_hash = ?, expires_at = ?, created_at = NOW() WHERE email = ? AND purpose = 'register'");
    $upd->bind_param("sss", $otpHash, $expiresAt, $email);
    $upd->execute();
    $upd->close();
    $conn->close();

    if (!sendOTPEmail($email, $otp, 'Resend OTP', 'complete your registration')) {
        sendJSON(false, 'Failed to resend OTP. Check SMTP settings.');
    }

    sendJSON(true, 'A new OTP has been sent to your email.');
}

sendJSON(false, 'Invalid action.');
?>
