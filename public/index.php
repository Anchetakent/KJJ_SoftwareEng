<?php
// public/index.php
require_once dirname(__DIR__) . '/app/includes/security.php';
start_secure_session();
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/app/includes/otp_service.php';

$error_message = "";
$success_message = "";

if (!enforce_session_timeout() && !empty($_SESSION['session_expired'])) {
    $error_message = "Your session expired. Please sign in again.";
    unset($_SESSION['session_expired']);
}

// Initialize OTP Service
$otpService = new OtpService($conn);

// Handle user clicking "Cancel" during any OTP or Reset phase
if (isset($_GET['cancel_mfa'])) {
    session_unset();
    session_regenerate_id(true);
    header("Location: index.php");
    exit();
}

define('LOGIN_RATE_LIMIT_MAX', 5);
define('LOGIN_RATE_LIMIT_WINDOW', 900);
define('LOGIN_RATE_LIMIT_CLEANUP', 86400);

function get_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function ensure_login_attempts_table($pdo, $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(191) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_login_attempts_email_ip (email, ip),
        KEY idx_login_attempts_time (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo->exec($sql) !== false;
    }

    return $conn->query($sql) === true;
}

function get_login_failure_stats($pdo, $conn, string $email, string $ip, string $cutoff): array {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS failures, MIN(attempted_at) AS first_failure FROM login_attempts WHERE email = ? AND ip = ? AND success = 0 AND attempted_at >= ?");
        $stmt->execute([$email, $ip, $cutoff]);
        $row = $stmt->fetch();
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS failures, MIN(attempted_at) AS first_failure FROM login_attempts WHERE email = ? AND ip = ? AND success = 0 AND attempted_at >= ?");
        $stmt->bind_param("sss", $email, $ip, $cutoff);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    }

    return [
        'failures' => (int) ($row['failures'] ?? 0),
        'first_failure' => $row['first_failure'] ?? null,
    ];
}

function record_login_attempt($pdo, $conn, string $email, string $ip, bool $success): bool {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, ?)");
        return $stmt->execute([$email, $ip, $success ? 1 : 0]);
    }

    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, ?)");
    $successFlag = $success ? 1 : 0;
    $stmt->bind_param("ssi", $email, $ip, $successFlag);
    return $stmt->execute();
}

function cleanup_login_attempts($pdo, $conn, string $cutoff): void {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < ?");
        $stmt->execute([$cutoff]);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempted_at < ?");
    $stmt->bind_param("s", $cutoff);
    $stmt->execute();
}

function verify_password_and_upgrade(string $password, string $stored, callable $onUpgrade): bool {
    $info = password_get_info($stored);
    $isHash = ($info['algo'] ?? 0) !== 0;

    if ($isHash) {
        $valid = password_verify($password, $stored);
        if ($valid && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            $onUpgrade(password_hash($password, PASSWORD_DEFAULT));
        }
        return $valid;
    }

    if (hash_equals($stored, $password)) {
        $onUpgrade(password_hash($password, PASSWORD_DEFAULT));
        return true;
    }

    return false;
}

