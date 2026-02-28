<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $stepsTable = config('approval-workflow.tables.approval_flow_steps');
        $requestsTable = config('approval-workflow.tables.approval_requests');

        Schema::table($stepsTable, function (Blueprint $table) {
            $table->json('approvers')->nullable()->after('approver_id');
            $table->enum('strategy', ['any', 'all'])->default('any')->after('approvers');
        });

        Schema::table($requestsTable, function (Blueprint $table) {
            $table->json('pending_approvers')->nullable()->after('current_approver_id');
            $table->json('approved_by')->nullable()->after('pending_approvers');
        });
    }

    public function down(): void
    {
        $stepsTable = config('approval-workflow.tables.approval_flow_steps');
        $requestsTable = config('approval-workflow.tables.approval_requests');

        if (Schema::hasColumn($stepsTable, 'approvers')) {
            Schema::table($stepsTable, function (Blueprint $table) {
                $table->dropColumn('approvers');
                $table->dropColumn('strategy');
            });
        }

        if (Schema::hasColumn($requestsTable, 'pending_approvers')) {
            Schema::table($requestsTable, function (Blueprint $table) {
                $table->dropColumn('pending_approvers');
                $table->dropColumn('approved_by');
            });
        }
    }
};
