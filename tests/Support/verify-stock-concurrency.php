<?php

// Two real PHP workers, two database connections, one stock product.
require __DIR__.'/../../bootstrap/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Consumable;
use App\Models\ConsumableStockPlace;
use App\Models\ConsumableStockSetting;
use App\Models\User;
use App\Services\ConsumableStock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'snipeit_test'
    || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('Run only with pcntl against the isolated snipeit_test database.');
}
$admin = User::factory()->superuser()->create();
Auth::login($admin);
ConsumableStockSetting::updateOrCreate(['id' => 1], ['enabled' => true]);
$place = ConsumableStockPlace::create(['name' => 'Concurrency fixture', 'kind' => 'reserve']);
$stock = app(ConsumableStock::class);
foreach ([false, true] as $replay) {
    $item = Consumable::factory()->create(['qty' => 0, 'company_id' => null]);
    $common = ['operation_id' => (string) Str::uuid(), 'note' => 'Concurrency fixture'];
    $stock->execute($item, $common + ['action' => 'enable', 'unit' => 'piece',
        'expiry_required' => true, 'to_place_id' => $place->id]);
    $stock->execute($item, ['operation_id' => (string) Str::uuid(), 'note' => 'Receipt',
        'action' => 'receive', 'number' => 'RACE', 'expires_on' => '2099-01-01',
        'to_place_id' => $place->id, 'quantity' => 5]);
    $sharedId = (string) Str::uuid();
    $barrier = sys_get_temp_dir().'/stock-race-'.Str::uuid();
    DB::disconnect();
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not create test worker.');
        }
        if ($pid === 0) {
            DB::purge();
            $deadline = microtime(true) + 10;
            while (! file_exists($barrier) && microtime(true) < $deadline) {
                usleep(1000);
                clearstatcache();
            }
            if (! file_exists($barrier)) {
                exit(4);
            }
            try {
                $stock->execute($item, ['operation_id' => $replay ? $sharedId : (string) Str::uuid(),
                    'note' => 'Concurrent consumption', 'action' => 'consume',
                    'from_place_id' => $place->id, 'quantity' => 4]);
                DB::disconnect();
                exit(0);
            } catch (ValidationException $error) {
                DB::disconnect();
                exit(2);
            } catch (Throwable $error) {
                fwrite(STDERR, $error->getMessage()."\n");
                exit(3);
            }
        }
        $workers[] = $pid;
    }
    touch($barrier);
    $statuses = [];
    foreach ($workers as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wexitstatus($status);
    }
    unlink($barrier);
    DB::purge();
    sort($statuses);
    if ($statuses !== ($replay ? [0, 0] : [0, 2]) || $item->fresh()->numRemaining() !== 1
        || $item->fresh()->qty !== 1) {
        throw new RuntimeException('Concurrent operations corrupted stock: '.json_encode($statuses));
    }
}
fwrite(STDOUT, "Concurrent depletion and concurrent UUID replay preserved stock.\n");
