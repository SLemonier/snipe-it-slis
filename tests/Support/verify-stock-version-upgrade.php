<?php

// Two separate PHP processes boot the actual old and new applications.
// This file only accepts the disposable CI database, never production.
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\ConsumableStockLot;
use App\Models\ConsumableStockSetting;
use App\Models\Setting;
use App\Models\User;
use App\Services\ConsumableStock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../../bootstrap/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'snipeit_test') {
    throw new RuntimeException('Only the isolated snipeit_test database is allowed.');
}

$mode = $argv[1] ?? '';
$snapshotFile = $argv[2] ?? '';
if (! in_array($mode, ['seed', 'verify'], true) || ! $snapshotFile) {
    throw new RuntimeException('Usage: verify-stock-version-upgrade.php seed|verify snapshot.json');
}
$stock = app(ConsumableStock::class);

if ($mode === 'seed') {
    Artisan::call('migrate', ['--force' => true]);
    Setting::factory()->create(['alerts_enabled' => 1, 'alert_email' => 'stock-ci@example.test']);
    $admin = User::factory()->superuser()->create();
    Auth::login($admin);
    $item = Consumable::factory()->create(['qty' => 12, 'company_id' => null, 'order_number' => 'SLIS-UPGRADE-PO']);
    $untracked = Consumable::factory()->create(['qty' => 17, 'company_id' => null]);
    for ($i = 0; $i < 2; $i++) {
        $item->users()->attach($admin->id, ['created_by' => $admin->id]);
    }
    Asset::factory()->create();
    $reserve = $stock->savePlace(['name' => 'Reserve CI', 'kind' => 'reserve']);
    $vehicle = $stock->savePlace(['name' => 'VSAV CI', 'kind' => 'vehicle']);
    $bag = $stock->savePlace(['name' => 'Bag CI', 'kind' => 'bag', 'parent_id' => $vehicle->id]);
    ConsumableStockSetting::whereKey(1)->update(['enabled' => true, 'expiry_days' => 90, 'reminder_days' => 14]);

    $perform = function (string $action, array $data) use ($stock, $item) {
        return $stock->execute($item->fresh(), array_merge([
            'action' => $action, 'operation_id' => (string) Str::uuid(), 'note' => 'Upgrade fixture',
        ], $data));
    };
    $perform('enable', ['unit' => 'piece', 'expiry_required' => true, 'to_place_id' => $reserve->id]);
    $unknown = ConsumableStockLot::where('consumable_id', $item->id)->firstOrFail();
    $perform('identify', ['lot_id' => $unknown->id, 'from_place_id' => $reserve->id,
        'quantity' => 10, 'number' => 'INITIAL-CI', 'expires_on' => '2098-01-01']);
    $perform('receive', ['to_place_id' => $reserve->id, 'quantity' => 5,
        'number' => 'NEW-CI', 'expires_on' => '2099-01-01', 'reference' => 'DELIVERY-CI']);
    $perform('transfer', ['from_place_id' => $reserve->id, 'to_place_id' => $vehicle->id, 'quantity' => 4]);
    $perform('transfer', ['from_place_id' => $vehicle->id, 'to_place_id' => $bag->id, 'quantity' => 2]);
    $perform('consume', ['from_place_id' => $bag->id, 'quantity' => 1]);
    $perform('receive', ['to_place_id' => $reserve->id, 'quantity' => 2,
        'number' => 'EXPIRED-CI', 'expires_on' => '2020-01-01']);
    $perform('minimum', ['to_place_id' => $bag->id, 'quantity' => 3]);
    DB::table('consumable_stock_alerts')->insert(['key' => 'existing-reminder', 'sent_at' => now()]);

    $tables = [];
    foreach (['settings', 'places', 'place_events', 'products', 'lots', 'balances', 'operations', 'movements', 'minimums', 'alerts'] as $suffix) {
        $table = 'consumable_stock_'.$suffix;
        $order = $suffix === 'products' ? 'consumable_id' : ($suffix === 'alerts' ? 'key' : 'id');
        $tables[$table] = ['order' => $order, 'columns' => ['*'], 'rows' => DB::table($table)->orderBy($order)->get()->toArray()];
    }
    foreach ([
        'consumables' => ['id', 'name', 'qty', 'company_id', 'category_id', 'created_at', 'updated_at', 'deleted_at'],
        'consumables_users' => Schema::getColumnListing('consumables_users'),
        'assets' => ['id', 'name', 'asset_tag', 'model_id', 'assigned_to', 'assigned_type', 'deleted_at'],
        'users' => ['id', 'username', 'email', 'password', 'permissions', 'deleted_at'],
        'action_logs' => ['id', 'action_type', 'item_id', 'item_type', 'target_id', 'target_type', 'note', 'log_meta'],
    ] as $table => $columns) {
        $tables[$table] = ['order' => 'id', 'columns' => $columns, 'rows' => DB::table($table)->select($columns)->orderBy('id')->get()->toArray()];
    }
    file_put_contents($snapshotFile, json_encode([
        'tables' => $tables, 'admin' => $admin->id, 'item' => $item->id,
        'untracked' => $untracked->id, 'bag' => $bag->id, 'stock' => $stock->snapshot($item->fresh()),
    ], JSON_THROW_ON_ERROR));
    fwrite(STDOUT, "Seeded actual 8.1.18 with lots, vehicle/bag stock, consumption, history and alert settings.\n");
    exit(0);
}

