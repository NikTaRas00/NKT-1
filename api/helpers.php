<?php
declare(strict_types=1);

function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/config.php';
    $config = is_readable($path) ? (require_once $path) : [];
    return $config;
}

function send_verification_email(string $email, string $rawToken): bool
{
    $config = load_config();
    $siteUrl = rtrim((string)($config['SITE_URL'] ?? ''), '/');
    $from = trim((string)($config['MAIL_FROM'] ?? ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || substr($from, -10) === '@localhost') {
        // Shared hosts reject/drop mail from a non-domain sender, so this is
        // almost certainly the reason mail never arrives.
        error_log('[NKT-1 mail] MAIL_FROM is missing or invalid ("' . $from . '"); set it to an address on your own domain in api/.env');
        return false;
    }
    $link = $siteUrl . '/api/verify.php?token=' . $rawToken;

    $subject = 'Verify your NKT-1 account';
    $body = "Welcome to NKT-1!\r\n\r\nVerify your email by opening this link:\r\n{$link}\r\n\r\nThis link expires in 24 hours. If you didn't request this, ignore this email.\r\n";
    $domain = substr(strrchr($from, '@'), 1);
    $headers = implode("\r\n", [
        "From: NKT-1 <{$from}>",
        "Reply-To: {$from}",
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
    ]);

    // -f sets the envelope sender (Return-Path). cPanel/Exim and most spam
    // filters require it to match a real address on the domain.
    $sent = mail($email, $subject, $body, $headers, '-f' . $from);
    if (!$sent) {
        $lastError = error_get_last();
        error_log('[NKT-1 mail] mail() with -f failed for ' . $email . ($lastError ? (' | ' . $lastError['message']) : '') . ' -- retrying without -f');
        $sent = mail($email, $subject, $body, $headers);
    }
    if (!$sent) {
        $lastError = error_get_last();
        error_log('[NKT-1 mail] mail() returned false sending to ' . $email . ($lastError ? (' | ' . $lastError['message']) : ''));
    } else {
        error_log('[NKT-1 mail] mail() accepted for delivery to ' . $email);
    }
    return $sent;
}
