<?php
/**
 * Microsoft Graph email helper (client-credentials / application flow).
 * Acquires an app token, then sends mail as the configured From mailbox.
 */
if (!defined('FACILON_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Acquire an application access token for Graph. Throws on failure. */
function graph_token(array $g): string
{
    $url = "https://login.microsoftonline.com/{$g['tenant']}/oauth2/v2.0/token";
    $body = http_build_query([
        'client_id'     => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'scope'         => $g['scope'],
        'grant_type'    => 'client_credentials',
    ]);

    [$res, $code, $err] = graph_curl($url, $body, ['Content-Type: application/x-www-form-urlencoded']);

    if ($res === false) {
        throw new RuntimeException('Token request failed: ' . $err);
    }
    $j = json_decode($res, true);
    if ($code >= 300 || empty($j['access_token'])) {
        $detail = isset($j['error_description']) ? $j['error_description'] : $res;
        throw new RuntimeException('Token error (HTTP ' . $code . '): ' . $detail);
    }
    return $j['access_token'];
}

/**
 * Send an HTML email through Graph. $to may be a string or an array of addresses.
 * Throws on failure so the caller can log it.
 */
function graph_send_mail(array $g, string $token, $to, string $subject, string $html, array $attachments = []): bool
{
    $recipients = array_map(
        fn($addr) => ['emailAddress' => ['address' => $addr]],
        (array) $to
    );

    $message = [
        'subject'      => $subject,
        'body'         => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => $recipients,
    ];
    if ($attachments) {
        $message['attachments'] = $attachments;
    }

    $payload = json_encode([
        'message'         => $message,
        'saveToSentItems' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $url = "{$g['base']}/users/" . rawurlencode($g['from']) . "/sendMail";

    [$res, $code, $err] = graph_curl($url, $payload, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);

    if ($res === false) {
        throw new RuntimeException('sendMail transport error: ' . $err);
    }
    if ($code >= 300) {
        throw new RuntimeException('sendMail HTTP ' . $code . ': ' . $res);
    }
    return true; // 202 Accepted, empty body
}

/**
 * Acquire one token and send all messages, logging each attempt.
 * Best-effort: never throws. Returns a role => bool status map.
 * $messages: [ ['to'=>..,'role'=>'client|admin','subject'=>..,'html'=>..], ... ]
 */
function send_notifications(array $cfg, PDO $pdo, string $type, ?int $sid, array $messages): array
{
    $status = [];
    try {
        $token = graph_token($cfg['graph']);
    } catch (Throwable $e) {
        foreach ($messages as $m) {
            facilon_log_email($pdo, $type, $sid, $m['to'], $m['role'], $m['subject'], 'failed', $e->getMessage());
            $status[$m['role']] = false;
        }
        return $status;
    }

    foreach ($messages as $m) {
        try {
            graph_send_mail($cfg['graph'], $token, $m['to'], $m['subject'], $m['html'], $m['attachments'] ?? []);
            facilon_log_email($pdo, $type, $sid, $m['to'], $m['role'], $m['subject'], 'sent', null);
            $status[$m['role']] = true;
        } catch (Throwable $e) {
            facilon_log_email($pdo, $type, $sid, $m['to'], $m['role'], $m['subject'], 'failed', $e->getMessage());
            $status[$m['role']] = false;
        }
    }
    return $status;
}

/** Minimal cURL POST helper returning [body|false, httpCode, error]. */
function graph_curl(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$res, (int) $code, $err];
}
