<?php
// login.php — Login Backend
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(false, 'Invalid request method.');

$email    = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password))          sendJSON(false, 'Email and password are required.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sendJSON(false, 'Invalid email format.');

$conn = getDB();
$stmt = $conn->prepare("SELECT id, full_name, email, password, is_active FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) sendJSON(false, 'Invalid email or password.');

$user = $result->fetch_assoc();
$stmt->close();

if (!$user['is_active']) sendJSON(false, 'Your account has been deactivated. Please contact support.');
if (!password_verify($password, $user['password'])) sendJSON(false, 'Invalid email or password.');

// Update last login
$upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
$upd->bind_param("i", $user['id']); $upd->execute(); $upd->close();
$conn->close();

// Set session
$_SESSION['user_id']    = $user['id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_name']  = $user['full_name'];
$_SESSION['logged_in']  = true;

sendJSON(true, 'Login successful!', [
    'user_id'   => $user['id'],
    'email'     => $user['email'],
    'full_name' => $user['full_name'],
    'redirect'  => 'detect.html'
]);
?>
