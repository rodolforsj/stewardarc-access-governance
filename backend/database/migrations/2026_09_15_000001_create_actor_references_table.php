<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actor_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('external_identity_key')->unique();
            $table->text('display_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actor_references');
    }
};
