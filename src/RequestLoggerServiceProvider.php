<?php

namespace Bilfeldt\RequestLogger;

use Bilfeldt\RequestLogger\Commands\PruneRequestLogsCommand;
use Bilfeldt\RequestLogger\Listeners\LogRequest;
use Bilfeldt\RequestLogger\Middleware\LogRequestMiddleware;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class RequestLoggerServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/request-logger.php', 'request-logger');

        Event::listen(RequestHandled::class, LogRequest::class);
    }

    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/request-logger.php' => config_path('request-logger.php'),
            ], ['config', 'request-logger-config']);

            $this->publishes([
                __DIR__.'/../database/migrations/create_request_logs_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_request_logs_table.php'),
                // you can add any number of migrations here
            ], ['migrations', 'request-logger-migrations']);

            $this->commands([
                PruneRequestLogsCommand::class,
            ]);
        }

        $this->registerMiddlewareAlias();
        $this->bootMacros();

        // TODO: Register command PruneRequestLogsCommand::class);
    }

    private function registerMiddlewareAlias(): void
    {
        $this->app
            ->make(Router::class)
            ->aliasMiddleware('requestlog', LogRequestMiddleware::class);
    }

    private function bootMacros(): void
    {
        Request::macro('enableLog', function (string ...$drivers): Request {
            $loggers = $this->attributes->get('log', []);

            if (empty($drivers)) {
                $loggers[] = RequestLoggerFacade::getDefaultDriver();
            }

            foreach ($drivers as $driver) {
                $loggers[] = $driver;
            }

            $this->attributes->set('log', $loggers);

            return $this;
        });

        /**
         * Mask an array's values by keys, supporting nested keys with case-insensitive matching.
         * If the provided mask is a single character, it will be repeated to match the original value's length.
         */
        Arr::macro('maskCaseInsensitive', function (array $array, array $keys, string $character = '*'): array {
            $lowerKeys = array_map('mb_strtolower', $keys);

            // Iterate over the flattened array keys
            foreach (array_keys(Arr::dot($array)) as $dottedKey) {
                $lowerDottedKey = mb_strtolower($dottedKey);
                // Try to match the lowercased dotted key or its parent key, so we can also mask nested arrays.
                $matchedKey = in_array($lowerDottedKey, $lowerKeys, true)
                    ? $dottedKey
                    : (in_array(Str::beforeLast($lowerDottedKey, '.'), $lowerKeys, true)
                        ? Str::beforeLast($dottedKey, '.')
                        : null);

                if ($matchedKey !== null) {
                    $value = Arr::get($array, $matchedKey);
                    $masked = match (true) {
                        mb_strlen($character) > 1 => $character,
                        ! filled($value) => $value,
                        is_string($value) || is_int($value) => Str::mask((string) $value, $character, 0),
                        default => str_repeat($character, 8), // default mask: '********'
                    };

                    if ($value !== $masked && Arr::has($array, $matchedKey)) {
                        Arr::set($array, $matchedKey, $masked);
                    }
                }
            }

            return $array;
        });
    }
}
