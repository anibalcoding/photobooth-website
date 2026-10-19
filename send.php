<?php
declare(strict_types=1);

/* ---------------------------------------------------------------
   Booking enquiry handler for Hostinger shared hosting (PHP mail).

   BEFORE LAUNCH: set MAIL_FROM to a real mailbox on your own domain.
   Hostinger sends through your domain, so a From: address on another
   domain (gmail.com included) fails SPF and lands in spam or bounces.
   Create something like noreply@yourdomain.com in hPanel > Emails.
----------------------------------------------------------------*/

const MAIL_TO        = 'flashinglightsphotobooth11@gmail.com';
const MAIL_FROM      = 'noreply@YOUR-DOMAIN.com';
const MAIL_FROM_NAME = 'Flashing Lights Website';
const SUCCESS_URL    = 'thank-you.html';
const MIN_SECONDS    = 3;      // faster than this is a bot
const MAX_PER_HOUR   = 6;      // per IP

/* ----------------------------- helpers ----------------------- */

function clean(string $v): string {
    // strip CR/LF so nothing can be injected into mail headers
    return trim(str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v));
}

function headerName(string $v): string {
    // A display name must not carry header-special characters.
    $v = str_replace([':', '<', '>', '"', ',', ';', '@'], ' ', $v);
    $v = trim(preg_replace('/\s+/', ' ', $v));
    return '"' . $v . '"';
}

function wantsJson(): bool {
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT'])
            && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
}

function finish(bool $ok, string $msg, int $code = 200): never {
    if (wantsJson()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $msg]);
    } elseif ($ok) {
        header('Location: ' . SUCCESS_URL, true, 303);
    } else {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Message not sent</title>'
           . '<p style="font:16px system-ui;max-width:40em;margin:3em auto">'
           . htmlspecialchars($msg, ENT_QUOTES)
           . ' <a href="index.html#contact">Go back and try again</a>.</p>';
    }
    exit;
}

/* --------------------------- guard rails --------------------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    finish(false, 'This endpoint only accepts form submissions.', 405);
}

// Honeypot: a real person never fills this in, it is hidden.
if (clean((string)($_POST['company'] ?? '')) !== '') {
    finish(true, 'Thank you, your enquiry has been sent.'); // silent success for bots
}

// Timing check, only when JS supplied a timestamp.
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0) {
    $age = time() - (int)($ts / 1000);
    if ($age < MIN_SECONDS || $age > 7200) {
        finish(false, 'That form was submitted too quickly. Please try again.', 422);
    }
}

// Light per-IP throttle. Fails open if the filesystem is not writable.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$bucket = sys_get_temp_dir() . '/fl_enq_' . md5($ip);
if (is_readable($bucket)) {
    $hits = array_filter(
        array_map('intval', explode(',', (string)file_get_contents($bucket))),
        fn($t) => $t > time() - 3600
    );
    if (count($hits) >= MAX_PER_HOUR) {
        finish(false, 'Too many enquiries from this connection. Please email us directly.', 429);
    }
    $hits[] = time();
    @file_put_contents($bucket, implode(',', $hits), LOCK_EX);
} else {
    @file_put_contents($bucket, (string)time(), LOCK_EX);
}

/* ---------------------------- validate ----------------------- */

$first = clean((string)($_POST['first_name'] ?? ''));
$last  = clean((string)($_POST['last_name']  ?? ''));
$email = clean((string)($_POST['email']      ?? ''));
$phone = clean((string)($_POST['phone']      ?? ''));
$type  = clean((string)($_POST['event_type'] ?? ''));
$date  = clean((string)($_POST['event_date'] ?? ''));
$notes = trim((string)($_POST['message'] ?? ''));

$errors = [];
if ($first === '') { $errors[] = 'first name'; }
if ($last  === '') { $errors[] = 'last name'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'a valid email address'; }
if (mb_strlen($first) > 80 || mb_strlen($last) > 80 || mb_strlen($email) > 160
    || mb_strlen($phone) > 40 || mb_strlen($notes) > 4000) {
    finish(false, 'One of those fields was too long.', 422);
}
if ($errors) {
    finish(false, 'Please provide ' . implode(', ', $errors) . '.', 422);
}

$allowed = ['Wedding', 'Corporate Event', 'Brand Activation', 'Birthday / Private', 'Other'];
if (!in_array($type, $allowed, true)) { $type = 'Not specified'; }
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = 'Not specified'; }

/* ------------------------------ send ------------------------- */

$name    = $first . ' ' . $last;
$subject = sprintf('New enquiry: %s - %s', $type, $name);

$body = "New booking enquiry from the website\n"
      . str_repeat('=', 44) . "\n\n"
      . "Name:        {$name}\n"
      . "Email:       {$email}\n"
      . "Phone:       " . ($phone !== '' ? $phone : 'Not provided') . "\n"
      . "Event type:  {$type}\n"
      . "Event date:  " . ($date !== '' ? $date : 'Not specified') . "\n\n"
      . "About the event:\n"
      . ($notes !== '' ? $notes : 'Nothing added.') . "\n\n"
      . str_repeat('-', 44) . "\n"
      . 'Submitted: ' . date('d M Y, H:i') . " (server time)\n"
      . "Reply directly to this email to answer {$first}.\n";

$headers = [
    'From: ' . sprintf('%s <%s>', headerName(MAIL_FROM_NAME), MAIL_FROM),
    'Reply-To: ' . sprintf('%s <%s>', headerName($name), $email),
    'Content-Type: text/plain; charset=UTF-8',
    'MIME-Version: 1.0',
    'X-Mailer: PHP/' . phpversion(),
];

$sent = @mail(
    MAIL_TO,
    '=?UTF-8?B?' . base64_encode($subject) . '?=',
    $body,
    implode("\r\n", $headers),
    '-f' . MAIL_FROM
);

if (!$sent) {
    error_log('Flashing Lights: mail() failed for ' . $email);
    finish(false, 'Sorry, something went wrong sending your enquiry. '
                . 'Please email flashinglightsphotobooth11@gmail.com directly.', 500);
}

finish(true, 'Thank you. Your enquiry has been sent, we will be in touch within 24 hours.');
