<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('resource_id');
            $table->text('name');
            $table->text('classification');
            $table->boolean('is_available');

            $table->foreign('resource_id')
                ->references('id')
                ->on('resources')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access_profiles
                ADD CONSTRAINT access_profiles_classification_check
                CHECK (classification IN ('standard', 'privileged'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('access_profiles');
    }
};
