@extends('layouts.app')

@section('title', 'Stock In/Out')

@section('content')
<div class="row">
  <div class="col-12">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Stock Transfers</h2>
        <a href="{{ route('stock_transfer.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Stock Transfer</a>
      </header>
      <div class="card-body">
        {{-- Filter Form --}}
        <form method="GET" action="{{ route('stock_transfer.index') }}" class="mb-3">
          <div class="row">
            <div class="col-md-2">
              <label>From Date</label>
              <input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}">
            </div>
            <div class="col-md-2">
              <label>To Date</label>
              <input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}">
            </div>
            <div class="col-md-3">
              <label>From Location</label>
              <select name="from_location_id" class="form-control select2-js">
                <option value="">All Locations</option>
                @foreach($locations as $loc)
                  <option value="{{ $loc->id }}" {{ request('from_location_id') == $loc->id ? 'selected' : '' }}>
                    {{ $loc->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>To Location</label>
              <select name="to_location_id" class="form-control select2-js">
                <option value="">All Locations</option>
                @foreach($locations as $loc)
                  <option value="{{ $loc->id }}" {{ request('to_location_id') == $loc->id ? 'selected' : '' }}>
                    {{ $loc->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary btn-block mt-4">
                <i class="fas fa-search"></i> Filter
              </button>
            </div>
          </div>
        </form>

        {{-- Success/Error Messages --}}
        @if(session('success'))
          <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        @endif

        @if(session('error'))
          <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        @endif

        {{-- Data Table --}}
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover">
            <thead>
              <tr>
                <th width="5%">#</th>
                <th>Date</th>
                <th>From Location</th>
                <th>To Location</th>
                <th>Items</th>
                <th>Remarks</th>
                <th>Created By</th>
                <th width="12%">Actions</th>
              </tr>
            </thead>
            <tbody>
              @forelse($transfers as $transfer)
                <tr>
                  <td>{{ $transfer->id }}</td>
                  <td>{{ $transfer->date->format('d-M-Y') }}</td>
                  <td>{{ $transfer->fromLocation->name }}</td>
                  <td>{{ $transfer->toLocation->name }}</td>
                  <td>
                    <span class="badge bg-info">{{ $transfer->details->count() }} items</span>
                  </td>
                  <td>{{ $transfer->remarks ?? '-' }}</td>
                  <td>{{ $transfer->creator->name ?? '-' }}</td>
                  <td>
                    <a href="{{ route('stock_transfer.print', $transfer->id) }}" target="_blank" class="text-success" title="Print"><i class="fas fa-print"></i></a>
                    <a href="{{ route('stock_transfer.edit', $transfer->id) }}" class="text-primary"title="Edit"> <i class="fas fa-edit"></i></a>
                    <form action="{{ route('stock_transfer.destroy', $transfer->id) }}" 
                          method="POST" 
                          class="d-inline" 
                          onsubmit="return confirm('Are you sure? This will reverse all stock movements.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-link p-0 m-0 text-danger" title="Delete">
                            <i class="fa fa-trash-alt"></i>
                        </button>
                    </form>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="8" class="text-center">No stock transfers found.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-3">
          {{ $transfers->withQueryString()->links() }}
        </div>
      </div>
    </section>
  </div>
</div>

<script>
  $(document).ready(function() {
    $('.select2-js').select2({ width: '100%' });
  });
</script>
@endsection