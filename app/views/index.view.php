<?php
$error_message = $error_message ?? '';
$success_message = $success_message ?? '';
$is_registering = $is_registering ?? false;
$is_forgot_pw = $is_forgot_pw ?? false;
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
            
            <?php if (!empty($success_message)): ?><div class="success-box"><?php echo $success_message; ?></div><?php endif; ?>
            
            <form method="POST">
                <div class="input-group">
                    <label>Create New Password</label>
                    <input type="password" name="new_password" placeholder="••••••••" required minlength="6">
                </div>
                <button type="submit" name="reset_password" class="btn-submit">Save Password</button>
            </form>

        <?php elseif (isset($_SESSION['otp_context']) && $_SESSION['otp_context'] === 'forgot_password'): ?>
            <div class="header">
                <i class="fa-solid fa-key"></i>
                <h2>Verify Identity</h2>
                <p>We've sent a recovery code to <br><strong><?php echo htmlspecialchars($_SESSION['pending_email']); ?></strong></p>
            </div>
            
            <?php if (!empty($success_message)): ?><div class="success-box"><?php echo $success_message; ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo $error_message; ?></div><?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>Security Code</label>
                    <input type="text" name="otp_code" placeholder="123456" maxlength="6" required autocomplete="off" style="text-align: center; letter-spacing: 8px; font-size: 1.25rem; font-weight: 700;">
                </div>
                <button type="submit" name="verify_forgot_otp" class="btn-submit">Verify & Continue</button>
            </form>
            <form method="POST" class="resend-form" data-resend-form>
                <input type="hidden" name="resend_otp" value="1">
                <button type="submit" class="resend-btn" data-resend-button data-cooldown-seconds="60">Resend Code</button>
            </form>
            <div class="footer"><a href="index.php?cancel_mfa=1"><i class="fa-solid fa-arrow-left"></i> Cancel Recovery</a></div>

        <?php elseif ($is_forgot_pw): ?>
            <div class="header">
                <i class="fa-solid fa-unlock-keyhole"></i>
                <h2>Account Recovery</h2>
                <p>Enter your email to receive a password reset code.</p>
            </div>
            
            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo $error_message; ?></div><?php endif; ?>
            
            <form method="POST" action="index.php?action=forgot_password">
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="name@dlsud.edu.ph" required>
                </div>
                <button type="submit" name="forgot_step_1" class="btn-submit">Send Recovery Code</button>
            </form>
            <div class="footer">
                Remember your password? <a href="index.php">Sign In</a>
            </div>

        <?php elseif (isset($_SESSION['otp_context']) && $_SESSION['otp_context'] === 'register'): ?>
            <div class="header">
                <i class="fa-solid fa-shield-halved"></i>
                <h2>Email Verification</h2>
                <p>We've sent a 6-digit creation code to <br><strong><?php echo htmlspecialchars($_SESSION['pending_email']); ?></strong></p>
            </div>
            
            <?php if (!empty($success_message)): ?><div class="success-box"><?php echo $success_message; ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo $error_message; ?></div><?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>Authentication Code</label>
                    <input type="text" name="otp_code" placeholder="123456" maxlength="6" required autocomplete="off" style="text-align: center; letter-spacing: 8px; font-size: 1.25rem; font-weight: 700;">
                </div>
                <button type="submit" name="verify_register_otp" class="btn-submit">Verify & Create Account</button>
            </form>
            <form method="POST" class="resend-form" data-resend-form>
                <input type="hidden" name="resend_otp" value="1">
                <button type="submit" class="resend-btn" data-resend-button data-cooldown-seconds="60">Resend Code</button>
            </form>
            <div class="footer"><a href="index.php?cancel_mfa=1"><i class="fa-solid fa-arrow-left"></i> Cancel Registration</a></div>

        <?php elseif ($is_registering): ?>
            <div class="header">
                <i class="fa-solid fa-user-plus"></i>
                <h2>Create Account</h2>
                <p>Register a new system user</p>
            </div>
            
            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo $error_message; ?></div><?php endif; ?>
            
            <form method="POST" action="index.php?action=register">
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="name@dlsud.edu.ph" required>
                </div>
                <div class="input-group">
                    <label>System Role</label>
                    <select name="role" required>
                        <option value="Faculty">Faculty</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Create Password</label>
                    <input type="password" name="password" placeholder="••••••••" required minlength="6">
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
            
            <?php if (!empty($success_message)): ?><div class="success-box"><?php echo $success_message; ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="error-box"><?php echo $error_message; ?></div><?php endif; ?>
            
            <form method="POST" action="index.php">
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="name@dlsud.edu.ph" required>
                </div>
                <div class="input-group">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <label style="margin-bottom: 0;">Password</label>
                        <a href="index.php?action=forgot_password" style="font-size: 0.75rem; text-decoration: none; color: var(--primary); font-weight: 600;">Forgot password?</a>
                    </div>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" name="login_step_1" class="btn-submit">Sign In</button>
            </form>
            <div class="footer">
                Don't have an account? <a href="index.php?action=register">Register here</a>
                <div class="policy-text">Protected by Institutional IT Policy</div>
            </div>
        <?php endif; ?>

    </div>
    <script src="assets/js/index.js"></script>
</body>
</html>