// Display global success messages (like after a password reset)
if (isset($_SESSION['global_success'])) {
    $success_message = $_SESSION['global_success'];
    unset($_SESSION['global_success']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "Security check failed. Please refresh and try again.";
    } else {
        // Handle "Resend OTP"
        if (isset($_POST['resend_otp'])) {
            $email = $_SESSION['pending_email'] ?? '';
            $context = $_SESSION['otp_context'] ?? 'registration';

            if ($email) {
                $result = $otpService->generateAndSendOtp($email, $context);
                if ($result['success']) {
                    $success_message = "A new verification code has been sent to your email.";
                    $_SESSION['last_otp_send_time'] = time(); // Reset the cooldown timer
                } else {
                    $error_message = $result['error'];
                }
            } else {
                $error_message = "Session expired. Please try again.";
                session_unset();
            }
        }

    // ==========================================
    // LOGIN: DIRECT LOGIN (EXISTING USERS)
    // ==========================================
    if (isset($_POST['login_step_1'])) {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $ip = get_client_ip();
        $windowCutoff = date('Y-m-d H:i:s', time() - LOGIN_RATE_LIMIT_WINDOW);

        if (!ensure_login_attempts_table($pdo ?? null, $conn)) {
            error_log('Failed to ensure login_attempts table for rate limiting.');
            $error_message = "Login temporarily unavailable. Please try again later.";
        } else {
            $failures = get_login_failure_stats($pdo ?? null, $conn, $email, $ip, $windowCutoff);
            if ($failures['failures'] >= LOGIN_RATE_LIMIT_MAX) {
                $firstFailure = $failures['first_failure'] ? strtotime($failures['first_failure']) : time();
                $retryIn = max(1, LOGIN_RATE_LIMIT_WINDOW - (time() - $firstFailure));
                $retryMinutes = (int) ceil($retryIn / 60);
                $error_message = "Too many login attempts. Try again in {$retryMinutes} minute(s).";
            } else {

        // Use PDO for secure prepared statements when available
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT id, role, status, password FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $validPassword = verify_password_and_upgrade($password, (string) $user['password'], function ($newHash) use ($pdo, $user) {
                    try {
                        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $upd->execute([$newHash, $user['id']]);
                    } catch (Exception $e) {
                        error_log('Password rehash failed: ' . $e->getMessage());
                    }
                });

                if (!$validPassword) {
                    $error_message = "Invalid email or password.";
                    record_login_attempt($pdo, $conn, $email, $ip, false);
                } elseif (strtolower($user['status'] ?? 'active') === 'suspended') {
                    $error_message = "Account suspended. Contact System Admin.";
                    record_login_attempt($pdo, $conn, $email, $ip, false);
                    // Log blocked login attempt
                    $log_stmt = $pdo->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
                    $log_stmt->execute([$email, 'Blocked login attempt - account suspended']);
                } else {
                    regenerate_session_on_login();
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = $user['role'];

                    record_login_attempt($pdo, $conn, $email, $ip, true);
                    cleanup_login_attempts($pdo, $conn, date('Y-m-d H:i:s', time() - LOGIN_RATE_LIMIT_CLEANUP));

                    $log_stmt = $pdo->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
                    $log_stmt->execute([$email, 'Successful login via web portal.']);

                    header("Location: dashboard.php");
                    exit();
                }
            } else {
                $error_message = "Invalid email or password.";
                record_login_attempt($pdo, $conn, $email, $ip, false);
            }
        } else {
            // Fallback to existing mysqli behavior if PDO isn't available
            $stmt = $conn->prepare("SELECT id, role, password FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $validPassword = verify_password_and_upgrade($password, (string) $row['password'], function ($newHash) use ($conn, $row) {
                    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->bind_param("si", $newHash, $row['id']);
                    if (!$upd->execute()) {
                        error_log('Password rehash failed: ' . $conn->error);
                    }
                });

                if ($validPassword) {
                    regenerate_session_on_login();
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = $row['role'];

                    record_login_attempt($pdo, $conn, $email, $ip, true);
                    cleanup_login_attempts($pdo, $conn, date('Y-m-d H:i:s', time() - LOGIN_RATE_LIMIT_CLEANUP));

                    $log_action = "Successful login via web portal.";
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
                    $log_stmt->bind_param("ss", $email, $log_action);
                    $log_stmt->execute();

                    header("Location: dashboard.php");
                    exit();
                }
            }

            $error_message = "Invalid email or password.";
            record_login_attempt($pdo, $conn, $email, $ip, false);
        }
            }
        }
    }

    // ==========================================
    // REGISTER: VALIDATE NEW ACCOUNT & SEND OTP
    // ==========================================
    elseif (isset($_POST['register_step_1'])) {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        // Hardcode the role to 'Faculty'
        $role = 'Faculty';

        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $error_message = "This email is already registered. Please sign in.";
        } else {
            // Use the new OTP Service
            $result = $otpService->generateAndSendOtp($email, 'registration');

            if ($result['success']) {
                $_SESSION['otp_context'] = 'register';
                $_SESSION['pending_email'] = $email;
                $_SESSION['pending_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $_SESSION['pending_role'] = $role;
                $_SESSION['last_otp_send_time'] = time();

                $success_message = "A verification code has been sent to your email address.";
            } else {
                $error_message = $result['error']; // Shows rate limit or Brevo API errors
            }
        }
    }

    // ==========================================
    // REGISTER: VERIFY OTP & CREATE
    // ==========================================
    elseif (isset($_POST['verify_register_otp'])) {
        $entered_otp = trim($_POST['otp_code']);
        $email = $_SESSION['pending_email'] ?? '';

        if (!$email) {
            $error_message = "Session expired. Please restart registration.";
            session_unset();
        } else {
            // Verify using the database
            $verifyResult = $otpService->verifyOtp($email, $entered_otp);

            if ($verifyResult['success']) {
                $role = $_SESSION['pending_role'];
                $passwordHash = $_SESSION['pending_password_hash'] ?? '';

                if (empty($passwordHash)) {
                    $error_message = "Session expired. Please restart registration.";
                } else {
                    $insert = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
                    $insert->bind_param("sss", $email, $passwordHash, $role);
                    $insert->execute();

                    $log_action = "New account registered and verified via email OTP.";
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
                    $log_stmt->bind_param("ss", $email, $log_action);
                    $log_stmt->execute();

                    session_unset();
                    regenerate_session_on_login();
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = $role;

                    header("Location: dashboard.php");
                    exit();
                }
            } else {
                $error_message = $verifyResult['error'];
            }
        }
    }

    // ==========================================
    // RECOVERY STEP 1: VALIDATE EMAIL & SEND OTP
    // ==========================================
    elseif (isset($_POST['forgot_step_1'])) {
        $email = trim($_POST['email']);

        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();

        // Always show the same message to prevent email enumeration
        if ($check->get_result()->num_rows > 0) {
            $result = $otpService->generateAndSendOtp($email, 'recovery');

            if ($result['success']) {
                $_SESSION['otp_context'] = 'forgot_password';
                $_SESSION['pending_email'] = $email;
                $_SESSION['last_otp_send_time'] = time();
            } else {
                // If there's an API/Rate limit error, we might optionally want to show it.
                // For strictest security, keep the generic message. We'll show the error here for better UX during development.
                $error_message = $result['error'];
            }
        }

        if (empty($error_message)) {
            $success_message = "If an account exists, a recovery code was sent to that email.";
            // Force context even if email wasn't found to prevent enumeration
            if (!isset($_SESSION['otp_context'])) {
                $_SESSION['otp_context'] = 'forgot_password';
                $_SESSION['pending_email'] = $email; // Fake pending state
            }
        }
    }

    // ==========================================
    // RECOVERY STEP 2: VERIFY OTP
    // ==========================================
    elseif (isset($_POST['verify_forgot_otp'])) {
        $entered_otp = trim($_POST['otp_code']);
        $email = $_SESSION['pending_email'] ?? '';

        if (!$email) {
            $error_message = "Session expired. Please try again.";
        } else {
            $verifyResult = $otpService->verifyOtp($email, $entered_otp);

            if ($verifyResult['success']) {
                $_SESSION['reset_password_granted'] = true;
                unset($_SESSION['otp_context']);
                $success_message = "Identity verified. You may now create a new password.";
            } else {
                // If it's a fake pending state (email didn't exist), just show invalid code
                $error_message = $verifyResult['error'] ?? "Invalid security code. Please try again.";
            }
        }
    }

    // ==========================================
    // RECOVERY STEP 3: SAVE NEW PASSWORD
    // ==========================================
    elseif (isset($_POST['reset_password'])) {
        $new_password = $_POST['new_password'];
        $email = $_SESSION['pending_email'];

        $passwordHash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $passwordHash, $email);
        $stmt->execute();

        $log_action = "Account password recovered and reset via email OTP.";
        $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
        $log_stmt->bind_param("ss", $email, $log_action);
        $log_stmt->execute();

        session_unset();
        $_SESSION['global_success'] = "Password successfully reset. You can now sign in.";
        header("Location: index.php");
        exit();
    }
    }
}

