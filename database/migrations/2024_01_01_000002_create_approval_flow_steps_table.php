<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_flow_steps');
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

            $table->integer('level')->default(1);
            $table->unsignedBigInteger('role_id')->nullable(); // If using spatie/laravel-permission or similar

            // For approver_id, we assume it follows the same ID type as the package tables or standard. 
            // Ideally this should be configurable or use morph, but let's stick to simple ID/UUID based on config for now.
            // Or better, make it nullable unsignedBigInteger by default, and uuid if use_uuid is true?
            // Let's assume if package uses UUID, User uses UUID.
            if ($useUuid) {
                $table->uuid('approver_id')->nullable();
            } else {
                $table->foreignId('approver_id')->nullable();
            }

            $table->string('action')->default('approve'); // approve, verify, etc.
            $table->timestamps();

            $table->unique(['approval_flow_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('approval-workflow.tables.approval_flow_steps'));
    }
};
