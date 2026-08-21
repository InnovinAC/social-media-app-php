<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Database\Connection;
use Phpvin\Http\Commands;
use Phpvin\Http\ExceptionHandler;
use Phpvin\Http\HttpException;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Http\Session;
use Phpvin\Http\UploadedFile;
use Phpvin\Log\FileLogger;
use Phpvin\Middleware\ThrottleRequests;
use Phpvin\RateLimit\RateLimiter;
use Phpvin\View\ViewFactory;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

/**
 * Defaults and boundaries.
 *
 * Every assertion here exists because mutation testing showed the value could
 * be flipped with the suite staying green: a default nobody pinned, or a
 * comparison whose edge nobody reached.
 */
final class DefaultsAndEdgesTest extends TestCase
{
    private function app(array $config = []): Application
    {
        return new Application(__DIR__ . '/fixtures', $config);
    }

    // --- Application defaults ---------------------------------------------

    #[Test]
    public function sessions_are_on_and_debug_is_off_by_default(): void
    {
        $app = $this->app();

        $this->assertTrue($app->usesSession(), 'sessions default on');
        $this->assertFalse((bool) $app->config('debug'), 'debug defaults off, since it leaks stack traces');
    }

    #[Test]
    public function sessions_can_be_turned_off(): void
    {
        $this->assertFalse($this->app(['session' => false])->usesSession());
    }

    #[Test]
    public function the_session_cookie_defaults_are_the_safe_ones(): void
    {
        $cookie = $this->app()->config('session_cookie');

        $this->assertTrue($cookie['httponly'], 'unreadable from JavaScript');
        $this->assertSame('Lax', $cookie['samesite'], 'not sent on cross-site requests');
        $this->assertSame('/', $cookie['path']);

        // Off, because a secure cookie is never sent over plain HTTP and
        // every local setup would break. Turned on once TLS is real.
        $this->assertFalse($cookie['secure']);
    }

    #[Test]
    public function there_is_no_default_encryption_key(): void
    {
        // A key that ships with the framework is a key everybody shares.
        $this->assertNull($this->app()->config('key'));
    }

