<?php
// public/oauth.php
session_start();

// Load Composer dependencies and Database
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/db.php';

// 1. Determine Provider (Store in session so we remember it during the callback)
if (isset($_GET['provider'])) {
  $providerName = $_GET['provider'];
  $_SESSION['oauth_provider'] = $providerName;
} else {
  // During the callback from Google/Microsoft, ?provider is gone, so we fetch it from the session
  $providerName = $_SESSION['oauth_provider'] ?? '';
}

if (!in_array($providerName, ['google', 'microsoft'])) {
  die('Invalid provider.');
}

// 2. Initialize the correct OAuth Provider
if ($providerName === 'google') {
  $oauthProvider = new \League\OAuth2\Client\Provider\Google([
    'clientId'     => $_ENV['GOOGLE_CLIENT_ID'],
    'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'],
    'redirectUri'  => $_ENV['GOOGLE_REDIRECT_URI'],
  ]);
} else {
  $oauthProvider = new \TheNetworg\OAuth2\Client\Provider\Azure([
    'clientId'          => $_ENV['MICROSOFT_CLIENT_ID'],
    'clientSecret'      => $_ENV['MICROSOFT_CLIENT_SECRET'],
    'redirectUri'       => $_ENV['MICROSOFT_REDIRECT_URI'],
    // Standard endpoint for personal Microsoft accounts and work/school accounts
    'defaultEndPointVersion' => '2.0'
  ]);
}

// 3. Step One: Redirect to Provider's Login Screen
if (!isset($_GET['code'])) {
  $options = [];
  // Microsoft requires explicit scopes to be requested
  if ($providerName === 'microsoft') {
    $options['scope'] = ['openid', 'email', 'profile', 'User.Read'];
  }

  $authUrl = $oauthProvider->getAuthorizationUrl($options);
  $_SESSION['oauth2state'] = $oauthProvider->getState();
  header('Location: ' . $authUrl);
  exit;
}

// 4. Step Two: Security Check (Prevent CSRF Attacks)
if (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
  unset($_SESSION['oauth2state']);
  die('Invalid State. Potential security breach.');
}

// 5. Step Three: Exchange Code for Access Token and User Details
try {
  $token = $oauthProvider->getAccessToken('authorization_code', [
    'code' => $_GET['code']
  ]);

  $user = $oauthProvider->getResourceOwner($token);

  // Normalize data based on provider
  // Normalize data based on provider
  if ($providerName === 'google') {
    /** @var \League\OAuth2\Client\Provider\GoogleUser $user */
    $email = $user->getEmail();
  } else {
    /** @var \TheNetworg\OAuth2\Client\Provider\AzureResourceOwner $user */
    $email = $user->claim('email') ?? $user->claim('userPrincipalName');
  }

  $providerId = $user->getId();
  $role = 'Faculty'; // Hardcoded requirement

  if (empty($email)) {
    die('Could not retrieve email address from provider.');
  }

  // 6. Database Logic: Login or Register
  $stmt = $conn->prepare("SELECT id, role, auth_provider, provider_id FROM users WHERE email = ?");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows > 0) {
    // User exists. Auto-link account if they were a local user, then log them in.
    $existingUser = $result->fetch_assoc();

    if (empty($existingUser['provider_id'])) {
      $update = $conn->prepare("UPDATE users SET auth_provider = ?, provider_id = ? WHERE id = ?");
      $update->bind_param("ssi", $providerName, $providerId, $existingUser['id']);
      $update->execute();
    }

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $existingUser['role'];
  } else {
    // User does not exist. Register them instantly as Faculty.
    $insert = $conn->prepare("INSERT INTO users (email, role, auth_provider, provider_id) VALUES (?, ?, ?, ?)");
    $insert->bind_param("ssss", $email, $role, $providerName, $providerId);
    $insert->execute();

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
  }

  // Log the action
  $log_action = "Successful SSO login via " . ucfirst($providerName);
  $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_email, action) VALUES (?, ?)");
  $log_stmt->bind_param("ss", $email, $log_action);
  $log_stmt->execute();

  // Redirect to Dashboard
  header("Location: dashboard.php");
  exit();
} catch (\Exception $e) {
  die('OAuth Error: ' . $e->getMessage());
}
