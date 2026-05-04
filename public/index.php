<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$error_message = "";
$success_message = "";

if (isset($_GET['cancel_mfa'])) {
    session_unset();
    header("Location: index.php");
    exit();
}

if (isset($_SESSION['global_success'])) {
    $success_message = $_SESSION['global_success'];
    unset($_SESSION['global_success']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
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
    } elseif (isset($_POST['register_step_1'])) {
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
    } elseif (isset($_POST['verify_register_otp'])) {
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

            session_unset();
            $_SESSION['admin_logged_in'] = true;
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
    } elseif (isset($_POST['forgot_step_1'])) {
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
            $error_message = "If an account exists, a recovery code was sent.";
        }
    } elseif (isset($_POST['verify_forgot_otp'])) {
        $entered_otp = trim($_POST['otp_code']);

        if ($entered_otp == $_SESSION['otp']) {
            $_SESSION['reset_password_granted'] = true;
            unset($_SESSION['otp_context']);
            unset($_SESSION['otp']);
            $success_message = "Identity verified. You may now create a new password.";
        } else {
            $error_message = "Invalid security code. Please try again.";
        }
    } elseif (isset($_POST['reset_password'])) {
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

$is_registering = isset($_GET['action']) && $_GET['action'] === 'register';
$is_forgot_pw = isset($_GET['action']) && $_GET['action'] === 'forgot_password';

require __DIR__ . '/../app/views/index.view.php';
