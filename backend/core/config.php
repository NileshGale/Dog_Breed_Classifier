<?php
// config.php — Central configuration for PawDetect
// ─────────────────────────────────────────────────

// ── Database Settings (Local vs Production) ────────
if (isset($_SERVER['HTTP_HOST']) && ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1' || str_contains($_SERVER['HTTP_HOST'], '192.168.'))) {
    // Local development settings (XAMPP)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'pawdetect_db');
} else {
    // Production settings (InfinityFree or other live host)
    define('DB_HOST', 'sql305.infinityfree.com'); 
    define('DB_USER', 'if0_42226919');                
    define('DB_PASS', 'bestfriend2616');
    define('DB_NAME', 'if0_42226919_dog_detect');
}

// ── Machine Learning API Mode ──────────────────────
// Leave as empty string to run the Python model locally via command execution (requires local Python & TensorFlow).
// Set to your hosted API endpoint (e.g. 'https://dog-breed-classifier-api.onrender.com/predict') to support InfinityFree.
define('ML_API_URL', 'https://dog-breed-classifier-1a5u.onrender.com/predict');

// ── SMTP (PHPMailer — no Composer) ────────────────
// Place PHPMailer files at: PHPMailer/src/{PHPMailer,SMTP,Exception}.php
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'nileshgale025@gmail.com');
define('SMTP_PASS',      'okyx drui yoom kfmw');
define('SMTP_FROM',      'nileshgale025@gmail.com');
define('SMTP_FROM_NAME', 'PawDetect');

// ── Uploads ───────────────────────────────────────
define('UPLOADS_DIR', __DIR__ . '/../../uploads/images/');
define('UPLOADS_URL', 'uploads/images/');

// ── Session hardening ─────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if HTTPS
    session_start();
}

// ── Timezone ──────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');

// ── Error reporting (disable in production) ───────
error_reporting(0);
ini_set('display_errors', 0);

// ── Helpers ───────────────────────────────────────
function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'DB connection failed.']));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function sendJSON($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendMail(string $toEmail, string $subject, string $title, string $messageHtml, string $footerText = null) {
    require_once __DIR__ . '/../../vendor/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../../vendor/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../../vendor/PHPMailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'PawDetect — ' . $subject;

        $footer = $footerText ?: "&copy; " . date('Y') . " PawDetect. All rights reserved.";

        $mail->Body    = "
        <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;border:1px solid #eee;border-radius:12px;overflow:hidden'>
          <div style='background:linear-gradient(135deg,#b55a2a,#c9a96e);padding:24px;text-align:center'>
            <h2 style='color:#fff;margin:0;font-size:22px'>🐾 PawDetect</h2>
          </div>
          <div style='padding:32px'>
            <h3 style='color:#3b2a1a'>{$title}</h3>
            {$messageHtml}
          </div>
          <div style='background:#fdf8f3;padding:14px;text-align:center'>
            <p style='color:#bbb;font-size:12px;margin:0'>{$footer}</p>
          </div>
        </div>";
        $mail->AltBody = strip_tags($messageHtml);
        $mail->send();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

function sendOTPEmail(string $toEmail, string $otp, string $subject = 'Your OTP Code', string $purpose = 'verify your account') {
    $msgBody = "
        <p style='color:#555;margin-top:8px'>Please use the OTP below to {$purpose}.</p>
        <div style='background:#fdf8f3;border:2px dashed #c9a96e;border-radius:10px;padding:24px;text-align:center;margin:24px 0'>
          <span style='font-size:40px;font-weight:bold;letter-spacing:12px;color:#b55a2a'>{$otp}</span>
        </div>
        <p style='color:#888;font-size:13px'>⏱ Valid for <strong>10 minutes</strong>. Do not share it with anyone.</p>
        <p style='color:#bbb;font-size:12px;margin-top:12px'>If you did not request this, please ignore this email.</p>";

    return sendMail($toEmail, $subject, 'Email Verification', $msgBody);
}

function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: login_page.html');
        exit;
    }
}
?>
