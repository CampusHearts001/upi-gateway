<?php

namespace App\Providers;

use App\Models\PaymentAccount;
use App\Policies\PaymentAccountPolicy;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Never render detailed exception pages in production, even if an
        // environment file was accidentally deployed with APP_DEBUG=true.
        // In production, force insecure webhooks and insecure redirects off.
        if ($this->app->isProduction()) {
            config([
                'app.debug' => false,
                'gateway.webhook_allow_insecure' => false,
                'gateway.allow_insecure_redirects' => false,
            ]);
            if (config('database.default') !== 'mysql') {
                throw new RuntimeException('Production requires a MySQL database connection.');
            }
            URL::useOrigin(config('app.url'));
            URL::forceScheme('https');
        }

        // Named rate limiters are not registered here because the API throttle
        // is enforced inside AuthenticateApiKey (RateLimiter::tooManyAttempts /
        // RateLimiter::hit with explicit keys).  Route-level throttle:X,Y
        // middleware handles public endpoint limits via Laravel's built-in
        // ThrottleRequests middleware.

        Gate::policy(PaymentAccount::class, PaymentAccountPolicy::class);
        Gate::define('manual-payment-override', fn ($user) => $user->isAdmin());

        view()->share('csp_nonce', '');

        // ── Structured Queue Worker Lifecycle Logging ────────────────────────
        Queue::before(function (JobProcessing $event) {
            Log::info('Queue worker started processing job', [
                'job_id' => $event->job->getJobId(),
                'job_name' => class_basename($event->job->resolveName()),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
                'connection' => $event->connectionName,
            ]);
        });

        Queue::after(function (JobProcessed $event) {
            Log::info('Queue worker completed job successfully', [
                'job_id' => $event->job->getJobId(),
                'job_name' => class_basename($event->job->resolveName()),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
            ]);
        });

        Queue::exceptionOccurred(function (JobExceptionOccurred $event) {
            Log::warning('Queue worker job encountered exception and will retry if attempts remain', [
                'job_id' => $event->job->getJobId(),
                'job_name' => class_basename($event->job->resolveName()),
                'attempt' => $event->job->attempts(),
                'max_tries' => $event->job->maxTries(),
                'exception_class' => class_basename($event->exception),
            ]);
        });

        Queue::failing(function (JobFailed $event) {
            Log::error('Queue worker job failed permanently after exhausting all retries', [
                'job_id' => $event->job->getJobId(),
                'job_name' => class_basename($event->job->resolveName()),
                'attempts' => $event->job->attempts(),
                'exception_class' => class_basename($event->exception),
            ]);
        });
    }
}
