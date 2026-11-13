<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableStockBalance extends Model
{
    protected $table = 'consumable_stock_balances';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['quantity' => 'integer'];

    /** @return BelongsTo<ConsumableStockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ConsumableStockLot::class, 'lot_id');
    }

    /** @return BelongsTo<ConsumableStockPlace, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(ConsumableStockPlace::class, 'place_id');
    }
}
