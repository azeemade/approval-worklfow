<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('approval-workflow.tables.approval_flows');

        Schema::create($table, function (Blueprint $table) {
            if (config('approval-workflow.use_uuid')) {
                $table->uuid('id')->primary();
            } else {
                $table->id();
            }
            $table->string('name');
            $table->string('action_type')->index(); // e.g. 'expense', 'leave'
            $table->string('trigger_type')->default('manual'); // 'manual', 'model_save'
            $table->unsignedBigInteger('team_id')->nullable()->index(); // Multi-tenancy support
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('approval-workflow.tables.approval_flows'));
    }
};
