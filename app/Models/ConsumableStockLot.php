<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableStockLot extends Model
{
    protected $table = 'consumable_stock_lots';

    protected $guarded = [];

    protected $casts = ['expires_on' => 'date', 'quarantined' => 'boolean', 'unidentified' => 'boolean'];

    /** @return BelongsTo<Consumable, $this> */
    public function consumable(): BelongsTo
    {
        return $this->belongsTo(Consumable::class);
    }

    /** @return HasMany<ConsumableStockBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(ConsumableStockBalance::class, 'lot_id');
    }

    public function usable(bool $expiryRequired): bool
    {
        return ! $this->quarantined && ! $this->unidentified
            && (! $expiryRequired || $this->expires_on !== null)
            && ($this->expires_on === null || ! $this->expires_on->lt(today()));
    }
}
