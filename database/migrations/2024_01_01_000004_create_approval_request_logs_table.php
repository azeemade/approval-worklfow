<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_request_logs');
        $requestsTable = config('approval-workflow.tables.approval_requests');

        Schema::create($table, function (Blueprint $table) use ($requestsTable) {
            $useUuid = config('approval-workflow.use_uuid');

            if ($useUuid) {
                $table->uuid('id')->primary();
                $table->foreignUuid('approval_request_id')->constrained($requestsTable)->cascadeOnDelete();
                $table->uuid('user_id')->nullable();
            } else {
                $table->id();
                $table->foreignId('approval_request_id')->constrained($requestsTable)->cascadeOnDelete();
                $table->foreignId('user_id')->nullable();
            }
            $table->string('action'); // approved, rejected, commented, changes_requested
            $table->text('comment')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('approval-workflow.tables.approval_request_logs'));
    }
};
