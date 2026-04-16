<?php
// forgot_password.php — Send OTP + Reset Password
// Actions: send_otp | verify_otp | reset_password
require_once __DIR__ . '/../core/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(false, 'Invalid request method.');

$action = trim($_POST['action'] ?? '');

// ── Send OTP to email ──────────────────────────────────────────────
if ($action === 'send_otp') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
        sendJSON(false, 'Please enter a valid email address.');

    $conn = getDB();
    $chk  = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1");
    $chk->bind_param("s", $email);
    $chk->execute();
    $chk->store_result();
    // Don't reveal whether email exists — always success message
    if ($chk->num_rows === 0) {
        $chk->close(); $conn->close();
        sendJSON(true, 'If that email exists, an OTP has been sent.');
    }
    $chk->close();

    $otp       = generateOTP();
    $otpHash   = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $del = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'forgot_password'");
    $del->bind_param("s", $email); $del->execute(); $del->close();

    $ins = $conn->prepare("INSERT INTO otp_verifications (email, otp_hash, purpose, expires_at) VALUES (?, ?, 'forgot_password', ?)");
    $ins->bind_param("sss", $email, $otpHash, $expiresAt);
    $ins->execute(); $ins->close();
    $conn->close();

    sendOTPEmail($email, $otp, 'Password Reset OTP', 'reset your password');
    sendJSON(true, 'If that email exists, an OTP has been sent.');
}

// ── Verify OTP ────────────────────────────────────────────────────
if ($action === 'verify_otp') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $otp   = trim($_POST['otp'] ?? '');

    if (empty($email) || empty($otp))         sendJSON(false, 'Email and OTP required.');
    if (!preg_match('/^\d{6}$/', $otp))       sendJSON(false, 'OTP must be 6 digits.');

    $conn = getDB();
    $stmt = $conn->prepare("SELECT otp_hash, expires_at FROM otp_verifications WHERE email = ? AND purpose = 'forgot_password' ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) sendJSON(false, 'No OTP request found. Please start again.');
    $row = $result->fetch_assoc();
    $stmt->close(); $conn->close();

    if (strtotime($row['expires_at']) < time()) sendJSON(false, 'OTP has expired. Please request a new one.');
    if (!password_verify($otp, $row['otp_hash'])) sendJSON(false, 'Invalid OTP. Please try again.');

    // Store verified state in session
    $_SESSION['fp_verified_email'] = $email;
    sendJSON(true, 'OTP verified. You may now set a new password.');
}

// ── Reset Password ────────────────────────────────────────────────
if ($action === 'reset_password') {
    $email           = $_SESSION['fp_verified_email'] ?? '';
    $password        = $_POST['password']        ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if (empty($email))             sendJSON(false, 'Session expired. Please start the reset process again.');
    if (strlen($password) < 8)    sendJSON(false, 'Password must be at least 8 characters.');
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password))
        sendJSON(false, 'Password must contain at least one special character.');
    if ($password !== $confirmPassword) sendJSON(false, 'Passwords do not match.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $conn = getDB();
    $upd  = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $upd->bind_param("ss", $hash, $email);
    $upd->execute();
    $upd->close();

    // Cleanup OTP record
    $del = $conn->prepare("DELETE FROM otp_verifications WHERE email = ? AND purpose = 'forgot_password'");
    $del->bind_param("s", $email); $del->execute(); $del->close();
    $conn->close();

    unset($_SESSION['fp_verified_email']);
    sendJSON(true, 'Password reset successfully! You can now login.');
}

sendJSON(false, 'Invalid action.');
?>
