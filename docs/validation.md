# Validation

[← back to the README](../README.md)

```php
$clean = $validator->validate($request->all(), [
    'email'    => 'required|email|max:255',
    'password' => 'required|min:12|confirmed',
    'age'      => 'nullable|integer|between:13,120',
]);
```

`validate()` returns only the keys named in the rules, so the result is safe to
hand straight to `fill()`. On failure it throws a `ValidationException`, and the
framework turns that into a redirect-back with errors and old input flashed, or
a 422 with a JSON body if the client wanted JSON.

Use `check()` instead to get the errors back rather than an exception.

Passwords and tokens are stripped from the flashed input, so they never come
back down the wire into a redrawn form.

## Rules

| | |
| --- | --- |
| Presence | `required`, `nullable` |
| Format | `email`, `url`, `date`, `regex:/…/` |
| Type | `numeric`, `integer`, `boolean` |
| Text | `alpha`, `alpha_num`, `alpha_dash` |
| Size | `min:n`, `max:n`, `between:a,b` |
| Comparison | `confirmed`, `same:field`, `different:field`, `in:a,b,c` |
| Files | `file`, `image`, `mimes:jpg,png` |

`min`, `max` and `between` measure numbers by magnitude, strings by length,
arrays by count, and uploads by kilobytes.

`nullable` short-circuits the rest of the rules when the field is empty, and
every rule except `required` passes vacuously on empty input, so a blank
optional field collects one error at most, not five.

## Custom rules

The rule set is open. `unique` and `exists` cannot ship, because the ORM is
optional, so this is how you add them:

```php
$validator->extend('unique', function (mixed $value, array $args): ?string {
    [$table, $column] = $args;

    return User::query()->where($column, '=', $value)->exists()
        ? 'That value is already taken.'
        : null;
});

$validator->validate($input, ['email' => 'required|email|unique:users,email']);
```

The callback receives the value, the rule's arguments, the whole input, and the
field name. Return a string to fail with that message, `false` to fail with a
generic one, or `null`/`true` to pass. Registering a name that already exists
overrides the built-in.

Register them once, in a service provider:

```php
final class ValidationProvider implements ServiceProvider
{
    public function register(Container $container, Application $app): void
    {
        $container->singleton(Validator::class, function (): Validator {
            return (new Validator())->extend('unique', ...);
        });
    }
}
```

## File uploads

```php
$data = $validator->validate(
    [...$request->all(), 'avatar' => $request->file('avatar')],
    ['avatar' => 'required|image|mimes:jpg,png|max:2048'],
);

$path = $request->file('avatar')->store(__DIR__ . '/../storage/uploads');
```

Everything the browser says about an upload is attacker-controlled. `mimeType()`
reads the actual bytes, `extension()` derives from that, and `store()` writes a
generated name. The client's filename never reaches the filesystem, and a
supplied name is stripped of any directory component.

The `image` and `mimes` rules check the detected type, so a PHP script uploaded
as `innocent.png` with `Content-Type: image/png` fails.

Array fields work too:

```php
foreach ($request->fileList('photos') as $photo) { ... }
```
