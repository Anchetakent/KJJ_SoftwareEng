<?php
session_start();
require_once 'config/db.php'; 

$error_message = "";
$success_message = "";

// Handle user clicking "Cancel" during any OTP or Reset phase
if (isset($_GET['cancel_mfa'])) {
    session_unset();
    header("Location: index.php");
    exit();
}

// Display global success messages (like after a password reset)
if (isset($_SESSION['global_success'])) {
    $success_message = $_SESSION['global_success'];
    unset($_SESSION['global_success']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // ==========================================
    // LOGIN: DIRECT LOGIN (EXISTING USERS)
    // ==========================================
    if (isset($_POST['login_step_1'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, role FROM users WHERE email = ? AND password = ?");
        $stmt->bind_param("ss", $email, $password);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($id, $role);
            $stmt->fetch();

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['email'] = $email;
            $_SESSION['role'] = $role;
            
            $log_action = "Successful login via web portal.";
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
            $log_stmt->bind_param("ss", $email, $log_action);
            $log_stmt->execute();
            
            header("Location: dashboard.php");
            exit();
        } else {
            $error_message = "Invalid email or password.";
        }
    }
    
    // ==========================================
    // REGISTER: VALIDATE NEW ACCOUNT & SEND OTP
    // ==========================================
    elseif (isset($_POST['register_step_1'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];
        $role = $_POST['role'];

        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $error_message = "This email is already registered. Please sign in.";
        } else {
            $otp = rand(100000, 999999);
            
            $_SESSION['otp_context'] = 'register';
            $_SESSION['pending_email'] = $email;
            $_SESSION['pending_password'] = $password;
            $_SESSION['pending_role'] = $role;
            $_SESSION['otp'] = $otp;
            
            $success_message = "Verification code sent! (Local Test - Your code is: <strong>$otp</strong>)";
        }
    }

    // ==========================================
    // REGISTER: VERIFY OTP & CREATE
    // ==========================================
    elseif (isset($_POST['verify_register_otp'])) {
        $entered_otp = trim($_POST['otp_code']);
        
        if ($entered_otp == $_SESSION['otp']) {
            $email = $_SESSION['pending_email'];
            $role = $_SESSION['pending_role'];
            $password = $_SESSION['pending_password'];

            $insert = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
            $insert->bind_param("sss", $email, $password, $role);
            $insert->execute();

            $log_action = "New account registered and verified via OTP.";
            
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['email'] = $email;
            $_SESSION['role'] = $role;
            
            session_unset(); // Cleanup temps
            $_SESSION['admin_logged_in'] = true; // Restore main session variables
            $_SESSION['email'] = $email;
            $_SESSION['role'] = $role;
            
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
            $log_stmt->bind_param("ss", $email, $log_action);
            $log_stmt->execute();
            
            header("Location: dashboard.php");
            exit();
        } else {
            $error_message = "Invalid security code. Please try again.";
        }
    }

    // ==========================================
    // RECOVERY STEP 1: VALIDATE EMAIL & SEND OTP
    // ==========================================
    elseif (isset($_POST['forgot_step_1'])) {
        $email = $_POST['email'];
        
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $otp = rand(100000, 999999);
            
            $_SESSION['otp_context'] = 'forgot_password';
            $_SESSION['pending_email'] = $email;
            $_SESSION['otp'] = $otp;

            $success_message = "Recovery code sent! (Local Test - Your code is: <strong>$otp</strong>)";
        } else {
            // Give a generic error to prevent email enumeration (Security best practice)
            $error_message = "If an account exists, a recovery code was sent."; 
        }
    }

    // ==========================================
    // RECOVERY STEP 2: VERIFY OTP
    // ==========================================
    elseif (isset($_POST['verify_forgot_otp'])) {
        $entered_otp = trim($_POST['otp_code']);
        
        if ($entered_otp == $_SESSION['otp']) {
            $_SESSION['reset_password_granted'] = true;
            unset($_SESSION['otp_context']);
            unset($_SESSION['otp']);
            $success_message = "Identity verified. You may now create a new password.";
        } else {
            $error_message = "Invalid security code. Please try again.";
        }
    }

    // ==========================================
    // RECOVERY STEP 3: SAVE NEW PASSWORD
    // ==========================================
    elseif (isset($_POST['reset_password'])) {
        $new_password = $_POST['new_password'];
        $email = $_SESSION['pending_email'];
        
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $new_password, $email);
        $stmt->execute();
        
        $log_action = "Account password recovered and reset via OTP.";
        $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
        $log_stmt->bind_param("ss", $email, $log_action);
        $log_stmt->execute();

        session_unset();
        $_SESSION['global_success'] = "Password successfully reset. You can now sign in.";
        header("Location: index.php");
        exit();
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
    <style>
        :root {
            --primary: #10b981;
            --primary-hover: #059669;
            --slate-900: #0f172a;
            --slate-700: #334155;
            --bg-soft: #f8fafc;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { 
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            letter-spacing: -0.01em;
            margin: 0;
            position: relative;
            overflow: hidden;
            background-color: var(--slate-900);
        }
        
        body::before {
            content: "";
            position: absolute;
            top: -20px;
            left: -20px;
            right: -20px;
            bottom: -20px;
            background-image: url('images/dlsud.png');
            background-size: cover;
            background-position: center;
            filter: blur(8px) brightness(0.6);
            z-index: -1;
        }

        .login-card {
            background: white;
            width: 400px;
            padding: 48px;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.4s ease-out;
            position: relative;
            z-index: 1;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .header { text-align: center; margin-bottom: 32px; }
        .header i { font-size: 2.5rem; color: var(--primary); margin-bottom: 12px; }
        .header h2 { color: var(--slate-900); font-weight: 700; font-size: 1.5rem; }
        .header p { color: #64748b; font-size: 0.85rem; margin-top: 8px; line-height: 1.4; }
        
        .error-box { background: #fef2f2; color: #b91c1c; padding: 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 500; margin-bottom: 20px; }
        .success-box { background: #ecfdf5; color: #047857; padding: 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 500; margin-bottom: 20px; border: 1px solid #a7f3d0; }

        .input-group { margin-bottom: 20px; }
        .input-group label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--slate-700); margin-bottom: 6px; text-transform: uppercase; }
        .input-group input, .input-group select { 
            width: 100%; 
            padding: 12px 16px; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            font-size: 0.95rem;
            transition: 0.2s;
            background: white;
        }
        .input-group input:focus, .input-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); }

        .btn-submit { 
            width: 100%; background: var(--primary); color: white; border: none; 
            padding: 14px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; 
        }
        .btn-submit:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .footer { margin-top: 24px; text-align: center; font-size: 0.85rem; color: #94a3b8; }
        .footer a { color: var(--primary); text-decoration: none; font-weight: 600; transition: 0.2s; }
        .footer a:hover { color: var(--primary-hover); }
        .policy-text { font-size: 0.75rem; margin-top: 15px; color: #cbd5e1; }
    </style>
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
                        <option value="Teacher">Faculty / Teacher</option>
                        <option value="adminoffice">Administrative Office</option>
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
</body>
</html>