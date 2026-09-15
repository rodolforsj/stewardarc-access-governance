<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('requester_actor_reference_id');
            $table->uuid('access_profile_id');
            $table->text('justification');
            $table->text('approval_flow');
            $table->integer('requested_duration_seconds')->nullable();
            $table->text('current_state');
            $table->timestampTz('requested_at');

            $table->foreign('requester_actor_reference_id')
                ->references('id')
                ->on('actor_references')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreign('access_profile_id')
                ->references('id')
                ->on('access_profiles')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access_requests
                ADD CONSTRAINT access_requests_approval_flow_check
                CHECK (approval_flow IN ('standard', 'privileged'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE access_requests
                ADD CONSTRAINT access_requests_current_state_check
                CHECK (current_state IN ('S1', 'S2', 'S3', 'S4', 'S5'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE access_requests
                ADD CONSTRAINT access_requests_requested_duration_seconds_check
                CHECK (
                    (
                        approval_flow = 'privileged'
                        AND requested_duration_seconds IS NOT NULL
                        AND requested_duration_seconds > 0
                        AND requested_duration_seconds <= 7776000
                    )
                    OR (
                        approval_flow = 'standard'
                        AND requested_duration_seconds IS NULL
                    )
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX access_requests_processing_requester_profile_unique
                ON access_requests (requester_actor_reference_id, access_profile_id)
                WHERE current_state IN ('S1', 'S2', 'S3')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
