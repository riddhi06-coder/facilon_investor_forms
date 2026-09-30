<?php
/**
 * Contact / enquiry submission endpoint.
 * Validates, stores the enquiry, then emails the client and the admin.
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

$name         = field($in, 'name');
$email        = field($in, 'email');
$phone        = field($in, 'phone');
$organisation = field($in, 'organisation');
$enquiryType  = field($in, 'enquiry_type');
$subject      = field($in, 'subject');
$message      = field($in, 'message');
$consent      = truthy($in['consent'] ?? false);

// ---- Server-side validation (mirrors the client) -------------------------
$errors = [];
if ($name === '')                       { $errors['name'] = 'Please enter your name.'; }
elseif (!valid_name($name))             { $errors['name'] = 'Name cannot contain numbers or special characters.'; }
if ($email === '' || !is_email($email)) { $errors['email'] = 'Please enter a valid email address.'; }
if ($phone === '')                      { $errors['phone'] = 'Please enter your phone / mobile number.'; }
elseif (!valid_mobile($phone))          { $errors['phone'] = 'Please enter a valid mobile number (10 to 15 digits, no letters).'; }
if ($enquiryType === '')                { $errors['enquiry_type'] = 'Please select an enquiry type.'; }
if ($subject === '')                    { $errors['subject'] = 'Please enter a subject.'; }
if ($message === '')                    { $errors['message'] = 'Please tell us how we can help.'; }
if (!$consent)                          { $errors['consent'] = 'Please accept the privacy confirmation to continue.'; }

if ($errors) {
    respond(['success' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'], 422);
}

// ---- Persist -------------------------------------------------------------
try {
    $pdo    = facilon_db($cfg);
    $userId = facilon_upsert_user($pdo, $email, $name);

    $stmt = $pdo->prepare(
        "INSERT INTO contact_submissions
        (user_id, name, email, phone, organisation, enquiry_type, subject, message, consent, ip, user_agent, created_at)
        VALUES
        (:user_id, :name, :email, :phone, :organisation, :enquiry_type, :subject, :message, :consent, :ip, :user_agent, NOW())"
    );
    $stmt->execute([
        ':user_id'      => $userId,
        ':name'         => $name,
        ':email'        => $email,
        ':phone'        => $phone !== '' ? $phone : null,
        ':organisation' => $organisation !== '' ? $organisation : null,
        ':enquiry_type' => $enquiryType,
        ':subject'      => $subject,
        ':message'      => $message,
        ':consent'      => $consent ? 1 : 0,
        ':ip'           => client_ip(),
        ':user_agent'   => user_agent(),
    ]);

    $id        = (int) $pdo->lastInsertId();
    $reference = 'FIC-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE contact_submissions SET reference = ? WHERE id = ?")->execute([$reference, $id]);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'We could not submit your enquiry. Please try again shortly.'], 500);
}

// ---- Email (best-effort) -------------------------------------------------
$emailData = [
    'reference'    => $reference,
    'name'         => $name,
    'email'        => $email,
    'phone'        => $phone,
    'organisation' => $organisation,
    'enquiry_type' => $enquiryType,
    'subject'      => $subject,
    'message'      => $message,
    'created_at'   => date('Y-m-d H:i'),
    'ip'           => client_ip(),
];

$logo = facilon_logo_attachment();
$logoAtt = $logo ? [$logo] : [];

$emailStatus = send_notifications($cfg, $pdo, 'contact', $id, [
    ['to' => $email,              'role' => 'client', 'subject' => 'We have received your enquiry — ' . $reference, 'html' => contact_client_email($cfg['brand'], $emailData), 'attachments' => $logoAtt],
    ['to' => $cfg['admin_email'], 'role' => 'admin',  'subject' => '[' . $enquiryType . '] New enquiry — ' . $reference, 'html' => contact_admin_email($cfg['brand'], $emailData), 'attachments' => $logoAtt],
]);

respond([
    'success'   => true,
    'reference' => $reference,
    'email'     => $emailStatus,
    'message'   => 'Thank you — your enquiry has been submitted.',
]);
