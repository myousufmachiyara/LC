@extends('layouts.app')
@section('title', 'Inventory Reports')

@section('content')
<div class="tabs">
    {{-- NAV TABS --}}
    <ul class="nav nav-tabs">
        <li class="nav-item"><a class="nav-link {{ $tab=='IL'?'active':'' }}" data-bs-toggle="tab" href="#IL">Item Ledger</a></li>
        <li class="nav-item"><a class="nav-link {{ $tab=='SR'?'active':'' }}" data-bs-toggle="tab" href="#SR">Stock Inhand</a></li>
        <li class="nav-item"><a class="nav-link {{ $tab=='STR'?'active':'' }}" data-bs-toggle="tab" href="#STR">Stock Transfer</a></li>
    </ul>

    <div class="tab-content mt-3">

        {{-- ITEM LEDGER --}}
        <div id="IL" class="tab-pane fade {{ $tab=='IL'?'show active':'' }}">
            <form method="GET" class="mb-3">
                <input type="hidden" name="tab" value="IL">
                <div class="row">
                    <div class="col-md-3">
                        <label>Product</label>
                        <select name="item_id" class="form-control">
                            <option value="">-- All Products --</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                                @foreach($product->variations as $var)
                                    <option value="{{ $product->id }}-{{ $var->id }}" {{ request('item_id') == $product->id.'-'.$var->id ? 'selected' : '' }}>
                                        {{ $product->name }} ({{ $var->sku }})
                                    </option>
                                @endforeach
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>

            @php
                $totalIn  = collect($itemLedger)->sum('qty_in');
                $totalOut = collect($itemLedger)->sum('qty_out');
                $balance  = $totalIn - $totalOut;
            @endphp

            <div class="mb-3 text-end">
                <h5 class="card-title">Closing Balance: <span class="text-danger">{{ $balance }}</span></h5>
            </div>

            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Product</th>
                        <th>Variation</th>
                        <th>Qty In</th>
                        <th>Qty Out</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($itemLedger as $row)
                        <tr>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['type'] }}</td>
                            <td>{{ $row['description'] }}</td>
                            <td>{{ $row['product'] }}</td>
                            <td>{{ $row['variation'] ?? '-' }}</td>
                            <td class="text-success">{{ $row['qty_in'] }}</td>
                            <td class="text-danger">{{ $row['qty_out'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center">No ledger records found.</td></tr>
                    @endforelse
                </tbody>
                @if(count($itemLedger))
                <tfoot>
                    <tr>
                        <th colspan="5" class="text-end">Totals</th>
                        <th class="text-success">{{ $totalIn }}</th>
                        <th class="text-danger">{{ $totalOut }}</th>
                    </tr>
                    <tr>
                        <th colspan="5" class="text-end">Closing Balance</th>
                        <th colspan="2" class="text-primary">{{ $balance }}</th>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>

        {{-- STOCK INHAND --}}
        <div id="SR" class="tab-pane fade {{ $tab=='SR'?'show active':'' }}">
            <form method="GET" class="mb-3">
                <input type="hidden" name="tab" value="SR">
                <div class="row">
                    <div class="col-md-3">
                        <label>Product</label>
                        <select name="item_id" class="form-control">
                            <option value="">-- All Products --</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" {{ request('item_id') == $product->id ? 'selected' : '' }}>{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Location</label>
                        <select name="location_id" class="form-control">
                            <option value="">-- All Locations --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Costing Method</label>
                        <select name="costing_method" class="form-control">
                            <option value="avg" {{ request('costing_method') == 'avg' ? 'selected' : '' }}>Average Rate</option>
                            <option value="latest" {{ request('costing_method') == 'latest' ? 'selected' : '' }}>Latest Rate</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>

            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Location</th>
                        <th>Variation</th>
                        <th>Quantity</th>
                        <th>Cost Price</th>
                        <th>Total Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stockInHand as $stock)
                        <tr>
                            <td>{{ $stock['product'] }}</td>
                            <td><span class="badge bg-info">{{ $stock['location'] }}</span></td>
                            <td>{{ $stock['variation'] ?? '-' }}</td>
                            <td>{{ $stock['quantity'] }}</td>
                            <td>{{ number_format($stock['price'], 2) }}</td>
                            <td>{{ number_format($stock['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center">No stock data found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- STOCK TRANSFER --}}
        <div id="STR" class="tab-pane fade {{ $tab=='STR'?'show active':'' }}">
            <form method="GET" class="mb-3">
                <input type="hidden" name="tab" value="STR">
                <div class="row">
                    <div class="col-md-3">
                        <label>From Location</label>
                        <select name="from_location_id" class="form-control">
                            <option value="">-- All --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ request('from_location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>To Location</label>
                        <select name="to_location_id" class="form-control">
                            <option value="">-- All --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ request('to_location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>From Date</label>
                        <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label>To Date</label>
                        <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>

            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Product</th>
                        <th>Variation</th>
                        <th>From Location</th>
                        <th>To Location</th>
                        <th>Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stockTransfers as $st)
                        <tr>
                            <td>{{ $st['date'] }}</td>
                            <td>{{ $st['reference'] }}</td>
                            <td>{{ $st['product'] }}</td>
                            <td>{{ $st['variation'] ?? '-' }}</td>
                            <td>{{ $st['from'] }}</td>
                            <td>{{ $st['to'] }}</td>
                            <td>{{ $st['quantity'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center">No stock transfer data found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection