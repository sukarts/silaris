<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Résout le tenant AVANT authentification (login, reset), sans contexte utilisateur.
 * Ordre : sous-domaine (slug.domaine) → en-tête X-Tenant-Slug. Retourne null si indéterminé.
 *
 * Utilisé pour SCOPER la recherche de compte pré-auth : ne fait qu'ajouter un filtre
 * tenant_id, jamais d'élévation. Combiné à la garde anti-ambiguïté du login, il élimine
 * la sélection arbitraire quand un email existe sur plusieurs tenants.
 */
final class GuestTenantResolver
{
    public function resolve(Request $request): ?string
    {
        $slug = $this->slugFromHost($request) ?? $request->header('X-Tenant-Slug');
        if ($slug === null || $slug === '') {
            return null;
        }

        return DB::table('tenants')->where('slug', $slug)->where('is_active', true)->value('id');
    }

    private function slugFromHost(Request $request): ?string
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        // {slug}.silaris.app → au moins 3 segments, premier segment = slug (hors www/api/app).
        if (count($parts) < 3) {
            return null;
        }
        $candidate = $parts[0];

        return in_array($candidate, ['www', 'api', 'app', 'localhost'], true) ? null : $candidate;
    }
}
