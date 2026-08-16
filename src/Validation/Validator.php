<?php

declare(strict_types=1);

namespace Phpvin\Validation;

use Closure;
use InvalidArgumentException;
use Phpvin\Http\UploadedFile;

/**
 * Rule-based input validation.
 *
 *     $clean = $validator->validate($request->all(), [
 *         'email'    => 'required|email|max:255',
 *         'password' => 'required|min:12|confirmed',
 *         'age'      => 'nullable|integer|between:13,120',
 *     ]);
 *
 * validate() returns only the keys that were named in the rules, so the result
 * is safe to hand to Model::fill() without re-filtering.
 */
final class Validator
{
    /**
     * Rules registered with extend(), by name.
     *
     * @var array<string, Closure(mixed, list<string>, array<string, mixed>, string): (string|bool|null)>
     */
    private array $custom = [];

    /**
     * Register a rule.
     *
     * The callback receives the value, the rule's arguments, the whole input
     * and the field name. Return a string to fail with that message, false to
     * fail with a generic one, or null/true to pass.
     *
     *     $validator->extend('unique', function ($value, array $args) {
     *         [$table, $column] = $args;
     *
     *         return User::query()->where($column, '=', $value)->exists()
     *             ? 'That value is already taken.'
     *             : null;
     *     });
     *
     *     $validator->validate($input, ['email' => 'required|email|unique:users,email']);
     *
     * This is how the rules the framework cannot ship get added: `unique` and
     * `exists` need a database, and the ORM here is optional.
     *
     * @param Closure(mixed, list<string>, array<string, mixed>, string): (string|bool|null) $check
     */
    public function extend(string $rule, Closure $check): self
    {
        $this->custom[$rule] = $check;

        return $this;
    }

    public function hasRule(string $rule): bool
    {
        return isset($this->custom[$rule]);
    }

    /**
     * @param  array<string, mixed>              $data
     * @param  array<string, string|list<string>> $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException when any rule fails
     */
    public function validate(array $data, array $rules): array
    {
        $errors = $this->check($data, $rules);

        if ($errors !== []) {
            throw new ValidationException($errors, $this->withoutSecrets($data));
        }

        return array_intersect_key($data, $rules);
    }

