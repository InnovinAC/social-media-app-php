<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Validation\ValidationException;
use Phpvin\Validation\Validator;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    #[Test]
    public function it_returns_only_the_fields_that_were_named_in_the_rules(): void
    {
        $clean = $this->validator->validate(
            ['email' => 'a@b.co', 'is_admin' => '1'],
            ['email' => 'required|email'],
        );

        $this->assertSame(['email' => 'a@b.co'], $clean);
    }

    #[Test]
    public function it_reports_a_missing_required_field(): void
    {
        $errors = $this->validator->check([], ['email' => 'required']);

        $this->assertSame(['Email is required.'], $errors['email']);
    }

    #[Test]
    public function whitespace_only_input_counts_as_missing(): void
    {
        $errors = $this->validator->check(['name' => '   '], ['name' => 'required']);

        $this->assertArrayHasKey('name', $errors);
    }

    #[Test]
    public function it_validates_email_addresses(): void
    {
        $this->assertSame([], $this->validator->check(['email' => 'a@b.co'], ['email' => 'email']));
        $this->assertArrayHasKey('email', $this->validator->check(['email' => 'nope'], ['email' => 'email']));
    }

    #[Test]
    public function min_and_max_measure_string_length(): void
    {
        $errors = $this->validator->check(['password' => 'short'], ['password' => 'min:12']);

        $this->assertSame(['Password must be at least 12 characters.'], $errors['password']);
    }

    #[Test]
    public function min_and_max_measure_numeric_magnitude(): void
    {
        $errors = $this->validator->check(['age' => 8], ['age' => 'numeric|min:13']);

        $this->assertSame(['Age must be at least 13.'], $errors['age']);
    }

    #[Test]
    public function between_checks_both_ends(): void
    {
        $rules = ['age' => 'integer|between:13,120'];

        $this->assertSame([], $this->validator->check(['age' => '30'], $rules));
        $this->assertArrayHasKey('age', $this->validator->check(['age' => '9'], $rules));
        $this->assertArrayHasKey('age', $this->validator->check(['age' => '130'], $rules));
    }

    #[Test]
    public function nullable_skips_the_remaining_rules_when_empty(): void
    {
        $this->assertSame([], $this->validator->check(['bio' => ''], ['bio' => 'nullable|min:10']));
        $this->assertArrayHasKey('bio', $this->validator->check(['bio' => 'short'], ['bio' => 'nullable|min:10']));
    }

    #[Test]
    public function an_absent_optional_field_collects_no_errors(): void
    {
        // Without this, a blank optional field would fail email, min and max
        // all at once and bury the user in messages.
        $this->assertSame([], $this->validator->check([], ['website' => 'url|max:255']));
    }

    #[Test]
    public function confirmed_compares_against_the_confirmation_field(): void
    {
        $rules = ['password' => 'confirmed'];

        $this->assertSame([], $this->validator->check(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            $rules,
        ));

        $this->assertArrayHasKey('password', $this->validator->check(
            ['password' => 'secret', 'password_confirmation' => 'different'],
            $rules,
        ));
    }

    #[Test]
    public function in_restricts_to_a_list(): void
    {
        $rules = ['role' => 'in:admin,editor'];

        $this->assertSame([], $this->validator->check(['role' => 'editor'], $rules));
        $this->assertArrayHasKey('role', $this->validator->check(['role' => 'owner'], $rules));
    }

    #[Test]
    public function integer_rejects_decimals_and_words(): void
    {
        $this->assertSame([], $this->validator->check(['n' => '42'], ['n' => 'integer']));
        $this->assertArrayHasKey('n', $this->validator->check(['n' => '4.2'], ['n' => 'integer']));
        $this->assertArrayHasKey('n', $this->validator->check(['n' => 'forty'], ['n' => 'integer']));
    }

    #[Test]
    public function a_regex_rule_keeps_its_colons_and_commas(): void
    {
        $rules = ['code' => 'regex:/^[A-Z]{2},\d{3}$/'];

        $this->assertSame([], $this->validator->check(['code' => 'AB,123'], $rules));
        $this->assertArrayHasKey('code', $this->validator->check(['code' => 'nope'], $rules));
    }

    #[Test]
    public function rules_can_be_given_as_an_array(): void
    {
        $errors = $this->validator->check(['email' => 'nope'], ['email' => ['required', 'email']]);

        $this->assertArrayHasKey('email', $errors);
    }

    #[Test]
    public function it_collects_every_failure_for_a_field(): void
    {
        $errors = $this->validator->check(['email' => 'nope'], ['email' => 'email|min:20']);

        $this->assertCount(2, $errors['email']);
    }

    #[Test]
    public function validate_throws_with_the_errors_attached(): void
    {
        try {
            $this->validator->validate([], ['email' => 'required']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertSame('Email is required.', $e->first());
        }
    }

    #[Test]
    public function the_exception_does_not_carry_passwords_back_to_the_form(): void
    {
        try {
            $this->validator->validate(
                ['email' => 'nope', 'password' => 'hunter2', 'api_token' => 'abc'],
                ['email' => 'email'],
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->input());
            $this->assertArrayNotHasKey('password', $e->input());
            $this->assertArrayNotHasKey('api_token', $e->input());
        }
    }

    #[Test]
    public function an_unknown_rule_is_a_programmer_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown validation rule [wibble]');

        $this->validator->check(['a' => 'b'], ['a' => 'wibble']);
    }

    // --- custom rules -----------------------------------------------------

    #[Test]
    public function a_registered_rule_can_fail_with_its_own_message(): void
    {
        $this->validator->extend('unique', fn (mixed $value): ?string
            => $value === 'taken' ? 'That one is already in use.' : null);

        $errors = $this->validator->check(['name' => 'taken'], ['name' => 'unique']);

        $this->assertSame(['That one is already in use.'], $errors['name']);
    }

    #[Test]
    public function a_registered_rule_that_passes_adds_nothing(): void
    {
        $this->validator->extend('unique', fn (mixed $value): ?string => null);

        $this->assertSame([], $this->validator->check(['name' => 'free'], ['name' => 'unique']));
    }

    #[Test]
    public function returning_false_produces_a_generic_message(): void
    {
        $this->validator->extend('even', fn (mixed $value): bool => (int) $value % 2 === 0);

        $this->assertSame([], $this->validator->check(['n' => 4], ['n' => 'even']));
        $this->assertSame(['N is not valid.'], $this->validator->check(['n' => 3], ['n' => 'even'])['n']);
    }

    #[Test]
    public function a_registered_rule_receives_its_arguments(): void
    {
        $seen = [];

        $this->validator->extend('unique', function (mixed $value, array $args) use (&$seen): ?string {
            $seen = $args;

            return null;
        });

        $this->validator->check(['email' => 'a@b.co'], ['email' => 'unique:users,email']);

        $this->assertSame(['users', 'email'], $seen);
    }

    #[Test]
    public function a_registered_rule_can_see_the_whole_input_and_field_name(): void
    {
        $this->validator->extend('matches_other', fn (mixed $value, array $args, array $data, string $field): ?string
            => $value === ($data['other'] ?? null) ? null : "$field must equal other.");

        $this->assertSame([], $this->validator->check(['a' => 1, 'other' => 1], ['a' => 'matches_other']));
        $this->assertArrayHasKey('a', $this->validator->check(['a' => 1, 'other' => 2], ['a' => 'matches_other']));
    }

    #[Test]
    public function a_registered_rule_overrides_a_built_in_of_the_same_name(): void
    {
        $this->validator->extend('email', fn (): ?string => null);

        $this->assertSame([], $this->validator->check(['email' => 'not-an-email'], ['email' => 'email']));
    }

    #[Test]
    public function registered_rules_are_reported(): void
    {
        $this->assertFalse($this->validator->hasRule('unique'));

        $this->validator->extend('unique', fn (): ?string => null);

        $this->assertTrue($this->validator->hasRule('unique'));
    }

    #[Test]
    public function a_registered_rule_is_skipped_for_empty_optional_input(): void
    {
        $called = false;

        $this->validator->extend('unique', function () use (&$called): ?string {
            $called = true;

            return 'nope';
        });

        $this->validator->check([], ['email' => 'unique']);

        $this->assertFalse($called, 'a rule should not run against absent input');
    }
}
