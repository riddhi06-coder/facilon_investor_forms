<?php
/** HTML email templates for navigator and contact submissions. */
if (!defined('FACILON_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Return the Facilon logo as PNG bytes for email use (converted from WebP,
 * which many email clients cannot render). Cached after first conversion.
 * Returns null if the source or GD WebP support is unavailable.
 */
function facilon_logo_png(): ?string
{
    $cache = __DIR__ . '/logo-email.png';
    if (is_file($cache)) {
        return file_get_contents($cache);
    }
    $src = __DIR__ . '/../images/logo.webp';
    if (!is_file($src) || !function_exists('imagecreatefromwebp')) {
        return null;
    }
    $img = @imagecreatefromwebp($src);
    if (!$img) {
        return null;
    }
    imagealphablending($img, false);
    imagesavealpha($img, true);
    ob_start();
    imagepng($img);
    $data = ob_get_clean();
    imagedestroy($img);
    @file_put_contents($cache, $data);
    return $data;
}

/** Build the inline logo attachment for Graph (src="cid:facilonlogo"), or null. */
function facilon_logo_attachment(): ?array
{
    $png = facilon_logo_png();
    if ($png === null) {
        return null;
    }
    return [
        '@odata.type'  => '#microsoft.graph.fileAttachment',
        'name'         => 'logo.png',
        'contentType'  => 'image/png',
        'isInline'     => true,
        'contentId'    => 'facilonlogo',
        'contentBytes' => base64_encode($png),
    ];
}

/** Brand-styled outer wrapper for all emails. Logo centered in the header. */
function email_shell(string $brand, string $heading, string $innerHtml): string
{
    $b = esc($brand);
    $h = esc($heading);
    // Use the inline logo when available; otherwise fall back to brand text.
    $header = facilon_logo_png() !== null
        ? '<img src="cid:facilonlogo" alt="' . $b . '" width="150" style="display:inline-block;width:150px;max-width:60%;height:auto;border:0" />'
        : '<span style="color:#052f55;font-size:20px;font-weight:700;letter-spacing:.3px">Facilon<span style="color:#d5a438"> Services</span></span>';
    return <<<HTML
<div style="margin:0;padding:24px;background:#f2f5f7;font-family:Arial,Helvetica,sans-serif;color:#0f1923">
  <div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #dce3ea;border-radius:12px;overflow:hidden">
    <div style="background:#ffffff;padding:22px 24px;text-align:center;border-bottom:3px solid #d5a438">
      {$header}
    </div>
    <div style="padding:24px">
      <h1 style="margin:0 0 16px;font-size:20px;color:#073b69">{$h}</h1>
      {$innerHtml}
    </div>
    <div style="background:#f7f9fb;border-top:1px solid #dce3ea;padding:16px 24px;font-size:11px;color:#687783;line-height:1.6">
      {$b}. This is an automated message from the Facilon Access Navigator.
      Facilon does not provide investment advice or confirm eligibility; final decisions rest with the relevant Service Provider.
    </div>
  </div>
</div>
HTML;
}

/** Render an array of strings as an HTML list, or a fallback line. */
function email_list(array $items, string $emptyText = 'None'): string
{
    if (!$items) {
        return '<p style="margin:0;color:#687783">' . esc($emptyText) . '</p>';
    }
    $lis = implode('', array_map(fn($i) => '<li style="margin:2px 0">' . esc($i) . '</li>', $items));
    return '<ul style="margin:6px 0 0;padding-left:20px;color:#4a5568;font-size:14px;line-height:1.6">' . $lis . '</ul>';
}

/** A simple label/value summary table. */
function email_rows(array $pairs): string
{
    $rows = '';
    foreach ($pairs as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $rows .= '<tr>'
            . '<td style="padding:8px 10px;border:1px solid #e5ebf0;background:#f7f9fb;font-size:13px;color:#687783;width:40%;vertical-align:top">' . esc($label) . '</td>'
            . '<td style="padding:8px 10px;border:1px solid #e5ebf0;font-size:13px;color:#0f1923;vertical-align:top">' . nl2br(esc($value)) . '</td>'
            . '</tr>';
    }
    return '<table style="width:100%;border-collapse:collapse;margin:8px 0">' . $rows . '</table>';
}

/* ----------------------- OTP email ----------------------- */

function otp_email(string $brand, string $code): string
{
    $c = esc($code);
    $body = '<p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#4a5568">'
        . 'Use the verification code below to confirm your email address and activate your Facilon Access Navigator. '
        . 'This code expires in 10 minutes.</p>'
        . '<div style="text-align:center;margin:20px 0">'
        . '<span style="display:inline-block;font-size:30px;font-weight:800;letter-spacing:10px;color:#052f55;'
        . 'background:#eaf5fc;border:1px solid #c7dfed;border-radius:10px;padding:14px 22px">' . $c . '</span>'
        . '</div>'
        . '<p style="margin:16px 0 0;font-size:12px;color:#687783;line-height:1.6">'
        . '<strong>Security reminder:</strong> Facilon will never ask you to share this code with anyone by call, '
        . 'message or email. If you did not request this, you can ignore this email.</p>';

    return email_shell($brand, 'Your verification code', $body);
}

/* ----------------------- Navigator emails ----------------------- */

function navigator_client_email(string $brand, array $p): string
{
    $body = '<p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#4a5568">'
        . 'Thank you — your Facilon Access Navigator has been saved. Below is a summary of the route and requirements we captured. '
        . 'Nothing has been shared with any Service Provider; a later introduction requires your separate consent.</p>'
        . email_rows([
            'Reference'          => $p['reference'],
            'Investor type'      => $p['investor_type'],
            'Investment currency'=> $p['currency'],
            'Market'             => $p['market'],
            'Objectives'         => $p['intention'],
            'Help requested'     => $p['help_requested'],
        ])
        . '<h3 style="margin:20px 0 4px;font-size:15px;color:#073b69">Service Providers still required</h3>'
        . email_list($p['providers_remaining_labels'], 'None — you indicated all are in place.')
        . '<p style="margin:18px 0 0;font-size:12px;color:#687783;line-height:1.6">'
        . 'You can withdraw consent or request correction/deletion at any time via privacy@facilonservices.com.</p>';

    return email_shell($brand, 'Your Access Navigator has been saved', $body);
}

function navigator_admin_email(string $brand, array $p): string
{
    $body = '<p style="margin:0 0 12px;font-size:14px;color:#4a5568">A new Investor Access Navigator profile has been saved.</p>'
        . email_rows([
            'Reference'           => $p['reference'],
            'Email'               => $p['email'],
            'Name'                => $p['name'],
            'Person type'         => $p['person_type'],
            'Investor type'       => $p['investor_type'],
            'Investment currency' => $p['currency'],
            'Market'              => $p['market'],
            'Objectives'          => $p['intention'],
            'Help requested'      => $p['help_requested'],
            'Educational updates' => $p['consent_educational'] ? 'Yes' : 'No',
            'Submitted'           => $p['created_at'],
            'IP'                  => $p['ip'],
        ])
        . '<h3 style="margin:18px 0 4px;font-size:14px;color:#073b69">Providers already in place</h3>'
        . email_list($p['providers_in_place_labels'], 'None selected')
        . '<h3 style="margin:16px 0 4px;font-size:14px;color:#073b69">Providers still required</h3>'
        . email_list($p['providers_remaining_labels'], 'None');

    return email_shell($brand, 'New Access Navigator submission', $body);
}

/* ----------------------- Contact emails ----------------------- */

function contact_client_email(string $brand, array $p): string
{
    $body = '<p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#4a5568">'
        . 'Thank you for contacting Facilon. We have received your enquiry and will respond as soon as possible. '
        . 'A summary is shown below for your records.</p>'
        . email_rows([
            'Reference'    => $p['reference'],
            'Enquiry type' => $p['enquiry_type'],
            'Subject'      => $p['subject'],
            'Message'      => $p['message'],
        ]);

    return email_shell($brand, 'We have received your enquiry', $body);
}

function contact_admin_email(string $brand, array $p): string
{
    $body = '<p style="margin:0 0 12px;font-size:14px;color:#4a5568">A new contact enquiry has been submitted.</p>'
        . email_rows([
            'Reference'    => $p['reference'],
            'Name'         => $p['name'],
            'Email'        => $p['email'],
            'Phone'        => $p['phone'],
            'Organisation' => $p['organisation'],
            'Enquiry type' => $p['enquiry_type'],
            'Subject'      => $p['subject'],
            'Message'      => $p['message'],
            'Submitted'    => $p['created_at'],
            'IP'           => $p['ip'],
        ]);

    $heading = 'New enquiry: ' . ($p['enquiry_type'] !== '' ? $p['enquiry_type'] : 'General');
    return email_shell($brand, $heading, $body);
}
