<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_requests');
        $flowsTable = config('approval-workflow.tables.approval_flows');

        Schema::create($table, function (Blueprint $table) use ($flowsTable) {
            $useUuid = config('approval-workflow.use_uuid');

            if ($useUuid) {
                $table->uuid('id')->primary();
                $table->foreignUuid('approval_flow_id')->constrained($flowsTable)->cascadeOnDelete();
            } else {
                $table->id();
                $table->foreignId('approval_flow_id')->constrained($flowsTable)->cascadeOnDelete();
            }

            if ($useUuid) {
                // For UUID morphs
                $table->uuidMorphs('model');
            } else {
                $table->morphs('model');
            }

            $table->integer('current_level')->default(1);
            $table->string('status')->default('pending'); // pending, approved, rejected, returned

            if ($useUuid) {
                $table->uuid('creator_id')->nullable();
                $table->uuid('current_approver_id')->nullable();
            } else {
                $table->foreignId('creator_id')->nullable();
                $table->foreignId('current_approver_id')->nullable();
            }

            $table->json('metadata')->nullable(); // Extra data like frontend URLs
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index for faster lookups
            $table->index(['model_type', 'model_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('approval-workflow.tables.approval_requests'));
    }
};
