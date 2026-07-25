<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Interface\Http\Controller;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\PackageModel;
use Silaris\Modules\Shared\Domain\Exception\DomainException;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;
use Symfony\Component\HttpFoundation\Response;

final class PackagePaymentRequired extends DomainException
{
    public static function make(string $detail): self
    {
        return new self($detail);
    }

    public function errorCode(): string
    {
        return 'package.payment_required';
    }
}

final class DeliveryOtpInvalid extends DomainException
{
    public static function make(string $detail): self
    {
        return new self($detail);
    }

    public function errorCode(): string
    {
        return 'package.delivery_otp_invalid';
    }
}

final class PackageTransitionForbidden extends DomainException
{
    public static function make(string $from, string $action): self
    {
        return new self("Colis en statut « {$from} » : action « {$action} » impossible");
    }

    public function errorCode(): string
    {
        return 'package.invalid_transition';
    }
}

/**
 * Colis LCL — réception (étiquettes QR), empotage, dépotage, remise.
 * Chaque jalon alimente la timeline du dossier (visible portail + suivi public).
 */
class PackageController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipment_id' => ['sometimes', 'uuid'],
            'container_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['received', 'stuffed', 'unstuffed', 'delivered'])],
            'search' => ['sometimes', 'string', 'max:40'],
        ]);

        return response()->json(
            PackageModel::with(['container:id,number', 'shipment:id,reference'])
                ->when($validated['shipment_id'] ?? null, fn ($q, $v) => $q->where('shipment_id', $v))
                ->when($validated['container_id'] ?? null, fn ($q, $v) => $q->where('container_id', $v))
                ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($validated['search'] ?? null, fn ($q, $v) => $q->whereLike('reference', '%'.strtoupper($v).'%'))
                ->orderBy('reference')
                ->cursorPaginate(100),
        );
    }

    /**
     * POST /v1/shipments/{id}/packages — réception entrepôt.
     * Crée N colis d'un coup, références séquencées sur le dossier, timeline mise à jour.
     */
    public function store(Request $request, string $shipmentId): JsonResponse
    {
        $shipment = ShipmentModel::findOrFail($shipmentId);
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:2000'],
            'description' => ['nullable', 'string', 'max:255'],
            'unit_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'unit_volume_m3' => ['nullable', 'numeric', 'min:0'],
        ]);

        $packages = DB::transaction(function () use ($shipment, $data, $request) {
            $created = [];
            for ($i = 0; $i < $data['count']; $i++) {
                $sequence = DB::selectOne('SELECT next_sequence(?, ?) AS value', [
                    $this->tenant->id(), "package:{$shipment->id}",
                ])->value;

                $created[] = PackageModel::create([
                    'shipment_id' => $shipment->id,
                    'reference' => sprintf('%s-C%04d', $shipment->reference, $sequence),
                    'description' => $data['description'] ?? null,
                    'weight_kg' => $data['unit_weight_kg'] ?? null,
                    'volume_m3' => $data['unit_volume_m3'] ?? null,
                    'status' => 'received',
                    'received_at' => now(),
                    'received_by' => $request->user()?->id,
                ]);
            }

            DB::table('shipment_events')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenant->id(),
                'shipment_id' => $shipment->id,
                'type' => 'tracking',
                'title' => "Réception entrepôt — {$data['count']} colis enregistrés",
                'payload' => json_encode(['packages' => count($created)]),
                'source' => 'internal',
                'actor_id' => $request->user()?->id,
                'occurred_at' => now(),
            ]);

            return $created;
        });

        return response()->json([
            'data' => $packages,
            'labels_url' => "/api/v1/shipments/{$shipment->id}/packages/labels",
        ], 201);
    }

    /**
     * GET /v1/shipments/{id}/packages/labels — planche d'étiquettes PDF (QR → suivi public).
     */
    public function labels(string $shipmentId): Response
    {
        $shipment = ShipmentModel::with('client:id,name,code')->findOrFail($shipmentId);
        $packages = PackageModel::where('shipment_id', $shipmentId)->orderBy('reference')->get();
        abort_if($packages->isEmpty(), 404, 'Aucun colis sur ce dossier');

        // Destination en toutes lettres — l'information anti-erreur d'empotage.
        $destinationName = DB::table('ports')->where('locode', $shipment->destination_locode)->value('name')
            ?? DB::table('airports')->where('locode', $shipment->destination_locode)->orWhere('iata', substr($shipment->destination_locode, 2))->value('name')
            ?? $shipment->destination_locode;

        $clientPhone = DB::table('party_contacts')
            ->where('party_id', $shipment->client_id)
            ->orderByDesc('is_primary')
            ->value('phone');

        $publicBase = rtrim(config('app.frontend_url', config('app.url')), '/');
        $writer = new Writer(new ImageRenderer(new RendererStyle(150, 0), new SvgImageBackEnd));
        $total = $packages->count();

        $labels = $packages->values()->map(fn (PackageModel $package, int $index) => [
            'reference' => $package->reference,
            'position' => $index + 1,
            'total' => $total,
            'client' => $shipment->client->name,
            'client_code' => $shipment->client->code,
            'client_phone' => $clientPhone,
            'destination_name' => $destinationName,
            'destination_locode' => $shipment->destination_locode,
            'origin_locode' => $shipment->origin_locode,
            'weight_kg' => $package->weight_kg,
            'volume_m3' => $package->volume_m3,
            'description' => $package->description,
            'qr_svg' => base64_encode($writer->writeString("{$publicBase}/track?q={$package->reference}")),
        ]);

        return Pdf::loadView('pdf.package-labels', [
            'labels' => $labels,
            'shipmentReference' => $shipment->reference,
            'mode' => $shipment->mode->value,
        ])->setPaper([0, 0, 283.5, 425.25]) // 100 × 150 mm
            ->download("etiquettes-{$shipment->reference}.pdf");
    }

    /**
     * POST /v1/packages/scan — jalon par référence (pistolet scanner ou saisie).
     * Actions : stuffed (exige container_id), unstuffed, delivered (exige delivered_to).
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:32'],
            'action' => ['required', Rule::in(['stuffed', 'unstuffed', 'delivered'])],
            'container_id' => ['required_if:action,stuffed', 'nullable', 'uuid', 'exists:containers,id'],
            'consolidation_id' => ['nullable', 'uuid', 'exists:consolidations,id'],
            'delivered_to' => ['required_if:action,delivered', 'nullable', 'string', 'max:255'],
            'force' => ['sometimes', 'boolean'],
            'otp' => ['nullable', 'string', 'size:6'],
        ]);

        $package = PackageModel::where('reference', strtoupper(trim($data['reference'])))->firstOrFail();

        $allowed = match ($package->status) {
            'received' => ['stuffed'],
            'stuffed' => ['unstuffed'],
            'unstuffed' => ['delivered'],
            default => [],
        };
        if (! in_array($data['action'], $allowed, true)) {
            throw PackageTransitionForbidden::make($package->status, $data['action']);
        }

        // Remise contre règlement : bloquée tant que les factures du dossier ne sont pas soldées
        // (statut rapatrié d'Odoo). Dérogation tracée : permission packages.force_delivery + force=true.
        $forced = false;
        if ($data['action'] === 'delivered' && $this->paymentRequiredBeforeDelivery()) {
            $unsettled = $this->unsettledInvoices($package->shipment_id);
            if ($unsettled !== []) {
                $canForce = ($data['force'] ?? false) && $request->user()->hasPermission('packages.force_delivery');
                if (! $canForce) {
                    throw PackagePaymentRequired::make(
                        'Remise bloquée — règlement en attente : '.implode(', ', $unsettled)
                        .'. Dérogation possible par un responsable (packages.force_delivery).',
                    );
                }
                $forced = true;
            }
        }

        // Confirmation de remise par OTP : le code envoyé au client doit être présenté
        // au magasinier. La dérogation (force + permission) couvre aussi ce contrôle (tracée).
        if ($data['action'] === 'delivered' && ! $forced && $this->deliveryOtpRequired()) {
            $this->verifyDeliveryOtp($package->shipment_id, $data['otp'] ?? null);
        }

        DB::transaction(function () use ($package, $data, $request, $forced): void {
            $containerNumber = null;
            $updates = ['status' => $data['action']];
            match ($data['action']) {
                'stuffed' => $updates += [
                    'container_id' => $data['container_id'],
                    'consolidation_id' => $data['consolidation_id'] ?? null,
                    'stuffed_at' => now(),
                ],
                'unstuffed' => $updates += ['unstuffed_at' => now()],
                'delivered' => $updates += ['delivered_at' => now(), 'delivered_to' => $data['delivered_to']],
            };
            $package->update($updates);

            if ($data['action'] === 'stuffed') {
                $containerNumber = DB::table('containers')->where('id', $data['container_id'])->value('number');
            }

            $titles = [
                'stuffed' => "Colis {$package->reference} empoté dans le conteneur {$containerNumber}",
                'unstuffed' => "Colis {$package->reference} dépoté",
                'delivered' => 'Colis '.$package->reference.' remis à '.($data['delivered_to'] ?? '')
                    .($forced ? ' — DÉROGATION : facture non soldée, remise autorisée par '.$request->user()->fullName() : ''),
            ];
            DB::table('shipment_events')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenant->id(),
                'shipment_id' => $package->shipment_id,
                'type' => 'tracking',
                'title' => $titles[$data['action']],
                'payload' => json_encode(['package' => $package->reference, 'action' => $data['action'], 'forced' => $forced]),
                'source' => 'internal',
                'actor_id' => $request->user()?->id,
                'occurred_at' => now(),
            ]);
        });

        return response()->json($package->fresh('container:id,number'));
    }

    /**
     * POST /v1/packages/request-delivery-otp — génère le code de retrait et l'envoie AU CLIENT.
     * Le magasinier ne voit jamais le code : la réponse ne contient que le destinataire masqué.
     */
    public function requestDeliveryOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:32']]);
        $package = PackageModel::with('shipment:id,reference,client_id')
            ->where('reference', strtoupper(trim($data['reference'])))
            ->firstOrFail();

        $recipient = $this->clientRecipient($package->shipment->client_id);
        if ($recipient === null) {
            throw DeliveryOtpInvalid::make('Aucun email de contact client — renseignez un contact ou un compte portail.');
        }

        $code = (string) random_int(100000, 999999);
        Cache::put(
            "delivery_otp:{$package->shipment_id}",
            ['hash' => Hash::make($code), 'attempts' => 0],
            now()->addMinutes(30),
        );

        Mail::raw(
            "Code de retrait SILARIS : {$code}\n\n"
            ."Dossier {$package->shipment->reference} — présentez ce code au magasinier pour retirer vos colis.\n"
            .'Valable 30 minutes.',
            fn ($message) => $message->to($recipient)->subject("Code de retrait — dossier {$package->shipment->reference}"),
        );

        DB::table('shipment_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'shipment_id' => $package->shipment_id,
            'type' => 'tracking',
            'title' => 'Code de retrait envoyé au client',
            'payload' => json_encode(['channel' => 'email']),
            'source' => 'internal',
            'actor_id' => $request->user()?->id,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'sent' => true,
            'sent_to' => $this->maskEmail($recipient),
            'expires_in_minutes' => 30,
        ]);
    }

    private function deliveryOtpRequired(): bool
    {
        $settings = DB::table('tenants')->where('id', $this->tenant->id())->value('settings');
        $decoded = json_decode((string) $settings, true) ?: [];

        return (bool) ($decoded['require_delivery_otp'] ?? true);
    }

    private function verifyDeliveryOtp(string $shipmentId, ?string $otp): void
    {
        if ($otp === null) {
            throw DeliveryOtpInvalid::make('Code de retrait requis — demandez au client le code reçu (« Envoyer le code » si besoin).');
        }

        $key = "delivery_otp:{$shipmentId}";
        $entry = Cache::get($key);
        if ($entry === null) {
            throw DeliveryOtpInvalid::make('Aucun code actif ou code expiré — renvoyez un code au client.');
        }
        if ($entry['attempts'] >= 5) {
            Cache::forget($key);
            throw DeliveryOtpInvalid::make('Trop de tentatives — renvoyez un nouveau code au client.');
        }
        if (! Hash::check($otp, $entry['hash'])) {
            $entry['attempts']++;
            Cache::put($key, $entry, now()->addMinutes(30));
            throw DeliveryOtpInvalid::make('Code incorrect ('.(5 - $entry['attempts']).' essai(s) restant(s)).');
        }

        Cache::forget($key); // usage unique — couvre le retrait en cours pour ce dossier
    }

    /** Email du client : compte portail d'abord, sinon contact principal. */
    private function clientRecipient(string $clientId): ?string
    {
        return DB::table('portal_accounts')->where('party_id', $clientId)->where('is_active', true)->value('email')
            ?? DB::table('party_contacts')->where('party_id', $clientId)->orderByDesc('is_primary')->value('email');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).str_repeat('*', max(2, strlen($local) - 1)).'@'.$domain;
    }

    /** Réglage tenant : remise conditionnée au règlement (défaut : oui). */
    private function paymentRequiredBeforeDelivery(): bool
    {
        $settings = DB::table('tenants')->where('id', $this->tenant->id())->value('settings');
        $decoded = json_decode((string) $settings, true) ?: [];

        return (bool) ($decoded['require_payment_before_delivery'] ?? true);
    }

    /**
     * Factures non soldées du dossier (type facture, émises, non payées).
     * Aucune facture émise = également bloquant (remise contre facture réglée).
     *
     * @return list<string>
     */
    private function unsettledInvoices(string $shipmentId): array
    {
        $invoices = DB::table('invoices')
            ->where('shipment_id', $shipmentId)
            ->where('type', 'invoice')
            ->where('status', '<>', 'draft')
            ->get(['number', 'payment_status']);

        if ($invoices->isEmpty()) {
            return ['aucune facture émise sur ce dossier'];
        }

        return $invoices
            ->filter(fn ($invoice) => $invoice->payment_status !== 'paid')
            ->map(fn ($invoice) => "{$invoice->number} ({$invoice->payment_status})")
            ->values()
            ->all();
    }
}
