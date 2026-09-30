<?php
/** Shared request/response helpers for the JSON endpoints. */
if (!defined('FACILON_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Emit a JSON response and stop. */
function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read and decode the JSON request body (falls back to form-encoded POST). */
function read_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST ?: [];
}

/** Trim a scalar value from input; returns '' for missing/non-scalar. */
function field($input, string $key): string
{
    return isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
}

function is_email(string $v): bool
{
    return (bool) filter_var($v, FILTER_VALIDATE_EMAIL);
}

/** Name: letters/spaces/.'- only (no digits), at least 2 chars. Unicode-aware. */
function valid_name(string $v): bool
{
    $v = trim($v);
    return mb_strlen($v) >= 2 && (bool) preg_match("/^[\p{L}][\p{L} .'-]*$/u", $v);
}

/** Mobile: no letters, 10 to 15 digits (grouping chars allowed). */
function valid_mobile(string $v): bool
{
    $v = trim($v);
    if (preg_match('/[A-Za-z]/', $v)) {
        return false;
    }
    if (!preg_match('/^[+()\d\s-]+$/', $v)) {
        return false;
    }
    $digits = strlen(preg_replace('/\D/', '', $v));
    return $digits >= 10 && $digits <= 15;
}

function truthy($v): bool
{
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on' || $v === 'yes';
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function user_agent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

function esc($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
