<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('post_dated_cheques', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['receivable', 'payable'])->default('receivable'); // New
            $table->string('cheque_number'); // Removed unique so transferred cheques can exist
            $table->date('cheque_date');
            $table->decimal('amount', 15, 2);
            $table->string('bank_name');
            $table->string('remarks')->nullable();
            
            // Who gave it to us (Receivable) OR Who we gave it to (Payable)
            $table->string('party_name'); 
            
            // For transfers: if we give a received cheque to a customer
            $table->string('transfer_to_party')->nullable(); 

            $table->enum('status', ['received', 'issued', 'transferred', 'deposited', 'cleared', 'bounced'])
                ->default('received');
            $table->date('deposited_at')->nullable();
            $table->date('cleared_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void { Schema::dropIfExists('post_dated_cheques'); }
};