@extends('layouts.app')

@section('title', 'Stock Transfer | Edit')

@section('content')
<div class="row">
    <form action="{{ route('stock_transfer.update', $transfer->id) }}" method="POST" id="stockTransferForm">
        @csrf
        @method('PUT')

        <div class="col-12 mb-2">
            <section class="card">
                <header class="card-header">
                    <h2 class="card-title">Edit Stock Transfer #{{ $transfer->id }}</h2>
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </header>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-3">
                            <label>Transfer Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="{{ old('date', $transfer->date->format('Y-m-d')) }}" required />
                        </div>
                        <div class="col-md-3">
                            <label>From Location <span class="text-danger">*</span></label>
                            <select name="from_location_id" id="fromLocation" class="form-control select2-js" required>
                                <option value="">Select From Location</option>
                                @foreach($locations as $loc)
                                    <option value="{{ $loc->id }}" data-type="{{ $loc->type }}" {{ old('from_location_id', $transfer->from_location_id) == $loc->id ? 'selected' : '' }}>
                                        {{ $loc->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>To Location <span class="text-danger">*</span></label>
                            <select name="to_location_id" id="toLocation" class="form-control select2-js" required>
                                <option value="">Select To Location</option>
                                @foreach($locations as $loc)
                                    <option value="{{ $loc->id }}" data-type="{{ $loc->type }}" {{ old('to_location_id', $transfer->to_location_id) == $loc->id ? 'selected' : '' }}>
                                        {{ $loc->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Remarks</label>
                            <input type="text" name="remarks" class="form-control" value="{{ old('remarks', $transfer->remarks) }}">
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="card">
                <header class="card-header">
                    <h2 class="card-title">Items In/Out</h2>
                </header>
                <div class="card-body">
                    <table class="table table-bordered" id="itemTable">
                        <thead>
                            <tr>
                                <th width="25%">Product</th>
                                <th width="18%">Variation</th>
                                <th width="22%">Lot Number</th>
                                <th width="18%">Qty</th>
                                <th width="5%"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transfer->details as $index => $detail)
                            <tr>
                                <td>
                                    <select name="items[{{ $index }}][product_id]" class="form-control select2-js product-select" required>
                                        <option value="">Select Product</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}" 
                                                    data-unit="{{ $product->measurementUnit->name ?? '' }}"
                                                    {{ $detail->product_id == $product->id ? 'selected' : '' }}>
                                                {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    {{-- Preselect via data-attribute --}}
                                    <select name="items[{{ $index }}][variation_id]" class="form-control select2-js variation-select" data-preselect-variation="{{ $detail->variation_id }}">
                                        <option value="">Select Variation</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="lot-out-wrapper">
                                        {{-- Preselect via data-attribute --}}
                                        <select name="items[{{ $index }}][lot_number]" class="form-control select2-js lot-select" data-preselect-lot="{{ $detail->lot_number }}">
                                            <option value="{{ $detail->lot_number }}" selected>{{ $detail->lot_number }}</option>
                                        </select>
                                        <small class="text-muted lot-hint">Select lot from source</small>
                                    </div>
                                    
                                    <div class="lot-in-wrapper" style="display:none;">
                                        <div class="input-group mb-1">
                                            <input type="text" name="items[{{ $index }}][new_lot_number]" class="form-control new-lot-input" placeholder="Vendor Lot (Optional)" value="{{ $detail->lot_number }}">
                                        </div>
                                    </div>
                                    
                                    <input type="hidden" name="items[{{ $index }}][generate_lot]" class="generate-lot-flag" value="0">
                                </td>
                                <td>
                                    <div class="input-group">
                                        <input type="number" name="items[{{ $index }}][quantity]" class="form-control quantity" step="any" value="{{ $detail->quantity }}" required>
                                        <input type="text" class="form-control part-unit-name" style="width:60px; flex:none;" readonly value="{{ $detail->product->measurementUnit->name ?? '' }}">
                                    </div>
                                    <small class="text-muted available-qty-info" style="display:none;">
                                        Available: <span class="available-qty">0</span>
                                    </small>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-success btn-sm" onclick="addRow()">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
                <footer class="card-footer text-end">
                    <a href="{{ route('stock_transfer.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Transfer
                    </button>
                </footer>
            </section>
        </div>
    </form>
</div>

<script>
    let rowIndex = {{ count($transfer->details) }};

    $(document).ready(function () {
        $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

        // --- EDIT MODE INITIALIZATION ---
        $('#itemTable tbody tr').each(function() {
            const row = $(this);
            const productId = row.find('.product-select').val();
            const variationId = row.find('.variation-select').data('preselect-variation');
            const existingLotNumber = row.find('.lot-select').data('preselect-lot');
            const fromLocationId = $('#fromLocation').val();

            if (productId) {
                // Load variations first, then lots to maintain selection order
                loadVariations(row, productId, variationId, function() {
                    if (fromLocationId) {
                        checkAndLoadLots(row, fromLocationId, productId, variationId, existingLotNumber);
                    }
                });
            }
        });

        // Trigger UI labels on load based on To Location
        $('#toLocation').trigger('change');

        // Location Change Events
        $('#fromLocation').on('change', function() {
            const fromLocationId = $(this).val();
            $('#itemTable tbody tr').each(function() {
                const row = $(this);
                const productId = row.find('.product-select').val();
                const variationId = row.find('.variation-select').val();
                if (fromLocationId && productId) {
                    checkAndLoadLots(row, fromLocationId, productId, variationId);
                } else {
                    resetLotFields(row);
                }
            });
        });

        $('#toLocation').on('change', function() {
            const toLocType = $(this).find(':selected').data('type');
            if (toLocType === 'customer') {
                $('.card-title').first().html('<i class="fas fa-shopping-cart text-primary"></i> Dispatch to Customer (Sale)');
                $('.btn-primary').html('<i class="fas fa-shipping-fast"></i> Complete Sale');
            } else {
                $('.card-title').first().text('Edit Stock Transfer #{{ $transfer->id }}');
                $('.btn-primary').html('<i class="fas fa-save"></i> Update Transfer');
            }
        });

        // Dynamic Field Events
        $(document).on('change', '.product-select', function () {
            const row = $(this).closest('tr');
            const productId = $(this).val();
            const unit = $(this).find(':selected').data('unit');
            row.find('.part-unit-name').val(unit || '');

            if (productId) {
                loadVariations(row, productId);
                checkAndLoadLots(row, $('#fromLocation').val(), productId, null);
            } else {
                resetLotFields(row);
            }
        });

        $(document).on('change', '.variation-select', function() {
            const row = $(this).closest('tr');
            checkAndLoadLots(row, $('#fromLocation').val(), row.find('.product-select').val(), $(this).val());
        });

        $(document).on('change', '.lot-select', function() {
            const row = $(this).closest('tr');
            const availableQty = $(this).find(':selected').data('qty') || 0;
            if (availableQty > 0) {
                row.find('.available-qty').text(availableQty);
                row.find('.available-qty-info').show();
                row.find('.quantity').attr('max', availableQty);
            } else {
                row.find('.available-qty-info').hide();
                row.find('.quantity').removeAttr('max');
            }
        });
    });

    // Helper: AJAX Load Variations
    function loadVariations(row, productId, preselectId = null, callback = null) {
        const $el = row.find('.variation-select');
        $.get(`/product/${productId}/variations`, function (data) {
            let options = '<option value="">Select Variation</option>';
            (data.variation || []).forEach(v => {
                options += `<option value="${v.id}" ${preselectId == v.id ? 'selected' : ''}>${v.sku}</option>`;
            });
            $el.html(options).select2({ width: '100%' });
            if (callback) callback();
        });
    }

    // Helper: AJAX Load Lots
    function checkAndLoadLots(row, locId, prodId, varId, preselectLot = null) {
        if (!locId || !prodId) return;
        const fromLocType = $('#fromLocation').find(':selected').data('type');
        const $out = row.find('.lot-out-wrapper');
        const $in = row.find('.lot-in-wrapper');

        if (fromLocType === 'vendor') {
            $out.hide(); $in.show();
            row.find('.generate-lot-flag').val('1');
            return;
        }

        $.get(`/stock-lots/available`, { location_id: locId, product_id: prodId, variation_id: varId || '' }, function(data) {
            let options = '<option value="">Select Lot</option>';
            if (data.lots && data.lots.length > 0) {
                data.lots.forEach(lot => {
                    options += `<option value="${lot.lot_number}" data-qty="${lot.quantity}" ${preselectLot == lot.lot_number ? 'selected' : ''}>
                        ${lot.lot_number} (Avail: ${lot.quantity})
                    </option>`;
                });
                row.find('.lot-select').html(options).select2({ width: '100%' }).trigger('change');
                $out.show(); $in.hide();
            } else {
                $out.hide(); $in.show().html('<span class="badge bg-danger">Out of Stock</span>');
            }
        });
    }

    function resetLotFields(row) {
        row.find('.lot-select').html('<option value="">Select source first</option>');
        row.find('.available-qty-info').hide();
    }

    function addRow() {
        const idx = rowIndex++;
        const rowHtml = `<tr>
            <td><select name="items[${idx}][product_id]" class="form-control select2-js product-select" required>
                <option value="">Select Product</option>
                @foreach($products as $p)<option value="{{ $p->id }}" data-unit="{{ $p->measurementUnit->name ?? '' }}">{{ $p->name }}</option>@endforeach
            </select></td>
            <td><select name="items[${idx}][variation_id]" class="form-control select2-js variation-select"><option value="">Select Variation</option></select></td>
            <td>
                <div class="lot-out-wrapper"><select name="items[${idx}][lot_number]" class="form-control select2-js lot-select"><option value="">Select source first</option></select></div>
                <div class="lot-in-wrapper" style="display:none;"><input type="text" name="items[${idx}][new_lot_number]" class="form-control" placeholder="Lot (Optional)"></div>
                <input type="hidden" name="items[${idx}][generate_lot]" class="generate-lot-flag" value="0">
            </td>
            <td><div class="input-group">
                <input type="number" name="items[${idx}][quantity]" class="form-control quantity" step="any" required>
                <input type="text" class="form-control part-unit-name" style="width:60px; flex:none;" readonly>
            </div></td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="$(this).closest('tr').remove()"><i class="fas fa-times"></i></button></td>
        </tr>`;
        $('#itemTable tbody').append(rowHtml);
        $('#itemTable tbody tr:last .select2-js').select2({ width: '100%' });
    }
</script>
@endsection