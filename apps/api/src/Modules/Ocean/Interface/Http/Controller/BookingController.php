<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\BookingModel;
use Silaris\Modules\Tracking\Application\Service\TrackingSubscriber;

class BookingController
{
    public function __construct(private readonly TrackingSubscriber $subscriber) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['requested', 'confirmed', 'rolled', 'cancelled'])],
            'shipment_id' => ['sometimes', 'uuid'],
        ]);

        return response()->json(
            BookingModel::with(['voyage.vessel', 'shipment:id,reference'])
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($validated['shipment_id'] ?? null, fn ($q, $s) => $q->where('shipment_id', $s))
                ->orderByDesc('created_at')
                ->cursorPaginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'uuid', 'exists:shipments,id'],
            'carrier_id' => ['required', 'uuid', 'exists:carriers,id'],
            'voyage_id' => ['nullable', 'uuid', 'exists:voyages,id'],
            'booking_number' => ['nullable', 'string', 'max:32'],
            'vgm_cutoff' => ['nullable', 'date'],
            'doc_cutoff' => ['nullable', 'date'],
            'port_cutoff' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $booking = BookingModel::create($data);

        // Les conteneurs sont souvent affectés avant que la compagnie soit
        // arrêtée : le booking complète leurs abonnements en attente.
        $this->subscriber->attachCarrier(
            $data['shipment_id'],
            DB::table('carriers')->where('id', $data['carrier_id'])->value('scac'),
        );

        return response()->json($booking, 201);
    }

    public function update(Request $request, string $bookingId): JsonResponse
    {
        $booking = BookingModel::findOrFail($bookingId);
        $booking->update($request->validate([
            'voyage_id' => ['nullable', 'uuid', 'exists:voyages,id'],
            'booking_number' => ['nullable', 'string', 'max:32'],
            'vgm_cutoff' => ['nullable', 'date'],
            'doc_cutoff' => ['nullable', 'date'],
            'port_cutoff' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]));

        return response()->json($booking->fresh(['voyage.vessel']));
    }

    /** POST /v1/bookings/{id}/confirm */
    public function confirm(Request $request, string $bookingId): JsonResponse
    {
        $booking = BookingModel::where('status', 'requested')->findOrFail($bookingId);
        $data = $request->validate(['booking_number' => ['required', 'string', 'max:32']]);
        $booking->update([...$data, 'status' => 'confirmed', 'confirmed_at' => now()]);

        return response()->json($booking->fresh());
    }

    /** POST /v1/bookings/{id}/roll — report navire suivant (cut-off manqué). */
    public function roll(Request $request, string $bookingId): JsonResponse
    {
        $booking = BookingModel::whereIn('status', ['requested', 'confirmed'])->findOrFail($bookingId);
        $data = $request->validate(['voyage_id' => ['nullable', 'uuid', 'exists:voyages,id']]);
        $booking->update(['status' => 'rolled', ...$data]);

        return response()->json($booking->fresh());
    }
}
