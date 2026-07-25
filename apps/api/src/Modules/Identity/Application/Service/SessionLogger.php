<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Application\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SessionLogger
{
    public function log(string $tenantId, string $userId, string $event, Request $request): void
    {
        DB::table('sessions_log')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'event' => $event,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
