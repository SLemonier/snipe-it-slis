<?php

// Runs only against the throwaway CI database, outside PHPUnit's transaction.
// Proves the additive migration can be applied to populated 8.1.18 tables.
require __DIR__.'/../../bootstrap/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Asset;
use App\Models\Consumable;
use App\Models\ConsumableStockSetting;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!app()->environment('testing') || config('database.connections.mysql.database') !== 'snipeit_test') {
    throw new RuntimeException('This fixture must only run against the isolated snipeit_test database.');
}
$modulePath = database_path('migrations/2026_11_13_100000_create_consumable_stock_tables.php');
$baseline = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => $path !== $modulePath));
Artisan::call('migrate', ['--path' => $baseline, '--realpath' => true, '--force' => true]);
Setting::factory()->create();
$admin = User::factory()->superuser()->create();
Auth::login($admin);
$item = Consumable::factory()->create(['qty' => 12]);
for ($i = 0; $i < 3; $i++) {
    $item->users()->attach($admin->id, ['created_by' => $admin->id]);
}
Asset::factory()->create();
$tables = ['consumables', 'consumables_users', 'action_logs', 'assets', 'users', 'settings', 'locations'];
$before = [];
foreach ($tables as $table) {
    $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
}
$migration = require $modulePath;
$migration->up();
foreach ($tables as $table) {
    if ($before[$table] !== DB::table($table)->orderBy('id')->get()->toJson()) {
        throw new RuntimeException('Existing rows changed during installation: '.$table);
    }
}
if (ConsumableStockSetting::current()->enabled || DB::table('consumable_stock_products')->exists()
    || $item->fresh()->numRemaining() !== 9) {
    throw new RuntimeException('Installation unexpectedly changed existing consumable behaviour.');
}
$migration->down(); // Safe because none of the new tables contain data.
foreach ($tables as $table) {
    if ($before[$table] !== DB::table($table)->orderBy('id')->get()->toJson()) {
        throw new RuntimeException('Existing rows changed during unused-module rollback: '.$table);
    }
}
if (Schema::hasTable('consumable_stock_products')) {
    throw new RuntimeException('Unused module tables were not rolled back.');
}
fwrite(STDOUT, "Populated 8.1.18 upgrade and unused-module rollback preserved all fixture rows.\n");
