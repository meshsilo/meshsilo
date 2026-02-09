<?php

require_once dirname(__DIR__, 2) . '/includes/Mail.php';

class MailTest extends SiloTestCase {
    protected function setUp(): void {
        parent::setUp();
        // Set default config for testing so loadConfig() uses the log driver
        Mail::setDefaultConfig([
            'driver' => 'log',
            'host' => 'localhost',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_address' => 'test@example.com',
            'from_name' => 'Silo Test',
        ]);
    }

    protected function tearDown(): void {
        // Reset to log driver config so no real emails are sent
        Mail::setDefaultConfig([
            'driver' => 'log',
            'host' => 'localhost',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_address' => 'test@example.com',
            'from_name' => 'Silo Test',
        ]);
        parent::tearDown();
    }

    public function testSanitizeEmailRejectsInvalidEmail(): void {
        $mail = Mail::create();
        $this->expectException(\InvalidArgumentException::class);
        $mail->to('not-an-email');
    }

    public function testSanitizeEmailRejectsEmptyString(): void {
        $mail = Mail::create();
        $this->expectException(\InvalidArgumentException::class);
        $mail->to('');
    }

    public function testSanitizeEmailRejectsInjectionAttempt(): void {
        $mail = Mail::create();
        $this->expectException(\InvalidArgumentException::class);
        $mail->to("user@example.com\r\nBcc: attacker@evil.com");
    }

    public function testSanitizeEmailAcceptsValidEmail(): void {
        $mail = Mail::create();
        $result = $mail->to('valid@example.com');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testCcSanitizesEmails(): void {
        $mail = Mail::create();
        $this->expectException(\InvalidArgumentException::class);
        $mail->cc('invalid-email');
    }

    public function testCcAcceptsValidEmail(): void {
        $mail = Mail::create();
        $result = $mail->cc('cc@example.com');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testBccSanitizesEmails(): void {
        $mail = Mail::create();
        $this->expectException(\InvalidArgumentException::class);
        $mail->bcc('invalid-email');
    }

    public function testBccAcceptsValidEmail(): void {
        $mail = Mail::create();
        $result = $mail->bcc('bcc@example.com');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testCcAcceptsArrayOfEmails(): void {
        $mail = Mail::create();
        $result = $mail->cc(['cc1@example.com' => 'CC One', 'cc2@example.com' => 'CC Two']);
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testBccAcceptsArrayOfEmails(): void {
        $mail = Mail::create();
        $result = $mail->bcc(['bcc1@example.com', 'bcc2@example.com']);
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testFromSanitizesEmail(): void {
        $mail = Mail::create();
        $this->expectException(\InvalidArgumentException::class);
        $mail->from('not-valid');
    }

    public function testFromAcceptsValidEmail(): void {
        $mail = Mail::create();
        $result = $mail->from('sender@example.com', 'Sender Name');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testReplyToSanitizesEmail(): void {
        $mail = Mail::create();
        $this->expectException(\InvalidArgumentException::class);
        $mail->replyTo('bad-email');
    }

    public function testSubjectCanBeSet(): void {
        $mail = Mail::create();
        $result = $mail->subject('Test Subject');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testHtmlBodyCanBeSet(): void {
        $mail = Mail::create();
        $result = $mail->html('<p>Hello</p>');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testTextBodyCanBeSet(): void {
        $mail = Mail::create();
        $result = $mail->text('Hello');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testBodySetsBothHtmlAndText(): void {
        $mail = Mail::create();
        $result = $mail->body('<p>Hello</p>', 'Hello');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testSendThrowsWithoutRecipient(): void {
        $mail = Mail::create();
        $mail->subject('Test')->html('<p>Body</p>');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No recipients specified');
        $mail->send();
    }

    public function testSendThrowsWithoutSubject(): void {
        $mail = Mail::create();
        $mail->to('test@example.com')->html('<p>Body</p>');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No subject specified');
        $mail->send();
    }

    public function testSendThrowsWithoutBody(): void {
        $mail = Mail::create();
        $mail->to('test@example.com')->subject('Test');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No body specified');
        $mail->send();
    }

    public function testRenderEscapesHtmlByDefault(): void {
        $mail = Mail::create();
        $mail->render('Hello {{ name }}!', ['name' => '<script>alert("xss")</script>']);

        // Access the body via reflection to verify escaping
        $reflection = new ReflectionClass($mail);
        $bodyProp = $reflection->getProperty('body');
        $bodyProp->setAccessible(true);
        $body = $bodyProp->getValue($mail);

        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testRenderAllowsRawOutputWithBangSyntax(): void {
        $mail = Mail::create();
        $mail->render('Hello {!! html !!}!', ['html' => '<strong>World</strong>']);

        $reflection = new ReflectionClass($mail);
        $bodyProp = $reflection->getProperty('body');
        $bodyProp->setAccessible(true);
        $body = $bodyProp->getValue($mail);

        $this->assertStringContainsString('<strong>World</strong>', $body);
    }

    public function testTemplateThrowsForMissingTemplate(): void {
        $mail = Mail::create();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Email template not found');
        $mail->template('nonexistent_template_that_does_not_exist');
    }

    public function testAttachThrowsForMissingFile(): void {
        $mail = Mail::create();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Attachment file not found');
        $mail->attach('/nonexistent/file.txt');
    }

    public function testCustomHeaderCanBeSet(): void {
        $mail = Mail::create();
        $result = $mail->header('X-Custom', 'value');
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testCreateReturnsMailInstance(): void {
        $mail = Mail::create();
        $this->assertInstanceOf(Mail::class, $mail);
    }

    public function testToAcceptsArrayOfEmails(): void {
        $mail = Mail::create();
        $result = $mail->to(['user1@example.com' => 'User One', 'user2@example.com' => 'User Two']);
        $this->assertInstanceOf(Mail::class, $result);
    }

    public function testToAcceptsNumericIndexedArray(): void {
        $mail = Mail::create();
        $result = $mail->to(['user1@example.com', 'user2@example.com']);
        $this->assertInstanceOf(Mail::class, $result);
    }
}
