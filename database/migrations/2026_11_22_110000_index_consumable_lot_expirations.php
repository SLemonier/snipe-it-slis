<?php

use App\Models\ConsumableStockLot;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ConsumableStockLot::whereNotNull('expires_on')->chunkById(200, function ($lots) {
            foreach ($lots as $lot) {
                $lot->forceSyncCalendarEvents();
            }
        });
    }

    public function down(): void
    {
        // Preserve the additive calendar index and all source lots on code rollback.
    }
};