    /**
     * Run the rules and return the errors instead of throwing.
     *
     * @param  array<string, mixed>               $data
     * @param  array<string, string|list<string>> $rules
     * @return array<string, list<string>>
     */
    public function check(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $parsed = $this->parse($fieldRules);
            $value = $data[$field] ?? null;

            $nullable = in_array('nullable', array_column($parsed, 0), true);

            if ($nullable && $this->isEmpty($value)) {
                continue;
            }

            foreach ($parsed as [$rule, $arguments]) {
                if ($rule === 'nullable') {
                    continue;
                }

                // Everything except `required` passes vacuously on empty input,
                // so a blank optional field does not collect five errors.
                if ($rule !== 'required' && $this->isEmpty($value)) {
                    continue;
                }

                $message = $this->apply($rule, $value, $arguments, $field, $data);

                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>         $arguments
     * @param  array<string, mixed> $data
     * @return string|null          An error message, or null when the value passes.
     */
    private function apply(string $rule, mixed $value, array $arguments, string $field, array $data): ?string
    {
        $label = $this->label($field);

        if (isset($this->custom[$rule])) {
            $result = ($this->custom[$rule])($value, $arguments, $data, $field);

            return match (true) {
                is_string($result) => $result,
                $result === false => "$label is not valid.",
                default => null,
            };
        }

        return match ($rule) {
            'file' => ! $value instanceof UploadedFile || ! $value->isValid()
                ? ($value instanceof UploadedFile ? $value->errorMessage() : "$label must be a file.")
                : null,

            // Checks the bytes, not the client's Content-Type, which is a
            // field the uploader controls.
            'image' => ! $value instanceof UploadedFile || ! $value->isImage()
                ? "$label must be an image."
                : null,

            'mimes' => ! $value instanceof UploadedFile || ! in_array((string) $value->extension(), $arguments, true)
                ? sprintf('%s must be a file of type: %s.', $label, implode(', ', $arguments))
                : null,

            'required' => $this->isEmpty($value)
                ? "$label is required."
                : null,

            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false
                ? "$label must be a valid email address."
                : null,

            'url' => filter_var((string) $value, FILTER_VALIDATE_URL) === false
                ? "$label must be a valid URL."
                : null,

            'numeric' => ! is_numeric($value)
                ? "$label must be a number."
                : null,

            'integer' => filter_var($value, FILTER_VALIDATE_INT) === false
                ? "$label must be a whole number."
                : null,

            'boolean' => ! in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off'], true)
                ? "$label must be true or false."
                : null,

            'alpha' => preg_match('/^[\pL]+$/u', (string) $value) !== 1
                ? "$label may only contain letters."
                : null,

            'alpha_num' => preg_match('/^[\pL\pN]+$/u', (string) $value) !== 1
                ? "$label may only contain letters and numbers."
                : null,

            'alpha_dash' => preg_match('/^[\pL\pN_-]+$/u', (string) $value) !== 1
                ? "$label may only contain letters, numbers, dashes and underscores."
                : null,

            'min' => $this->size($value) < (float) $this->argument($arguments, 0, 'min')
                ? sprintf('%s must be at least %s.', $label, $this->sizeUnit($value, $arguments[0]))
                : null,

            'max' => $this->size($value) > (float) $this->argument($arguments, 0, 'max')
                ? sprintf('%s may not be more than %s.', $label, $this->sizeUnit($value, $arguments[0]))
                : null,

            'between' => $this->size($value) < (float) $this->argument($arguments, 0, 'between')
                || $this->size($value) > (float) $this->argument($arguments, 1, 'between')
                    ? sprintf('%s must be between %s and %s.', $label, $arguments[0], $arguments[1])
                    : null,

            'in' => ! in_array((string) $value, $arguments, true)
                ? sprintf('%s must be one of: %s.', $label, implode(', ', $arguments))
                : null,

            'confirmed' => ($data[$field . '_confirmation'] ?? null) !== $value
                ? "$label does not match its confirmation."
                : null,

            'same' => ($data[$this->argument($arguments, 0, 'same')] ?? null) !== $value
                ? sprintf('%s must match %s.', $label, $this->label($arguments[0]))
                : null,

            'different' => ($data[$this->argument($arguments, 0, 'different')] ?? null) === $value
                ? sprintf('%s must be different from %s.', $label, $this->label($arguments[0]))
                : null,

            'regex' => preg_match($this->argument($arguments, 0, 'regex'), (string) $value) !== 1
                ? "$label is not in the expected format."
                : null,

            'date' => strtotime((string) $value) === false
                ? "$label must be a valid date."
                : null,

            default => throw new InvalidArgumentException("Unknown validation rule [$rule]."),
        };
    }

    /**
     * @param  string|list<string> $rules
     * @return list<array{0: string, 1: list<string>}>
     */
    private function parse(string|array $rules): array
    {
        $list = is_string($rules) ? explode('|', $rules) : $rules;
        $parsed = [];

        foreach ($list as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            // `regex:` arguments can contain colons and commas, so they are
            // taken verbatim rather than split.
            if (str_starts_with($rule, 'regex:')) {
                $parsed[] = ['regex', [substr($rule, 6)]];

                continue;
            }

            [$name, $arguments] = array_pad(explode(':', $rule, 2), 2, null);

            $parsed[] = [$name, $arguments === null ? [] : explode(',', $arguments)];
        }

        return $parsed;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value instanceof UploadedFile) {
            return $value->error === UPLOAD_ERR_NO_FILE;
        }

        return $value === null
            || $value === []
            || (is_string($value) && trim($value) === '');
    }

    /**
     * Numeric values are measured by magnitude, arrays by count, uploads by
     * kilobytes, and everything else by string length.
     */
    private function size(mixed $value): float
    {
        if ($value instanceof UploadedFile) {
            return $value->sizeInKilobytes();
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return (float) count($value);
        }

        return (float) mb_strlen((string) $value);
    }

    private function sizeUnit(mixed $value, string $limit): string
    {
        if ($value instanceof UploadedFile) {
            return "$limit kB";
        }

        if (is_numeric($value)) {
            return $limit;
        }

        return is_array($value) ? "$limit items" : "$limit characters";
    }

    /**
     * @param list<string> $arguments
     */
    private function argument(array $arguments, int $index, string $rule): string
    {
        return $arguments[$index]
            ?? throw new InvalidArgumentException("The [$rule] rule is missing a required argument.");
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (str_contains((string) $key, 'password') || str_contains((string) $key, 'token')) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
