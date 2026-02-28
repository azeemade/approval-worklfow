<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_requests');

        Schema::table($table, function (Blueprint $table) {
            $table->json('requested_changes')->nullable()->after('metadata');
            $table->json('removed_approvers')->nullable()->after('requested_changes');
        });
    }

    public function down(): void
    {
        $tableName = config('approval-workflow.tables.approval_requests');

        if (Schema::hasColumn($tableName, 'requested_changes')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('requested_changes');
            });
        }

        if (Schema::hasColumn($tableName, 'removed_approvers')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('removed_approvers');
            });
        }
    }
};
