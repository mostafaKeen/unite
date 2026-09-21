<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Unite\UniteClient;
use App\Services\Bitrix\BitrixService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\TenantSeeder;

class UniteBitrixIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }

    public function test_super_admin_can_access_dashboard_and_manage_tenants(): void
    {
        $superAdmin = User::where('role', User::ROLE_SUPER_ADMIN)->first();
        $this->assertNotNull($superAdmin);

        $response = $this->actingAs($superAdmin)->get('/dashboard');
        $response->assertStatus(200);

        // Super Admin can create new tenant
        $createTenantRes = $this->actingAs($superAdmin)->postJson('/tenants', [
            'name' => 'Saudi German Hospital Dubai',
            'b24_domain' => 'sgh.bitrix24.com',
            'unite_environment' => 'sandbox',
            'unite_base_url' => 'https://ucexternalapi-test.uniteemr.org',
            'unite_app_id' => 'sgh-app-id-1234',
        ]);

        $createTenantRes->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Saudi German Hospital Dubai',
        ]);
    }

    public function test_tenant_admin_cannot_create_or_manage_tenants(): void
    {
        $tenantAdmin = User::where('role', User::ROLE_TENANT_ADMIN)->first();
        $this->assertNotNull($tenantAdmin);

        // Tenant Admin attempts to create tenant -> should be 403 Forbidden
        $response = $this->actingAs($tenantAdmin)->postJson('/tenants', [
            'name' => 'Unauthorized New Clinic',
            'unite_environment' => 'sandbox',
            'unite_base_url' => 'https://ucexternalapi-test.uniteemr.org',
        ]);

        $response->assertStatus(403);
    }

    public function test_tenant_admin_can_manage_their_tenant_users(): void
    {
        $tenantAdmin = User::where('email', 'admin.dubai@unite.ae')->first();
        $this->assertNotNull($tenantAdmin);

        // Tenant Admin creates a new coordinator user for their tenant
        $response = $this->actingAs($tenantAdmin)->postJson('/api/users', [
            'name' => 'Dr. Layla Specialist',
            'email' => 'dr.layla@unite.ae',
            'password' => 'password123',
            'role' => User::ROLE_TENANT_USER,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'dr.layla@unite.ae',
            'tenant_id' => $tenantAdmin->tenant_id,
            'role' => User::ROLE_TENANT_USER,
        ]);
    }

    public function test_bitrix24_crm_widget_remains_open_without_web_login(): void
    {
        $tenant = Tenant::where('slug', 'unite-healthcare-dubai')->first();
        $tenant->update([
            'unite_app_id' => 'test-app-id',
            'unite_app_key' => 'test-app-key',
            'unite_access_token' => 'valid_token_test_123',
            'unite_token_expires_at' => now()->addHours(4),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*/CreateAppointment*' => \Illuminate\Support\Facades\Http::response([
                'Status' => 'Success',
                'Message' => 'Appointment Created Successfully',
                'Data' => [
                    'appointmentid' => 12545545,
                    'appointmentstatus' => 'AAC',
                ],
            ], 200),
        ]);

        // Widget view loads without session auth (Bitrix24 iframe context)
        $response = $this->get("/b24/widget/deal-tab/{$tenant->id}?deal_id=1042");
        $response->assertStatus(200);

        // Slots retrieval works via widget
        $slotsRes = $this->getJson("/b24/widget/deal-tab/{$tenant->id}/slots?clinic_id=DHA-H-44JKWE&doctor_id=DHA-REW688&date=18-04-2024");
        $slotsRes->assertStatus(200);

        // Appointment booking works via widget
        $payload = [
            'b24_deal_id' => '3012',
            'clinicid' => 'DHA-H-44JKWE',
            'clinicname' => 'Unite Clinic Downtown Dubai',
            'doctorid' => 'DHA-REW688',
            'doctorname' => 'Dr. Ravichandran V',
            'firstname' => 'Nasser',
            'lastname' => 'Al-Maktoum',
            'gender' => 'M',
            'mobileno' => '971-509998877',
            'startdatetime' => now()->addDays(2)->format('d-m-Y') . ' 11:30',
        ];

        $bookRes = $this->postJson("/b24/widget/deal-tab/{$tenant->id}/book", $payload);
        $bookRes->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_register_bitrix24_client_secret_encrypted_and_hidden(): void
    {
        $superAdmin = User::where('role', User::ROLE_SUPER_ADMIN)->first();

        $response = $this->actingAs($superAdmin)->postJson('/tenants', [
            'name' => 'King\'s College Hospital London Dubai',
            'b24_domain' => 'kingshospital.bitrix24.com',
            'b24_client_id' => 'local.65e219fa8211.902410',
            'b24_client_secret' => 'super_secret_local_key_998877',
            'unite_environment' => 'sandbox',
            'unite_base_url' => 'https://ucexternalapi-test.uniteemr.org',
            'unite_app_id' => 'kch-app-id-55',
            'unite_app_key' => 'unite_kch_secret_key',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify b24_client_secret is NOT exposed in the JSON response payload
        $json = $response->json();
        $this->assertArrayNotHasKey('b24_client_secret', $json['tenant']);
        $this->assertTrue($json['tenant']['has_b24_client_secret']);

        // Verify decrypted access via model cast
        $tenant = Tenant::where('name', 'King\'s College Hospital London Dubai')->first();
        $this->assertNotNull($tenant);
        $this->assertEquals('super_secret_local_key_998877', $tenant->b24_client_secret);
        $this->assertEquals('local.65e219fa8211.902410', $tenant->b24_client_id);

        // Verify encryption at rest: raw database value is NOT plaintext
        $rawSecret = \Illuminate\Support\Facades\DB::table('tenants')->where('id', $tenant->id)->value('b24_client_secret');
        $this->assertNotEquals('super_secret_local_key_998877', $rawSecret);
        $this->assertStringStartsWith('eyJ', $rawSecret); // Laravel encrypted payload header
    }

    public function test_tenant_update_retains_secret_when_empty_string_passed(): void
    {
        $superAdmin = User::where('role', User::ROLE_SUPER_ADMIN)->first();
        $tenant = Tenant::where('slug', 'unite-healthcare-dubai')->first();

        // First set a known client secret
        $tenant->update(['b24_client_secret' => 'original_secret_123']);

        // Send update without providing a new secret (empty string)
        $updateRes = $this->actingAs($superAdmin)->putJson("/tenants/{$tenant->id}", [
            'name' => 'Unite Healthcare Medical Group (Updated)',
            'b24_client_secret' => '', // blanked on edit form
        ]);

        $updateRes->assertStatus(200)->assertJson(['success' => true]);

        // Secret should remain unchanged
        $freshTenant = $tenant->fresh();
        $this->assertEquals('original_secret_123', $freshTenant->b24_client_secret);
        $this->assertEquals('Unite Healthcare Medical Group (Updated)', $freshTenant->name);

        // Now provide an explicit new secret to rotate
        $rotateRes = $this->actingAs($superAdmin)->putJson("/tenants/{$tenant->id}", [
            'b24_client_secret' => 'rotated_secret_999',
        ]);

        $rotateRes->assertStatus(200);
        $this->assertEquals('rotated_secret_999', $tenant->fresh()->b24_client_secret);
    }

    public function test_bitrix24_onappinstall_callback_saves_tokens(): void
    {
        $tenant = Tenant::where('slug', 'unite-healthcare-dubai')->first();

        $response = $this->postJson("/api/b24/webhook/{$tenant->id}", [
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'access_token' => 'install_access_token_12345',
                'refresh_token' => 'install_refresh_token_67890',
                'expires_in' => 3600,
                'member_id' => $tenant->b24_member_id,
                'domain' => $tenant->b24_domain,
            ],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $fresh = $tenant->fresh();
        $this->assertEquals('install_access_token_12345', $fresh->b24_access_token);
        $this->assertEquals('install_refresh_token_67890', $fresh->b24_refresh_token);
        $this->assertTrue($fresh->has_b24_oauth);
    }

    public function test_get_items_fetches_directly_from_unite_emr_api(): void
    {
        $superAdmin = User::where('role', User::ROLE_SUPER_ADMIN)->first();
        $tenant = Tenant::where('slug', 'unite-healthcare-dubai')->first();

        // Configure valid token so client executes GET /GetItemDetails
        $tenant->update([
            'unite_access_token' => 'real_unite_token_valid_xyz',
            'unite_token_expires_at' => now()->addHours(4),
            'items_cache' => [],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*/GetItemDetails*' => \Illuminate\Support\Facades\Http::response([
                'status' => 'Success',
                'message' => 'Data Fetched Successfully.',
                'data' => [
                    [
                        'clinic_id' => 'DHA-H-44JKWE',
                        'item_code' => 1001,
                        'item_description' => 'General Consultation Live',
                        'price' => 500,
                        'average_time_in_minutes' => 30,
                        'PackageItemDetails' => [],
                    ],
                    [
                        'clinic_id' => 'DHA-H-44JKWE',
                        'item_code' => 2001,
                        'item_description' => 'Full Body Checkup Live',
                        'price' => 2500,
                        'average_time_in_minutes' => 120,
                        'PackageItemDetails' => [
                            [
                                'cpt_code' => 'CPT101',
                                'item_description' => 'Blood Test',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($superAdmin)->getJson("/tenants/{$tenant->id}/items");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'source' => 'unite_emr_api',
                'count' => 2,
            ])
            ->assertJsonPath('items.0.item_code', 1001)
            ->assertJsonPath('items.0.item_description', 'General Consultation Live')
            ->assertJsonPath('items.1.item_code', 2001)
            ->assertJsonPath('items.1.PackageItemDetails.0.cpt_code', 'CPT101');

        // Verify no database cache was populated or written
        $this->assertEmpty($tenant->fresh()->items_cache);
    }
}
