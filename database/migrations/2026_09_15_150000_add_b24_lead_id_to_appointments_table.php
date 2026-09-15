<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('appointments') && !Schema::hasColumn('appointments', 'b24_lead_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->string('b24_lead_id')->nullable()->index()->after('b24_deal_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'b24_lead_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropColumn('b24_lead_id');
            });
        }
    }
};
