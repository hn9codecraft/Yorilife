<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PasswordHasher;
use App\Services\UserValidator;
use PHPUnit\Framework\TestCase;

final class UserValidatorTest extends TestCase
{
    public function testEmailIsTrimmedAndLowerCased(): void
    {
        $errors = [];
        self::assertSame('asha@yorilife.in', UserValidator::email('  Asha@YoriLife.IN ', $errors));
        self::assertSame([], $errors);
    }

    public function testInvalidEmailsAreRejected(): void
    {
        foreach (['', 'no-at-sign', 'a@', str_repeat('a', 250) . '@x.io', 123] as $value) {
            $errors = [];
            self::assertNull(UserValidator::email($value, $errors));
            self::assertArrayHasKey('email', $errors);
        }
    }

    public function testNameIsCollapsedAndControlCharactersRejected(): void
    {
        $errors = [];
        self::assertSame('Asha Rao', UserValidator::name("  Asha \t  Rao ", $errors));
        self::assertNull(UserValidator::name("Asha\u{0007}", $errors));
        self::assertArrayHasKey('name', $errors);
    }

    public function testPasswordLengthRules(): void
    {
        $errors = [];
        self::assertNull(UserValidator::newPassword('short', $errors));
        self::assertNull(UserValidator::newPassword(str_repeat('x', 73), $errors), 'over 72 bytes is rejected, not truncated');
        $errors = [];
        self::assertSame('twelve-chars', UserValidator::newPassword('twelve-chars', $errors));
        self::assertSame([], $errors);
    }

    public function testUnknownFieldsAreReported(): void
    {
        $errors = [];
        UserValidator::onlyFields(['name' => 'x', 'password_hash' => 'y', 'id' => 1], ['name'], $errors);
        self::assertSame(['password_hash', 'id'], array_keys($errors));
    }

    public function testPasswordHasherUsesBcryptCost12AndVerifies(): void
    {
        $hash = PasswordHasher::hash('a-strong-password');

        self::assertStringStartsWith('$2y$12$', $hash);
        self::assertTrue(PasswordHasher::verify('a-strong-password', $hash));
        self::assertFalse(PasswordHasher::verify('wrong', $hash));
        self::assertFalse(PasswordHasher::verify('anything', null), 'missing account never verifies');
        self::assertFalse(PasswordHasher::needsRehash($hash));
        self::assertTrue(PasswordHasher::needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 10])));
    }
}
