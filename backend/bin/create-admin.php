<?php

declare(strict_types=1);

/*
 * One-time provisioning of the first Super Admin.
 *
 *   php bin/create-admin.php --email=owner@example.com --name="Full Name"
 *       prompts for the password twice (hidden on macOS/Linux; on Windows the
 *       console cannot hide input — prefer --password-stdin there)
 *
 *   php bin/create-admin.php --email=… --name=… --password-stdin < secret.txt
 *       reads the password from standard input (first line)
 *
 * Refuses to run if a Super Admin already exists (pass --additional to add
 * another deliberately) or if the email is already registered. The password
 * is never accepted as a command-line argument (it would land in shell history).
 */

use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordHasher;
use App\Services\UserValidator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$fail = static function (string $message): never {
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
};

$options = getopt('', ['email:', 'name:', 'password-stdin', 'additional']);
if (in_array('--password', array_map(static fn ($a) => explode('=', $a)[0], $argv), true)) {
    $fail('Do not pass the password on the command line. Use the prompt or --password-stdin.');
}

$errors = [];
$email = UserValidator::email($options['email'] ?? null, $errors);
$name = UserValidator::name($options['name'] ?? null, $errors);
if ($errors !== []) {
    $fail(implode(' ', $errors) . ' Usage: php bin/create-admin.php --email=… --name="…"');
}

$users = new UserRepository();
$role = (new RoleRepository())->findBySlug('super_admin') ?? $fail('Role "super_admin" not found. Run php bin/migrate.php first.');

if ($users->countByRole('super_admin') > 0 && !isset($options['additional'])) {
    $fail('A Super Admin already exists. Use --additional only if you really need another one.');
}
if ($users->emailExists($email)) {
    $fail('A user with this email already exists.');
}

$readSecret = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $hide = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($hide) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hide) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return rtrim((string) $line, "\r\n");
};

if (isset($options['password-stdin'])) {
    $password = rtrim((string) fgets(STDIN), "\r\n");
} else {
    if (DIRECTORY_SEPARATOR !== '/') {
        fwrite(STDOUT, "Note: Windows consoles show typed characters. Make sure nobody can see your screen, or use --password-stdin.\n");
    }
    $password = $readSecret('Password: ');
    if ($readSecret('Repeat password: ') !== $password) {
        $fail('Passwords do not match.');
    }
}

$password = UserValidator::newPassword($password, $errors);
if ($errors !== []) {
    $fail(implode(' ', $errors));
}

$id = $users->create($name, $email, PasswordHasher::hash($password), (int) $role['id']);

fwrite(STDOUT, "Super Admin created (id {$id}, {$email}). Sign in at the ERP login page.\n");