$before = json_decode(file_get_contents($snapshotFile), true, flags: JSON_THROW_ON_ERROR);
foreach ($before['tables'] as $table => $data) {
    $rows = DB::table($table)->select($data['columns'])->orderBy($data['order'])->get()->toJson();
    if (json_decode($rows, true) !== $data['rows']) {
        throw new RuntimeException('Upgrade changed existing data: '.$table);
    }
}
Auth::loginUsingId($before['admin']);
$item = Consumable::findOrFail($before['item']);
if ($stock->snapshot($item) !== $before['stock']) {
    throw new RuntimeException('The lot ledger changed during the upgrade.');
}
if (Consumable::findOrFail($before['untracked'])->numRemaining() !== 17) {
    throw new RuntimeException('Untracked stock changed.');
}
if ($item->legacy_order_number !== 'SLIS-UPGRADE-PO'
    || ! $item->orders()->where('order_number', 'SLIS-UPGRADE-PO')->exists()) {
    throw new RuntimeException('Native order migration lost the acquisition reference.');
}
if (DB::table('migrations')->where('migration', '2026_11_13_100000_create_consumable_stock_tables')->count() !== 1) {
    throw new RuntimeException('The installed lot migration was replayed.');
}
$operation = ['action' => 'consume', 'operation_id' => (string) Str::uuid(),
    'note' => 'Use after 8.8.3 upgrade', 'from_place_id' => $before['bag'], 'quantity' => 1];
$stock->execute($item, $operation);
$stock->execute($item->fresh(), $operation);
$after = $stock->snapshot($item->fresh());
if (! $after['consistent'] || $after['physical'] !== $before['stock']['physical'] - 1) {
    throw new RuntimeException('Use or idempotent retry failed after upgrade.');
}
$alertRows = DB::table('consumable_stock_alerts')->orderBy('key')->get()->toJson();
if (Artisan::call('snipeit:consumable-stock-alerts', ['--dry-run' => true]) !== 0
    || $alertRows !== DB::table('consumable_stock_alerts')->orderBy('key')->get()->toJson()) {
    throw new RuntimeException('Alert dry run failed or changed reminder state.');
}
fwrite(STDOUT, "8.1.18 -> 8.8.3 preserved all fixture rows; resumed use, retry and alert dry run passed.\n");
