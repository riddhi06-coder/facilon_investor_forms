<?php
/**
 * Investor Access Navigator submission endpoint.
 * Validates, stores the profile, then emails the client and the admin.
 */
define('FACILON_APP', true);

header('Content-Type: application/json; charset=utf-8');

$cfg     = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/graph.php';
require __DIR__ . '/mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

$in = read_input();

// ---- Server-side validation (mirrors the client) -------------------------
$errors = [];

$email        = field($in, 'email');
$name         = field($in, 'name');
$personType   = field($in, 'personType');
$investorType = field($in, 'investorType');
$currency     = field($in, 'currency');
$intention    = field($in, 'intention');

$consentSave  = truthy($in['consentSave'] ?? false);
$consentEdu   = truthy($in['consentEducational'] ?? false);
$consentAck   = truthy($in['consentAck'] ?? false);

if ($email === '' || !is_email($email)) {
    $errors['email'] = 'Please enter a valid email address.';
}
if ($name === '') {
    $errors['name'] = 'Please enter your name.';
} elseif (!valid_name($name)) {
    $errors['name'] = 'Name cannot contain numbers or special characters.';
}
if ($personType === '') {
    $errors['personType'] = 'Please select who is investing.';
}
if ($investorType === '' || stripos($investorType, 'requiring clarification') !== false) {
    $errors['investorClass'] = 'Please select your investor type.';
}
if ($currency === '') {
    $errors['currency'] = 'Please select an investment currency.';
}
if ($intention === '') {
    $errors['intents'] = 'Please select at least one objective.';
}
if (!$consentSave) {
    $errors['consent'] = 'Please accept the save and alert consent to continue.';
}
if (!$consentAck) {
    $errors['consent'] = 'Please accept the required confirmations to continue.';
}

if ($errors) {
    respond(['success' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'], 422);
}

// ---- Verify the emailed OTP before saving anything -----------------------
$token = field($in, 'token');
$code  = field($in, 'code');
if ($token === '' || $code === '') {
    respond(['success' => false, 'errors' => ['otp' => 'Please enter the 6-digit verification code.'], 'message' => 'Verification required.'], 422);
}

try {
    $pdo = facilon_db($cfg);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'We could not complete verification. Please try again shortly.'], 500);
}

$otp = $pdo->prepare("SELECT * FROM navigator_otps WHERE token = ? AND email = ? LIMIT 1");
$otp->execute([$token, $email]);
$row = $otp->fetch();

