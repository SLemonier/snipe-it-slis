<?php

namespace App\Console\Commands;

use App\Mail\ConsumableStockAlert;
use App\Models\Consumable;
use App\Models\ConsumableStockLot;
use App\Models\ConsumableStockPlace;
use App\Models\ConsumableStockProduct;
use App\Models\ConsumableStockSetting;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendConsumableStockAlerts extends Command
{
    protected $signature = 'snipeit:consumable-stock-alerts {--dry-run : Show due alerts without sending or recording them}';

    protected $description = 'Report consumable lot expiry and location stock shortages.';

    public function handle(): int
    {
        $settings = Setting::getSettings();
        $stockSettings = ConsumableStockSetting::current();
        if (! $stockSettings->enabled || ! $settings || ! $settings->alerts_enabled) {
            $this->info('Consumable stock alerts are disabled.');

            return self::SUCCESS;
        }
        $recipients = array_values(array_unique(array_filter(array_map('trim', explode(',', $settings->alert_email ?? '')),
            fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
        if (! $recipients && ! $this->option('dry-run')) {
            $this->error('No valid alert recipient configured.');

            return self::FAILURE;
        }

        $result = Cache::lock('consumable-stock-alerts', 300)->get(function () use ($stockSettings, $recipients) {
            $rows = [];
            $keys = [];
            $cutoff = today()->addDays($stockSettings->expiry_days);
            $lots = ConsumableStockLot::whereNotNull('expires_on')->whereDate('expires_on', '<=', $cutoff)
                ->whereHas('balances', fn ($q) => $q->where('quantity', '>', 0))
                ->with(['consumable', 'balances.place.parent'])->get();
            foreach ($lots as $lot) {
                $product = ConsumableStockProduct::find($lot->consumable_id);
                if (! $product || ! $lot->consumable) {
                    continue;
                }
                $kind = $lot->expires_on->lt(today()) ? 'expired' : 'upcoming';
                $key = hash('sha256', 'expiry:'.$lot->id.':'.$lot->expires_on->toDateString().':'.$kind.':'.$stockSettings->expiry_days);
                if (! $this->due($key, $stockSettings->reminder_days)) {
                    continue;
                }
                foreach ($lot->balances as $balance) {
                    if ($balance->quantity > 0) {
                        $rows[] = [
                            'consumable_id' => $lot->consumable_id, 'product' => $lot->consumable->name,
                            'lot' => $lot->number, 'expires_on' => $lot->expires_on->toDateString(),
                            'place' => $balance->place?->displayPath(), 'quantity' => $balance->quantity,
                            'unit' => $product->unit, 'kind' => $kind,
                        ];
                    }
                }
                $keys[] = $key;
            }

            foreach (DB::table('consumable_stock_minimums')->get() as $minimum) {
                $item = Consumable::find($minimum->consumable_id);
                $product = ConsumableStockProduct::find($minimum->consumable_id);
                $place = ConsumableStockPlace::find($minimum->place_id);
                if (! $item || ! $product || ! $place) {
                    continue;
                }
                $available = 0;
                $lots = ConsumableStockLot::where('consumable_id', $item->id)
                    ->with(['balances' => fn ($q) => $q->where('place_id', $place->id)])->get();
                foreach ($lots as $lot) {
                    if ($lot->usable($product->expiry_required)) {
                        $available += $lot->balances->sum('quantity');
                    }
                }
                $key = hash('sha256', 'minimum:'.$minimum->id);
                if ($available >= $minimum->quantity) {
                    if (! $this->option('dry-run')) {
                        DB::table('consumable_stock_alerts')->where('key', $key)->delete();
                    }

                    continue;
                }
                if ($this->due($key, $stockSettings->reminder_days)) {
                    $keys[] = $key;
                    $rows[] = [
                        'consumable_id' => $item->id, 'product' => $item->name,
                        'lot' => '', 'expires_on' => '', 'place' => $place->displayPath(),
                        'quantity' => $available, 'unit' => $product->unit,
                        'minimum' => $minimum->quantity, 'kind' => 'low',
                    ];
                }
            }

            if (! $rows) {
                $this->info('No consumable stock alerts due.');

                return self::SUCCESS;
            }
            if ($this->option('dry-run')) {
                $this->table(['Product', 'Lot', 'Expiry', 'Location', 'Quantity', 'Status'],
                    array_map(fn ($row) => [$row['product'], $row['lot'], $row['expires_on'], $row['place'], $row['quantity'], $row['kind']], $rows));

                return self::SUCCESS;
            }
            try {
                Mail::to($recipients)->send(new ConsumableStockAlert($rows));
            } catch (\Throwable $error) {
                Log::warning('Consumable stock alert delivery failed: '.$error->getMessage());
                $this->error('Alert delivery failed; alerts remain pending.');

                return self::FAILURE;
            }
            foreach ($keys as $key) {
                DB::table('consumable_stock_alerts')->updateOrInsert(['key' => $key], ['sent_at' => now()]);
            }
            $this->info(count($rows).' consumable stock alert rows sent.');

            return self::SUCCESS;
        });

        if ($result === false) {
            $this->info('Another consumable stock alert check is running.');

            return self::SUCCESS;
        }

        return $result;
    }

    private function due(string $key, int $days): bool
    {
        $sent = DB::table('consumable_stock_alerts')->where('key', $key)->value('sent_at');

        return ! $sent || Carbon::parse($sent)->lte(now()->subDays($days));
    }
}
