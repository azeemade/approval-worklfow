<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_requests');

        Schema::table($table, function (Blueprint $table) {
            $table->unsignedInteger('rejected_count')->default(0)->after('approved_by');
        });
    }

    public function down(): void
    {
        $table = config('approval-workflow.tables.approval_requests');

        if (Schema::hasColumn($table, 'rejected_count')) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('rejected_count');
            });
        }
    }
};