if (!$row || (int) $row['consumed'] === 1) {
    respond(['success' => false, 'errors' => ['otp' => 'This code is no longer valid. Please request a new one.'], 'message' => 'Invalid verification code.'], 422);
}
if (strtotime($row['expires_at']) < time()) {
    respond(['success' => false, 'errors' => ['otp' => 'Your code has expired. Please request a new one.'], 'message' => 'Code expired.'], 422);
}
if ((int) $row['attempts'] >= 5) {
    respond(['success' => false, 'errors' => ['otp' => 'Too many incorrect attempts. Please request a new code.'], 'message' => 'Too many attempts.'], 429);
}
if (!password_verify($code, $row['code_hash'])) {
    $pdo->prepare("UPDATE navigator_otps SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
    respond(['success' => false, 'errors' => ['otp' => 'Incorrect code. Please check and try again.'], 'message' => 'Incorrect code.'], 422);
}
// Correct code — consume it so it cannot be reused.
$pdo->prepare("UPDATE navigator_otps SET consumed = 1 WHERE id = ?")->execute([$row['id']]);

// ---- Normalise arrays for storage + email --------------------------------
$arr = static function ($v): array {
    return is_array($v) ? $v : [];
};
$servicesProducts   = $arr($in['servicesProducts'] ?? []);
$providersRequired  = $arr($in['providersRequired'] ?? []);
$providersInPlace   = $arr($in['providersInPlace'] ?? []);
$providersRemaining = $arr($in['providersRemaining'] ?? []);
$coreKyc            = $arr($in['coreKycDocuments'] ?? []);
$providerDocs       = $arr($in['providerSpecificDocuments'] ?? []);
$nriBanking         = field($in, 'nriOciBankingGuidance');
$helpRequested      = field($in, 'helpRequested');
$fullProfile        = is_array($in['fullProfile'] ?? null) ? $in['fullProfile'] : $in;

$providerLabel = static fn($p) => trim(($p['market'] ?? '') . ': ' . ($p['name'] ?? ''), ': ');
$spLabel       = static fn($x) => trim(($x['market'] ?? '') . ': ' . ($x['service'] ?? '') . ' — ' . ($x['product'] ?? ''), ': —');

// ---- Persist -------------------------------------------------------------
try {
    $userId = facilon_upsert_user($pdo, $email, $name);

    $stmt = $pdo->prepare(
        "INSERT INTO navigator_submissions
        (user_id, email, name, person_type, investor_type, currency, market, intention,
         services_products, providers_required, providers_in_place, providers_remaining,
         help_requested, core_kyc, provider_documents, nri_banking,
         consent_save, consent_educational, consent_ack, full_profile, ip, user_agent, created_at)
        VALUES
        (:user_id, :email, :name, :person_type, :investor_type, :currency, :market, :intention,
         :services_products, :providers_required, :providers_in_place, :providers_remaining,
         :help_requested, :core_kyc, :provider_documents, :nri_banking,
         :consent_save, :consent_educational, :consent_ack, :full_profile, :ip, :user_agent, NOW())"
    );
    $stmt->execute([
        ':user_id'             => $userId,
        ':email'               => $email,
        ':name'                => $name !== '' ? $name : null,
        ':person_type'         => $personType,
        ':investor_type'       => $investorType,
        ':currency'            => $currency,
        ':market'              => field($in, 'market'),
        ':intention'           => $intention,
        ':services_products'   => json_encode($servicesProducts, JSON_UNESCAPED_UNICODE),
        ':providers_required'  => json_encode($providersRequired, JSON_UNESCAPED_UNICODE),
        ':providers_in_place'  => json_encode($providersInPlace, JSON_UNESCAPED_UNICODE),
        ':providers_remaining' => json_encode($providersRemaining, JSON_UNESCAPED_UNICODE),
        ':help_requested'      => $helpRequested,
        ':core_kyc'            => json_encode($coreKyc, JSON_UNESCAPED_UNICODE),
        ':provider_documents'  => json_encode($providerDocs, JSON_UNESCAPED_UNICODE),
        ':nri_banking'         => $nriBanking !== '' ? $nriBanking : null,
        ':consent_save'        => $consentSave ? 1 : 0,
        ':consent_educational' => $consentEdu ? 1 : 0,
        ':consent_ack'         => $consentAck ? 1 : 0,
        ':full_profile'        => json_encode($fullProfile, JSON_UNESCAPED_UNICODE),
        ':ip'                  => client_ip(),
        ':user_agent'          => user_agent(),
    ]);

    $id        = (int) $pdo->lastInsertId();
    $reference = 'FIP-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE navigator_submissions SET reference = ? WHERE id = ?")->execute([$reference, $id]);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'We could not save your details. Please try again shortly.'], 500);
}

// ---- Email (best-effort: never fails the save) ---------------------------
$emailData = [
    'reference'                  => $reference,
    'email'                      => $email,
    'name'                       => $name,
    'person_type'                => $personType,
    'investor_type'              => $investorType,
    'currency'                   => $currency,
    'market'                     => field($in, 'market'),
    'intention'                  => $intention,
    'help_requested'             => $helpRequested,
    'consent_educational'        => $consentEdu,
    'created_at'                 => date('Y-m-d H:i'),
    'ip'                         => client_ip(),
    'services_products_labels'   => array_map($spLabel, $servicesProducts),
    'providers_required_labels'  => array_map($providerLabel, $providersRequired),
    'providers_in_place_labels'  => array_map($providerLabel, $providersInPlace),
    'providers_remaining_labels' => array_map($providerLabel, $providersRemaining),
];

$logo = facilon_logo_attachment();
$logoAtt = $logo ? [$logo] : [];

$emailStatus = send_notifications($cfg, $pdo, 'navigator', $id, [
    ['to' => $email,               'role' => 'client', 'subject' => 'Your Facilon Access Navigator has been saved', 'html' => navigator_client_email($cfg['brand'], $emailData), 'attachments' => $logoAtt],
    ['to' => $cfg['admin_email'],  'role' => 'admin',  'subject' => 'New Access Navigator submission — ' . $reference, 'html' => navigator_admin_email($cfg['brand'], $emailData), 'attachments' => $logoAtt],
]);

respond([
    'success'   => true,
    'reference' => $reference,
    'email'     => $emailStatus,
    'message'   => 'Your Access Navigator has been saved.',
]);
