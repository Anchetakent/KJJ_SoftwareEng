CREATE TABLE IF NOT EXISTS email_otp_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    context VARCHAR(50) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 5,
    status ENUM('active', 'verified', 'expired', 'blocked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_created (email, created_at),
    INDEX idx_email_context_status (email, context, status),
    INDEX idx_expires_at (expires_at)
);

