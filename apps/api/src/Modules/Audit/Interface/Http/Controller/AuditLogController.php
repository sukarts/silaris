<?php

declare(strict_types=1);

namespace Silaris\Modules\Audit\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Audit\Infrastructure\Persistence\Model\AuditLogModel;

class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['sometimes', 'string', 'max:64'],
            'entity_id' => ['sometimes', 'uuid'],
            'user_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', 'string', 'max:64'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        return response()->json(
            AuditLogModel::query()
                ->when($validated['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
                ->when($validated['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
                ->when($validated['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
                ->when($validated['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
                ->when($validated['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
                ->when($validated['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', $v))
                ->orderByDesc('occurred_at')
                ->cursorPaginate(50),
        );
    }
}
