<?php

declare(strict_types=1);

namespace Phpvin\View;

/**
 * Resolves a template name to a file inside the template root, or to nothing.
 *
 * Template names reach engines from application code, and application code
 * routinely builds them from a URL: `/page/{slug}` rendering "pages/$slug" is
 * the obvious way to write a CMS. That makes the name attacker-controlled, and
 * a name like `../../config/database` then reads, or with the PHP engine
 * *executes*, a file that is none of the caller's business.
 *
 * Twig's loader has always refused to look outside its directories. This gives
 * the other engines the same guarantee, so that swapping the engine named in a
 * config file cannot quietly change what an application is exposed to.
 */
final class TemplatePath
{
    /**
     * @param  string      $extension Appended when the name does not end in it.
     * @return string|null The absolute path, or null when the name escapes the
     *                     root, is malformed, or the root does not exist.
     */
    public static function resolve(string $root, string $template, string $extension): ?string
    {
        // A null byte truncates the path inside the C library that ultimately
        // opens it, so "evil.php\0.html" would pass an extension check here and
        // open something else there. PHP blocks this in its own stream layer,
        // but a name containing one is hostile by construction: refuse it.
        if (str_contains($template, "\0")) {
            return null;
        }

        $template = str_ends_with($template, $extension) ? $template : $template . $extension;

        // Lexical check first. It costs nothing, it does not care whether the
        // path exists, and it catches `a/../../etc/passwd`, where the realpath
        // check below would be defeated by `a` simply not being there.
        foreach (explode('/', str_replace('\\', '/', $template)) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }

        $base = realpath($root);

        if ($base === false) {
            return null;
        }

        $path = $base . '/' . ltrim($template, '/');
        $real = realpath($path);

        if ($real === false) {
            // No such file. Hand back the path anyway so the caller reports a
            // plain "not found": the name was legitimate, the file was not
            // there, and those two failures should not look alike.
            return $path;
        }

        // The name was clean but a symlink inside the root can still point out
        // of it, so the resolved path is what actually has to be contained.
        return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
    }
}
