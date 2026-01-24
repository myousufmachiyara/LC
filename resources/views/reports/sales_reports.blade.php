@extends('layouts.app')

@section('title', 'Sales Reports')

@section('content')
<div class="tabs">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab==='SR'?'active':'' }}" href="{{ route('reports.sale', ['tab'=>'SR','from_date'=>$from,'to_date'=>$to]) }}">Sales Register</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='SRET'?'active':'' }}" href="{{ route('reports.sale', ['tab'=>'SRET','from_date'=>$from,'to_date'=>$to]) }}">Sales Return</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='CW'?'active':'' }}" href="{{ route('reports.sale', ['tab'=>'CW','from_date'=>$from,'to_date'=>$to]) }}">Customer Wise</a>
        </li>
    </ul>

    <div class="tab-content mt-3">
        {{-- SALES REGISTER --}}
        <div id="SR" class="tab-pane fade {{ $tab==='SR'?'show active':'' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="row g-3 mb-3">
                <input type="hidden" name="tab" value="SR">
                <div class="col-md-3">
                    <label>From Date</label>
                    <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                </div>
                <div class="col-md-3">
                    <label>To Date</label>
                    <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>

            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sales as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>{{ $row->invoice }}</td>
                            <td>{{ $row->customer }}</td>
                            <td>{{ number_format($row->total,2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No sales found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- SALES RETURN --}}
        <div id="SRET" class="tab-pane fade {{ $tab==='SRET'?'show active':'' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="row g-3 mb-3">
                <input type="hidden" name="tab" value="SRET">
                <div class="col-md-3">
                    <label>From Date</label>
                    <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                </div>
                <div class="col-md-3">
                    <label>To Date</label>
                    <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>

            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Return No</th>
                        <th>Customer</th>
                        <th>Total Return</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($returns as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>{{ $row->invoice }}</td>
                            <td>{{ $row->customer }}</td>
                            <td>{{ number_format($row->total,2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No returns found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- CUSTOMER WISE --}}
        <div id="CW" class="tab-pane fade {{ $tab === 'CW' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="row g-3 mb-3">
                <input type="hidden" name="tab" value="CW">
                <div class="col-md-3">
                    <label>From Date</label>
                    <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                </div>
                <div class="col-md-3">
                    <label>To Date</label>
                    <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                </div>
                <div class="col-md-3">
                    <label>Customer</label>
                    <select name="customer_id" class="form-control">
                        <option value="">All Customers</option>
                        @foreach($customers as $cust)
                            <option value="{{ $cust->id }}" {{ $customerId == $cust->id ? 'selected' : '' }}>
                                {{ $cust->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>

            <table class="table table-bordered table-striped">
                <thead class="table-dark">
                    <tr>
                        <th>Customer Name</th>
                        <th class="text-center">No. of Invoices</th>
                        <th class="text-end">Total Revenue (Net)</th>
                    </tr>
                </thead>
                <tbody>
                    @php $grandTotal = 0; @endphp
                    @forelse($customerWise as $row)
                        <tr>
                            <td>{{ $row->customer }}</td>
                            <td class="text-center">{{ $row->count }}</td>
                            <td class="text-end">{{ number_format($row->total, 2) }}</td>
                        </tr>
                        @php $grandTotal += $row->total; @endphp
                    @empty
                        <tr><td colspan="3" class="text-center text-muted">No sales data found.</td></tr>
                    @endforelse
                </tbody>
                @if($customerWise->count() > 0)
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="2" class="text-end">Grand Total:</td>
                        <td class="text-end text-primary">{{ number_format($grandTotal, 2) }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection
