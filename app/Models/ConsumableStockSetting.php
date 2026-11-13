<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableStockSetting extends Model
{
    protected $table = 'consumable_stock_settings';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'expiry_days' => 'integer', 'reminder_days' => 'integer'];

    public static function current(): self
    {
        return (\Illuminate\Support\Facades\Schema::hasTable('consumable_stock_settings') ? static::find(1) : null) ?? new self(['enabled' => false, 'expiry_days' => 60, 'reminder_days' => 7]);
    }
}
