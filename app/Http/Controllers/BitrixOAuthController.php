<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Services\Bitrix\BitrixService;
use App\Services\Sync\AppointmentSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitrixOAuthController extends Controller
{
    public function __construct(
        protected BitrixService $bitrixService,
        protected AppointmentSyncService $syncService
    ) {}

    /**
     * Start OAuth flow with Bitrix24
     */
    public function redirect(Tenant $tenant): RedirectResponse
    {
        if (empty($tenant->b24_domain) || empty($tenant->b24_client_id)) {
            return redirect()->route('dashboard')->with('error', 'Bitrix24 domain and client_id must be configured first.');
        }

        $portal = preg_replace('#^https?://#', '', rtrim($tenant->b24_domain, '/'));
        $authorizeUrl = "https://{$portal}/oauth/authorize/?" . http_build_query([
            'client_id' => $tenant->b24_client_id,
            'state' => $tenant->id,
        ]);

        return redirect()->away($authorizeUrl);
    }

    /**
     * Handle OAuth callback from Bitrix24
     */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $tenantId = $request->query('state');
        $domain = $request->query('domain');
        $memberId = $request->query('member_id');

        if (!$code || !$tenantId) {
            return redirect()->route('dashboard')->with('error', 'Invalid OAuth callback response from Bitrix24.');
        }

        $tenant = Tenant::findOrFail($tenantId);

        // Exchange code for tokens at oauth.bitrix.info
        $tokenRes = Http::get('https://oauth.bitrix.info/oauth/token/', [
            'grant_type' => 'authorization_code',
            'client_id' => $tenant->b24_client_id,
            'client_secret' => $tenant->b24_client_secret,
            'code' => $code,
        ]);

        $data = $tokenRes->json();

        if ($tokenRes->successful() && !empty($data['access_token'])) {
            $tenant->update([
                'b24_domain' => $domain ?: $tenant->b24_domain,
                'b24_member_id' => $memberId ?: ($data['member_id'] ?? $tenant->b24_member_id),
                'b24_access_token' => $data['access_token'],
                'b24_refresh_token' => $data['refresh_token'] ?? $tenant->b24_refresh_token,
                'b24_client_endpoint' => $data['client_endpoint'] ?? "https://{$domain}/rest/",
                'b24_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            ]);

            // Automatically bind CRM tab widget and event listeners
            $this->bitrixService->registerIntegrationPlacements($tenant, $request->getSchemeAndHttpHost());

            return redirect()->route('dashboard')->with('success', 'Bitrix24 connected and configured successfully!');
        }

        return redirect()->route('dashboard')->with('error', 'Failed to retrieve tokens from Bitrix24 authorization server.');
    }

    /**
     * Webhook receiver for Bitrix24 events (e.g. ONCRMDEALUPDATE)
     */
    public function handleWebhook(Request $request, Tenant $tenant): JsonResponse
    {
        $event = $request->input('event');
        $data = $request->input('data');
        $auth = $request->input('auth', []);

        // Security check: Verify event origin matches tenant member_id or domain
        if (!empty($auth)) {
            $incomingMemberId = $auth['member_id'] ?? null;
            $incomingDomain = $auth['domain'] ?? null;

            if (!empty($tenant->b24_member_id) && $incomingMemberId && $incomingMemberId !== $tenant->b24_member_id) {
                Log::warning("Bitrix24 webhook member_id mismatch for tenant {$tenant->name}. Incoming: {$incomingMemberId}");
                return response()->json(['error' => 'Unauthorized member_id'], 403);
            }

            if (!empty($tenant->b24_domain) && $incomingDomain && !str_contains($tenant->b24_domain, $incomingDomain)) {
                Log::warning("Bitrix24 webhook domain mismatch for tenant {$tenant->name}. Incoming: {$incomingDomain}");
                return response()->json(['error' => 'Unauthorized domain'], 403);
            }
        }

        Log::info("Verified Bitrix24 webhook for tenant {$tenant->name}: {$event}", ['data' => $data]);

        // Support Bitrix24 local application ONAPPINSTALL callback
        if ($event === 'ONAPPINSTALL' && !empty($auth['access_token'])) {
            $tenant->update([
                'b24_access_token' => $auth['access_token'],
                'b24_refresh_token' => $auth['refresh_token'] ?? $tenant->b24_refresh_token,
                'b24_member_id' => $auth['member_id'] ?? $tenant->b24_member_id,
                'b24_domain' => $auth['domain'] ?? $tenant->b24_domain,
                'b24_token_expires_at' => now()->addSeconds((int) ($auth['expires_in'] ?? 3600)),
                'b24_client_endpoint' => !empty($auth['client_endpoint']) 
                    ? $auth['client_endpoint'] 
                    : "https://" . ($auth['domain'] ?? $tenant->b24_domain) . "/rest/",
            ]);

            try {
                $this->bitrixService->registerIntegrationPlacements($tenant, $request->getSchemeAndHttpHost());
            } catch (\Exception $e) {
                Log::warning("Bitrix24 ONAPPINSTALL registration warning: " . $e->getMessage());
            }

            return response()->json(['success' => true, 'message' => 'Local application installed successfully']);
        }

        if ($event === 'ONCRMDEALUPDATE' && !empty($data['FIELDS']['ID'])) {
            $dealId = $data['FIELDS']['ID'];
            
            // Check if there is a linked appointment
            $appointment = Appointment::where('tenant_id', $tenant->id)
                ->where('b24_deal_id', (string) $dealId)
                ->first();

            if ($appointment) {
                // Fetch updated deal details from Bitrix
                $dealData = $this->bitrixService->getDeal($tenant, $dealId);
                $stageId = $dealData['STAGE_ID'] ?? null;

                // Match stage to Unite status
                $matchedStatus = null;
                foreach (BitrixService::STATUS_MAP as $status => $info) {
                    if (isset($info['stage']) && $info['stage'] === $stageId) {
                        $matchedStatus = $status;
                        break;
                    }
                }

                if ($matchedStatus && $matchedStatus !== $appointment->status) {
                    $this->syncService->updateStatus($tenant, $appointment, $matchedStatus, 'bitrix');
                }
            }
        }

        return response()->json(['success' => true]);
    }
}
