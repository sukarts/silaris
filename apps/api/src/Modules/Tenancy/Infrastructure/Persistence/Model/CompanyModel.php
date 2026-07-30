<?php

declare(strict_types=1);

namespace Silaris\Modules\Tenancy\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Silaris\Modules\Shared\Infrastructure\Persistence\BaseModel;
use Silaris\Modules\Shared\Infrastructure\Persistence\Concerns\BelongsToTenant;

class CompanyModel extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'companies';

    /** Dit si la clé FNE est posée, sans jamais l'exposer. */
    protected $appends = ['fne_api_key_set'];

    protected $casts = [
        'address' => 'array',
        'invoice_settings' => 'array',
        'shipment_settings' => 'array',
        'is_active' => 'bool',
        'fne_settings' => 'array',
        // Clé d'API DGI : chiffrée au repos, jamais lisible en base.
        'fne_api_key' => 'encrypted',
    ];

    /** Le secret d'API ne doit jamais sortir par la sérialisation JSON. */
    protected $hidden = ['fne_api_key'];

    public function branches(): HasMany
    {
        return $this->hasMany(BranchModel::class, 'company_id');
    }

    /**
     * La clé d'API est-elle configurée ? Booléen dérivé, pour que l'écran
     * indique « configurée » sans transporter le secret lui-même.
     */
    protected function fneApiKeySet(): Attribute
    {
        return Attribute::make(get: fn (): bool => ($this->getRawOriginal('fne_api_key') ?? '') !== '');
    }
}
