<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class PublicStorageController extends Controller
{
    public function __invoke(string $path)
    {
        abort_if(preg_match('/[\x00-\x1f]/', rawurldecode($path)), 404);
        $parts = explode('/', str_replace('\\', '/', rawurldecode($path)));
        abort_if(in_array('', $parts, true) || collect($parts)->contains(fn ($part) => str_starts_with($part, '.')), 404);
        abort_if(preg_match('/\.(?:php[0-9]?|phtml|phar|sqlite[0-9]*|sql|log|bak|zip|tar|gz)$/i', $path), 404);
        $root = realpath(Storage::disk('public')->path(''));
        $file = realpath(Storage::disk('public')->path(implode('/', $parts)));
        abort_unless($root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

        // A public disk needs no symlink or shell command. Never serve private storage.
        $response = response()->file($file, ['X-Content-Type-Options' => 'nosniff']);
        if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'pdf', 'txt'], true)) {
            $response->setContentDisposition('attachment');
        }

        return $response;
    }
}
