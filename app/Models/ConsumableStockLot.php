<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableStockLot extends Model
{
    protected $table = 'consumable_stock_lots';
    protected $guarded = [];
    protected $casts = ['expires_on' => 'date', 'quarantined' => 'boolean', 'unidentified' => 'boolean'];

    public function consumable()
    {
        return $this->belongsTo(Consumable::class);
    }

    public function balances()
    {
        return $this->hasMany(ConsumableStockBalance::class, 'lot_id');
    }

    public function usable(bool $expiryRequired): bool
    {
        return !$this->quarantined && !$this->unidentified
            && (!$expiryRequired || $this->expires_on !== null)
            && ($this->expires_on === null || !$this->expires_on->lt(today()));
    }
}
