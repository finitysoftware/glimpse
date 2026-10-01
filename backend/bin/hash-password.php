<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Read only from redirected input, never a command-line argument or an echoed terminal prompt.
if (stream_isatty(STDIN)) {
    fwrite(STDERR, "Provide the password through redirected standard input from a password manager or protected temporary file. Do not put it in command history.\n");
    exit(1);
}
$password = rtrim((string) stream_get_contents(STDIN, 1025), "\r\n");
$bcrypt = in_array('--bcrypt', $argv ?? [], true);
$argon = !$bcrypt && defined('PASSWORD_ARGON2ID');
if (mb_strlen($password, 'UTF-8') < 5 || strlen($password) > ($argon ? 1024 : 72)) {
    fwrite(STDERR, "Use at least 5 characters and at most 1024 bytes (72 bytes without Argon2id).\n");
    exit(1);
}
$hash = password_hash($password, $bcrypt ? PASSWORD_BCRYPT : ($argon ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT));
if (!password_verify($password, $hash)) {
    fwrite(STDERR, "The generated password hash could not be verified.\n");
    exit(1);
}
echo $hash . "\n";
