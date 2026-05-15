<?php
/**
 * mailer.php — Gmail SMTP OTP sender using PHPMailer
 *
 * SETUP STEPS:
 * 1. Run in your project root: composer require phpmailer/phpmailer
 * 2. Go to your Gmail → Google Account → Security → 2-Step Verification → App Passwords
 * 3. Create an App Password for "Mail" and paste it below as MAIL_PASS
 * 4. Update MAIL_FROM and MAIL_FROM_NAME with your Gmail address and name
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── CONFIG — change these ──────────────────────────────────
define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);
define('MAIL_FROM',      'jeffmarionc@gmail.com');   // Your Gmail address
define('MAIL_PASS',      'znpf zunp quug xxkd'); // Gmail App Password (16-char)
define('MAIL_FROM_NAME', 'ACLC SVS');
// ──────────────────────────────────────────────────────────

/**
 * Sends a 6-digit OTP to the given email address.
 *
 * @param string $toEmail   Recipient email
 * @param string $toName    Recipient display name
 * @param string $otp       The 6-digit OTP code
 * @return bool             true on success, false on failure
 */
function sendOTPEmail(string $toEmail, string $toName, string $otp): bool
{
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_FROM;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your ACLC SVS Login OTP Code';
        $mail->Body    = "
            <div style='font-family:Segoe UI,sans-serif;max-width:480px;margin:auto;'>
                <div style='background:#1a3a5c;padding:20px 28px;border-radius:12px 12px 0 0;'>
                    <h2 style='color:#fff;margin:0;font-size:1.1rem;'>ACLC — Student Violation System</h2>
                </div>
                <div style='background:#f8fafc;padding:28px;border:1px solid #e2e8f0;border-radius:0 0 12px 12px;'>
                    <p style='color:#1e2a3a;font-size:.95rem;margin-top:0;'>Hello, <strong>" . htmlspecialchars($toName) . "</strong>!</p>
                    <p style='color:#64748b;font-size:.88rem;'>Use the OTP below to complete your login. It expires in <strong>5 minutes</strong>.</p>
                    <div style='background:#1a3a5c;color:#fff;font-size:2.2rem;font-weight:800;letter-spacing:10px;
                                text-align:center;padding:18px;border-radius:10px;margin:20px 0;'>
                        {$otp}
                    </div>
                    <p style='color:#94a3b8;font-size:.78rem;margin:0;'>
                        If you did not request this, please ignore this email or contact the SAO office.
                    </p>
                </div>
            </div>
        ";
        $mail->AltBody = "Your ACLC SVS OTP code is: {$otp}. It expires in 5 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer error: " . $mail->ErrorInfo);
        return false;
    }
}

function sendViolationEmail(string $toEmail, string $toName, string $violationType, string $description, string $date): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_FROM;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'ACLC SVS — New Violation Recorded';
        $mail->Body    = "
            <div style='font-family:Segoe UI,sans-serif;max-width:480px;margin:auto;'>
                <div style='background:#1a3a5c;padding:20px 28px;border-radius:12px 12px 0 0;'>
                    <h2 style='color:#fff;margin:0;font-size:1.1rem;'>ACLC — Student Violation System</h2>
                </div>
                <div style='background:#f8fafc;padding:28px;border:1px solid #e2e8f0;border-radius:0 0 12px 12px;'>
                    <p style='color:#1e2a3a;font-size:.95rem;margin-top:0;'>Hello, <strong>" . htmlspecialchars($toName) . "</strong>!</p>
                    <p style='color:#64748b;font-size:.88rem;'>A new violation has been recorded on your account:</p>
                    <div style='background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:16px;margin:16px 0;'>
                        <table style='width:100%;font-size:.88rem;border-collapse:collapse;'>
                            <tr><td style='color:#64748b;padding:5px 0;font-weight:600;'>Violation:</td><td style='color:#1e2a3a;font-weight:700;'>" . htmlspecialchars($violationType) . "</td></tr>
                            <tr><td style='color:#64748b;padding:5px 0;font-weight:600;'>Date:</td><td style='color:#1e2a3a;'>" . htmlspecialchars($date) . "</td></tr>
                            " . ($description ? "<tr><td style='color:#64748b;padding:5px 0;font-weight:600;'>Details:</td><td style='color:#1e2a3a;'>" . htmlspecialchars($description) . "</td></tr>" : "") . "
                        </table>
                    </div>
                    <div style='background:#fef3c7;border:1.5px solid #fcd34d;border-radius:8px;padding:12px 16px;margin-bottom:16px;'>
                        <p style='margin:0;font-size:.85rem;color:#92400e;'>
                            ⚠️ Please log in to your SVS account to view details or file an appeal if you believe this is an error.
                        </p>
                    </div>
                    <a href='http://jeff1.free.nf/StudentViolation/login.php'
                       style='display:inline-block;background:#1a3a5c;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:700;font-size:.88rem;'>
                        → Login to SVS
                    </a>
                    <p style='color:#94a3b8;font-size:.75rem;margin-top:16px;'>
                        If you have questions, contact the SAO office directly.
                    </p>
                </div>
            </div>
        ";
        $mail->AltBody = "A new violation ({$violationType}) has been recorded on your account on {$date}. Login to SVS to view details or file an appeal.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Violation email error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Generates a secure 6-digit OTP string.
 */
function generateOTP(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}