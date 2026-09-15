<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revocation_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('granted_access_id')->unique();
            $table->uuid('actor_reference_id');
            $table->timestampTz('recorded_at');

            $table->foreign('granted_access_id')
                ->references('id')
                ->on('granted_accesses')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreign('actor_reference_id')
                ->references('id')
                ->on('actor_references')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revocation_confirmations');
    }
};
