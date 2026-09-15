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
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');

            // Bitrix24 Portal & OAuth
            $table->string('b24_domain')->nullable();
            $table->string('b24_member_id')->nullable()->unique();
            $table->text('b24_client_id')->nullable();
            $table->text('b24_client_secret')->nullable();
            $table->text('b24_access_token')->nullable();
            $table->text('b24_refresh_token')->nullable();
            $table->timestamp('b24_token_expires_at')->nullable();
            $table->string('b24_client_endpoint')->nullable();
            $table->integer('b24_deal_category_id')->nullable()->default(0);

            // Unite EMR Credentials & Auth
            $table->enum('unite_environment', ['sandbox', 'production'])->default('sandbox');
            $table->string('unite_base_url')->default('https://ucexternalapi-test.uniteemr.org');
            $table->text('unite_app_id')->nullable();
            $table->text('unite_app_key')->nullable();
            $table->text('unite_initial_token')->nullable();
            $table->text('unite_access_token')->nullable();
            $table->text('unite_refresh_token')->nullable();
            $table->timestamp('unite_token_expires_at')->nullable();
            
            // Multiple Clinics & Cached Data
            $table->string('default_clinic_id')->nullable();
            $table->json('clinics_cache')->nullable();
            $table->json('doctors_cache')->nullable();
            $table->json('items_cache')->nullable();

            // Settings & Mappings
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('unite_appointment_id')->nullable()->index();
            $table->string('b24_deal_id')->nullable()->index();
            $table->string('b24_lead_id')->nullable()->index();
            $table->string('b24_contact_id')->nullable()->index();
            
            // Clinic & Doctor
            $table->string('clinic_id');
            $table->string('clinic_name')->nullable();
            $table->string('doctor_id');
            $table->string('doctor_name')->nullable();

            // Patient
            $table->string('patient_firstname');
            $table->string('patient_middlename')->nullable();
            $table->string('patient_lastname');
            $table->string('patient_gender', 5)->default('U'); // M, F, U
            $table->string('patient_mobileno');
            $table->string('patient_email')->nullable();
            $table->string('patient_dob')->nullable();
            $table->string('patient_phototype')->nullable(); // EMIRATES_ID, Passport, etc.
            $table->string('patient_photoid')->nullable();
            $table->string('patient_pin')->nullable();
            $table->string('patient_nationality')->nullable();

            // Appointment schedule
            $table->dateTime('start_datetime');
            $table->integer('duration_minutes')->default(15);
            $table->string('status', 10)->default('AAC'); // AAC, ACF, APH, CNR, CVI, YTC, NSW
            $table->string('status_description')->nullable();
            $table->text('remarks')->nullable();
            $table->string('requested_by')->nullable();
            $table->json('item_codes')->nullable();

            // Invoicing
            $table->string('invoice_reference')->nullable();
            $table->json('invoice_details')->nullable();
            $table->json('invoice_payments')->nullable();
            $table->decimal('invoice_total', 10, 2)->nullable();

            // Sync tracking
            $table->string('last_synced_source', 20)->default('unite'); // unite | bitrix
            $table->string('sync_hash')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('direction', 30); // bitrix_to_unite, unite_to_bitrix, unite_poll, auth
            $table->string('entity_type', 30); // appointment, status, invoice, directory
            $table->string('status', 20); // success, failed, warning
            $table->string('message')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('tenants');
    }
};
