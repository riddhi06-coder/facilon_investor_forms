<?php
/**
 * Step 1 of the navigator save: validate the register fields, generate a
 * 6-digit email verification code, store it (hashed) and email it.
 * NOTHING is saved to the submissions table and no confirmation/admin mail
 * is sent here — that happens only after the code is verified.
 */
define('FACILON_APP', true);

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/graph.php';
require __DIR__ . '/mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

$in = read_input();

$email       = field($in, 'email');
$name        = field($in, 'name');
$consentSave = truthy($in['consentSave'] ?? false);
$consentAck  = truthy($in['consentAck'] ?? false);

// Validate the register fields (mirrors the client).
$errors = [];
if ($email === '' || !is_email($email)) {
    $errors['email'] = 'Please enter a valid email address.';
}
if ($name === '') {
    $errors['name'] = 'Please enter your name.';
} elseif (!valid_name($name)) {
    $errors['name'] = 'Name cannot contain numbers or special characters.';
}
if (!$consentSave || !$consentAck) {
    $errors['consent'] = 'Please accept the required confirmations (marked *) to continue.';
}
if ($errors) {
    respond(['success' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'], 422);
}

try {
    $pdo = facilon_db($cfg);

    // Generate a fresh code + opaque token.
    $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare(
        "INSERT INTO navigator_otps (token, email, code_hash, expires_at, created_at)
         VALUES (:token, :email, :hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())"
    );
    $stmt->execute([
        ':token' => $token,
        ':email' => $email,
        ':hash'  => password_hash($code, PASSWORD_DEFAULT),
    ]);
    $otpId = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'We could not start verification. Please try again shortly.'], 500);
}

// Send the code (best-effort logging, but this send must succeed to proceed).
$logo    = facilon_logo_attachment();
$status  = send_notifications($cfg, $pdo, 'otp', $otpId, [
    ['to' => $email, 'role' => 'client', 'subject' => 'Your Facilon verification code', 'html' => otp_email($cfg['brand'], $code), 'attachments' => $logo ? [$logo] : []],
]);

if (empty($status['client'])) {
    respond(['success' => false, 'message' => 'We could not send the verification code. Please check the email address and try again.'], 502);
}

respond([
    'success' => true,
    'token'   => $token,
    'message' => 'A 6-digit verification code has been sent to ' . $email . '.',
]);
