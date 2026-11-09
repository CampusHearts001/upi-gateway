<?php

namespace App\Support;

class DeploymentFilesystem
{
    /** Create missing runtime directories only; never chmod, replace or empty existing paths. */
    public static function prepare(string $applicationPath): array
    {
        $root = realpath($applicationPath);
        $failures = [];
        if ($root === false) {
            return ['Application directory could not be resolved.'];
        }
        foreach (['bootstrap/cache', 'storage/app/public', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
            $path = $root;
            foreach (explode('/', $directory) as $segment) {
                $parent = realpath($path);
                $path .= DIRECTORY_SEPARATOR.$segment;
                if (is_dir($path)) {
                    continue;
                }
                if ($parent === false || ($parent !== $root && ! str_starts_with($parent, $root.DIRECTORY_SEPARATOR))
                    || file_exists($path) || is_link($path) || ! @mkdir($path, 0755)) {
                    $failures[] = $directory;
                    break;
                }
            }
        }

        return array_values(array_unique($failures));
    }
}
