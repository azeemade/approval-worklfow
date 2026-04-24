<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_flows');

        Schema::table($table, function (Blueprint $table) {
            $table->unsignedInteger('rejection_threshold')->default(1)->after('is_active');
        });
    }

    public function down(): void
    {
        $table = config('approval-workflow.tables.approval_flows');

        if (Schema::hasColumn($table, 'rejection_threshold')) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('rejection_threshold');
            });
        }
    }
};
