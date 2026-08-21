# Testing your application

[← back to the README](../README.md)

Because nothing in phpvin reaches for a global, a request is just a function
call. The toolkit builds on that: no HTTP server, no superglobals, no browser
driver. It is `Application::handle()` with assertions wrapped round the response.

```php
use Phpvin\Testing\ApplicationTestCase;

final class NotesTest extends ApplicationTestCase
{
    protected function createApplication(): Application
    {
        return require __DIR__ . '/../bootstrap.php';
    }

    public function test_a_note_can_be_added(): void
    {
        $this->withSession(['user_id' => 1])
            ->post('/notes', ['body' => 'hello'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('success');
    }
}
```

Build the application the same way your front controller does, so the test
exercises the real wiring rather than a parallel setup that drifts.

## Making requests

```php
$this->get('/posts');
$this->post('/posts', ['title' => 'Hello']);
$this->put('/posts/1', [...]);        $this->patch(...);   $this->delete(...);
$this->json('POST', '/api/posts', [...]);   // Accept: application/json
$this->call('REPORT', '/odd', [...], ['x-custom' => 'header']);
```

Two things happen for you, because otherwise every test would begin with the
same boilerplate:

- **The CSRF token is attached** to anything that is not a read. A browser gets
  it from the rendered form; a test should not have to fetch a page to scrape
  one. `withoutCsrfToken()` withholds it for a single request, which is how you
  prove the guard works.
- **The session persists between requests**, and flash data ages exactly once
  per request, so a value flashed during a redirect is readable on the page
  you land on, and gone the request after.

```php
$this->withSession(['user_id' => 7]);   // seed state
$this->followingRedirects()->post('/login', [...])->assertSee('Dashboard');
$this->useInMemoryDatabase();           // a fresh SQLite database for this test
```

## Assertions

Every one returns `$this`, so a test reads as a sentence.

| | |
| --- | --- |
| Status | `assertOk`, `assertCreated`, `assertNoContent`, `assertNotFound`, `assertForbidden`, `assertUnauthorised`, `assertStatus`, `assertSuccessful`, `assertServerError` |
| Redirects | `assertRedirect(?string $to)` |
| Headers | `assertHeader`, `assertHeaderMissing` |
| Body | `assertSee`, `assertDontSee`, `assertBody` |
| JSON | `assertJson`, `assertJsonPath`, `assertJsonCount`, `json()` |
| Session | `assertSessionHas`, `assertSessionMissing` |
| Validation | `assertValidationErrors`, `assertNoValidationErrors` |

`assertSee` escapes its needle by default, so `assertSee("Tom & Jerry")` matches
the escaped HTML actually on the page. Pass `escape: false` to match markup.

`assertValidationErrors` works on both shapes a failure can take (a redirect
with errors flashed, and a 422 with a JSON body), so the same assertion covers
the form and the API.

## Failure messages

A failing assertion prints the status and the body it actually got:

```
Expected status 201.
Got 200 with body:
[{"command":"prepend","selector":"#notes","html":"<li class=\"note\"…
```

That is deliberate. "Failed asserting that 500 matches 200" tells you a test
broke; it does not tell you your migration threw.

## Fuzzing your own parsers

The framework ships `bin/fuzz`, which throws input no reasonable caller would
send at every parser it owns and requires each one to fail *deliberately*: a
documented exception is a pass, a `TypeError` is not. If your application has a
parser of its own (a webhook body, an import file, a signed URL), the same
harness shape is worth copying:

```php
foreach ($cases as $input) {
    try {
        $parser->parse($input);
    } catch (MalformedWebhook) {
        // Fine: understood and refused.
    } catch (Throwable $e) {
        $this->fail(sprintf(
            '%s reached code that did not expect it: %s',
            var_export($input, true),
            $e->getMessage(),
        ));
    }
}
```

The value is in the second `catch`. Asserting that bad input throws proves very
little, because almost anything throws on bad input; asserting *which* error it
throws is what separates a case you handled from one you happened to survive.

One caution learned the hard way: check that the harness can actually fail.
Break the guard on purpose, confirm it gets caught, then put it back. An
assertion too weak to fail reads as coverage while providing none.

## What this does not do

There is no browser automation and no JavaScript execution. The AJAX layer is
tested from the server side (assert the command list, assert the fragment),
and the client half is covered by the framework's own suite. If you need to
drive a real browser, reach for a browser tool; this is not trying to be one.
