<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Débouché comptable
    |--------------------------------------------------------------------------
    |
    | Comptabilité vers laquelle reporter les factures validées. « null » : aucune
    | — SILARIS se suffit à lui-même. « odoo » : report via le module OdooSync.
    | D'autres connecteurs (Sage, export FEC) s'ajoutent en implémentant le port
    | AccountingLedger et en s'enregistrant ici.
    |
    */
    'driver' => env('ACCOUNTING_DRIVER', 'null'),
];
