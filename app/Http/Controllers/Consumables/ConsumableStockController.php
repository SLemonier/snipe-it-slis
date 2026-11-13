<?php

namespace App\Http\Controllers\Consumables;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumableStockRequest;
use App\Models\Company;
use App\Models\Consumable;
use App\Models\ConsumableStockOperation;
use App\Models\ConsumableStockPlace;
use App\Models\ConsumableStockProduct;
use App\Models\ConsumableStockSetting;
use App\Services\ConsumableStock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsumableStockController extends Controller
{
    public function index(Request $request, ConsumableStock $stock)
    {
        $this->authorize('view', Consumable::class);
        $query = Consumable::whereIn('id', ConsumableStockProduct::select('consumable_id'))->orderBy('name');
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }
        $places = ConsumableStockPlace::with('parent')->orderBy('name')->get();
        $placeIds = null;
        if ($request->filled('place_id')) {
            $selected = $places->firstWhere('id', (int) $request->input('place_id'));
            abort_unless($selected, 404);
            $placeIds = [$selected->id];
            do {
                $before = count($placeIds);
                foreach ($places as $place) {
                    if (in_array($place->parent_id, $placeIds, true) && !in_array($place->id, $placeIds, true)) {
                        $placeIds[] = $place->id;
                    }
                }
            } while ($before !== count($placeIds));
            $query->whereIn('id', \Illuminate\Support\Facades\DB::table('consumable_stock_balances as balances')
                ->join('consumable_stock_lots as lots', 'lots.id', '=', 'balances.lot_id')
                ->whereIn('balances.place_id', $placeIds)->where('balances.quantity', '>', 0)->select('lots.consumable_id'));
        }
        $items = $query->paginate(25);
        $summaries = [];
        foreach ($items as $item) {
            $summary = $stock->snapshot($item);
            if ($placeIds !== null) {
                $balances = collect($summary['balances'])->whereIn('place_id', $placeIds);
                $summary['physical'] = $balances->sum('quantity');
                $summary['usable'] = $balances->where('usable', true)->sum('quantity');
                $summary['unavailable'] = $summary['physical'] - $summary['usable'];
            }
            $summaries[$item->id] = $summary;
        }
        return view('consumables.stock.index', compact('items', 'summaries', 'places'));
    }

    public function show(Consumable $consumable, ConsumableStock $stock, Request $request)
    {
        $this->authorize('view', $consumable);
        $snapshot = $stock->snapshot($consumable);
        $history = ConsumableStockOperation::where('consumable_id', $consumable->id)
            ->with(['movements.lot', 'actor'])->orderByDesc('created_at')->orderByDesc('id')->paginate(30);
        if ($request->is('api/*')) {
            return response()->json(Helper::formatStandardApiResponse('success', [
                'stock' => $snapshot,
                'history' => $history->map(fn ($op) => [
                    'id' => $op->id, 'action' => $op->action, 'actor_id' => $op->actor_id,
                    'note' => $op->note, 'created_at' => $op->created_at->toIso8601String(),
                    'details' => $op->details,
                    'movements' => $op->movements->map(fn ($movement) => [
                        'lot_id' => $movement->lot_id, 'from_place_id' => $movement->from_place_id,
                        'to_place_id' => $movement->to_place_id, 'quantity' => $movement->quantity,
                    ])->all(),
                ])->all(),
                'pagination' => ['page' => $history->currentPage(), 'last_page' => $history->lastPage()],
            ]));
        }
        $places = ConsumableStockPlace::where('company_id', $consumable->company_id)->orderBy('name')->get();
        $module = ConsumableStockSetting::current();
        return view('consumables.stock.show', compact('consumable', 'snapshot', 'history', 'places', 'module'));
    }

    public function store(Consumable $consumable, ConsumableStockRequest $request, ConsumableStock $stock)
    {
        $operation = $stock->execute($consumable, $request->validated());
        if ($request->is('api/*')) {
            return response()->json(Helper::formatStandardApiResponse('success', [
                'operation_id' => $operation->id, 'stock' => $stock->snapshot($consumable->fresh()),
            ]));
        }
        return redirect()->route('consumables.stock.show', $consumable)->with('success', trans('consumable_stock.saved'));
    }

    public function places(Request $request)
    {
        $this->authorize('view', Consumable::class);
        $places = ConsumableStockPlace::with('parent')->orderBy('name')->get();
        if ($request->is('api/*')) {
            return response()->json(Helper::formatStandardApiResponse('success', $places->map(fn ($place) => [
                'id' => $place->id, 'name' => $place->name, 'path' => $place->displayPath(),
                'kind' => $place->kind, 'parent_id' => $place->parent_id,
                'company_id' => $place->company_id, 'asset_id' => $place->asset_id, 'location_id' => $place->location_id,
            ])->all()));
        }
        $companies = Company::orderBy('name')->get();
        return view('consumables.stock.places', compact('places', 'companies'));
    }

    public function savePlace(Request $request, ConsumableStock $stock, ?ConsumableStockPlace $place = null)
    {
        $this->authorize('superuser');
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'kind' => ['required', Rule::in(['reserve', 'vehicle', 'bag'])],
            'company_id' => 'nullable|integer|exists:companies,id',
            'parent_id' => 'nullable|integer',
            'asset_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
        ]);
        $place = $stock->savePlace($data, $place);
        if ($request->is('api/*')) {
            return response()->json(Helper::formatStandardApiResponse('success', ['id' => $place->id]));
        }
        return redirect()->route('consumables.stock.places')->with('success', trans('consumable_stock.saved'));
    }
}
