<?php

declare(strict_types=1);

namespace Phpvin\View;

use Closure;
use InvalidArgumentException;
use Phpvin\Http\Response;

/**
 * The view service controllers inject.
 *
 * It holds an Engine and adds the one thing an engine should not care about:
 * turning a rendered template into an HTTP Response.
 */
final class ViewFactory
{
    public function __construct(private readonly Engine $engine) {}

    /**
     * Build a view factory from the `views` config block.
     *
     *     'views' => ['engine' => 'twig', 'path' => __DIR__ . '/resources/views']
     *     'views' => ['engine' => 'php',  'path' => __DIR__ . '/resources/views']
     *     'views' => ['engine' => 'html', 'path' => __DIR__ . '/public/pages']
     *     'views' => ['engine' => 'none']            // API only
     *     'views' => ['engine' => fn () => new MyEngine()]
     *
     * @param  array<string, mixed> $config
     * @return self|null            Null when no engine is configured, which is
     *                              how an API-only application is expressed.
     */
    public static function fromConfig(array $config, bool $debug = false): ?self
    {
        // `??` already turns a null entry into the default, so only the
        // explicit opt-outs need checking here.
        $engine = $config['engine'] ?? 'twig';

        if ($engine === 'none' || $engine === false) {
            return null;
        }

        if ($engine instanceof Engine) {
            return new self($engine);
        }

        if ($engine instanceof Closure) {
            $built = $engine($config);

            if (! $built instanceof Engine) {
                throw new InvalidArgumentException(
                    'A view engine closure must return an instance of ' . Engine::class . '.',
                );
            }

            return new self($built);
        }

        $path = $config['path'] ?? throw new InvalidArgumentException(
            "The [$engine] view engine needs a 'path' in the views config.",
        );

        return new self(match ($engine) {
            'twig' => TwigEngine::create((string) $path, $debug, $config['cache'] ?? false),
            'php' => new PhpEngine((string) $path),
            'html' => new HtmlEngine((string) $path),
            default => throw new InvalidArgumentException(
                "Unknown view engine [$engine]. Use 'twig', 'php', 'html', 'none', "
                . 'or pass an Engine instance or closure.',
            ),
        });
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->engine->render($template, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function response(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->render($template, $data), $status);
    }

    /**
     * Render a partial for an AJAX swap.
     *
     * A fragment template is one without a layout: just the piece of markup
     * that is going into the page. The response is marked so client code and
     * caches can tell it apart from a full document.
     *
     * @param array<string, mixed> $data
     */
    public function fragment(string $template, array $data = [], int $status = 200): Response
    {
        return $this->response($template, $data, $status)
            ->header('X-Phpvin-Fragment', '1')
            ->header('Vary', 'X-Requested-With');
    }

    public function exists(string $template): bool
    {
        return $this->engine->exists($template);
    }

    public function share(string $key, mixed $value): void
    {
        $this->engine->share($key, $value);
    }

    public function supportsFunctions(): bool
    {
        return $this->engine instanceof SupportsFunctions;
    }

    public function addFunction(string $name, callable $callable): void
    {
        if (! $this->engine instanceof SupportsFunctions) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot expose functions to templates. Pass the value in with the '
                . 'template data instead, or use the Twig or PHP engine.',
                $this->engine::class,
            ));
        }

        $this->engine->addFunction($name, $callable);
    }
}
