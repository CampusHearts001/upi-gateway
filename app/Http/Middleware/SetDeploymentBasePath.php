<?php

namespace App\Http\Middleware;

use App\Support\DeploymentUrl;
use Closure;
use Illuminate\Http\Request;

class SetDeploymentBasePath
{
    public function handle(Request $request, Closure $next)
    {
        $base = rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');
        // During fresh setup, SCRIPT_NAME is the only configured mount point.
        if (! is_file(config('installer.env_path', base_path('.env')))) {
            $base = DeploymentUrl::basePath($request->server->all());
        }
        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        if ($base !== '' && $path !== $base && ! str_starts_with($path, $base.'/')) {
            abort(404);
        }
        $server = $request->server->all();
        // Requests in Laravel always entered through index.php, including the
        // routed /install.php compatibility URL. Native install.php bypasses this middleware.
        $script = 'index.php';
        $server['SCRIPT_NAME'] = $base.'/'.$script;
        $server['PHP_SELF'] = $server['SCRIPT_NAME'];
        $server['SCRIPT_FILENAME'] = public_path($script);
        $request->initialize(
            $request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $server, $request->getContent(),
        );

        return $next($request);
    }
}
