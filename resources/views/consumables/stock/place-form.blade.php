@php($suffix = $editingPlace?->id ?? 'new')
<form method="POST" action="{{ $editingPlace ? route('consumables.stock.places.update', $editingPlace) : route('consumables.stock.places.store') }}">
    @csrf
    @if($editingPlace) @method('PUT') @endif
    <div class="row">
        <div class="form-group col-sm-4"><label for="place-name-{{ $suffix }}">{{ trans('general.name') }}</label><input class="form-control" id="place-name-{{ $suffix }}" name="name" required maxlength="191" value="{{ $editingPlace?->name }}"></div>
        <div class="form-group col-sm-4"><label for="place-kind-{{ $suffix }}">{{ trans('consumable_stock.kind') }}</label><select class="form-control" id="place-kind-{{ $suffix }}" name="kind">
            @foreach(['reserve','vehicle','bag'] as $kind)<option value="{{ $kind }}" @selected($editingPlace?->kind === $kind)>{{ trans('consumable_stock.kinds.'.$kind) }}</option>@endforeach
        </select></div>
        <div class="form-group col-sm-4"><label for="place-parent-{{ $suffix }}">{{ trans('consumable_stock.parent') }}</label><select class="form-control" id="place-parent-{{ $suffix }}" name="parent_id">
            <option value="">{{ trans('consumable_stock.none') }}</option>
            @foreach($places as $parent) @if($parent->id !== $editingPlace?->id)<option value="{{ $parent->id }}" @selected($editingPlace?->parent_id === $parent->id)>{{ $parent->displayPath() }}</option>@endif @endforeach
        </select></div>
    </div>
    <div class="row">
        <div class="form-group col-sm-4"><label for="place-company-{{ $suffix }}">{{ trans('general.company') }}</label><select class="form-control" id="place-company-{{ $suffix }}" name="company_id">
            <option value="">{{ trans('consumable_stock.none') }}</option>
            @foreach($companies as $company)<option value="{{ $company->id }}" @selected((string) $editingPlace?->company_id === (string) $company->id)>{{ $company->name }}</option>@endforeach
        </select></div>
        <div class="form-group col-sm-4"><label for="place-asset-{{ $suffix }}">{{ trans('consumable_stock.linked_asset') }}</label>
            <select class="js-data-ajax" style="width:100%" data-endpoint="hardware" id="place-asset-{{ $suffix }}" name="asset_id">
                <option value="">{{ trans('consumable_stock.none') }}</option>
                @if($editingPlace?->asset_id) @php($linkedAsset = \App\Models\Asset::find($editingPlace->asset_id))
                    <option value="{{ $editingPlace->asset_id }}" selected>{{ $linkedAsset?->asset_tag }} — {{ $linkedAsset?->name }}</option>
                @endif
            </select>
        </div>
        <div class="form-group col-sm-4"><label for="place-location-{{ $suffix }}">{{ trans('consumable_stock.linked_location') }}</label>
            <select class="js-data-ajax" style="width:100%" data-endpoint="locations" id="place-location-{{ $suffix }}" name="location_id">
                <option value="">{{ trans('consumable_stock.none') }}</option>
                @if($editingPlace?->location_id) @php($linkedLocation = \App\Models\Location::find($editingPlace->location_id))
                    <option value="{{ $editingPlace->location_id }}" selected>{{ $linkedLocation?->name }}</option>
                @endif
            </select>
        </div>
    </div>
    <button class="btn btn-primary" type="submit">{{ trans('general.save') }}</button>
</form>
