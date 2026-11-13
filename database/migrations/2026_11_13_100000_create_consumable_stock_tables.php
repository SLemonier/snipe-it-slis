<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only new tables: installing the module never rewrites existing inventory.
        Schema::create('consumable_stock_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('expiry_days')->default(60);
            $table->unsignedInteger('reminder_days')->default(7);
            $table->timestamps();
        });
        Schema::create('consumable_stock_places', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('kind', 20);
            $table->unsignedInteger('company_id')->nullable()->index();
            $table->unsignedInteger('asset_id')->nullable()->index();
            $table->unsignedInteger('location_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')->references('id')->on('consumable_stock_places')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('consumable_stock_place_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('place_id')->index();
            $table->unsignedInteger('actor_id')->nullable();
            $table->text('before_state')->nullable();
            $table->text('after_state');
            $table->timestamp('created_at');
        });
        Schema::create('consumable_stock_products', function (Blueprint $table) {
            $table->unsignedInteger('consumable_id')->primary();
            $table->string('unit', 50);
            $table->boolean('expiry_required')->default(true);
            $table->integer('opening_qty');
            $table->unsignedInteger('opening_assignments');
            $table->integer('expected_qty');
            $table->unsignedInteger('enabled_by')->nullable();
            $table->timestamps();
        });
        Schema::create('consumable_stock_lots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('consumable_id')->index();
            $table->string('number', 191);
            $table->date('expires_on')->nullable()->index();
            $table->boolean('quarantined')->default(false);
            $table->boolean('unidentified')->default(false);
            $table->unique(['consumable_id', 'number'], 'stock_lot_product_number');
            $table->timestamps();
        });
        Schema::create('consumable_stock_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lot_id');
            $table->unsignedBigInteger('place_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unique(['lot_id', 'place_id'], 'stock_balance_lot_place');
            $table->foreign('lot_id')->references('id')->on('consumable_stock_lots')->restrictOnDelete();
            $table->foreign('place_id')->references('id')->on('consumable_stock_places')->restrictOnDelete();
        });
        Schema::create('consumable_stock_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('consumable_id')->index();
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('action', 30);
            $table->string('fingerprint', 64);
            $table->text('note');
            $table->text('details')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('consumable_stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('operation_id');
            $table->unsignedBigInteger('lot_id');
            $table->unsignedBigInteger('from_place_id')->nullable();
            $table->unsignedBigInteger('to_place_id')->nullable();
            $table->integer('quantity');
            $table->foreign('operation_id')->references('id')->on('consumable_stock_operations')->restrictOnDelete();
            $table->foreign('lot_id')->references('id')->on('consumable_stock_lots')->restrictOnDelete();
        });
        Schema::create('consumable_stock_minimums', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('consumable_id')->index();
            $table->unsignedBigInteger('place_id');
            $table->unsignedInteger('quantity');
            $table->unique(['consumable_id', 'place_id'], 'stock_minimum_product_place');
        });
        Schema::create('consumable_stock_alerts', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        // A code rollback must not destroy the new ledger or change old quantities.
        foreach (['consumable_stock_products', 'consumable_stock_places', 'consumable_stock_operations', 'consumable_stock_settings'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Stock data exists. Keep these additive tables and use the documented code rollback procedure.');
            }
        }
        foreach (['consumable_stock_alerts', 'consumable_stock_minimums', 'consumable_stock_movements',
            'consumable_stock_operations', 'consumable_stock_balances', 'consumable_stock_lots',
            'consumable_stock_products', 'consumable_stock_place_events', 'consumable_stock_places', 'consumable_stock_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
