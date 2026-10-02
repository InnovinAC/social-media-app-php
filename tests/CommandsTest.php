<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Http\Commands;
use Phpvin\Http\Request;
use Phpvin\Http\Response;

final class CommandsTest extends TestCase
{
    #[Test]
    public function it_starts_empty(): void
    {
        $commands = Commands::make();

        $this->assertTrue($commands->isEmpty());
        $this->assertSame(0, $commands->count());
        $this->assertSame('[]', $commands->toResponse()->body());
    }

    #[Test]
    public function each_operation_records_its_name_and_payload(): void
    {
        $commands = Commands::make()->append('#list', '<li>a</li>');

        $this->assertSame(
            [['command' => 'append', 'selector' => '#list', 'html' => '<li>a</li>']],
            $commands->all(),
        );
    }

    #[Test]
    public function operations_keep_their_order(): void
    {
        $commands = Commands::make()
            ->prepend('#notes', '<li>first</li>')
            ->remove('#empty-state')
            ->text('#count', '1');

        $this->assertSame(
            ['prepend', 'remove', 'text'],
            array_column($commands->all(), 'command'),
        );
    }

    #[Test]
    public function it_covers_the_dom_insertion_operations(): void
    {
        $commands = Commands::make()
            ->append('#a', 'x')
            ->prepend('#b', 'x')
            ->replace('#c', 'x')
            ->inner('#d', 'x')
            ->before('#e', 'x')
            ->after('#f', 'x')
            ->remove('#g');

        $this->assertSame(
            ['append', 'prepend', 'replace', 'inner', 'before', 'after', 'remove'],
            array_column($commands->all(), 'command'),
        );
    }

    #[Test]
    public function an_attribute_can_be_set_or_removed(): void
    {
        $commands = Commands::make()
            ->attr('#x', 'disabled', 'disabled')
            ->attr('#x', 'disabled', null);

        $this->assertSame('disabled', $commands->all()[0]['value']);
        $this->assertNull($commands->all()[1]['value']);
    }

    #[Test]
    public function null_survives_json_encoding_so_the_client_can_tell_remove_from_set(): void
    {
        $body = Commands::make()->attr('#x', 'disabled', null)->toResponse()->body();

        $this->assertStringContainsString('"value":null', $body);
    }

    #[Test]
    public function it_can_trigger_a_client_event_with_a_detail_payload(): void
    {
        $commands = Commands::make()->trigger('note:added', ['id' => 7]);

        $this->assertSame([
            'command' => 'trigger',
            'event' => 'note:added',
            'detail' => ['id' => 7],
            'selector' => null,
        ], $commands->all()[0]);
    }

    #[Test]
    public function it_can_invoke_a_command_registered_on_the_client(): void
    {
        $commands = Commands::make()->call('confetti', ['count' => 50]);

        $this->assertSame([
            'command' => 'call',
            'name' => 'confetti',
            'arguments' => ['count' => 50],
        ], $commands->all()[0]);
    }

    #[Test]
    public function the_response_carries_the_command_content_type(): void
    {
        $response = Commands::make()->remove('#x')->toResponse(201);

        $this->assertSame(201, $response->status());
        $this->assertSame(Commands::CONTENT_TYPE, $response->getHeader('content-type'));
    }

    #[Test]
    public function slashes_and_unicode_are_left_readable(): void
    {
        $body = Commands::make()->append('#x', '<a href="/notes/3">é</a>')->toResponse()->body();

        $this->assertStringContainsString('/notes/3', $body);
        $this->assertStringContainsString('é', $body);
    }

    #[Test]
    public function a_controller_can_return_the_builder_directly(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'providers' => [],
        ]);

        $app->router()->post('/notes', fn (): Commands => Commands::make()->remove('#empty-state'));

        $response = $app->handle(Request::create('POST', '/notes'));

        $this->assertSame(Commands::CONTENT_TYPE, $response->getHeader('content-type'));
        $this->assertSame(
            [['command' => 'remove', 'selector' => '#empty-state']],
            json_decode($response->body(), true),
        );
    }

    // --- the trigger header, usable on any response ------------------------

    #[Test]
    public function any_response_can_fire_a_client_event(): void
    {
        $response = Response::html('<li>a</li>')->triggerClient('note:added', ['id' => 3]);

        $this->assertSame(
            ['note:added' => ['id' => 3]],
            json_decode((string) $response->getHeader(Response::TRIGGER_HEADER), true),
        );
    }

    #[Test]
    public function several_events_accumulate_in_the_one_header(): void
    {
        $response = Response::html('')
            ->triggerClient('first')
            ->triggerClient('second', ['n' => 2]);

        $events = json_decode((string) $response->getHeader(Response::TRIGGER_HEADER), true);

        $this->assertSame(['first', 'second'], array_keys($events));
        $this->assertSame(['n' => 2], $events['second']);
    }
}
