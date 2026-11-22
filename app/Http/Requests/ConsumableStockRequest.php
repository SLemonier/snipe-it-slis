<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ConsumableStockRequest extends Request
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function attributes(): array
    {
        return [
            'from_place_id' => trans('consumable_stock.from'),
            'to_place_id' => trans('consumable_stock.to'),
            'from_location_id' => trans('consumable_stock.from'),
            'to_location_id' => trans('consumable_stock.to'),
        ];
    }

    public function rules(): array
    {
        $action = $this->input('action');
        $positiveAdjustment = $action === 'adjust' && (int) $this->input('quantity') > 0;
        $negativeAdjustment = $action === 'adjust' && (int) $this->input('quantity') < 0;
        $inbound = in_array($action, ['receive', 'identify']) || $positiveAdjustment;
        $from = in_array($action, ['identify', 'transfer', 'consume', 'withdraw']) || $negativeAdjustment;
        $to = in_array($action, ['enable', 'receive', 'transfer', 'minimum']) || $positiveAdjustment;

        return [
            'action' => ['required', Rule::in(['enable', 'receive', 'identify', 'transfer', 'consume', 'withdraw', 'adjust', 'quarantine', 'minimum', 'amend_lot'])],
            'operation_id' => 'required|uuid',
            'note' => 'required|string|max:2000',
            'unit' => [Rule::requiredIf($action === 'enable'), 'nullable', 'string', 'max:50'],
            'expiry_required' => [Rule::requiredIf($action === 'enable'), 'boolean'],
            'quantity' => [
                Rule::requiredIf(! in_array($action, ['enable', 'quarantine', 'amend_lot'])),
                'nullable', 'integer', $action === 'adjust' ? 'between:-99999,99999' : ($action === 'minimum' ? 'between:0,99999' : 'between:1,99999'),
                $action === 'adjust' ? 'not_in:0' : 'not_in:-1',
            ],
            'from_place_id' => [Rule::requiredIf($from && ! $this->filled('from_location_id')), 'nullable', 'integer', 'prohibits:from_location_id'],
            'from_location_id' => ['nullable', 'integer', 'prohibits:from_place_id'],
            'to_place_id' => [Rule::requiredIf($to && ! $this->filled('to_location_id')), 'nullable', 'integer', 'prohibits:to_location_id'],
            'to_location_id' => ['nullable', 'integer', 'prohibits:to_place_id'],
            'lot_id' => [Rule::requiredIf(in_array($action, ['identify', 'withdraw', 'quarantine', 'amend_lot']) || $negativeAdjustment), 'nullable', 'integer'],
            'number' => [Rule::requiredIf($inbound || $action === 'amend_lot'), 'nullable', 'string', 'max:191'],
            'expires_on' => 'nullable|date_format:Y-m-d',
            'quarantined' => [Rule::requiredIf($action === 'quarantine'), 'boolean'],
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'reference' => 'nullable|string|max:191',
            'received_on' => 'nullable|date_format:Y-m-d',
        ];
    }
}
