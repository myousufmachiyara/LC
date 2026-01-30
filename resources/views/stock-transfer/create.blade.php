@extends('layouts.app')

@section('title', 'Stock In/Out | Create')

@section('content')
<div class="row">
  <form action="{{ route('stock_transfer.store') }}" method="POST">
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
              <label>Transfer Date</label>
              <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required />
            </div>
            <div class="col-md-3">
              <label>From Location</label>
              <select name="from_location_id" class="form-control select2-js" required>
                <option value="">Select From Location</option>
                @foreach($locations as $loc)
                  <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>To Location</label>
              <select name="to_location_id" class="form-control select2-js" required>
                <option value="">Select To Location</option>
                @foreach($locations as $loc)
                  <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
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
                      <th>Product</th>
                      <th>Variation</th>
                      <th width="18%">Qty</th> <th></th>
                  </tr>
              </thead>
              <tbody>
                <tr>
                  <td>
                    <select name="items[0][product_id]" class="form-control select2-js product-select" required>
                      <option value="">Select Product</option>
                      @foreach($products as $product)
                          {{-- Added data-unit attribute here --}}
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
                      {{-- Updated to include the Unit Display --}}
                      <div class="input-group">
                          <input type="number" name="items[0][quantity]" class="form-control quantity" step="any" required>
                          <input type="text" class="form-control part-unit-name" style="width:60px; flex:none;" readonly placeholder="Unit">
                      </div>
                  </td>
                  <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
                </tr>
              </tbody>
          </table>
          <button type="button" class="btn btn-success btn-sm" onclick="addRow()">+ Add Item</button>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('stock_transfer.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">Save Transfer</button>
        </footer>
      </section>
    </div>
  </form>
</div>

<script>
  let rowIndex = $('#itemTable tbody tr').length || 1;

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // 🔹 Manual Product selection flow
    $(document).on('change', '.product-select', function () {
        const row = $(this).closest('tr');
        const productId = $(this).val();
        
        // --- NEW LOGIC FOR UNIT ---
        const selectedOption = $(this).find(':selected');
        const unitName = selectedOption.data('unit') || '';
        row.find('.part-unit-name').val(unitName);
        // ---------------------------

        const preselectVariationId = $(this).data('preselectVariationId') || null;
        $(this).removeData('preselectVariationId');

        if (productId) {
            loadVariations(row, productId, preselectVariationId);
        } else {
            row.find('.variation-select')
                .html('<option value="">Select Variation</option>')
                .prop('disabled', false)
                .trigger('change');
        }
    });

  });

  // 🔹 Add Row
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
            <div class="input-group">
              <input type="number" name="items[${idx}][quantity]" class="form-control quantity" step="any" required>
              <input type="text" class="form-control part-unit-name" style="width:60px; flex:none;" readonly placeholder="Unit">
            </div>
          </td>
          <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
        </tr>
      `;
      $('#itemTable tbody').append(rowHtml);
      const $newRow = $('#itemTable tbody tr').last();
      $newRow.find('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
  }

  // 🔹 Remove Row
  function removeRow(btn) {
    $(btn).closest('tr').remove();
  }

  // 🔹 Load Variations
  function loadVariations(row, productId, preselectVariationId = null) {
    const $variationSelect = row.find('.variation-select');
    $variationSelect.html('<option value="">Loading...</option>').prop('disabled', false);

    $.get(`/product/${productId}/variations`, function (data) {
      let options = '<option value="">Select Variation</option>';
      (data.variation || []).forEach(v => {
        options += `<option value="${v.id}">${v.sku}</option>`;
      });
      $variationSelect.html(options).prop('disabled', false);

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
