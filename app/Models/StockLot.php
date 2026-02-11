<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'product_id',
        'variation_id',
        'lot_number',
        'quantity'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    // Relationships
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class);
    }
}