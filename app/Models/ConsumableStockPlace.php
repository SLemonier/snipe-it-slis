<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableStockPlace extends Model
{
    use CompanyableTrait;

    protected $table = 'consumable_stock_places';
    protected $guarded = [];
    protected $casts = ['company_id' => 'integer', 'parent_id' => 'integer'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function displayPath(): string
    {
        $names = [$this->name];
        $seen = [$this->id];
        $parent = $this->parent;
        while ($parent && !in_array($parent->id, $seen, true)) {
            array_unshift($names, $parent->name);
            $seen[] = $parent->id;
            $parent = $parent->parent;
        }

        return implode(' / ', $names);
    }
}
