<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // RBAC : toute vérification `can:<module>.<action>` passe par le catalogue de permissions.
        // Retourne null (et non false) pour les autres types d'utilisateurs → Gates/Policies classiques possibles.
        Gate::before(function ($user, string $ability) {
            if ($user instanceof UserModel && str_contains($ability, '.')) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        // Page publique de suivi : strict, par IP.
        RateLimiter::for('public-tracking', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
            Limit::perDay(300)->by($request->ip()),
        ]);

        // Login : anti brute-force par IP + email.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email'))),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // API interne : par utilisateur (ou IP avant auth).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()->id ?? $request->ip()));
    }
}
