<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Silaris\Modules\Crm\Interface\Http\Controller\ComplaintController;
use Silaris\Modules\Crm\Interface\Http\Controller\OpportunityController;
use Silaris\Modules\Crm\Interface\Http\Controller\PartyContactController;
use Silaris\Modules\Crm\Interface\Http\Controller\PartyController;

Route::prefix('parties')->group(function (): void {
    Route::get('/', [PartyController::class, 'index'])->can('crm.read');
    Route::post('/', [PartyController::class, 'store'])->can('crm.create');
    Route::get('/{partyId}', [PartyController::class, 'show'])->whereUuid('partyId')->can('crm.read');
    Route::patch('/{partyId}', [PartyController::class, 'update'])->whereUuid('partyId')->can('crm.update');
    Route::delete('/{partyId}', [PartyController::class, 'destroy'])->whereUuid('partyId')->can('crm.delete');
    Route::post('/{partyId}/convert', [PartyController::class, 'convert'])->whereUuid('partyId')->can('crm.convert');
    Route::post('/{partyId}/contacts', [PartyContactController::class, 'store'])->whereUuid('partyId')->can('crm.update');
    Route::patch('/{partyId}/contacts/{contactId}', [PartyContactController::class, 'update'])->can('crm.update');
    Route::delete('/{partyId}/contacts/{contactId}', [PartyContactController::class, 'destroy'])->can('crm.update');
});

Route::prefix('opportunities')->group(function (): void {
    Route::get('/', [OpportunityController::class, 'index'])->can('crm.read');
    Route::post('/', [OpportunityController::class, 'store'])->can('crm.create');
    Route::patch('/{opportunityId}', [OpportunityController::class, 'update'])->whereUuid('opportunityId')->can('crm.update');
});

Route::prefix('complaints')->group(function (): void {
    Route::get('/', [ComplaintController::class, 'index'])->can('complaints.read');
    Route::post('/', [ComplaintController::class, 'store'])->can('complaints.create');
    Route::patch('/{complaintId}', [ComplaintController::class, 'update'])->whereUuid('complaintId')->can('complaints.update');
});
