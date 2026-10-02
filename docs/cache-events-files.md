# Cache, events and file responses

[← back to the README](../README.md)

## Cache

PSR-16, so anything in the ecosystem drops in. Two stores ship: `FileStore`
works everywhere PHP does, `ArrayStore` lasts one request and is the obvious
choice in tests.

```php
final class Dashboard
{
    public function __construct(private readonly CacheInterface $cache) {}

    public function stats(): array
    {
        $stats = $this->cache->get('dashboard.stats');

        if ($stats === null) {
            $stats = $this->expensiveQuery();
            $this->cache->set('dashboard.stats', $stats, 300);
        }

        return $stats;
    }
}
```

Configure it with `cache.path`. Leave it out and you get `ArrayStore`. A file
cache nobody configured is a directory nobody knew was filling up.

Things worth knowing:

- **`false` and `null` are values, not misses.** `has()` is the way to ask, and
  `get()` takes a default for when you want one call.
- **Entries are written to a temporary file and renamed into place.** Rename is
  atomic, so a reader sees the old entry or the new one, never half of either.
  A short write (a full disk) is caught rather than leaving a truncated entry
  that reads back as corrupt forever.
- **Keys are hashed before they touch disk**, so a key containing a slash, or a
  name you would rather not have sitting in a directory listing, cannot become
  a path.
- **Nothing expires entries in the background.** A cache that stops the world to
  tidy up is worse than one that uses a little more disk. Run `prune()` from
  cron.
- **The clock is injectable.** `new FileStore($path, fn () => $now)` lets a test
  sit exactly on an expiry boundary instead of sleeping.

## Events

PSR-14. Events are objects and listeners are keyed by class name, so there are
no magic strings to typo and your editor can find every listener.

```php
$events->listen(UserRegistered::class, SendWelcomeEmail::class);
$events->listen(UserRegistered::class, fn (UserRegistered $e) => $log->info(...));

$events->dispatch(new UserRegistered($user));
```

A listener given as a class string is built by the container when the event
fires, not before, so registering one costs nothing.

Listeners registered against a parent class or an interface hear every event
that matches, which is how you audit a whole family at once:

```php
$events->listen(DomainEvent::class, RecordToAuditLog::class);
```

They run in registration order. An event implementing `StoppableEventInterface`
ends the chain when it says so. That is checked before each listener, so an event
stopped before dispatch never starts.

## Sending files

```php
return FileResponse::download('/var/exports/report.csv');
return FileResponse::download($path, 'Q3 Report.csv');
return FileResponse::inline($path, contentType: 'application/pdf');
```

The body is streamed in chunks rather than read into memory, so a 2 GB export
costs the same as a 2 KB one.

The filename gets more care than it looks like it needs. It is sent both quoted
and RFC 5987 encoded, because a name with a comma truncates the header for old
clients and a non-ASCII one is unreadable to them. Directory components, quotes,
backslashes and newlines are stripped; a filename is attacker-supplied often
enough that header injection through it is a real bug, not a theoretical one.

## Streaming a response

```php
return StreamedResponse::make(function (): void {
    $out = fopen('php://output', 'w');

    foreach (Order::query()->get() as $order) {
        fputcsv($out, [$order->id, $order->total]);
    }
}, headers: ['Content-Type' => 'text/csv']);
```

Nothing is held in memory, so the size of the export stops mattering. The trade
is real: once the first byte is written the status and headers are gone, so an
error halfway through cannot become a 500. Do the work that can fail before you
start writing.

`capture()` runs the callback and returns what it wrote, which is how you assert
on a streamed response without a live connection.
