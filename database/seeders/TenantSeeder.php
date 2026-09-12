<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Primary Tenant with the credentials provided by the user
        $tenant1 = Tenant::updateOrCreate(
            ['slug' => 'unite-healthcare-dubai'],
            [
                'name' => 'Unite Healthcare Medical Group (Dubai)',
                'status' => 'active',
                'b24_domain' => 'unite-health.bitrix24.com',
                'b24_member_id' => 'b24-member-uae-01',
                'b24_client_id' => 'local.65e219fa8211.902410',
                'b24_client_secret' => 'b24_sec_991823abce12879412',
                'b24_client_endpoint' => 'https://unite-health.bitrix24.com/rest/',
                'b24_deal_category_id' => 1,
                
                // Unite EMR credentials from User Prompt
                'unite_environment' => 'sandbox',
                'unite_base_url' => 'https://ucexternalapi-test.uniteemr.org',
                'unite_app_id' => 'b026f3c3-7d07-4e39-a6c9-95daa5e9333c',
                'unite_app_key' => 'H%goRVWeahvLNTuFNSy^N%Jiu3V+X(gl',
                'unite_initial_token' => 'AT-2-2-C48VsdfDFF7kNEVUt852n_Rk1_fWhq-_u',
                'unite_access_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCIkpXVCJ9.eyJqdGkiOiJmZDA3ZD...',
                'unite_refresh_token' => 'IUzI1NiIsInR5cCI6IkpXVCJ9.eyJqdGkiOiIxYzFhNjg0Yi0yNWNk...',
                'unite_token_expires_at' => now()->addMinutes(200),
                'default_clinic_id' => 'DHA-H-44JKWE',
                
                'clinics_cache' => [
                    [
                        'clinic_id' => 'DHA-H-44JKWE',
                        'name' => 'Unite Clinic Downtown Dubai',
                        'city' => 'Dubai',
                        'phone' => '+971 4 399 2200',
                    ],
                    [
                        'clinic_id' => 'DHA-H-7WF788',
                        'name' => 'Unite Clinic Dubai Hills',
                        'city' => 'Dubai',
                        'phone' => '+971 4 399 2201',
                    ],
                    [
                        'clinic_id' => 'DHA-H-992KLQ',
                        'name' => 'Unite Specialist Medical Center',
                        'city' => 'Dubai',
                        'phone' => '+971 4 399 2202',
                    ]
                ],
                'doctors_cache' => [
                    [
                        'doctor_id' => 'DHA-REW688',
                        'name' => 'Dr. Ravichandran V',
                        'specialty' => 'General Medicine & Family Practice',
                        'clinics' => ['DHA-H-44JKWE', 'DHA-H-992KLQ'],
                    ],
                    [
                        'doctor_id' => 'DHA-487KJGG',
                        'name' => 'Dr. George Mathew',
                        'specialty' => 'Cardiology & Internal Medicine',
                        'clinics' => ['DHA-H-44JKWE', 'DHA-H-7WF788'],
                    ],
                    [
                        'doctor_id' => 'DHA-P91848408',
                        'name' => 'Dr. Sarah Al Mansoori',
                        'specialty' => 'Dermatology & Aesthetics',
                        'clinics' => ['DHA-H-7WF788', 'DHA-H-992KLQ'],
                    ],
                    [
                        'doctor_id' => 'DHA-1234567',
                        'name' => 'Dr. Syed Farhan',
                        'specialty' => 'Pediatrics & Child Care',
                        'clinics' => ['DHA-H-44JKWE', 'DHA-H-7WF788', 'DHA-H-992KLQ'],
                    ]
                ],
                'items_cache' => [
                    [
                        'clinic_id' => 'DHA-H-44JKWE',
                        'item_code' => 101,
                        'item_description' => 'General Physician Consultation',
                        'price' => 250.00,
                        'average_time_in_minutes' => 20,
                        'PackageItemDetails' => []
                    ],
                    [
                        'clinic_id' => 'DHA-H-44JKWE',
                        'item_code' => 102,
                        'item_description' => 'Specialist Doctor Consultation',
                        'price' => 450.00,
                        'average_time_in_minutes' => 30,
                        'PackageItemDetails' => []
                    ],
                    [
                        'clinic_id' => 'DHA-H-44JKWE',
                        'item_code' => 20842,
                        'item_description' => 'Executive Health Screening Package',
                        'price' => 1200.00,
                        'average_time_in_minutes' => 60,
                        'PackageItemDetails' => [
                            ['cpt_code' => '80053', 'item_description' => 'Comprehensive Metabolic Panel'],
                            ['cpt_code' => '85025', 'item_description' => 'Complete Blood Count (CBC)']
                        ]
                    ],
                    [
                        'clinic_id' => 'DHA-H-7WF788',
                        'item_code' => 305,
                        'item_description' => 'Dermatology Skin Consultation & Analysis',
                        'price' => 350.00,
                        'average_time_in_minutes' => 25,
                        'PackageItemDetails' => []
                    ]
                ],
            ]
        );

        // 2. Secondary Tenant demonstrating multi-tenancy
        $tenant2 = Tenant::updateOrCreate(
            ['slug' => 'al-zahra-wellness-abudhabi'],
            [
                'name' => 'Al Zahra Wellness & EMR Clinic (Abu Dhabi)',
                'status' => 'active',
                'b24_domain' => 'alzahra-group.bitrix24.com',
                'b24_member_id' => 'b24-member-auh-02',
                'b24_client_id' => 'local.718bc009121a.489102',
                'b24_client_secret' => 'b24_sec_8891244199aa',
                'b24_client_endpoint' => 'https://alzahra-group.bitrix24.com/rest/',
                'b24_deal_category_id' => 2,
                'unite_environment' => 'sandbox',
                'unite_base_url' => 'https://ucexternalapi-test.uniteemr.org',
                'unite_app_id' => 'c891e442-1a22-4821-bc77-88910293411b',
                'unite_app_key' => 'K^po88We!vLNTuFNSy$N#Jiu8X+Y(zz',
                'unite_initial_token' => 'AT-3-3-Z99VwerKFF8kNEVUt741m_Rk2_xQwq-_a',
                'unite_access_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCIkpXVCJ9.auh_sample...',
                'unite_refresh_token' => 'IUzI1NiIsInR5cCI6IkpXVCJ9.auh_refresh...',
                'unite_token_expires_at' => now()->addMinutes(180),
                'default_clinic_id' => 'DOH-H-19028',
                'clinics_cache' => [
                    [
                        'clinic_id' => 'DOH-H-19028',
                        'name' => 'Al Zahra Wellness Center Abu Dhabi',
                        'city' => 'Abu Dhabi',
                        'phone' => '+971 2 688 1100',
                    ]
                ],
                'doctors_cache' => [
                    [
                        'doctor_id' => 'DOH-DR-9011',
                        'name' => 'Dr. Fatima Al Nuaimi',
                        'specialty' => 'Obstetrics & Gynecology',
                        'clinics' => ['DOH-H-19028'],
                    ]
                ],
                'items_cache' => [
                    [
                        'clinic_id' => 'DOH-H-19028',
                        'item_code' => 501,
                        'item_description' => 'Women Wellness Comprehensive Checkup',
                        'price' => 850.00,
                        'average_time_in_minutes' => 45,
                        'PackageItemDetails' => []
                    ]
                ]
            ]
        );

        // Seed sample appointments for Tenant 1
        Appointment::updateOrCreate(
            ['unite_appointment_id' => '12545545'],
            [
                'tenant_id' => $tenant1->id,
                'b24_deal_id' => '1042',
                'b24_contact_id' => '580',
                'clinic_id' => 'DHA-H-44JKWE',
                'clinic_name' => 'Unite Clinic Downtown Dubai',
                'doctor_id' => 'DHA-REW688',
                'doctor_name' => 'Dr. Ravichandran V',
                'patient_firstname' => 'Mohammed',
                'patient_lastname' => 'Al-Hashemi',
                'patient_gender' => 'M',
                'patient_mobileno' => '971-501234567',
                'patient_email' => 'm.alhashemi@example.com',
                'patient_dob' => '14-03-1990',
                'patient_phototype' => 'EMIRATES_ID',
                'patient_photoid' => '784199060660000',
                'start_datetime' => now()->addDays(1)->setTime(10, 30),
                'duration_minutes' => 30,
                'status' => 'ACF',
                'status_description' => 'Appointment Confirmed',
                'remarks' => 'Routine cardiology follow up',
                'requested_by' => 'Self',
                'item_codes' => [101],
                'last_synced_source' => 'bitrix',
                'synced_at' => now(),
            ]
        );

        Appointment::updateOrCreate(
            ['unite_appointment_id' => '991000000115806'],
            [
                'tenant_id' => $tenant1->id,
                'b24_deal_id' => '1058',
                'b24_contact_id' => '612',
                'clinic_id' => 'DHA-H-7WF788',
                'clinic_name' => 'Unite Clinic Dubai Hills',
                'doctor_id' => 'DHA-P91848408',
                'doctor_name' => 'Dr. Sarah Al Mansoori',
                'patient_firstname' => 'Arun',
                'patient_lastname' => 'Kumar',
                'patient_gender' => 'M',
                'patient_mobileno' => '971-551234568',
                'patient_email' => 'arun.k@example.com',
                'patient_dob' => '22-07-1988',
                'patient_phototype' => 'EMIRATES_ID',
                'patient_photoid' => '784198812345678',
                'start_datetime' => now()->addDays(2)->setTime(15, 30),
                'duration_minutes' => 25,
                'status' => 'AAC',
                'status_description' => 'Appointment Awaiting Confirmation',
                'remarks' => 'Dermatology consultation',
                'requested_by' => 'Bitrix24 CRM',
                'item_codes' => [305],
                'last_synced_source' => 'unite',
                'synced_at' => now(),
            ]
        );

        Appointment::updateOrCreate(
            ['unite_appointment_id' => '12545890'],
            [
                'tenant_id' => $tenant1->id,
                'b24_deal_id' => '1015',
                'b24_contact_id' => '492',
                'clinic_id' => 'DHA-H-44JKWE',
                'clinic_name' => 'Unite Clinic Downtown Dubai',
                'doctor_id' => 'DHA-487KJGG',
                'doctor_name' => 'Dr. George Mathew',
                'patient_firstname' => 'Fatima',
                'patient_lastname' => 'Zahra',
                'patient_gender' => 'F',
                'patient_mobileno' => '971-529876543',
                'patient_email' => 'fatima.z@example.com',
                'patient_dob' => '05-11-1994',
                'patient_phototype' => 'EMIRATES_ID',
                'patient_photoid' => '784199455667788',
                'start_datetime' => now()->subDay()->setTime(11, 0),
                'duration_minutes' => 60,
                'status' => 'APH',
                'status_description' => 'Appointment Honoured',
                'remarks' => 'Executive health checkup completed',
                'requested_by' => 'Father',
                'item_codes' => [20842],
                'invoice_reference' => 'UCM/C/1001250',
                'invoice_total' => 1260.00,
                'invoice_details' => [
                    [
                        'item_code' => 20842,
                        'item_description' => 'Executive Health Screening Package',
                        'item_price' => 1200,
                        'line_qty' => 1,
                        'line_gross_amt' => 1200,
                        'line_disc_amt' => 0,
                        'line_net_amt' => 1200,
                        'vat_per' => 5,
                        'vat_amt' => 60,
                    ]
                ],
                'invoice_payments' => [
                    [
                        'payment_mode' => 'CARD',
                        'paid_amt' => 1260,
                        'payment_reference_number' => '451145447645',
                        'transaction_card_type' => 'VISA',
                        'bank_name' => 'ENBD',
                        'paid_date' => now()->subDay()->format('d-m-Y H:i'),
                    ]
                ],
                'last_synced_source' => 'unite',
                'synced_at' => now(),
            ]
        );

        // Seed sample sync logs
        SyncLog::create([
            'tenant_id' => $tenant1->id,
            'direction' => 'auth',
            'entity_type' => 'auth',
            'status' => 'success',
            'message' => 'Successfully authenticated with Unite EMR Sandbox Gateway (App ID: b026f3c3-...)',
            'payload' => ['app_id' => 'b026f3c3-7d07-4e39-a6c9-95daa5e9333c'],
            'response' => ['Status' => 'Success', 'expires_in' => 240],
        ]);

        SyncLog::create([
            'tenant_id' => $tenant1->id,
            'direction' => 'bitrix_to_unite',
            'entity_type' => 'appointment',
            'status' => 'success',
            'message' => 'Created appointment #12545545 for patient Mohammed Al-Hashemi',
            'payload' => ['doctorid' => 'DHA-REW688', 'clinicid' => 'DHA-H-44JKWE', 'b24_deal_id' => '1042'],
            'response' => ['Status' => 'Success', 'Data' => ['appointmentid' => 12545545, 'appointmentstatus' => 'ACF']],
        ]);

        SyncLog::create([
            'tenant_id' => $tenant1->id,
            'direction' => 'unite_to_bitrix',
            'entity_type' => 'status',
            'status' => 'success',
            'message' => 'Synchronized status ACF to Bitrix24 Deal #1042 Stage C1:PREPAYMENT_INVOICE',
            'payload' => ['deal_id' => 1042, 'stage' => 'C1:PREPAYMENT_INVOICE'],
            'response' => ['result' => true],
        ]);

        // 3. Seed Users with Roles & Tenant Scoping
        // Super Admin (Full access to manage all tenants and platform settings)
        User::updateOrCreate(
            ['email' => 'admin@unite.ae'],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SUPER_ADMIN,
                'tenant_id' => null,
            ]
        );

        // Tenant Admin: Unite Healthcare Dubai
        User::updateOrCreate(
            ['email' => 'admin.dubai@unite.ae'],
            [
                'name' => 'Dubai Clinic Administrator',
                'password' => Hash::make('password'),
                'role' => User::ROLE_TENANT_ADMIN,
                'tenant_id' => $tenant1->id,
            ]
        );

        // Tenant User: Unite Healthcare Dubai
        User::updateOrCreate(
            ['email' => 'staff.dubai@unite.ae'],
            [
                'name' => 'Dubai Clinical Coordinator',
                'password' => Hash::make('password'),
                'role' => User::ROLE_TENANT_USER,
                'tenant_id' => $tenant1->id,
            ]
        );

        // Tenant Admin: Al Zahra Abu Dhabi
        User::updateOrCreate(
            ['email' => 'admin.auh@alzahra.ae'],
            [
                'name' => 'Abu Dhabi Clinic Administrator',
                'password' => Hash::make('password'),
                'role' => User::ROLE_TENANT_ADMIN,
                'tenant_id' => $tenant2->id,
            ]
        );
    }
}
