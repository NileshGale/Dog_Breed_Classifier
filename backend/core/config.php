<?php
// config.php — Central configuration for PawDetect
// ─────────────────────────────────────────────────

// ── Database ──────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'pawdetect_db');

// ── SMTP (PHPMailer — no Composer) ────────────────
// Place PHPMailer files at: PHPMailer/src/{PHPMailer,SMTP,Exception}.php
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'ranetanvi0203@gmail.com');
define('SMTP_PASS',      'dkob ahjb ntvp vsvj');
define('SMTP_FROM',      'ranetanvi0203@gmail.com');
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

function sendOTPEmail(string $toEmail, string $otp, string $subject = 'Your OTP Code', string $purpose = 'verify your account') {
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
        $mail->Body    = "
        <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;border:1px solid #eee;border-radius:12px;overflow:hidden'>
          <div style='background:linear-gradient(135deg,#b55a2a,#c9a96e);padding:24px;text-align:center'>
            <h2 style='color:#fff;margin:0;font-size:22px'>🐾 PawDetect</h2>
          </div>
          <div style='padding:32px'>
            <h3 style='color:#3b2a1a'>Email Verification</h3>
            <p style='color:#555;margin-top:8px'>Please use the OTP below to {$purpose}.</p>
            <div style='background:#fdf8f3;border:2px dashed #c9a96e;border-radius:10px;padding:24px;text-align:center;margin:24px 0'>
              <span style='font-size:40px;font-weight:bold;letter-spacing:12px;color:#b55a2a'>{$otp}</span>
            </div>
            <p style='color:#888;font-size:13px'>⏱ Valid for <strong>10 minutes</strong>. Do not share it with anyone.</p>
            <p style='color:#bbb;font-size:12px;margin-top:12px'>If you did not request this, please ignore this email.</p>
          </div>
          <div style='background:#fdf8f3;padding:14px;text-align:center'>
            <p style='color:#bbb;font-size:12px;margin:0'>&copy; " . date('Y') . " PawDetect. All rights reserved.</p>
          </div>
        </div>";
        $mail->AltBody = "PawDetect OTP: {$otp} — Valid for 10 minutes.";
        $mail->send();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: login_page.htm');
        exit;
    }
}
?>
