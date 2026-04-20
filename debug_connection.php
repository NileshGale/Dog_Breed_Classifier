<?php
// debug_connection.php
require_once __DIR__ . '/backend/core/config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html'); // Ensure HTML output for browser/CLI readability

echo "<h3>Diagnostic Report</h3>";

// 1. Check DB
try {
    $conn = getDB();
    echo "✅ Database connection successful.<br>";
    
    $res = $conn->query("SHOW TABLES");
    echo "Tables in database: ";
    $tables = [];
    while($row = $res->fetch_array()) $tables[] = $row[0];
    echo implode(", ", $tables) . "<br>";
    
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
}

// 2. Check Mail
echo "<h3>Testing SMTP Mail</h3>";
$testEmail = 'nileshgale520@gmail.com'; 
echo "Attempting to send test email to: $testEmail...<br>";

// Use PHPMailer classes
require_once __DIR__ . '/vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/src/SMTP.php';

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
    $mail->addAddress($testEmail);
    $mail->isHTML(true);
    $mail->Subject = 'PawDetect — Debug Test';
    $mail->Body    = "This is a test email to verify SMTP configuration.";
    
    $mail->SMTPDebug = 2; // Output detailed logs to stdout
    $mail->send();
    echo "<br>✅ Mail sent successfully!";
} catch (Exception $e) {
    echo "<br>❌ Mail error: " . $mail->ErrorInfo;
}
?>
