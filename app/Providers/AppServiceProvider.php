<?php

namespace App\Providers;

use App\Bots\ConnectorRegistry;
use App\Bots\Connectors\CardaqExportConnector;
use App\Bots\Connectors\CorefyExportConnector;
use App\Bots\Connectors\MadfinExportConnector;
use App\Reports\Parsers\CardaqParser;
use App\Reports\Parsers\CorefyParser;
use App\Reports\Parsers\MadfinParser;
use App\Reports\Parsers\ParserRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ParserRegistry::class, fn ($app) => new ParserRegistry([
            $app->make(CardaqParser::class),
            $app->make(CorefyParser::class),
            $app->make(MadfinParser::class),
        ]));

        $this->app->singleton(ConnectorRegistry::class, fn ($app) => new ConnectorRegistry([
            $app->make(CardaqExportConnector::class),
            $app->make(CorefyExportConnector::class),
            $app->make(MadfinExportConnector::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
