@extends('layouts.app')

@section('title', 'Create Sale Invoice')

@section('content')
<style>
    /* Prevents Select2 from collapsing and fixes alignment */
    .select2-container--default .select2-selection--single {
        height: 38px !important;
        padding: 5px;
        border: 1px solid #ced4da;
    }
    .select2-container {
        display: block !important;
        width: 100% !important;
    }
    #itemTable th { background: #f8f9fa; }
    #itemTable td { vertical-align: middle; }
</style>

<div class="row">
    <form action="{{ route('sale_invoices.store') }}" onkeydown="return event.key != 'Enter';" method="POST">
        @csrf
        <div class="col-12 mb-2">
            <section class="card">
                <header class="card-header">
                    <h2 class="card-title">Create Sale Invoice</h2>
                    @if ($errors->any())
                        <div class="alert alert-danger mt-2">
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
                        <div class="col-md-2">
                            <label>Invoice #</label>
                            <input type="text" name="invoice_no" class="form-control" readonly placeholder="Auto"/>
                        </div>
                        <div class="col-md-2">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required />
                        </div>
                        <div class="col-md-3">
                            <label>Customer Name</label>
                            <select name="account_id" class="form-control select2-js" required>
                                <option value="">Select Customer</option>
                                @foreach($customers as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Invoice Type</label>
                            <select name="type" id="invoice_type" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="card">
                <header class="card-header">
                    <h2 class="card-title">Invoice Items</h2>
                </header>
                <div class="card-body">
                    <table class="table table-bordered" id="itemTable">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th width="15%">Variation</th>
                                <th width="15%">Location</th>
                                <th width="10%">Price</th>
                                <th width="10%">Qty</th>
                                <th width="12%">Total</th>
                                <th width="50px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <select name="items[0][product_id]" id="item_name0" class="form-control select2-js product-select" onchange="onItemNameChange(this)" required>
                                        <option value="">Select Product</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}" 
                                                    data-price="{{ $product->selling_price }}" 
                                                    data-stock="{{ $product->real_time_stock }}">
                                                {{ $product->name }} (Stock: {{ $product->real_time_stock }})
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="items[0][variation_id]" id="variation0" class="form-control select2-js variation-select">
                                        <option value="">Select Variation</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="items[0][location_id]" id="location0" class="form-control select2-js location-select" onchange="fetchLocationStock(0)" required>
                                        <option value="">Select Location</option>
                                        @foreach($locations as $loc)
                                            <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">Stock: <strong id="stock_display0">0</strong></small>
                                </td>
                                <td><input type="number" name="items[0][sale_price]" class="form-control sale-price" step="any" required></td>
                                <td><input type="number" name="items[0][quantity]" class="form-control quantity" step="any" required></td>
                                <td><input type="number" name="items[0][total]" class="form-control row-total" readonly></td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-success btn-sm" onclick="addRow()">+ Add Item</button>

                    <hr>
                    <div class="row mb-2">
                        <div class="col-md-4">
                            <label>Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-2">
                            <label><strong>Discount (PKR)</strong></label>
                            <input type="number" name="discount" id="discountInput" class="form-control" step="any" value="0">
                        </div>
                        <div class="col-md-6 text-end">
                            <label><strong>Total Bill</strong></label>
                            <h4 class="text-primary mt-0">PKR <span id="netAmountText">0.00</span></h4>
                            <input type="hidden" name="net_amount" id="netAmountInput">
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-2">
                        <div class="col-md-4">
                            <label><strong>Receive Payment To:</strong></label>
                            <select name="payment_account_id" class="form-control select2-js">
                                <option value="">No Payment (Credit Sale)</option>
                                @foreach($paymentAccounts as $pAc)
                                    <option value="{{ $pAc->id }}">{{ $pAc->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Amount Received</label>
                            <input type="number" name="amount_received" id="amountReceived" class="form-control" step="any" value="0">
                        </div>
                        <div class="col-md-5 text-end">
                            <label>Remaining Balance</label>
                            <h4 class="text-danger mt-0">PKR <span id="balanceAmountText">0.00</span></h4>
                        </div>
                    </div>
                </div>
                <footer class="card-footer text-end">
                    <a href="{{ route('sale_invoices.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Invoice</button>
                </footer>
            </section>
        </div>
    </form>
</div>

<script>
    // Initialize Row Index based on current rows
    let rowIndex = $('#itemTable tbody tr').length;
    let locations = @json($locations); // Ensure this is passed from Controller

    $(document).ready(function () {
        // Initial Select2 Activation
        $('.select2-js').select2({ width: '100%' });

        // Calculation triggers
        $(document).on('input', '.sale-price, .quantity', function () {
            calcRowTotal($(this).closest('tr'));
        });

        $(document).on('input', '#amountReceived, #discountInput', calcTotal);

        // Cash/Credit toggle logic
        $(document).on('change', '#invoice_type', function() {
            if($(this).val() === 'cash') {
                $('#amountReceived').val($('#netAmountInput').val());
            } else {
                $('#amountReceived').val(0);
            }
            calcTotal();
        });

        // Stock Validation Color logic
        $(document).on('input', '.quantity', function () {
            const row = $(this).closest('tr');
            const stock = parseFloat(row.find('strong[id^="stock_display"]').text()) || 0;
            const qty = parseFloat($(this).val()) || 0;
            
            if (qty > stock) {
                $(this).addClass('is-invalid').css('border-color', 'red');
            } else {
                $(this).removeClass('is-invalid').css('border-color', '');
            }
        });
    });

    // FUNCTIONS MUST BE OUTSIDE $(document).ready()
    function addRow() {
        const idx = rowIndex++;
        const rowHtml = `
        <tr>
            <td>
                <select name="items[${idx}][product_id]" id="item_name${idx}" class="form-control product-select" onchange="onItemNameChange(this)" required>
                    <option value="">Select Product</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" data-price="{{ $product->selling_price }}" data-stock="{{ $product->real_time_stock }}">
                            {{ $product->name }} (Stock: {{ $product->real_time_stock }})
                        </option>
                    @endforeach
                </select>
            </td>
            <td>
                <select name="items[${idx}][variation_id]" id="variation${idx}" class="form-control variation-select">
                    <option value="">Select Variation</option>
                </select>
            </td>
            <td>
                <select name="items[${idx}][location_id]" id="location${idx}" class="form-control location-select" onchange="fetchLocationStock(${idx})" required>
                    <option value="">Select Location</option>
                    ${locations.map(l => `<option value="${l.id}">${l.name}</option>`).join('')}
                </select>
                <small class="text-muted">Stock: <strong id="stock_display${idx}">0</strong></small>
            </td>
            <td><input type="number" name="items[${idx}][sale_price]" class="form-control sale-price" step="any" required></td>
            <td><input type="number" name="items[${idx}][quantity]" class="form-control quantity" step="any" required></td>
            <td><input type="number" name="items[${idx}][total]" class="form-control row-total" readonly></td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
        </tr>`;

        $('#itemTable tbody').append(rowHtml);
        $(`#item_name${idx}, #variation${idx}, #location${idx}`).select2({ width: '100%' });
    }

    function onItemNameChange(selectElement) {
        const $row = $(selectElement).closest('tr');
        const itemId = selectElement.value;
        const idMatch = selectElement.id.match(/\d+$/);
        if (!idMatch) return;
        const rowNum = idMatch[0];

        // 1. Auto-fill Price
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const price = $(selectedOption).data('price') || 0;
        $row.find('.sale-price').val(price);

        // 2. Fetch Variations via AJAX
        const variationSelect = $(`#variation${rowNum}`);
        
        if (itemId) {
            variationSelect.html('<option value="">Loading...</option>').trigger('change.select2');

            fetch(`/product/${itemId}/variations`)
                .then(res => res.json())
                .then(data => {
                    variationSelect.html('<option value="">Select Variation</option>');
                    const variations = data.variation || data.variations || [];
                    
                    if (variations.length > 0) {
                        variations.forEach(v => {
                            variationSelect.append(`<option value="${v.id}">${v.sku || ''} ${v.name ? v.name : ''}</option>`);
                        });
                    } else {
                        variationSelect.html('<option value="">Standard (No Variations)</option>');
                    }
                    variationSelect.trigger('change.select2');
                })
                .catch(error => {
                    console.error('Error:', error);
                    variationSelect.html('<option value="">Error</option>').trigger('change.select2');
                });
        } else {
            variationSelect.html('<option value="">Select Variation</option>').trigger('change.select2');
        }
        
        calcRowTotal($row);
    }

    function removeRow(btn) {
        if ($('#itemTable tbody tr').length > 1) {
            $(btn).closest('tr').remove();
            calcTotal();
        }
    }

    function calcRowTotal(row) {
        const price = parseFloat(row.find('.sale-price').val()) || 0;
        const qty = parseFloat(row.find('.quantity').val()) || 0;
        row.find('.row-total').val((price * qty).toFixed(2));
        calcTotal();
    }

    function calcTotal() {
        let total = 0;
        $('.row-total').each(function () {
            total += parseFloat($(this).val()) || 0;
        });
        const discount = parseFloat($('#discountInput').val()) || 0;
        const netAmount = Math.max(0, total - discount);
        
        $('#netAmountText').text(netAmount.toLocaleString(undefined, {minimumFractionDigits: 2}));
        $('#netAmountInput').val(netAmount.toFixed(2));

        const received = parseFloat($('#amountReceived').val()) || 0;
        const balance = netAmount - received;
        $('#balanceAmountText').text(balance.toLocaleString(undefined, {minimumFractionDigits: 2}));
    }

    function fetchLocationStock(rowId) {
    const variationId = $(`#variation${rowId}`).val();
    const locationId = $(`#location${rowId}`).val();
    const stockSpan = $(`#stock_display${rowId}`);

    if (variationId && locationId) {
            stockSpan.text('...');
            
            $.get('/get-location-stock', {
                variation_id: variationId,
                location_id: locationId
            }, function(data) {
                stockSpan.text(data.stock);
                // Optional: color coding
                if(data.stock <= 0) stockSpan.addClass('text-danger').removeClass('text-primary');
                else stockSpan.addClass('text-primary').removeClass('text-danger');
            });
        } else {
            stockSpan.text('0');
        }
    }
</script>
@endsection