<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('access_request_id');
            $table->text('stage');
            $table->text('outcome');
            $table->uuid('actor_reference_id');
            $table->text('justification')->nullable();
            $table->timestampTz('decided_at');

            $table->foreign('access_request_id')
                ->references('id')
                ->on('access_requests')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreign('actor_reference_id')
                ->references('id')
                ->on('actor_references')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->unique(['access_request_id', 'stage']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE decisions
                ADD CONSTRAINT decisions_stage_check
                CHECK (stage IN ('resource_owner', 'governance'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE decisions
                ADD CONSTRAINT decisions_outcome_check
                CHECK (outcome IN ('approved', 'rejected'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE decisions
                ADD CONSTRAINT decisions_rejection_justification_check
                CHECK (
                    outcome <> 'rejected'
                    OR (justification IS NOT NULL AND btrim(justification) <> '')
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
