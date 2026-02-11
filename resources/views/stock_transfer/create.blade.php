@extends('layouts.app')

@section('title', 'Stock In/Out | Create')

@section('content')
<div class="row">
  <form action="{{ route('stock_transfer.store') }}" method="POST" id="stockTransferForm">
    @csrf

    <div class="col-12 mb-2">
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Create Stock In/Out</h2>
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
              <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required />
            </div>
            <div class="col-md-3">
              <label>From Location <span class="text-danger">*</span></label>
              <select name="from_location_id" id="fromLocation" class="form-control select2-js" required>
                  <option value="">Select From Location</option>
                  @foreach($locations as $loc)
                      <option value="{{ $loc->id }}" data-type="{{ $loc->type }}">
                          {{ $loc->name }} ({{ ucfirst($loc->type) }})
                      </option>
                  @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>To Location <span class="text-danger">*</span></label>
              <select name="to_location_id" id="toLocation" class="form-control select2-js" required>
                <option value="">Select To Location</option>
                @foreach($locations as $loc)
                  <option value="{{ $loc->id }}" {{ old('to_location_id') == $loc->id ? 'selected' : '' }}>
                    {{ $loc->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" value="{{ old('remarks') }}">
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
                <tr>
                  <td>
                    <select name="items[0][product_id]" class="form-control select2-js product-select" required>
                      <option value="">Select Product</option>
                      @foreach($products as $product)
                          <option value="{{ $product->id }}" 
                                  data-price="{{ $product->selling_price }}" 
                                  data-unit="{{ $product->measurementUnit->name ?? '' }}">
                              {{ $product->name }}
                          </option>
                      @endforeach
                    </select>
                  </td>
                  <td>
                    <select name="items[0][variation_id]" class="form-control select2-js variation-select">
                      <option value="">Select Variation</option>
                    </select>
                  </td>
                  <td>
                    {{-- Lot Selection (if lots exist at from_location) --}}
                    <div class="lot-out-wrapper">
                      <select name="items[0][lot_number]" class="form-control select2-js lot-select">
                        <option value="">Select from location first</option>
                      </select>
                      <small class="text-muted lot-hint">Select lot from source</small>
                    </div>
                    
                    {{-- New Lot Generation (if no lots available) --}}
                    <div class="lot-in-wrapper" style="display:none;">
                      <div class="input-group mb-1">
                        <input type="text" name="items[0][new_lot_number]" class="form-control new-lot-input" placeholder="New Lot#">
                      </div>
                    </div>
                    
                    {{-- Hidden flag to indicate lot generation --}}
                    <input type="hidden" name="items[0][generate_lot]" class="generate-lot-flag" value="0">
                  </td>
                  <td>
                      <div class="input-group">
                          <input type="number" name="items[0][quantity]" class="form-control quantity" step="any" required>
                          <input type="text" class="form-control part-unit-name" style="width:60px; flex:none;" readonly placeholder="Unit">
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
              </tbody>
          </table>
          <button type="button" class="btn btn-success btn-sm" onclick="addRow()">
            <i class="fas fa-plus"></i> Add Item
          </button>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('stock_transfer.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Transfer
          </button>
        </footer>
      </section>
    </div>
  </form>
</div>

<script>
  let rowIndex = 1;

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // When from_location changes, reload all lots
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
          $('.card-title').first().text('Create Stock In/Out');
          $('.btn-primary').html('<i class="fas fa-save"></i> Save Transfer');
      }
    });

    // Product selection
    $(document).on('change', '.product-select', function () {
        const row = $(this).closest('tr');
        const productId = $(this).val();
        const fromLocationId = $('#fromLocation').val();
        
        // Update unit
        const selectedOption = $(this).find(':selected');
        const unitName = selectedOption.data('unit') || '';
        row.find('.part-unit-name').val(unitName);

        const preselectVariationId = $(this).data('preselectVariationId') || null;
        $(this).removeData('preselectVariationId');

        if (productId) {
            loadVariations(row, productId, preselectVariationId);
            
            // Check for lots if from_location selected
            if (fromLocationId) {
              checkAndLoadLots(row, fromLocationId, productId, null);
            }
        } else {
            row.find('.variation-select')
                .html('<option value="">Select Variation</option>')
                .trigger('change');
            resetLotFields(row);
        }
    });

    // Variation selection
    $(document).on('change', '.variation-select', function() {
      const row = $(this).closest('tr');
      const productId = row.find('.product-select').val();
      const variationId = $(this).val();
      const fromLocationId = $('#fromLocation').val();
      
      if (fromLocationId && productId) {
        checkAndLoadLots(row, fromLocationId, productId, variationId);
      }
    });

    // Lot selection - show available quantity
    $(document).on('change', '.lot-select', function() {
      const row = $(this).closest('tr');
      const selectedOption = $(this).find(':selected');
      const availableQty = selectedOption.data('qty') || 0;
      
      if (availableQty > 0) {
        row.find('.available-qty').text(availableQty);
        row.find('.available-qty-info').show();
        row.find('.quantity').attr('max', availableQty);
      } else {
        row.find('.available-qty-info').hide();
        row.find('.quantity').removeAttr('max');
      }
    });

    // Quantity validation
    $(document).on('input', '.quantity', function() {
      const row = $(this).closest('tr');
      const maxQty = parseFloat($(this).attr('max')) || Infinity;
      const enteredQty = parseFloat($(this).val()) || 0;
      
      if (maxQty !== Infinity && enteredQty > maxQty) {
        $(this).addClass('is-invalid');
        row.find('.available-qty-info').addClass('text-danger');
      } else {
        $(this).removeClass('is-invalid');
        row.find('.available-qty-info').removeClass('text-danger');
      }
    });

    // Form validation before submit
    $('#stockTransferForm').on('submit', function(e) {
      let isValid = true;
      
      // Check if from and to locations are same
      if ($('#fromLocation').val() === $('#toLocation').val()) {
        alert('From and To locations cannot be the same!');
        e.preventDefault();
        return false;
      }
      
      $('.quantity').each(function() {
        const maxQty = parseFloat($(this).attr('max')) || Infinity;
        const enteredQty = parseFloat($(this).val()) || 0;
        
        if (maxQty !== Infinity && enteredQty > maxQty) {
          alert('Quantity exceeds available stock for some items');
          isValid = false;
          return false;
        }
      });
      
      if (!isValid) {
        e.preventDefault();
      }
    });
  });

  // Check if lots exist and show appropriate UI
  function checkAndLoadLots(row, locationId, productId, variationId) {
      const fromLocType = $('#fromLocation').find(':selected').data('type');
      const $lotOutWrapper = row.find('.lot-out-wrapper');
      const $lotInWrapper = row.find('.lot-in-wrapper');
      const $lotSelect = row.find('.lot-select');

      // SCENARIO 1: STOCK IN FROM VENDOR
      if (fromLocType === 'vendor') {
          $lotOutWrapper.hide();
          $lotInWrapper.show().html(`
              <div class="input-group mb-1">
                  <input type="text" name="items[${row.index()}][new_lot_number]" class="form-control new-lot-input" placeholder="New Lot No." readonly>
              </div>
              <small class="text-success"><i class="fas fa-plus-circle"></i> New stock entry: system will generate lot No.</small>
          `);
          row.find('.generate-lot-flag').val('1');
          row.find('.available-qty-info').hide();
          row.find('.quantity').removeAttr('max');
          return;
      }

      // SCENARIO 2, 3, 4: INTERNAL TRANSFER OR SALE (Deducting from Godown/Shop)
      $.get(`/stock-lots/available`, {
          location_id: locationId,
          product_id: productId,
          variation_id: variationId || ''
      }, function(data) {
          if (data.lots && data.lots.length > 0) {
              let options = '<option value="">Select Lot</option>';
              data.lots.forEach(lot => {
                  const expiryInfo = lot.lot_expiry_date ? ` (Exp: ${lot.lot_expiry_date})` : '';
                  options += `<option value="${lot.lot_number}" data-qty="${lot.quantity}">
                      ${lot.lot_number} - (Avail: ${lot.quantity})${expiryInfo}
                  </option>`;
              });

              $lotSelect.html(options);
              $lotOutWrapper.show();
              $lotInWrapper.hide();
              row.find('.generate-lot-flag').val('0');
          } else {
              // Error State: No stock in a godown/shop
              $lotOutWrapper.hide();
              $lotInWrapper.show().html(`
                  <span class="badge bg-danger">Out of Stock</span>
                  <small class="d-block text-danger">No lots available at this location.</small>
              `);
              row.find('.generate-lot-flag').val('0');
          }

          // Refresh Select2
          if ($lotSelect.hasClass('select2-hidden-accessible')) { $lotSelect.select2('destroy'); }
          $lotSelect.select2({ width: '100%' });

      }).fail(function() {
          alert('Error loading inventory data.');
      });
  }

  // Reset lot fields
  function resetLotFields(row) {
    row.find('.lot-select').html('<option value="">Select from location first</option>');
    row.find('.lot-out-wrapper').show();

    row.find('.lot-in-wrapper').html(`
      <div class="input-group mb-1">
        <input type="text" class="form-control new-lot-input" placeholder="Auto-generated" disabled>
      </div>
    `).hide();

    row.find('.available-qty-info').hide();
    row.find('.quantity').removeAttr('max').removeClass('is-invalid');
    row.find('.generate-lot-flag').val('0');
  }


  // Add Row
  function addRow() {
      const idx = rowIndex++;
      
      const rowHtml = `
        <tr>
          <td>
            <select name="items[${idx}][product_id]" class="form-control select2-js product-select" required>
              <option value="">Select Product</option>
              @foreach($products as $product)
                <option value="{{ $product->id }}" 
                        data-price="{{ $product->selling_price }}" 
                        data-unit="{{ $product->measurementUnit->name ?? '' }}">
                  {{ $product->name }}
                </option>
              @endforeach
            </select>
          </td>
          <td>
            <select name="items[${idx}][variation_id]" class="form-control select2-js variation-select">
              <option value="">Select Variation</option>
            </select>
          </td>
          <td>
            <div class="lot-out-wrapper">
              <select name="items[${idx}][lot_number]" class="form-control select2-js lot-select">
                <option value="">Select from location first</option>
              </select>
              <small class="text-muted lot-hint">Select lot from source</small>
            </div>
            
            <div class="lot-in-wrapper" style="display:none;">
              <div class="input-group mb-1">
                <input type="text" name="items[${idx}][new_lot_number]" class="form-control new-lot-input" placeholder="Auto-generated">
              </div>
            </div>
            
            <input type="hidden" name="items[${idx}][generate_lot]" class="generate-lot-flag" value="0">
          </td>
          <td>
            <div class="input-group">
              <input type="number" name="items[${idx}][quantity]" class="form-control quantity" step="any" required>
              <input type="text" class="form-control part-unit-name" style="width:60px; flex:none;" readonly placeholder="Unit">
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
      `;
      
      $('#itemTable tbody').append(rowHtml);
      const $newRow = $('#itemTable tbody tr').last();
      $newRow.find('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
  }

  // Remove Row
  function removeRow(btn) {
    if ($('#itemTable tbody tr').length > 1) {
      $(btn).closest('tr').remove();
    } else {
      alert('At least one item is required');
    }
  }

  // Load Variations
  function loadVariations(row, productId, preselectVariationId = null) {
    const $variationSelect = row.find('.variation-select');
    $variationSelect.html('<option value="">Loading...</option>');

    $.get(`/product/${productId}/variations`, function (data) {
      let options = '<option value="">Select Variation</option>';
      (data.variation || []).forEach(v => {
        options += `<option value="${v.id}">${v.sku}</option>`;
      });
      $variationSelect.html(options);

      if ($variationSelect.hasClass('select2-hidden-accessible')) {
        $variationSelect.select2('destroy');
      }
      $variationSelect.select2({ width: '100%', dropdownAutoWidth: true });

      if (preselectVariationId) {
        $variationSelect.val(String(preselectVariationId)).trigger('change');
      }
    });
  }
</script>

@endsection