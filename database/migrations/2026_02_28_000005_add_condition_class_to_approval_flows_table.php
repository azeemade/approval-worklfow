<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_flows');

        Schema::table($table, function (Blueprint $table) {
            $table->string('condition_class')->nullable()->after('trigger_type');
            $table->string('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        $tableName = config('approval-workflow.tables.approval_flows');

        if (Schema::hasColumn($tableName, 'condition_class')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('condition_class');
            });
        }
        if (Schema::hasColumn($tableName, 'description')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
