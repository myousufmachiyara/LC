<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    // Added 'type' to the fillable array
    protected $fillable = ['name', 'code', 'type'];

    /**
     * Get transfers sent FROM this location.
     */
    public function stockTransfersFrom()
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    /**
     * Get transfers sent TO this location.
     */
    public function stockTransfersTo()
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }

    /**
     * Get current stock lots residing at this location.
     */
    public function stockLots()
    {
        return $this->hasMany(StockLot::class);
    }

    public function isVendor()
    {
        return $this->type === 'vendor';
    }

    public function isCustomer()
    {
        return $this->type === 'customer';
    }
}