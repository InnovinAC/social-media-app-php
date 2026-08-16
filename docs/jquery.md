# The jQuery layer

[← back to the README](../README.md)

phpvin ships `phpvin.js`, a jQuery plugin in three layers: attributes for the
common cases, behaviours for your own JavaScript, and server-driven commands.
No build step, no component model, no client-side router.

```php
// routes/web.php
Assets::register($routes);   // serves phpvin.js, nothing to copy
```

```twig
<meta name="csrf-token" content="{{ csrf_token }}">
...
<script src="{{ asset('js/jquery.min.js') }}"></script>
<script src="{{ route('phpvin.script') }}"></script>
```

That's the setup. Now markup does the work:

```html
<form method="post" action="/notes" data-remote data-target="#notes" data-swap="prepend">
    <input name="body">
    <span class="error" data-error-for="body"></span>
    <button data-disable-with="Saving…">Add</button>
</form>

<a href="/notes/3"
   data-method="delete"
   data-confirm="Delete this note?"
   data-target="#note-3"
   data-swap="remove">&times;</a>
```

And the controller returns a fragment, with no JSON, no view model, no branching on
client type:

```php
return $this->views->fragment('notes/row', ['note' => $note], 201);
```

### Attributes

| Attribute | Effect |
| --- | --- |
| `data-remote` | Submit a form (or follow a link) over AJAX instead of navigating |
| `data-method` | `PUT`/`PATCH`/`DELETE` from an anchor, sent as POST with `_method` |
| `data-confirm` | `confirm()` before anything else runs |
| `data-target` | Selector the response acts on; defaults to the triggering element |
| `data-swap` | `replace`, `inner`, `append`, `prepend`, `before`, `after`, `remove`, `none` |
| `data-disable-with` | Temporary button label while the request is in flight |
| `data-error-for` | Where a validation message for that field is written |
| `data-reset="false"` | Keep the form's values after a successful submit |

### What it handles for you

- **CSRF on every request.** An `$.ajaxPrefilter` reads the meta tag and adds
  `X-CSRF-Token` to every same-origin, state-changing call, including plain
  `$.post` you write yourself.
- **Redirects.** XHR follows redirects itself and would hand jQuery a whole HTML
  document to swap into a `<div>`. [`UnobtrusiveJavaScript`](src/Middleware/UnobtrusiveJavaScript.php)
  turns an AJAX redirect into a 204 with the destination in a header, and the
  script navigates. One controller action serves both JS and no-JS clients.
- **Validation errors.** A 422 populates each `[data-error-for="field"]` and
  marks the input `.is-invalid`.
- **Dynamic content.** Every handler is delegated from `document`, so markup
  that arrives in a response is live the moment it lands.

For anything the attributes do not cover, events fire on the triggering
element: `phpvin:before` (cancellable), `phpvin:success`, `phpvin:error` and
`phpvin:complete`.

### Behaviours: your own JavaScript, wired the same way

Attributes cover the common cases. For anything else, register a behaviour and
it binds to every matching element, including elements that arrive later in a
response:

```js
phpvin.behavior('char-counter', function ($input, data) {
    var $output = $(data.output);

    function count() { $output.text($input.val().length + ' / ' + data.max); }

    $input.on('input.counter', count);
    count();

    return { destroy: function () { $input.off('.counter'); } };
});
```

```html
<input data-behavior="char-counter" data-output="#chars" data-max="200">
```

The element's `data-*` attributes arrive as the second argument. Returning a
`destroy` method registers teardown, which runs when jQuery removes the element,
including when a swap replaces it. Several behaviours can share an element:
`data-behavior="one two"`. Registering after page load still picks up what is
already on screen, so load order does not matter.

Custom swap strategies work the same way:

```js
phpvin.swap.register('fade-in', function ($target, $incoming) {
    $incoming.hide(); $target.append($incoming); $incoming.fadeIn(200);
});
```

### Commands: one response, any number of changes

A fragment can only change one place on the page. A command list changes as
many as you like, in one round trip:

```php
return Commands::make()
    ->prepend('#notes', $this->views->render('notes/row', ['note' => $note]))
    ->remove('#empty-state')
    ->text('#note-count', (string) $count)
    ->focus('input[name=body]')
    ->trigger('note:added', ['id' => $note->key()]);
```

Controllers can return the builder directly. Available out of the box: `append`,
`prepend`, `replace`, `inner`, `before`, `after`, `remove`, `addClass`,
`removeClass`, `toggleClass`, `attr`, `text`, `value`, `focus`, `scrollTo`,
`trigger`, `redirect`, `reload`, `log`.

Beyond that, `call()` invokes anything the page registered:

```js
phpvin.command('confetti', function (args) { /* whatever you like */ });
```

```php
Commands::make()->call('confetti', ['count' => 50]);
```

That is the "anything at all" hatch, and it is deliberately the *only* one: a
response can invoke commands you have already written, and nothing you have
not. There is no command that evaluates code from the response body, so a
compromised or spoofed response cannot introduce new behaviour.

Any response can also fire client events without switching to a command list:

```php
return $views->fragment('notes/row', ['note' => $note])
    ->triggerClient('note:added', ['id' => $note->key()]);
```

### Calling it yourself

`phpvin.request()` and the jQuery plugin surface are public:

```js
phpvin.request($('#panel'), { url: '/panel', method: 'GET', swap: 'inner' });

$('#panel').phpvin('load', '/panel');   // fetch into the element
$(html).phpvin();                       // wire behaviours on detached markup
```

### Getting jQuery

jQuery is not vendored into this repository. One command fetches it:

```bash
make vendor-js
```

`make serve` runs it for you if `skeleton/public/js/jquery.min.js` is missing.
