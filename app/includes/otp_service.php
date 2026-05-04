<?php
// app/includes/otp_service.php

class OtpService {
    private $conn;
    private $apiKey;
    private $senderEmail;
    private $senderName;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->apiKey = $_ENV['BREVO_API_KEY'] ?? '';
        $this->senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? 'kentlouiseancheta7@gmail.com';
        $this->senderName = $_ENV['BREVO_SENDER_NAME'] ?? 'EduPulse System';
    }

    // 1. Check Rate Limits (Max 5 requests per hour)
    public function canRequestOtp($email) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM email_otp_requests WHERE email = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['count'] < 5;
    }

    // 2. Generate and Send OTP
    public function generateAndSendOtp($email, $context = 'registration') {
        if (!$this->canRequestOtp($email)) {
            return ['success' => false, 'error' => 'Too many requests. Please try again in an hour.'];
        }

        // Generate 6-digit code
        $otpCode = (string) rand(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Invalidate old pending OTPs for this email
        $this->conn->query("DELETE FROM email_otp_requests WHERE email = '$email' AND verified = 0");

        // Save to Database
        $stmt = $this->conn->prepare("INSERT INTO email_otp_requests (email, otp_code, expires_at) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $email, $otpCode, $expiresAt);
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Database error generating OTP.'];
        }

        // Send via Brevo API
        $subject = "Your EduPulse Security Code";
        $htmlContent = "
            <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;'>
                <h2 style='color: #10b981; text-align: center;'>EduPulse Security</h2>
                <p>Hello,</p>
                <p>Use the following 6-digit code to verify your identity. This code is valid for 10 minutes.</p>
                <div style='background-color: #f8fafc; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; border-radius: 5px; margin: 20px 0;'>
                    {$otpCode}
                </div>
                <p style='font-size: 12px; color: #64748b; text-align: center;'>If you did not request this, please ignore this email.</p>
            </div>
        ";

        return $this->sendBrevoEmail($email, $subject, $htmlContent);
    }

    // 3. Verify OTP
    public function verifyOtp($email, $enteredCode) {
        $stmt = $this->conn->prepare("SELECT id, otp_code, expires_at, attempts FROM email_otp_requests WHERE email = ? AND verified = 0 ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return ['success' => false, 'error' => 'No pending OTP found for this email.'];
        }

        $row = $result->fetch_assoc();

        if (strtotime($row['expires_at']) < time()) {
            return ['success' => false, 'error' => 'This code has expired. Please request a new one.'];
        }

        if ($row['attempts'] >= 5) {
            return ['success' => false, 'error' => 'Maximum attempts reached. Please request a new code.'];
        }

        if ($row['otp_code'] !== $enteredCode) {
            // Increment attempt counter
            $newAttempts = $row['attempts'] + 1;
            $this->conn->query("UPDATE email_otp_requests SET attempts = $newAttempts WHERE id = " . $row['id']);
            return ['success' => false, 'error' => 'Invalid code. ' . (5 - $newAttempts) . ' attempts remaining.'];
        }

        // Mark as verified
        $this->conn->query("UPDATE email_otp_requests SET verified = 1 WHERE id = " . $row['id']);
        return ['success' => true];
    }

    // Native cURL call to Brevo API
    private function sendBrevoEmail($toEmail, $subject, $htmlContent) {
        $data = [
            'sender' => ['name' => $this->senderName, 'email' => $this->senderEmail],
            'to' => [['email' => $toEmail]],
            'subject' => $subject,
            'htmlContent' => $htmlContent
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $this->apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true];
        } else {
            // Log this error in production
            return ['success' => false, 'error' => 'Failed to deliver email. Please try again.'];
        }
    }
}
?>