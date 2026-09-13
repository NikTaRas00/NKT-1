<?php
declare(strict_types=1);

function load_config(): array
{
    $path = __DIR__ . '/config.php';
    return is_readable($path) ? (require $path) : [];
}

function send_verification_email(string $email, string $rawToken): void
{
    $config = load_config();
    $siteUrl = rtrim((string)($config['SITE_URL'] ?? ''), '/');
    $from = (string)($config['MAIL_FROM'] ?? 'noreply@localhost');
    $link = $siteUrl . '/api/verify.php?token=' . $rawToken;

    $subject = 'Verify your NKT-1 account';
    $body = "Welcome to NKT-1!\n\nVerify your email by opening this link:\n{$link}\n\nThis link expires in 24 hours. If you didn't request this, ignore this email.";
    $headers = "From: NKT-1 <{$from}>\r\nContent-Type: text/plain; charset=utf-8";

    mail($email, $subject, $body, $headers);
}
