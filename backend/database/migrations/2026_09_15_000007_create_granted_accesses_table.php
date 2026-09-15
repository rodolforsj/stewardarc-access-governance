<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('granted_accesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('grant_confirmation_id')->unique();
            $table->timestampTz('valid_until_at')->nullable();

            $table->foreign('grant_confirmation_id')
                ->references('id')
                ->on('grant_confirmations')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('granted_accesses');
    }
};
