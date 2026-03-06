<?php

require_once dirname(__DIR__, 2) . '/includes/Validator.php';

class ValidatorTest extends SiloTestCase {
    public function testRequiredFieldPasses(): void {
        $validator = Validator::make(['name' => 'John'], ['name' => 'required']);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->errors());
    }

    public function testRequiredFieldFails(): void {
        $validator = Validator::make(['name' => ''], ['name' => 'required']);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('name', $validator->errors());
    }

    public function testEmailValidation(): void {
        $validEmails = ['test@example.com', 'user.name@domain.org'];
        $invalidEmails = ['notanemail', 'missing@', '@nodomain.com'];

        foreach ($validEmails as $email) {
            $validator = Validator::make(['email' => $email], ['email' => 'required|email']);
            $this->assertTrue($validator->validate(), "Email should be valid: $email");
        }

        foreach ($invalidEmails as $email) {
            $validator = Validator::make(['email' => $email], ['email' => 'required|email']);
            $this->assertFalse($validator->validate(), "Email should be invalid: $email");
        }
    }

    public function testMinLengthValidation(): void {
        $validator = Validator::make(['password' => '12345'], ['password' => 'required|min_length:8']);

        $this->assertFalse($validator->validate());
        $this->assertStringContainsString('8', $validator->errors()['password'][0]);
    }

    public function testMaxLengthValidation(): void {
        $validator = Validator::make(
            ['title' => str_repeat('a', 300)],
            ['title' => 'required|max_length:255']
        );

        $this->assertFalse($validator->validate());
    }

    public function testNumericValidation(): void {
        $validator = Validator::make(['age' => 'twenty'], ['age' => 'required|numeric']);
        $this->assertFalse($validator->validate());

        $validator = Validator::make(['age' => '25'], ['age' => 'required|numeric']);
        $this->assertTrue($validator->validate());
    }

    public function testBetweenValidation(): void {
        $validator = Validator::make(['age' => 15], ['age' => 'required|between:18,100']);
        $this->assertFalse($validator->validate());

        $validator = Validator::make(['age' => 25], ['age' => 'required|between:18,100']);
        $this->assertTrue($validator->validate());
    }

    public function testInValidation(): void {
        $validator = Validator::make(
            ['status' => 'pending'],
            ['status' => 'required|in:active,inactive,pending']
        );
        $this->assertTrue($validator->validate());

        $validator = Validator::make(
            ['status' => 'unknown'],
            ['status' => 'required|in:active,inactive,pending']
        );
        $this->assertFalse($validator->validate());
    }

    public function testRegexValidation(): void {
        $validator = Validator::make(
            ['code' => 'ABC123'],
            ['code' => 'required|regex:/^[A-Z]{3}[0-9]{3}$/']
        );
        $this->assertTrue($validator->validate());

        $validator = Validator::make(
            ['code' => 'abc123'],
            ['code' => 'required|regex:/^[A-Z]{3}[0-9]{3}$/']
        );
        $this->assertFalse($validator->validate());
    }

    public function testUrlValidation(): void {
        $validator = Validator::make(
            ['website' => 'https://example.com'],
            ['website' => 'required|url']
        );
        $this->assertTrue($validator->validate());

        $validator = Validator::make(
            ['website' => 'not-a-url'],
            ['website' => 'required|url']
        );
        $this->assertFalse($validator->validate());
    }

    public function testConfirmedValidation(): void {
        $validator = Validator::make([
            'password' => 'secret123',
            'password_confirmation' => 'secret123'
        ], ['password' => 'required|confirmed']);
        $this->assertTrue($validator->validate());

        $validator = Validator::make([
            'password' => 'secret123',
            'password_confirmation' => 'different'
        ], ['password' => 'required|confirmed']);
        $this->assertFalse($validator->validate());
    }

    public function testMultipleRulesOnSameField(): void {
        $validator = Validator::make(
            ['email' => 'test'],
            ['email' => 'required|email|max_length:255']
        );

        $this->assertFalse($validator->validate());
    }

    public function testMultipleFields(): void {
        $validator = Validator::make([
            'name' => 'John',
            'email' => 'invalid',
            'age' => 15
        ], [
            'name' => 'required|min:2',
            'email' => 'required|email',
            'age' => 'required|between:18,100'
        ]);

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
        ], [
            'name' => 'required',
            'email' => 'required|email'
        ]);

        $validator->validate();
        $validated = $validator->validated();

        $this->assertArrayHasKey('name', $validated);
        $this->assertArrayHasKey('email', $validated);
        $this->assertArrayNotHasKey('extra', $validated);
    }

    public function testCustomErrorMessage(): void {
        $validator = Validator::make(['name' => ''], ['name' => 'required']);

        $validator->validate();
        $errors = $validator->errors();

        $this->assertStringContainsString('required', $errors['name'][0]);
    }

    public function testArrayValidation(): void {
        $validator = Validator::make(
            ['tags' => ['php', 'laravel', 'testing']],
            ['tags' => 'array']
        );
        $this->assertTrue($validator->validate());

        $validator = Validator::make(
            ['tags' => 'not an array'],
            ['tags' => 'required|array']
        );
        $this->assertFalse($validator->validate());
    }

    public function testBooleanValidation(): void {
        $boolValues = [true, false, 1, 0, '1', '0'];
        foreach ($boolValues as $val) {
            $validator = Validator::make(
                ['active' => $val],
                ['active' => 'required|boolean']
            );
            $this->assertTrue($validator->validate(), "Should accept boolean value: " . var_export($val, true));
        }
    }

    public function testDateValidation(): void {
        $validator = Validator::make(
            ['birthday' => '2000-01-15'],
            ['birthday' => 'required|date']
        );
        $this->assertTrue($validator->validate());

        $validator = Validator::make(
            ['birthday' => 'not-a-date'],
            ['birthday' => 'required|date']
        );
        $this->assertFalse($validator->validate());
    }

    public function testPassesAndFails(): void {
        $validator = Validator::make(['name' => 'John'], ['name' => 'required']);
        $this->assertTrue($validator->passes());
        $this->assertFalse($validator->fails());

        $validator = Validator::make(['name' => ''], ['name' => 'required']);
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->fails());
    }

    public function testValidateOrFailThrowsOnError(): void {
        $validator = Validator::make(['name' => ''], ['name' => 'required']);

        $this->expectException(ValidationException::class);
        $validator->validateOrFail();
    }

    public function testValidateOrFailReturnsValidatedData(): void {
        $validator = Validator::make(
            ['name' => 'John', 'extra' => 'ignored'],
            ['name' => 'required']
        );

        $validated = $validator->validateOrFail();
        $this->assertArrayHasKey('name', $validated);
        $this->assertArrayNotHasKey('extra', $validated);
    }
}
