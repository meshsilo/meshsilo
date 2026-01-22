<?php

require_once dirname(__DIR__, 2) . '/includes/Validator.php';

class ValidatorTest extends SiloTestCase {
    public function testRequiredFieldPasses(): void {
        $validator = Validator::make(['name' => 'John'])
            ->field('name')->required();

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->errors());
    }

    public function testRequiredFieldFails(): void {
        $validator = Validator::make(['name' => ''])
            ->field('name')->required();

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('name', $validator->errors());
    }

    public function testEmailValidation(): void {
        $validEmails = ['test@example.com', 'user.name@domain.org'];
        $invalidEmails = ['notanemail', 'missing@', '@nodomain.com'];

        foreach ($validEmails as $email) {
            $validator = Validator::make(['email' => $email])
                ->field('email')->email();
            $this->assertTrue($validator->validate(), "Email should be valid: $email");
        }

        foreach ($invalidEmails as $email) {
            $validator = Validator::make(['email' => $email])
                ->field('email')->email();
            $this->assertFalse($validator->validate(), "Email should be invalid: $email");
        }
    }

    public function testMinLengthValidation(): void {
        $validator = Validator::make(['password' => '12345'])
            ->field('password')->min(8);

        $this->assertFalse($validator->validate());
        $this->assertStringContainsString('8', $validator->errors()['password'][0]);
    }

    public function testMaxLengthValidation(): void {
        $validator = Validator::make(['title' => str_repeat('a', 300)])
            ->field('title')->max(255);

        $this->assertFalse($validator->validate());
    }

    public function testNumericValidation(): void {
        $validator = Validator::make(['age' => 'twenty'])
            ->field('age')->numeric();

        $this->assertFalse($validator->validate());

        $validator = Validator::make(['age' => '25'])
            ->field('age')->numeric();

        $this->assertTrue($validator->validate());
    }

    public function testBetweenValidation(): void {
        $validator = Validator::make(['age' => 15])
            ->field('age')->between(18, 100);

        $this->assertFalse($validator->validate());

        $validator = Validator::make(['age' => 25])
            ->field('age')->between(18, 100);

        $this->assertTrue($validator->validate());
    }

    public function testInValidation(): void {
        $validator = Validator::make(['status' => 'pending'])
            ->field('status')->in(['active', 'inactive', 'pending']);

        $this->assertTrue($validator->validate());

        $validator = Validator::make(['status' => 'unknown'])
            ->field('status')->in(['active', 'inactive', 'pending']);

        $this->assertFalse($validator->validate());
    }

    public function testRegexValidation(): void {
        $validator = Validator::make(['code' => 'ABC123'])
            ->field('code')->regex('/^[A-Z]{3}[0-9]{3}$/');

        $this->assertTrue($validator->validate());

        $validator = Validator::make(['code' => 'abc123'])
            ->field('code')->regex('/^[A-Z]{3}[0-9]{3}$/');

        $this->assertFalse($validator->validate());
    }

    public function testUrlValidation(): void {
        $validator = Validator::make(['website' => 'https://example.com'])
            ->field('website')->url();

        $this->assertTrue($validator->validate());

        $validator = Validator::make(['website' => 'not-a-url'])
            ->field('website')->url();

        $this->assertFalse($validator->validate());
    }

    public function testConfirmedValidation(): void {
        $validator = Validator::make([
            'password' => 'secret123',
            'password_confirmation' => 'secret123'
        ])->field('password')->confirmed();

        $this->assertTrue($validator->validate());

        $validator = Validator::make([
            'password' => 'secret123',
            'password_confirmation' => 'different'
        ])->field('password')->confirmed();

        $this->assertFalse($validator->validate());
    }

    public function testMultipleRulesOnSameField(): void {
        $validator = Validator::make(['email' => 'test'])
            ->field('email')->required()->email()->max(255);

        $this->assertFalse($validator->validate());
        // Should fail on email format, not required
    }

    public function testMultipleFields(): void {
        $validator = Validator::make([
            'name' => 'John',
            'email' => 'invalid',
            'age' => 15
        ])
        ->field('name')->required()->min(2)
        ->field('email')->required()->email()
        ->field('age')->required()->between(18, 100);

        $this->assertFalse($validator->validate());
        $errors = $validator->errors();

        $this->assertArrayNotHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    public function testValidatedReturnsOnlyValidatedFields(): void {
        $validator = Validator::make([
            'name' => 'John',
            'email' => 'john@example.com',
            'extra' => 'should not be included'
        ])
        ->field('name')->required()
        ->field('email')->required()->email();

        $validator->validate();
        $validated = $validator->validated();

        $this->assertArrayHasKey('name', $validated);
        $this->assertArrayHasKey('email', $validated);
        $this->assertArrayNotHasKey('extra', $validated);
    }

    public function testNullableField(): void {
        $validator = Validator::make(['bio' => null])
            ->field('bio')->nullable()->min(10);

        $this->assertTrue($validator->validate());

        $validator = Validator::make(['bio' => ''])
            ->field('bio')->nullable()->min(10);

        $this->assertTrue($validator->validate());
    }

    public function testCustomErrorMessage(): void {
        $validator = Validator::make(['name' => ''])
            ->field('name')->required();

        $validator->validate();
        $errors = $validator->errors();

        $this->assertStringContainsString('required', $errors['name'][0]);
    }

    public function testArrayValidation(): void {
        $validator = Validator::make(['tags' => ['php', 'laravel', 'testing']])
            ->field('tags')->array();

        $this->assertTrue($validator->validate());

        $validator = Validator::make(['tags' => 'not an array'])
            ->field('tags')->array();

        $this->assertFalse($validator->validate());
    }

    public function testBooleanValidation(): void {
        $boolValues = [true, false, 1, 0, '1', '0'];
        foreach ($boolValues as $val) {
            $validator = Validator::make(['active' => $val])
                ->field('active')->boolean();
            $this->assertTrue($validator->validate(), "Should accept boolean value: " . var_export($val, true));
        }
    }

    public function testDateValidation(): void {
        $validator = Validator::make(['birthday' => '2000-01-15'])
            ->field('birthday')->date();

        $this->assertTrue($validator->validate());

        $validator = Validator::make(['birthday' => 'not-a-date'])
            ->field('birthday')->date();

        $this->assertFalse($validator->validate());
    }
}
