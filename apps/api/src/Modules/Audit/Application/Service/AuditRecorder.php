<?php

declare(strict_types=1);

namespace Silaris\Modules\Audit\Application\Service;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PortalAccountModel;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Throwable;

/**
 * Journal d'audit automatique : une ligne par mutation Eloquent des modèles
 * métier (created/updated/deleted), avec diff old/new filtré. Branché via un
 * listener wildcard (SharedServiceProvider) — aucune écriture manuelle requise.
 *
 * Fail-open : une erreur d'audit ne doit JAMAIS faire échouer la mutation
 * métier (loggée en revanche). L'immutabilité de la table est garantie côté DB.
 */
final class AuditRecorder
{
    /** Tables jamais auditées (journaux eux-mêmes, techniques, bruit). */
    private const EXCLUDED_TABLES = [
        'audit_logs', 'sessions_log', 'outbox_events',
        'notifications', 'notification_deliveries',
        'personal_access_tokens', 'cache', 'jobs',
    ];

    /** Colonnes masquées dans old/new (secrets). */
    private const MASKED = ['password_hash', 'mfa_secret', 'mfa_recovery_codes', 'token', 'key_hash'];

    /** Colonnes ignorées dans les diffs (bruit). */
    private const IGNORED = ['updated_at', 'created_at', 'remember_token', 'last_used_at', 'last_login_at'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function record(string $action, Model $model): void
    {
        try {
            $this->write($action, $model);
        } catch (Throwable $e) {
            report($e); // fail-open : l'audit ne casse jamais le métier
        }
    }

    private function write(string $action, Model $model): void
    {
        if (! str_starts_with($model::class, 'Silaris\\')) {
            return;
        }
        if (in_array($model->getTable(), self::EXCLUDED_TABLES, true)) {
            return;
        }
        // Hors contexte tenant (seed, commande système) : rien à rattacher.
        if (! $this->tenant->has()) {
            return;
        }

        [$old, $new] = match ($action) {
            'created' => [null, $this->sanitize($model->getAttributes())],
            'deleted' => [$this->sanitize($model->getOriginal()), null],
            default => $this->diff($model),
        };
        // Update sans changement significatif (ex. touch) : pas de bruit.
        if ($action === 'updated' && $new === []) {
            return;
        }

        // Selon le guard actif : UserModel (interne) ou PortalAccountModel (portail).
        /** @var Authenticatable|null $actor */
        $actor = Auth::user();
        $userId = $actor instanceof UserModel ? $actor->getKey() : null;
        $portalId = $actor instanceof PortalAccountModel ? $actor->getKey() : null;
        $request = app()->bound('request') ? request() : null;

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'user_id' => $userId,
            'portal_account_id' => $portalId,
            'action' => $action,
            'entity_type' => $model->getTable(),
            'entity_id' => is_string($model->getKey()) ? $model->getKey() : null,
            'old_values' => $old === null ? null : json_encode($old),
            'new_values' => $new === null ? null : json_encode($new),
            'ip' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500) ?: null,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [old, new] limités aux colonnes modifiées */
    private function diff(Model $model): array
    {
        $changes = $this->sanitize($model->getChanges());
        $old = array_intersect_key($this->sanitize($model->getOriginal()), $changes);

        return [$old, $changes];
    }

    /** @param array<string, mixed> $attributes */
    private function sanitize(array $attributes): array
    {
        foreach (self::IGNORED as $column) {
            unset($attributes[$column]);
        }
        foreach (self::MASKED as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = '•••';
            }
        }

        return $attributes;
    }
}
