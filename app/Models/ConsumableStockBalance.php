<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableStockBalance extends Model
{
    protected $table = 'consumable_stock_balances';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['quantity' => 'integer'];

    public function lot()
    {
        return $this->belongsTo(ConsumableStockLot::class, 'lot_id');
    }

    public function place()
    {
        return $this->belongsTo(ConsumableStockPlace::class, 'place_id');
    }
}
