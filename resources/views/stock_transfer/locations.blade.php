@extends('layouts.app')

@section('title', 'Stock Transfer | Locations')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif
      
      <header class="card-header">
        <div style="display: flex; justify-content: space-between;">
          <h2 class="card-title">All Locations</h2>
          <div>
            <button type="button" class="modal-with-form btn btn-primary" href="#addLocationModal">
              <i class="fas fa-plus"></i> Add Location
            </button>
          </div>
        </div>
      </header>

      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="datatable-default">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Code</th>
                <th>Type</th> {{-- Added Column --}}
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($locations as $location)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $location->name }}</td>
                <td>{{ $location->code }}</td>
                <td>
                  {{-- Display type with a badge for better visibility --}}
                  <span class="badge {{ $location->type == 'godown' ? 'bg-info' : ($location->type == 'shop' ? 'bg-success' : 'bg-secondary') }}">
                    {{ ucfirst($location->type) }}
                  </span>
                </td>
                
                <td>
                  <a class="text-primary modal-with-form" href="#editLocationModal{{ $location->id }}">
                    <i class="fa fa-edit"></i>
                  </a>
                  <form action="{{ route('locations.destroy', $location->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 m-0 text-danger">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </form>
                </td>
              </tr>

              <div id="editLocationModal{{ $location->id }}" class="modal-block modal-block-warning mfp-hide">
                <section class="card">
                  <form method="post" action="{{ route('locations.update', $location) }}">
                    @csrf
                    @method('PUT')
                    <header class="card-header">
                      <h2 class="card-title">Edit Location</h2>
                    </header>
                    <div class="card-body">
                      <div class="form-group mb-3">
                        <label>Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="{{ old('name', $location->name) }}" required>
                      </div>
                      <div class="form-group mb-3">
                        <label>Code</label>
                        <input type="text" class="form-control" name="code" value="{{ old('code', $location->code) }}">
                      </div>
                      {{-- TYPE DROPDOWN FOR EDIT --}}
                      <div class="form-group mb-3">
                        <label>Location Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-control" required>
                          <option value="godown" {{ $location->type == 'godown' ? 'selected' : '' }}>Godown (Warehouse)</option>
                          <option value="shop" {{ $location->type == 'shop' ? 'selected' : '' }}>Shop (Retail)</option>
                          <option value="vendor" {{ $location->type == 'vendor' ? 'selected' : '' }}>Vendor (Supplier)</option>
                          <option value="customer" {{ $location->type == 'customer' ? 'selected' : '' }}>Customer</option>
                        </select>
                      </div>
                    </div>
                    <footer class="card-footer">
                      <div class="row">
                        <div class="col-md-12 text-end">
                          <button type="submit" class="btn btn-warning">Update</button>
                          <button class="btn btn-default modal-dismiss">Cancel</button>
                        </div>
                      </div>
                    </footer>
                  </form>
                </section>
              </div>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <div id="addLocationModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('locations.store') }}">
          @csrf
          <header class="card-header">
            <h2 class="card-title">New Location</h2>
          </header>
          <div class="card-body">
            <div class="form-group mb-3">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" value="{{ old('name') }}" required>
            </div>
            <div class="form-group mb-3">
              <label>Code</label>
              <input type="text" class="form-control" name="code" value="{{ old('code') }}">
            </div>
            {{-- TYPE DROPDOWN FOR NEW --}}
            <div class="form-group mb-3">
              <label>Location Type <span class="text-danger">*</span></label>
              <select name="type" class="form-control" required>
                <option value="godown" selected>Godown (Warehouse)</option>
                <option value="shop">Shop (Retail)</option>
                <option value="vendor">Vendor (Supplier)</option>
                <option value="customer">Customer</option>
              </select>
            </div>
          </div>
          <footer class="card-footer">
            <div class="row">
              <div class="col-md-12 text-end">
                <button type="submit" class="btn btn-primary">Create</button>
                <button class="btn btn-default modal-dismiss">Cancel</button>
              </div>
            </div>
          </footer>
        </form>
      </section>
    </div>
  </div>
</div>
@endsection