// Determine which form to show
$is_registering = isset($_GET['action']) && $_GET['action'] === 'register';
$is_forgot_pw = isset($_GET['action']) && $_GET['action'] === 'forgot_password';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal | EduPulse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/index.css">
</head>

<body>
    <div class="login-card">

        <?php if (isset($_SESSION['reset_password_granted'])): ?>
            <div class="header">
                <i class="fa-solid fa-lock"></i>
                <h2>New Password</h2>
                <p>Secure your account for <br><strong><?php echo htmlspecialchars($_SESSION['pending_email']); ?></strong></p>
            </div>

            <?php if (!empty($success_message)): ?><div class="success-box"><?php echo h($success_message); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="input-group">
                    <label>Create New Password</label>
                    <input type="password" name="new_password" placeholder=" " required minlength="6">
                </div>
                <button type="submit" name="reset_password" class="btn-submit">Save Password</button>
            </form>

        <?php elseif (isset($_SESSION['otp_context']) && ($_SESSION['otp_context'] === 'forgot_password' || $_SESSION['otp_context'] === 'register')): ?>
            <?php
            $icon = $_SESSION['otp_context'] === 'register' ? 'fa-shield-halved' : 'fa-key';
            $title = $_SESSION['otp_context'] === 'register' ? 'Email Verification' : 'Verify Identity';
            $submitName = $_SESSION['otp_context'] === 'register' ? 'verify_register_otp' : 'verify_forgot_otp';
            $submitText = $_SESSION['otp_context'] === 'register' ? 'Verify & Create Account' : 'Verify & Continue';
            $cancelUrl = "index.php?cancel_mfa=1";

            // Calculate time remaining for the 60s cooldown
            $lastSendTime = $_SESSION['last_otp_send_time'] ?? 0;
            $timeSinceSend = time() - $lastSendTime;
            $cooldownRemaining = max(0, 60 - $timeSinceSend);
            ?>
            <div class="header">
                <i class="fa-solid <?php echo h($icon); ?>"></i>
                <h2><?php echo h($title); ?></h2>
                <p>We've sent a 6-digit code to <br><strong><?php echo htmlspecialchars($_SESSION['pending_email']); ?></strong></p>
            </div>

            <?php if (!empty($success_message)): ?><div class="success-box"><?php echo h($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo h($error_message); ?></div><?php endif; ?>

            <form method="POST" id="otpForm">
                <?php echo csrf_field(); ?>
                <div class="input-group">
                    <label>Security Code</label>
                    <input type="text" name="otp_code" placeholder="123456" maxlength="6" required autocomplete="off" style="text-align: center; letter-spacing: 8px; font-size: 1.25rem; font-weight: 700;">
                </div>
                <button type="submit" name="<?php echo h($submitName); ?>" class="btn-submit"><?php echo h($submitText); ?></button>
            </form>

            <form method="POST" id="resendForm" style="margin-top: 15px;">
                <?php echo csrf_field(); ?>
                <button type="submit" name="resend_otp" id="resendBtn" class="btn-submit" style="background-color: #f1f5f9; color: var(--slate-700); font-size: 0.85rem;" <?php echo $cooldownRemaining > 0 ? 'disabled' : ''; ?>>
                    <?php echo $cooldownRemaining > 0 ? "Resend Code in {$cooldownRemaining}s" : "Resend Code"; ?>
                </button>
            </form>

            <div class="footer"><a href="<?php echo h($cancelUrl); ?>"><i class="fa-solid fa-arrow-left"></i> Cancel</a></div>

            <!-- Cooldown Timer Logic -->
            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    let cooldown = <?php echo $cooldownRemaining; ?>;
                    const resendBtn = document.getElementById('resendBtn');

                    if (cooldown > 0) {
                        const interval = setInterval(() => {
                            cooldown--;
                            if (cooldown <= 0) {
                                clearInterval(interval);
                                resendBtn.disabled = false;
                                resendBtn.textContent = "Resend Code";
                            } else {
                                resendBtn.textContent = `Resend Code in ${cooldown}s`;
                            }
                        }, 1000);
                    }
                });
            </script>

        <?php elseif ($is_forgot_pw): ?>
            <div class="header">
                <i class="fa-solid fa-unlock-keyhole"></i>
                <h2>Account Recovery</h2>
                <p>Enter your email to receive a password reset code.</p>
            </div>

            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo h($error_message); ?></div><?php endif; ?>

            <form method="POST" action="index.php?action=forgot_password">
                <?php echo csrf_field(); ?>
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="name@dlsud.edu.ph" required>
                </div>
                <button type="submit" name="forgot_step_1" class="btn-submit">Send Recovery Code</button>
            </form>
            <div class="footer">
                Remember your password? <a href="index.php">Sign In</a>
            </div>

        <?php elseif ($is_registering): ?>
            <div class="header">
                <i class="fa-solid fa-user-plus"></i>
                <h2>Create Account</h2>
                <p>Register a new system user</p>
            </div>

            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo h($error_message); ?></div><?php endif; ?>

            <form method="POST" action="index.php?action=register">
                <?php echo csrf_field(); ?>
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="name@dlsud.edu.ph" required>
                </div>
                <div class="input-group">
                    <label>Create Password</label>
                    <input type="password" name="password" placeholder=" " required minlength="6">
                </div>
                <button type="submit" name="register_step_1" class="btn-submit">Send Verification Code</button>
            </form>
            <div class="footer">
                Already have an account? <a href="index.php">Sign In</a>
            </div>

        <?php else: ?>
            <div class="header">
                <i class="fa-solid fa-graduation-cap"></i>
                <h2>EduPulse Portal</h2>
            </div>

            <?php if (!empty($success_message)): ?><div class="success-box"><?php echo h($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo h($error_message); ?></div><?php endif; ?>

            <form method="POST" action="index.php">
                <?php echo csrf_field(); ?>
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="name@dlsud.edu.ph" required>
                </div>
                <div class="input-group">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <label style="margin-bottom: 0;">Password</label>
                        <a href="index.php?action=forgot_password" style="font-size: 0.75rem; text-decoration: none; color: var(--primary); font-weight: 600;">Forgot password?</a>
                    </div>
                    <input type="password" name="password" placeholder=" " required>
                </div>
                <button type="submit" name="login_step_1" class="btn-submit">Sign In</button>
            </form>

            <!-- SSO Buttons Added Here -->
            <div style="display: flex; gap: 12px; margin-bottom: 20px; margin-top: 10px;">
                <!-- Google Button -->
                <a href="oauth.php?provider=google" class="btn-submit" style="background: white; color: var(--slate-700); border: 1px solid var(--border-color); text-align: center; text-decoration: none; display: flex; justify-content: center; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="18px" height="18px">
                        <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z" />
                        <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z" />
                        <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z" />
                        <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z" />
                    </svg> Google
                </a>

                <!-- Microsoft Button -->
                <a href="oauth.php?provider=microsoft" class="btn-submit" style="background: white; color: var(--slate-700); border: 1px solid var(--border-color); text-align: center; text-decoration: none; display: flex; justify-content: center; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 21 21" width="18px" height="18px">
                        <path fill="#f25022" d="M10 10H0V0h10v10z" />
                        <path fill="#7fba00" d="M21 10H11V0h10v10z" />
                        <path fill="#00a4ef" d="M10 21H0V11h10v10z" />
                        <path fill="#ffb900" d="M21 21H11V11h10v10z" />
                    </svg> Microsoft
                </a>
            </div>
            <!-- End SSO Buttons -->

            <div class="footer">
                Don't have an account? <a href="index.php?action=register">Register here</a>
                <div class="policy-text">Protected by Institutional IT Policy</div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
