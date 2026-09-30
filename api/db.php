<?php
/**
 * Database bootstrap. Connects with PDO and auto-creates the database and
 * tables on first run so setup is zero-touch on a fresh XAMPP install.
 */
if (!defined('FACILON_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

function facilon_db(array $cfg): PDO
{
    $d = $cfg['db'];
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Connect without selecting a DB so we can create it if missing.
    $pdo = new PDO("mysql:host={$d['host']};charset={$d['charset']}", $d['user'], $d['pass'], $opts);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$d['name']}`");

    // One row per unique investor/contact email.
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        email       VARCHAR(255) NOT NULL UNIQUE,
        name        VARCHAR(255) NULL,
        created_at  DATETIME NOT NULL,
        updated_at  DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Investor Access Navigator submissions.
    $pdo->exec("CREATE TABLE IF NOT EXISTS navigator_submissions (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        reference           VARCHAR(40) NULL UNIQUE,
        user_id             INT NULL,
        email               VARCHAR(255) NOT NULL,
        name                VARCHAR(255) NULL,
        person_type         VARCHAR(50) NULL,
        investor_type       VARCHAR(120) NULL,
        currency            VARCHAR(60) NULL,
        market              VARCHAR(80) NULL,
        intention           VARCHAR(500) NULL,
        services_products   LONGTEXT NULL,
        providers_required  LONGTEXT NULL,
        providers_in_place  LONGTEXT NULL,
        providers_remaining LONGTEXT NULL,
        help_requested      VARCHAR(20) NULL,
        core_kyc            LONGTEXT NULL,
        provider_documents  LONGTEXT NULL,
        nri_banking         TEXT NULL,
        consent_save        TINYINT(1) NOT NULL DEFAULT 0,
        consent_educational TINYINT(1) NOT NULL DEFAULT 0,
        consent_ack         TINYINT(1) NOT NULL DEFAULT 0,
        full_profile        LONGTEXT NULL,
        ip                  VARCHAR(45) NULL,
        user_agent          VARCHAR(255) NULL,
        created_at          DATETIME NOT NULL,
        INDEX (email),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Contact / enquiry submissions.
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_submissions (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        reference    VARCHAR(40) NULL UNIQUE,
        user_id      INT NULL,
        name         VARCHAR(255) NOT NULL,
        email        VARCHAR(255) NOT NULL,
        phone        VARCHAR(60) NULL,
        organisation VARCHAR(255) NULL,
        enquiry_type VARCHAR(120) NOT NULL,
        subject      VARCHAR(255) NOT NULL,
        message      TEXT NOT NULL,
        consent      TINYINT(1) NOT NULL DEFAULT 0,
        ip           VARCHAR(45) NULL,
        user_agent   VARCHAR(255) NULL,
        created_at   DATETIME NOT NULL,
        INDEX (email),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // One-time email verification codes for the navigator flow.
    $pdo->exec("CREATE TABLE IF NOT EXISTS navigator_otps (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        token      VARCHAR(64) NOT NULL UNIQUE,
        email      VARCHAR(255) NOT NULL,
        code_hash  VARCHAR(255) NOT NULL,
        attempts   INT NOT NULL DEFAULT 0,
        consumed   TINYINT(1) NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Audit log of every outbound email attempt.
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        submission_type VARCHAR(20) NOT NULL,
        submission_id   INT NULL,
        recipient       VARCHAR(255) NOT NULL,
        recipient_role  VARCHAR(20) NOT NULL,
        subject         VARCHAR(255) NULL,
        status          VARCHAR(20) NOT NULL,
        error           TEXT NULL,
        created_at      DATETIME NOT NULL,
        INDEX (submission_type, submission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    return $pdo;
}

/** Insert or update the user by email, returning its id. */
function facilon_upsert_user(PDO $pdo, string $email, ?string $name): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO users (email, name, created_at, updated_at)
         VALUES (:email, :name, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            name = COALESCE(VALUES(name), name),
            updated_at = NOW(),
            id = LAST_INSERT_ID(id)"
    );
    $stmt->execute([':email' => $email, ':name' => ($name !== '' ? $name : null)]);
    return (int) $pdo->lastInsertId();
}

/** Record an email attempt in the audit log (never throws). */
function facilon_log_email(PDO $pdo, string $type, ?int $sid, string $to, string $role, string $subject, string $status, ?string $error): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO email_log
             (submission_type, submission_id, recipient, recipient_role, subject, status, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$type, $sid, $to, $role, $subject, $status, $error]);
    } catch (Throwable $e) {
        // Logging must never break the request.
    }
}
