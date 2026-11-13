<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConsumableStockPlaceRequest extends Request
{
    public function authorize(): bool
    {
        return Gate::allows('superuser');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'kind' => ['required', Rule::in(['reserve', 'vehicle', 'bag'])],
            'company_id' => 'nullable|integer|exists:companies,id',
            'parent_id' => 'nullable|integer',
            'asset_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
        ];
    }
}
