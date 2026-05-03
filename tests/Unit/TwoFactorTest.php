<?php

require_once __DIR__ . '/../../includes/TwoFactor.php';

class TwoFactorTest extends SiloTestCase
{
    // A known secret for deterministic tests (base32-encoded)
    private const TEST_SECRET = 'JBSWY3DPEHPK3PXP';
    // Fixed timestamp: time slot 49834210 (timestamp = 1495026300)
    private const TEST_TIMESTAMP = 1495026300;

    public function testGenerateSecretReturnsNonEmptyString(): void
    {
        $secret = TwoFactor::generateSecret();
        $this->assertNotEmpty($secret);
        $this->assertIsString($secret);
    }

    public function testGenerateSecretUsesBase32Characters(): void
    {
        $secret = TwoFactor::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGenerateSecretIsUnique(): void
    {
        $s1 = TwoFactor::generateSecret();
        $s2 = TwoFactor::generateSecret();
        $this->assertNotSame($s1, $s2);
    }

    public function testGenerateCodeReturns6Digits(): void
    {
        $code = TwoFactor::generateCode(self::TEST_SECRET, self::TEST_TIMESTAMP);
        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testGenerateCodeIsDeterministic(): void
    {
        $code1 = TwoFactor::generateCode(self::TEST_SECRET, self::TEST_TIMESTAMP);
        $code2 = TwoFactor::generateCode(self::TEST_SECRET, self::TEST_TIMESTAMP);
        $this->assertSame($code1, $code2);
    }

    public function testGenerateCodeDiffersAcrossTimeSlots(): void
    {
        // Two adjacent 30s slots produce different codes for this known secret.
        // Using fixed timestamps to make the test fully deterministic.
        $code1 = TwoFactor::generateCode(self::TEST_SECRET, self::TEST_TIMESTAMP);
        $code2 = TwoFactor::generateCode(self::TEST_SECRET, self::TEST_TIMESTAMP + 30);
        $this->assertNotSame($code1, $code2);
    }

    public function testVerifyValidCode(): void
    {
        // Generate for current time; verify() checks current time ± window=1 (3 slots).
        // Even at a 30s slot boundary, the generated code falls within the window.
        $code = TwoFactor::generateCode(self::TEST_SECRET, time());
        $this->assertTrue(TwoFactor::verify(self::TEST_SECRET, $code));
    }

    public function testVerifyInvalidCode(): void
    {
        $this->assertFalse(TwoFactor::verify(self::TEST_SECRET, '000000'));
    }

    public function testVerifyRejectsNonNumericCode(): void
    {
        $this->assertFalse(TwoFactor::verify(self::TEST_SECRET, 'abcdef'));
    }

    public function testVerifyRejectsWrongLengthCode(): void
    {
        $this->assertFalse(TwoFactor::verify(self::TEST_SECRET, '12345'));
        $this->assertFalse(TwoFactor::verify(self::TEST_SECRET, '1234567'));
    }

    public function testVerifyAcceptsCodeInWindow(): void
    {
        $now = time();
        // Generate code for the previous time slot (should pass with window=1)
        $prevCode = TwoFactor::generateCode(self::TEST_SECRET, $now - 30);
        $this->assertTrue(TwoFactor::verify(self::TEST_SECRET, $prevCode, 1));
    }

    public function testGetOTPAuthUrlFormat(): void
    {
        $url = TwoFactor::getOTPAuthUrl(self::TEST_SECRET, 'user@example.com', 'TestApp');
        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString('secret=' . self::TEST_SECRET, $url);
        $this->assertStringContainsString('issuer=TestApp', $url);
    }

    public function testGetOTPAuthUrlContainsAccount(): void
    {
        $url = TwoFactor::getOTPAuthUrl(self::TEST_SECRET, 'alice', 'MyApp');
        $this->assertStringContainsString('alice', $url);
    }

    public function testGenerateBackupCodesReturns10Codes(): void
    {
        $codes = TwoFactor::generateBackupCodes();
        $this->assertCount(10, $codes);
    }

    public function testGenerateBackupCodesFormat(): void
    {
        $codes = TwoFactor::generateBackupCodes();
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $code);
        }
    }

    public function testHashBackupCodesProducesVerifiableHashes(): void
    {
        $codes = ['1234-5678'];
        $hashed = TwoFactor::hashBackupCodes($codes);
        $this->assertCount(1, $hashed);
        $this->assertTrue(password_verify('12345678', $hashed[0]));
    }

    public function testVerifyBackupCodeReturnsIndexOnMatch(): void
    {
        $codes = TwoFactor::generateBackupCodes();
        $hashed = TwoFactor::hashBackupCodes($codes);

        $result = TwoFactor::verifyBackupCode($codes[0], $hashed);
        $this->assertSame(0, $result);
    }

    public function testVerifyBackupCodeReturnsFalseOnNoMatch(): void
    {
        $codes = TwoFactor::generateBackupCodes();
        $hashed = TwoFactor::hashBackupCodes($codes);

        $result = TwoFactor::verifyBackupCode('0000-0000', $hashed);
        $this->assertFalse($result);
    }

    public function testVerifyBackupCodeStripsFormatting(): void
    {
        $hashed = TwoFactor::hashBackupCodes(['1234-5678']);
        // Should match without the dash too
        $result = TwoFactor::verifyBackupCode('12345678', $hashed);
        $this->assertSame(0, $result);
    }
}
