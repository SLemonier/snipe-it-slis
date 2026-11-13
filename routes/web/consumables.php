<?php

use App\Http\Controllers\Consumables;
use Illuminate\Support\Facades\Route;



Route::group(['prefix' => 'consumables', 'middleware' => ['auth']], function () {
    Route::get('stock', [Consumables\ConsumableStockController::class, 'index'])->name('consumables.stock.index');
    Route::get('stock/places', [Consumables\ConsumableStockController::class, 'places'])->name('consumables.stock.places');
    Route::post('stock/places', [Consumables\ConsumableStockController::class, 'savePlace'])->name('consumables.stock.places.store');
    Route::put('stock/places/{place}', [Consumables\ConsumableStockController::class, 'savePlace'])->missing(fn () => abort(404))->name('consumables.stock.places.update');
    Route::get('{consumable}/stock', [Consumables\ConsumableStockController::class, 'show'])->name('consumables.stock.show');
    Route::post('{consumable}/stock', [Consumables\ConsumableStockController::class, 'store'])->name('consumables.stock.store');

    Route::get(
        '{consumablesID}/checkout',
        [Consumables\ConsumableCheckoutController::class, 'create']
    )->name('consumables.checkout.show');

    Route::post(
        '{consumablesID}/checkout',
        [Consumables\ConsumableCheckoutController::class, 'store']
    )->name('consumables.checkout.store');

    Route::post(
        '{consumableId}/upload',
        [Consumables\ConsumablesFilesController::class, 'store']
    )->name('upload/consumable');

    Route::delete(
        '{consumableId}/deletefile/{fileId}',
        [Consumables\ConsumablesFilesController::class, 'destroy']
    )->name('delete/consumablefile');

    Route::get(
        '{consumableId}/showfile/{fileId}/{download?}',
        [Consumables\ConsumablesFilesController::class, 'show']
    )->name('show.consumablefile');

    Route::get('{consumable}/clone',
        [Consumables\ConsumablesController::class, 'clone']
    )->name('consumables.clone.create');
    

});
    
Route::resource('consumables', Consumables\ConsumablesController::class, [
    'middleware' => ['auth'],
    'parameters' => ['consumable' => 'consumable_id'],
]);
