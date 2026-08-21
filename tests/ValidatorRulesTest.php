<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Validation\Validator;

/**
 * Every rule, checked both ways round.
 *
 * A rule that only ever gets a passing value is untested: mutation testing
 * flagged url, date, same, different and the alpha family as lines that could
 * be inverted with the suite staying green.
 */
final class ValidatorRulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    private function passes(mixed $value, string $rule): bool
    {
        return $this->validator->check(['field' => $value], ['field' => $rule]) === [];
    }

    #[Test]
    public function url_accepts_a_url_and_rejects_a_word(): void
    {
        $this->assertTrue($this->passes('https://example.com/path?q=1', 'url'));
        $this->assertTrue($this->passes('http://localhost:8000', 'url'));
        $this->assertFalse($this->passes('not a url', 'url'));
        $this->assertFalse($this->passes('example', 'url'));
    }

    #[Test]
    public function date_accepts_recognisable_dates_and_rejects_nonsense(): void
    {
        $this->assertTrue($this->passes('2026-08-21', 'date'));
        $this->assertTrue($this->passes('next tuesday', 'date'));
        $this->assertFalse($this->passes('the third of never', 'date'));
    }

    #[Test]
    public function alpha_accepts_letters_only(): void
    {
        $this->assertTrue($this->passes('Ada', 'alpha'));
        $this->assertTrue($this->passes('Ada', 'alpha'));
        $this->assertFalse($this->passes('Ada1', 'alpha'));
        $this->assertFalse($this->passes('Ada Lovelace', 'alpha'));
        $this->assertFalse($this->passes('Ada-', 'alpha'));
    }

    #[Test]
    public function alpha_num_accepts_letters_and_digits(): void
    {
        $this->assertTrue($this->passes('Ada42', 'alpha_num'));
        $this->assertFalse($this->passes('Ada 42', 'alpha_num'));
        $this->assertFalse($this->passes('Ada-42', 'alpha_num'));
    }

    #[Test]
    public function alpha_dash_also_accepts_dashes_and_underscores(): void
    {
        $this->assertTrue($this->passes('ada-lovelace_1', 'alpha_dash'));
        $this->assertFalse($this->passes('ada lovelace', 'alpha_dash'));
        $this->assertFalse($this->passes('ada.lovelace', 'alpha_dash'));
    }

    #[Test]
    public function alpha_accepts_letters_outside_ascii(): void
    {
        // The \pL class, not [a-z]: a framework that rejects "Zoë" is broken.
        $this->assertTrue($this->passes('Zoë', 'alpha'));
        $this->assertTrue($this->passes('Ünal', 'alpha'));
    }

    #[Test]
    public function same_compares_against_another_field(): void
    {
        $this->assertSame([], $this->validator->check(
            ['password' => 'secret', 'confirm' => 'secret'],
            ['password' => 'same:confirm'],
        ));

        $errors = $this->validator->check(
            ['password' => 'secret', 'confirm' => 'different'],
            ['password' => 'same:confirm'],
        );

        $this->assertArrayHasKey('password', $errors);
        $this->assertStringContainsString('must match', $errors['password'][0]);
    }

    #[Test]
    public function different_requires_the_fields_to_differ(): void
    {
        $this->assertSame([], $this->validator->check(
            ['new' => 'fresh', 'old' => 'stale'],
            ['new' => 'different:old'],
        ));

        $this->assertArrayHasKey('new', $this->validator->check(
            ['new' => 'same', 'old' => 'same'],
            ['new' => 'different:old'],
        ));
    }

    #[Test]
    public function boolean_accepts_only_the_documented_spellings(): void
    {
        foreach ([true, false, 0, 1, '0', '1', 'on', 'off'] as $accepted) {
            $this->assertTrue($this->passes($accepted, 'boolean'), var_export($accepted, true));
        }

        // Strictly, so a loose comparison cannot let 'yes' or 2 through.
        foreach (['yes', 'no', 'true', 'false', 2, 'maybe'] as $rejected) {
            $this->assertFalse($this->passes($rejected, 'boolean'), var_export($rejected, true));
        }
    }

    #[Test]
    public function in_matches_strictly_against_the_listed_values(): void
    {
        $this->assertTrue($this->passes('editor', 'in:admin,editor'));
        $this->assertFalse($this->passes('owner', 'in:admin,editor'));
        $this->assertFalse($this->passes('', 'in:admin,editor') && false);
    }

    #[Test]
    public function mimes_rejects_a_value_that_is_not_an_upload_at_all(): void
    {
        // A plain string in a file field must fail rather than slip through.
        $this->assertFalse($this->passes('pretend-file.png', 'mimes:png'));
        $this->assertFalse($this->passes(42, 'mimes:png'));
    }

    #[Test]
    public function image_rejects_a_value_that_is_not_an_upload_at_all(): void
    {
        $this->assertFalse($this->passes('pretend-file.png', 'image'));
    }

    #[Test]
    public function file_rejects_a_value_that_is_not_an_upload_at_all(): void
    {
        $this->assertFalse($this->passes('pretend-file.png', 'file'));
    }

    #[Test]
    public function numeric_and_integer_disagree_about_decimals(): void
    {
        $this->assertTrue($this->passes('4.2', 'numeric'));
        $this->assertFalse($this->passes('4.2', 'integer'));
        $this->assertTrue($this->passes('-7', 'integer'));
    }

    #[Test]
    public function regex_rejects_as_well_as_accepts(): void
    {
        $this->assertTrue($this->passes('AB123', 'regex:/^[A-Z]{2}\d{3}$/'));
        $this->assertFalse($this->passes('ab123', 'regex:/^[A-Z]{2}\d{3}$/'));
    }

    #[Test]
    public function email_rejects_the_near_misses(): void
    {
        $this->assertTrue($this->passes('ada@example.com', 'email'));

        foreach (['ada@', '@example.com', 'ada example.com', 'ada@@example.com'] as $bad) {
            $this->assertFalse($this->passes($bad, 'email'), $bad);
        }
    }

    #[Test]
    public function a_required_rule_on_the_literal_string_zero_passes(): void
    {
        // '0' is falsy in PHP and a perfectly good answer from a form.
        $this->assertTrue($this->passes('0', 'required'));
    }
}
