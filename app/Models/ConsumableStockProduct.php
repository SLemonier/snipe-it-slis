<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableStockProduct extends Model
{
    protected $table = 'consumable_stock_products';

    protected $primaryKey = 'consumable_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['expiry_required' => 'boolean', 'opening_assignments' => 'integer', 'expected_qty' => 'integer'];

    /** @return BelongsTo<Consumable, $this> */
    public function consumable(): BelongsTo
    {
        return $this->belongsTo(Consumable::class);
    }
}
