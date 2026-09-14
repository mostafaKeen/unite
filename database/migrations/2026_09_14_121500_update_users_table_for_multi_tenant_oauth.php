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
        Schema::table('users', function (Blueprint $table) {
            // Drop global unique email constraint
            $table->dropUnique(['email']);

            // Add Bitrix24 User ID column
            $table->string('b24_user_id')->nullable()->after('tenant_id');

            // Composite index for multi-tenant email uniqueness (email is unique per tenant)
            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tenant_email_unique');
            $table->dropColumn('b24_user_id');
            $table->unique('email');
        });
    }
};