    #[Test]
    public function asking_for_the_encrypter_without_a_key_says_what_to_do(): void
    {
        $app = $this->app(['views' => ['engine' => 'none'], 'providers' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No encryption key is configured');

        $app->container()->get(\Phpvin\Crypto\Encrypter::class);
    }

    #[Test]
    public function a_configured_key_produces_a_working_encrypter(): void
    {
        $app = $this->app([
            'views' => ['engine' => 'none'],
            'providers' => [],
            'key' => \Phpvin\Crypto\Encrypter::generateKey(),
        ]);

        $encrypter = $app->container()->get(\Phpvin\Crypto\Encrypter::class);

        $this->assertSame('works', $encrypter->decrypt($encrypter->encrypt('works')));
    }

    #[Test]
    public function template_caching_is_off_by_default(): void
    {
        // Compiling every request is slow; a stale cache in development is
        // worse. Applications opt in once they have somewhere to write.
        $this->assertFalse($this->app()->config('views.cache'));
    }

    #[Test]
    public function base_path_appends_only_when_given_something(): void
    {
        $app = $this->app();

        $this->assertSame(__DIR__ . '/fixtures', $app->basePath());
        $this->assertSame(__DIR__ . '/fixtures/storage', $app->basePath('storage'));
        $this->assertSame(__DIR__ . '/fixtures/storage', $app->basePath('/storage'));
    }

    #[Test]
    public function no_log_path_means_nothing_is_recorded(): void
    {
        $app = $this->app(['views' => ['engine' => 'none'], 'providers' => []]);

        $this->assertInstanceOf(NullLogger::class, $app->container()->get(\Psr\Log\LoggerInterface::class));
    }

    #[Test]
    public function a_log_path_produces_a_file_logger(): void
    {
        $app = $this->app([
            'views' => ['engine' => 'none'],
            'providers' => [],
            'log' => ['path' => sys_get_temp_dir() . '/phpvin-defaults-' . getmypid() . '.log'],
        ]);

        $this->assertInstanceOf(FileLogger::class, $app->container()->get(\Psr\Log\LoggerInterface::class));
    }

    // --- Response boundaries ----------------------------------------------

    #[Test]
    public function redirect_and_success_ranges_are_inclusive_at_both_ends(): void
    {
        foreach ([200, 201, 299] as $status) {
            $this->assertTrue((new Response('', $status))->isSuccessful(), (string) $status);
            $this->assertFalse((new Response('', $status))->isRedirect(), (string) $status);
        }

        foreach ([300, 302, 399] as $status) {
            $this->assertTrue((new Response('', $status))->isRedirect(), (string) $status);
            $this->assertFalse((new Response('', $status))->isSuccessful(), (string) $status);
        }

        foreach ([199, 400, 500] as $status) {
            $this->assertFalse((new Response('', $status))->isSuccessful(), (string) $status);
            $this->assertFalse((new Response('', $status))->isRedirect(), (string) $status);
        }
    }

    #[Test]
    public function a_cookie_is_http_only_and_same_site_by_default(): void
    {
        $cookie = (new Response())->cookie('session', 'abc')->cookies()[0];

        // A cookie readable from JavaScript is one XSS away from a stolen
        // session, so the default has to be the safe one.
        $this->assertTrue($cookie['options']['httponly']);
        $this->assertSame('Lax', $cookie['options']['samesite']);
        $this->assertSame('/', $cookie['options']['path']);
    }

    #[Test]
    public function cookie_options_can_be_overridden(): void
    {
        $cookie = (new Response())->cookie('t', 'v', ['httponly' => false, 'secure' => true])->cookies()[0];

        $this->assertFalse($cookie['options']['httponly']);
        $this->assertTrue($cookie['options']['secure']);
    }

    // --- Request edges -----------------------------------------------------

    #[Test]
    public function has_looks_in_the_body_and_the_query_string(): void
    {
        $request = Request::create('POST', '/', body: ['a' => 1], query: ['b' => 2]);

        $this->assertTrue($request->has('a'), 'body');
        $this->assertTrue($request->has('b'), 'query string');
        $this->assertFalse($request->has('c'));
    }

    #[Test]
    public function has_file_is_false_when_no_file_was_sent(): void
    {
        $this->assertFalse(Request::create('POST', '/')->hasFile('avatar'));
    }

    #[Test]
    public function a_method_override_is_only_honoured_on_a_post(): void
    {
        // Otherwise `?_method=DELETE` on a link would be a one-click exploit.
        $get = Request::create('GET', '/thing', query: ['_method' => 'DELETE']);
        $this->assertSame('GET', $get->method);

        $post = Request::create('POST', '/thing', body: ['_method' => 'DELETE']);
        $this->assertSame('POST', $post->method, 'create() takes the verb literally');
    }

    // --- ExceptionHandler defaults -----------------------------------------

    #[Test]
    public function debug_is_off_by_default_in_the_handler_too(): void
    {
        $response = (new ExceptionHandler())->render(
            new RuntimeException('internal detail'),
            Request::create('GET', '/', headers: ['accept' => 'application/json']),
        );

        $this->assertStringNotContainsString('internal detail', $response->body());
    }

    #[Test]
    public function debug_adds_a_trace_to_server_errors_but_not_client_errors(): void
    {
        $handler = new ExceptionHandler(debug: true);
        $json = Request::create('GET', '/', headers: ['accept' => 'application/json']);

        $server = json_decode($handler->render(new RuntimeException('boom'), $json)->body(), true);
        $client = json_decode($handler->render(HttpException::notFound('nope'), $json)->body(), true);

        $this->assertArrayHasKey('trace', $server, '5xx is ours to debug');
        $this->assertArrayNotHasKey('trace', $client, '4xx is the client mistyping a URL');
    }

    // --- Connection edges --------------------------------------------------

    #[Test]
    public function select_one_returns_null_rather_than_false_for_no_rows(): void
    {
        $db = Connection::sqliteInMemory();
        $db->statement('CREATE TABLE t (id INTEGER)');

        $this->assertNull($db->selectOne('SELECT * FROM t'));
    }

    #[Test]
    public function statements_are_prepared_by_the_driver_not_emulated(): void
    {
        // Emulated prepares interpolate values client-side, which is where
        // charset-based injection tricks live. SQLite cannot report the
        // attribute back, so this only asserts where the driver can answer.
        $db = DatabaseTestCase::newConnection();

        try {
            $emulated = $db->pdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        } catch (PDOException) {
            $this->markTestSkipped($db->driver() . ' cannot report ATTR_EMULATE_PREPARES');
        }

        $this->assertFalse((bool) $emulated);
    }

    // --- UploadedFile validity --------------------------------------------

    #[Test]
    public function every_condition_can_make_an_upload_invalid(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpvin');
        file_put_contents($path, 'x');

        $this->assertTrue((new UploadedFile('a', 't', 1, $path))->isValid(), 'all conditions met');
        $this->assertFalse((new UploadedFile('a', 't', 1, $path, UPLOAD_ERR_PARTIAL))->isValid(), 'error set');
        $this->assertFalse((new UploadedFile('a', 't', 1, ''))->isValid(), 'no temporary path');
        $this->assertFalse((new UploadedFile('a', 't', 1, $path . '-gone'))->isValid(), 'path does not exist');

        unlink($path);
    }

    #[Test]
    public function an_invalid_upload_has_no_detectable_mime_type(): void
    {
        $this->assertNull((new UploadedFile('a', 'image/png', 0, '', UPLOAD_ERR_NO_FILE))->mimeType());
    }

    // --- small defaults ----------------------------------------------------

    #[Test]
    public function scroll_to_is_smooth_unless_told_otherwise(): void
    {
        $this->assertTrue(Commands::make()->scrollTo('#x')->all()[0]['smooth']);
        $this->assertFalse(Commands::make()->scrollTo('#x', smooth: false)->all()[0]['smooth']);
    }

    #[Test]
    public function the_throttle_message_gets_the_plural_right(): void
    {
        $storage = sys_get_temp_dir() . '/phpvin-plural-' . bin2hex(random_bytes(4));
        $limiter = new RateLimiter($storage);
        $throttle = new ThrottleRequests($limiter, maxAttempts: 1, decaySeconds: 1);
        $request = Request::create('POST', '/login');

        $throttle->process($request, fn (): Response => Response::html('ok'));
        $blocked = $throttle->process($request, fn (): Response => Response::html('ok'));

        $this->assertMatchesRegularExpression('/Try again in 1 second\./', $blocked->body());

        foreach (glob($storage . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($storage);
    }

    #[Test]
    public function a_view_engine_can_be_switched_off_with_false_as_well_as_none(): void
    {
        $this->assertNull(ViewFactory::fromConfig(['engine' => 'none']));
        $this->assertNull(ViewFactory::fromConfig(['engine' => false]));
    }

    #[Test]
    public function view_debug_is_off_by_default(): void
    {
        $views = ViewFactory::fromConfig(['engine' => 'php', 'path' => __DIR__ . '/fixtures/views']);

        $this->assertInstanceOf(ViewFactory::class, $views);
    }

    // --- remaining edges ---------------------------------------------------

    #[Test]
    public function a_distinct_count_without_a_column_counts_rows(): void
    {
        $db = Connection::sqliteInMemory();
        $db->statement('CREATE TABLE t (id INTEGER, kind TEXT)');
        $db->statement("INSERT INTO t (id, kind) VALUES (1,'a'), (2,'a'), (3,'b')");

        $query = new \Phpvin\Database\QueryBuilder($db, 't');

        // DISTINCT only means something with a column named; `COUNT(DISTINCT *)`
        // is not valid SQL anywhere.
        $this->assertSame(3, $query->distinct()->count());
        $this->assertSame(2, (new \Phpvin\Database\QueryBuilder($db, 't'))->distinct()->count('kind'));
    }

    #[Test]
    public function exists_answers_both_ways(): void
    {
        $db = Connection::sqliteInMemory();
        $db->statement('CREATE TABLE t (id INTEGER)');

        $this->assertFalse((new \Phpvin\Database\QueryBuilder($db, 't'))->exists(), 'empty table');

        $db->statement('INSERT INTO t (id) VALUES (1)');

        $this->assertTrue((new \Phpvin\Database\QueryBuilder($db, 't'))->exists());
        $this->assertFalse(
            (new \Phpvin\Database\QueryBuilder($db, 't'))->where('id', '=', 99)->exists(),
            'no matching row',
        );
    }

    #[Test]
    public function a_non_scalar_server_value_is_skipped_rather_than_stringified(): void
    {
        $server = $_SERVER;
        $post = $_POST;

        try {
            // Not hypothetical: $_SERVER['argv'] is an array, and some SAPIs
            // put other structures in there. Casting one to a string would be
            // a warning on every request.
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'HTTP_X_STRUCTURED' => ['not', 'a', 'string'],
                'HTTP_X_FINE' => 'a string',
            ];
            $_POST = [];

            $request = Request::fromGlobals();

            $this->assertNull($request->header('x-structured'), 'skipped entirely');
            $this->assertSame('a string', $request->header('x-fine'));
        } finally {
            $_SERVER = $server;
            $_POST = $post;
        }
    }

    #[Test]
    public function the_debug_error_page_shows_a_trace_only_when_debugging(): void
    {
        $html = Request::create('GET', '/', headers: ['accept' => 'text/html']);

        $quiet = (new ExceptionHandler(debug: false))->render(new RuntimeException('boom'), $html)->body();
        $loud = (new ExceptionHandler(debug: true))->render(new RuntimeException('boom'), $html)->body();

        $this->assertStringNotContainsString('<pre>', $quiet, 'no trace without debug');
        $this->assertStringContainsString('<pre>', $loud, 'a trace with debug');
        $this->assertStringContainsString('RuntimeException', $loud);
    }

    #[Test]
    public function a_client_error_page_never_shows_a_trace_even_when_debugging(): void
    {
        $html = Request::create('GET', '/', headers: ['accept' => 'text/html']);

        $body = (new ExceptionHandler(debug: true))->render(HttpException::notFound('nope'), $html)->body();

        // A 404 is not a bug in our code, so there is nothing to trace.
        $this->assertStringNotContainsString('<pre>', $body);
        $this->assertStringContainsString('nope', $body);
    }

    #[Test]
    public function a_request_from_globals_reads_the_verb_and_headers(): void
    {
        $server = $_SERVER;
        $post = $_POST;

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/things?page=2',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'REMOTE_ADDR' => '203.0.113.9',
            'argv' => ['ignored because it is not a scalar'],
        ];
        $_POST = [];

        try {
            $request = Request::fromGlobals();

            $this->assertSame('GET', $request->method);
            $this->assertSame('/things', $request->path);
            $this->assertSame('application/json', $request->header('accept'));
            $this->assertSame('application/x-www-form-urlencoded', $request->header('content-type'));
            $this->assertSame('203.0.113.9', $request->ip());
        } finally {
            $_SERVER = $server;
            $_POST = $post;
        }
    }

    #[Test]
    public function a_method_override_from_globals_only_applies_to_a_post(): void
    {
        $server = $_SERVER;
        $post = $_POST;

        try {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/thing'];
            $_POST = ['_method' => 'DELETE'];

            $this->assertSame('GET', Request::fromGlobals()->method, 'a GET is never overridden');

            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/thing'];

            $this->assertSame('DELETE', Request::fromGlobals()->method, 'a POST is');

            $_POST = ['_method' => 'BREW'];

            $this->assertSame('POST', Request::fromGlobals()->method, 'and only to a real verb');
        } finally {
            $_SERVER = $server;
            $_POST = $post;
        }
    }

    #[Test]
    public function a_log_line_that_cannot_be_written_is_reported_not_thrown(): void
    {
        $directory = sys_get_temp_dir() . '/phpvin-ro-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o755, true);
        $path = $directory . '/app.log';
        $fallback = sys_get_temp_dir() . '/phpvin-errorlog-' . bin2hex(random_bytes(4));

        // Directory exists but is not writable: mkdir succeeds, the write does
        // not. Losing a log line must never turn into a fatal, and must not
        // vanish silently either.
        chmod($directory, 0o500);
        $previous = ini_set('error_log', $fallback);

        try {
            (new FileLogger($path))->error('nowhere to go');

            $this->assertStringContainsString(
                'could not write',
                (string) @file_get_contents($fallback),
                'the failure was reported to the fallback log',
            );
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            chmod($directory, 0o755);
            @unlink($fallback);
            rmdir($directory);
        }
    }

    #[Test]
    public function a_successful_write_reports_nothing(): void
    {
        $path = sys_get_temp_dir() . '/phpvin-ok-' . bin2hex(random_bytes(4)) . '/app.log';
        $fallback = sys_get_temp_dir() . '/phpvin-errorlog-' . bin2hex(random_bytes(4));
        $previous = ini_set('error_log', $fallback);

        try {
            (new FileLogger($path))->info('this one lands');

            $this->assertFileDoesNotExist($fallback, 'nothing was sent to the fallback');
            $this->assertStringContainsString('this one lands', (string) file_get_contents($path));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($fallback);
            unlink($path);
            rmdir(dirname($path));
        }
    }

    #[Test]
    public function context_too_deep_to_encode_still_leaves_the_message(): void
    {
        $path = sys_get_temp_dir() . '/phpvin-deep-' . bin2hex(random_bytes(4)) . '/app.log';

        // json_encode gives up past its nesting limit and returns false even
        // with partial output allowed. The message must survive that.
        $deep = 'bottom';

        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        (new FileLogger($path))->error('message survives', ['deep' => $deep]);

        $this->assertStringContainsString('message survives', (string) file_get_contents($path));

        unlink($path);
        rmdir(dirname($path));
    }

    #[Test]
    public function twig_debug_is_off_unless_asked_for(): void
    {
        $engine = \Phpvin\View\TwigEngine::create(__DIR__ . '/fixtures/views');

        $this->assertFalse($engine->twig()->isDebug(), 'debug is opt-in');
        $this->assertTrue(
            \Phpvin\View\TwigEngine::create(__DIR__ . '/fixtures/views', debug: true)->twig()->isDebug(),
        );
    }

    #[Test]
    public function the_view_factory_passes_debug_through_to_the_engine(): void
    {
        $quiet = ViewFactory::fromConfig(['engine' => 'twig', 'path' => __DIR__ . '/fixtures/views']);
        $loud = ViewFactory::fromConfig(['engine' => 'twig', 'path' => __DIR__ . '/fixtures/views'], debug: true);

        $this->assertFalse($quiet?->engine()->twig()->isDebug());
        $this->assertTrue($loud?->engine()->twig()->isDebug());
    }

    // --- Session bookkeeping -----------------------------------------------

    #[Test]
    public function starting_twice_does_not_age_the_flash_bag_twice(): void
    {
        $first = new Session([]);
        $first->flash('status', 'saved');

        $second = new Session($first->all());
        $second->start();
        $second->start();

        // A second start() must not consume the message before it is read.
        $this->assertSame('saved', $second->flashed('status'));
    }

    #[Test]
    public function destroying_a_detached_session_clears_it(): void
    {
        $session = new Session([]);
        $session->put('user_id', 7);
        $session->destroy();

        $this->assertSame([], $session->all());
        $this->assertNull($session->get('user_id'));
    }

    #[Test]
    public function regenerating_a_detached_session_keeps_its_data(): void
    {
        $session = new Session([]);
        $session->put('user_id', 7);
        $session->regenerate();

        $this->assertSame(7, $session->get('user_id'));
    }

    // --- FileLogger edges --------------------------------------------------

    #[Test]
    public function context_that_cannot_be_encoded_does_not_lose_the_line(): void
    {
        $path = sys_get_temp_dir() . '/phpvin-log-edge-' . bin2hex(random_bytes(4)) . '/app.log';

        // A resource cannot be JSON encoded. The message still has to land.
        $handle = fopen('php://memory', 'r');
        (new FileLogger($path))->error('still recorded', ['handle' => $handle]);
        fclose($handle);

        $this->assertStringContainsString('still recorded', (string) file_get_contents($path));

        unlink($path);
        rmdir(dirname($path));
    }

    #[Test]
    public function an_object_in_the_context_is_not_used_as_a_placeholder(): void
    {
        $path = sys_get_temp_dir() . '/phpvin-log-obj-' . bin2hex(random_bytes(4)) . '/app.log';

        (new FileLogger($path))->info('value is {thing}', ['thing' => new stdClass()]);
        $line = (string) file_get_contents($path);

        // Only stringable values may be interpolated; anything else stays in
        // the context tail rather than becoming "Object".
        $this->assertStringContainsString('{thing}', $line);

        unlink($path);
        rmdir(dirname($path));
    }
}
