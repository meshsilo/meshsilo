<?php

require_once dirname(__DIR__, 2) . '/includes/Csrf.php';

class CsrfTest extends SiloTestCase {
    protected function setUp(): void {
        parent::setUp();
        // Reset CSRF state
        $_SESSION = [];
    }

    public function testGetTokenReturnsString(): void {
        $token = Csrf::getToken();

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGetTokenReturnsSameTokenInSession(): void {
        $token1 = Csrf::getToken();
        $token2 = Csrf::getToken();

        $this->assertEquals($token1, $token2);
    }

    public function testValidateAcceptsValidToken(): void {
        $token = Csrf::getToken();
        $_POST['csrf_token'] = $token;

        $this->assertTrue(Csrf::validate());
    }

    public function testValidateRejectsInvalidToken(): void {
        Csrf::getToken(); // Initialize session
        $_POST['csrf_token'] = 'invalid_token';

        $this->assertFalse(Csrf::validate());
    }

    public function testValidateRejectsMissingToken(): void {
        Csrf::getToken(); // Initialize session
        unset($_POST['csrf_token']);

        $this->assertFalse(Csrf::validate());
    }

    public function testValidateAcceptsDirectToken(): void {
        $token = Csrf::getToken();

        $this->assertTrue(Csrf::validate($token));
    }

    public function testFieldReturnsHtmlInput(): void {
        $field = Csrf::field();

        $this->assertStringContainsString('<input', $field);
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
        $this->assertStringContainsString('value="', $field);
    }

    public function testMetaTagReturnsHtmlMeta(): void {
        $meta = Csrf::metaTag();

        $this->assertStringContainsString('<meta', $meta);
        $this->assertStringContainsString('name="csrf-token"', $meta);
        $this->assertStringContainsString('content="', $meta);
    }

    public function testTimedTokenIsValid(): void {
        $token = Csrf::getTimedToken('test_form');

        $this->assertIsString($token);
        $this->assertTrue(Csrf::validate($token));
    }

    public function testRegenerateTokenCreatesNewToken(): void {
        $token1 = Csrf::getToken();
        Csrf::regenerateToken();
        $token2 = Csrf::getToken();

        $this->assertNotEquals($token1, $token2);
    }

    public function testAjaxSetupScriptContainsToken(): void {
        $token = Csrf::getToken();
        $script = Csrf::ajaxSetupScript();

        $this->assertStringContainsString('<script>', $script);
        $this->assertStringContainsString($token, $script);
        $this->assertStringContainsString('X-CSRF-Token', $script);
    }

    public function testValidateFromHeaderWorks(): void {
        $token = Csrf::getToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $this->assertTrue(Csrf::validate());
    }

    public function testTokenLengthIs64Characters(): void {
        $token = Csrf::getToken();

        // Token should be hex string of 32 bytes = 64 characters
        $this->assertEquals(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    public function testTimedFieldReturnsHtmlInput(): void {
        $field = Csrf::timedField('login_form');

        $this->assertStringContainsString('<input', $field);
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
    }

    public function testCheckSkipsGetRequests(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertTrue(Csrf::check());
    }

    public function testValidateOrFailThrowsOnInvalidToken(): void {
        Csrf::getToken();
        $_POST['csrf_token'] = 'bad_token';

        $this->expectException(CsrfException::class);
        Csrf::validateOrFail();
    }
}
