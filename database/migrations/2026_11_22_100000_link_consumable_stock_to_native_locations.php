<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $places = DB::table('consumable_stock_places')->orderBy('id')->get()->keyBy('id');
            $mapped = [];
            $visiting = [];
            $link = function ($id) use (&$link, &$mapped, &$visiting, $places) {
                if (isset($mapped[$id])) {
                    return $mapped[$id];
                }
                if (isset($visiting[$id]) || ! isset($places[$id])) {
                    throw new RuntimeException('Invalid stock location hierarchy. No inventory has been changed.');
                }
                $visiting[$id] = true;
                $place = $places[$id];
                $parentId = $place->parent_id ? $link($place->parent_id) : null;
                $location = $place->location_id
                    ? DB::table('locations')->where('id', $place->location_id)->whereNull('deleted_at')->first()
                    : null;
                if ($location && in_array($location->id, $mapped, true)) {
                    $location = null;
                }
                if (! $location) {
                    $query = DB::table('locations')->whereNull('deleted_at')
                        ->where('name', $place->name)->where('company_id', $place->company_id)
                        ->where('parent_id', $parentId)
                        ->whereNotIn('id', array_values($mapped))
                        ->whereNotIn('id', $places->whereNotNull('location_id')->pluck('location_id'));
                    $matches = $query->limit(2)->get();
                    $location = $matches->count() === 1 ? $matches->first() : null;
                }
                if (! $location) {
                    $name = $place->name;
                    if (DB::table('locations')->whereNull('deleted_at')->where('name', $name)
                        ->where('company_id', $place->company_id)->where('parent_id', $parentId)->exists()) {
                        $name .= ' [stock '.$place->id.']';
                    }
                    $locationId = DB::table('locations')->insertGetId([
                        'name' => $name, 'parent_id' => $parentId, 'company_id' => $place->company_id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $locationId = $location->id;
                }
                DB::table('consumable_stock_places')->where('id', $id)->update(['location_id' => $locationId]);
                $mapped[$id] = $locationId;
                unset($visiting[$id]);

                return $locationId;
            };
            foreach ($places->keys() as $id) {
                $link($id);
            }
        });
    }

    public function down(): void
    {
        // Native locations may now be used by other inventory. Preserve them and the ledger links.
    }
};
