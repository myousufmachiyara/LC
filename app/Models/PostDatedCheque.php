<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostDatedCheque extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type', // Add this
        'cheque_number', 
        'cheque_date', 
        'amount', 
        'bank_name', 
        'party_name', 
        'transfer_to_party', // Add this
        'status', 
        'deposited_at', 
        'cleared_at', 
        'remarks', 
        'created_by'
    ];

    protected $casts = [
        'cheque_date' => 'date',
        'deposited_at' => 'date',
        'cleared_at' => 'date',
    ];

    // Helper to check if the cheque is due for deposit
    public function scopeDue($query)
    {
        return $query->where('cheque_date', '<=', now())->where('status', 'received');
    }
}
