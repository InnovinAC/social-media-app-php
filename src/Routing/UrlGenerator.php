<?php

declare(strict_types=1);

namespace Phpvin\Routing;

use InvalidArgumentException;

/**
 * Turns route names into URLs. Inject it where you need to build links; there
 * is no global `route()` helper to reach for.
 */
final class UrlGenerator
{
    /**
     * @param string $baseUrl  Origin for absolute(), e.g. in emails.
     * @param string $assetUrl Origin for asset(). Empty means relative paths,
     *                         which is what you want unless assets live on a
     *                         CDN. A hard-coded origin breaks the moment the
     *                         app is reached on a different host or port.
     */
    public function __construct(
        private readonly Router $router,
        private string $baseUrl = '',
        private string $assetUrl = '',
    ) {}

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function setAssetUrl(string $assetUrl): void
    {
        $this->assetUrl = rtrim($assetUrl, '/');
    }

    /**
     * Build the path for a named route.
     *
     * @param array<string, string|int> $parameters Placeholder values; leftovers
     *                                              become query string pairs.
     */
    public function route(string $name, array $parameters = []): string
    {
        $route = $this->router->findByName($name)
            ?? throw new InvalidArgumentException("No route is named [$name].");

        $path = $route->toPath($parameters);

        $query = array_diff_key($parameters, array_flip($this->placeholders($route)));

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    /**
     * Build an absolute URL for a named route.
     *
     * @param array<string, string|int> $parameters
     */
    public function absolute(string $name, array $parameters = []): string
    {
        return $this->baseUrl . $this->route($name, $parameters);
    }

    public function asset(string $path): string
    {
        return $this->assetUrl . '/' . ltrim($path, '/');
    }

    /** @return list<string> */
    private function placeholders(Route $route): array
    {
        preg_match_all('/\{(\w+)[?*]?\}/', $route->uri, $matches);

        return $matches[1];
    }
}
