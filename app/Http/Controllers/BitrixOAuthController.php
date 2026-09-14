<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Bitrix\BitrixService;
use App\Services\Sync\AppointmentSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BitrixOAuthController extends Controller
{
    public function __construct(
        protected BitrixService $bitrixService,
        protected AppointmentSyncService $syncService
    ) {}

    /**
     * Handle local application iframe launch / installation view inside Bitrix24 portal
     */
    public function handleAppLaunch(Request $request, ?Tenant $tenant = null): Response
    {
        $input = $request->all();
        $domain = $request->input('DOMAIN') ?: $request->input('domain');
        $memberId = $request->input('member_id');
        $authToken = $request->input('AUTH_ID') ?: $request->input('access_token');
        $refreshToken = $request->input('REFRESH_ID') ?: $request->input('refresh_token');

        Log::info("Bitrix24 App Launch/Handler invoked", [
            'method' => $request->method(),
            'tenant_param' => $tenant?->id,
            'domain' => $domain,
            'member_id' => $memberId,
            'placement' => $request->input('PLACEMENT'),
            'placement_options' => $request->input('PLACEMENT_OPTIONS'),
            'params' => $input,
        ]);

        if (!$tenant && $domain) {
            $tenant = Tenant::where('b24_domain', 'like', "%{$domain}%")->first();
        }

        if (!$tenant && $memberId) {
            $tenant = Tenant::where('b24_member_id', $memberId)->first();
        }

        if (!$tenant) {
            $tenant = Tenant::first();
        }

        if ($tenant && !empty($authToken)) {
            $tenant->update([
                'b24_access_token' => $authToken,
                'b24_refresh_token' => $refreshToken ?: $tenant->b24_refresh_token,
                'b24_domain' => $domain ?: $tenant->b24_domain,
                'b24_member_id' => $memberId ?: $tenant->b24_member_id,
                'b24_token_expires_at' => now()->addSeconds((int)($request->input('AUTH_EXPIRES') ?: 3600)),
            ]);

            try {
                $this->bitrixService->registerIntegrationPlacements($tenant, $request->getSchemeAndHttpHost());
            } catch (\Exception $e) {
                Log::warning("Automatic placement registration on app launch warning: " . $e->getMessage());
            }
        }

        return Inertia::render('Bitrix/LocalApp', [
            'tenant' => $tenant,
            'b24Context' => [
                'domain' => $domain ?: $tenant?->b24_domain,
                'member_id' => $memberId ?: $tenant?->b24_member_id,
                'placement' => $request->input('PLACEMENT', 'DEFAULT'),
                'placement_options' => $request->input('PLACEMENT_OPTIONS'),
                'app_sid' => $request->input('APP_SID'),
            ],
            'isConfigured' => $tenant ? $tenant->isBitrixAuthenticated() : false,
        ]);
    }

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

    /**
     * Start User Sign-In OAuth flow with Bitrix24 for specific Tenant
     */
    public function userRedirect(Tenant $tenant): RedirectResponse
    {
        if (empty($tenant->b24_domain) || empty($tenant->b24_client_id)) {
            return redirect()->route('login')->with('error', "Bitrix24 OAuth is not configured for facility: {$tenant->name}.");
        }

        $portal = preg_replace('#^https?://#', '', rtrim($tenant->b24_domain, '/'));
        $redirectUri = route('b24.auth.user-callback');
        $authorizeUrl = "https://{$portal}/oauth/authorize/?" . http_build_query([
            'client_id' => $tenant->b24_client_id,
            'state' => $tenant->id,
            'redirect_uri' => $redirectUri,
        ]);

        Log::info("Initiating Bitrix24 User Sign-In OAuth for Tenant {$tenant->name}", [
            'tenant_id' => $tenant->id,
            'portal' => $portal,
            'redirect_uri' => $redirectUri,
        ]);

        return redirect()->away($authorizeUrl);
    }

    /**
     * Handle Bitrix24 User OAuth callback, detect user email & profile, and log user into Unite platform
     */
    public function userCallback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $tenantId = $request->query('state');
        $domain = $request->query('domain');

        Log::info("Received Bitrix24 User OAuth callback", [
            'code' => $code ? 'RECEIVED' : 'MISSING',
            'tenant_id' => $tenantId,
            'domain' => $domain,
        ]);

        if (!$code || !$tenantId) {
            return redirect()->route('login')->with('error', 'Invalid OAuth callback response from Bitrix24.');
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return redirect()->route('login')->with('error', 'Specified facility tenant was not found.');
        }

        // Exchange code for user access token
        $tokenRes = Http::get('https://oauth.bitrix.info/oauth/token/', [
            'grant_type' => 'authorization_code',
            'client_id' => $tenant->b24_client_id,
            'client_secret' => $tenant->b24_client_secret,
            'code' => $code,
        ]);

        $tokenData = $tokenRes->json();
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$tokenRes->successful() || !$accessToken) {
            Log::error("Bitrix24 User OAuth token exchange failed for tenant {$tenant->name}", ['response' => $tokenData]);
            return redirect()->route('login')->with('error', 'Bitrix24 authentication token retrieval failed.');
        }

        // Fetch current user details from Bitrix24 REST API: user.current
        $clientEndpoint = $tokenData['client_endpoint'] ?? "https://{$tenant->b24_domain}/rest/";
        $userRes = Http::get(rtrim($clientEndpoint, '/') . '/user.current', [
            'auth' => $accessToken,
        ]);

        $userData = $userRes->json();
        $b24User = $userData['result'] ?? null;

        if (!$userRes->successful() || empty($b24User)) {
            Log::error("Failed to fetch Bitrix24 user profile via user.current API", ['response' => $userData]);
            return redirect()->route('login')->with('error', 'Could not retrieve user profile from Bitrix24.');
        }

        Log::info("Fetched Bitrix24 user profile for login", [
            'b24_user_id' => $b24User['ID'] ?? null,
            'email' => $b24User['EMAIL'] ?? null,
            'name' => ($b24User['NAME'] ?? '') . ' ' . ($b24User['LAST_NAME'] ?? ''),
            'tenant' => $tenant->name,
        ]);

        // Find or create local tenant user (supports identical email across different tenants)
        $user = User::findOrCreateFromBitrix($tenant, $b24User);

        // Authenticate user in Laravel session
        Auth::login($user, true);

        return redirect()->intended('/dashboard')->with('status', "Signed in via Bitrix24 as {$user->name} ({$tenant->name}).");
    }

    /**
     * Automatic Zero-Button Bitrix24 Login & Installation Handshake.
     * Called when the app is installed or opened in an iframe inside Bitrix24.
     * Bitrix24 POSTs: DOMAIN, member_id, AUTH_ID, REFRESH_ID, AUTH_EXPIRES, PLACEMENT, PLACEMENT_OPTIONS
     */
    public function autoLogin(Request $request)
    {
        $domain       = $request->input('DOMAIN') ?: $request->input('domain');
        $memberId     = $request->input('member_id');
        $accessToken  = $request->input('AUTH_ID') ?: $request->input('access_token');
        $refreshToken = $request->input('REFRESH_ID') ?: $request->input('refresh_token');
        $expiresIn    = (int) ($request->input('AUTH_EXPIRES') ?: 3600);
        $placement    = $request->input('PLACEMENT', 'DEFAULT');
        $placementOptions = $request->input('PLACEMENT_OPTIONS');

        Log::info('[Bitrix24 Auto-Login] Entry point hit', [
            'method'    => $request->method(),
            'domain'    => $domain,
            'member_id' => $memberId,
            'placement' => $placement,
            'has_token' => !empty($accessToken),
        ]);

        // Check if user already has an active session matching this member_id
        if (Auth::check() && $memberId && session('bitrix_member_id') === $memberId) {
            Log::info('[Bitrix24 Auto-Login] Active session found, redirecting directly', [
                'user_id' => Auth::id(),
                'member_id' => $memberId,
            ]);
            return $this->redirectAfterBitrixAuth($placement, $placementOptions, session('tenant_id'));
        }

        if (!$domain || !$memberId || !$accessToken) {
            if (!Auth::check()) {
                return redirect()->route('login');
            }
            return redirect()->route('dashboard');
        }

        // 1. Fetch current Bitrix24 user details via user.current REST API
        $b24User = $this->fetchBitrixCurrentUser($domain, $accessToken);
        Log::info('[Bitrix24 Auto-Login] user.current result', [
            'has_user' => !empty($b24User),
            'email' => $b24User['EMAIL'] ?? null,
            'id' => $b24User['ID'] ?? null,
        ]);

        // 2. Find or dynamically resolve/create Tenant for this Bitrix24 domain / member_id
        $tenant = Tenant::where('b24_member_id', $memberId)
            ->orWhere('b24_domain', 'like', "%{$domain}%")
            ->first();

        if (!$tenant) {
            $companyName = ucfirst(explode('.', $domain)[0]);
            $tenant = Tenant::create([
                'name' => "{$companyName} Healthcare",
                'slug' => Str::slug($companyName . '-' . Str::random(5)),
                'status' => 'active',
                'b24_domain' => $domain,
                'b24_member_id' => $memberId,
                'b24_access_token' => $accessToken,
                'b24_refresh_token' => $refreshToken,
                'b24_token_expires_at' => now()->addSeconds($expiresIn),
                'b24_client_endpoint' => "https://{$domain}/rest/",
            ]);
            Log::info("[Bitrix24 Auto-Login] Created new Tenant record for portal: {$domain}");
        } else {
            $tenant->update([
                'b24_domain' => $domain,
                'b24_member_id' => $memberId,
                'b24_access_token' => $accessToken,
                'b24_refresh_token' => $refreshToken ?: $tenant->b24_refresh_token,
                'b24_token_expires_at' => now()->addSeconds($expiresIn),
                'b24_client_endpoint' => "https://{$domain}/rest/",
            ]);
        }

        // 3. Register placements (Lead, Deal, Contact) automatically
        try {
            $this->bitrixService->registerIntegrationPlacements($tenant, $request->getSchemeAndHttpHost());
        } catch (\Exception $e) {
            Log::warning("[Bitrix24 Auto-Login] Placements registration non-blocking warning: " . $e->getMessage());
        }

        // 4. Resolve or create local user
        $user = User::findOrCreateFromBitrix($tenant, $b24User ?: [
            'ID' => 1,
            'EMAIL' => "admin@{$tenant->slug}.local",
            'NAME' => 'Bitrix',
            'LAST_NAME' => 'User',
            'ADMIN' => 1,
        ]);

        // 5. Issue short-lived one-time token in Cache (valid 5 minutes)
        $token = Str::random(64);
        Cache::put('bitrix_login_token_' . $token, [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'member_id' => $memberId,
            'placement' => $placement,
            'placement_options' => $placementOptions,
        ], now()->addMinutes(5));

        $redirectUrl = route('b24.token-login', [
            'token' => $token,
            'member_id' => $memberId,
        ]);

        // 6. Return install_finish Blade view (BX24.installFinish() then redirects to GET token-login route)
        return response()->view('bitrix.install_finish', [
            'redirectUrl' => $redirectUrl,
            'domain' => $domain,
        ]);
    }

    /**
     * GET route: consumes one-time token and establishes iframe session cookies
     */
    public function tokenLogin(Request $request)
    {
        $token = $request->query('token');
        $memberId = $request->query('member_id');

        if (!$token) {
            return redirect()->route('login')->with('error', 'Missing Bitrix24 login token.');
        }

        $cacheKey = 'bitrix_login_token_' . $token;
        $data = Cache::get($cacheKey);

        if (!$data) {
            return redirect()->route('login')->with('error', 'Login session expired. Please reopen from Bitrix24.');
        }

        Cache::forget($cacheKey);

        $user = User::find($data['user_id']);
        if (!$user) {
            return redirect()->route('login')->with('error', 'User account not found.');
        }

        Auth::login($user, true);
        session([
            'bitrix_member_id' => $data['member_id'],
            'tenant_id' => $data['tenant_id'],
        ]);
        $request->session()->regenerate();

        Log::info('[Bitrix24 Auto-Login] User authenticated via tokenLogin', [
            'user_id' => $user->id,
            'email' => $user->email,
            'tenant_id' => $data['tenant_id'],
        ]);

        return $this->redirectAfterBitrixAuth($data['placement'] ?? 'DEFAULT', $data['placement_options'] ?? null, $data['tenant_id']);
    }

    private function redirectAfterBitrixAuth(string $placement, $placementOptions, ?string $tenantId)
    {
        // If loaded inside CRM detail tab, route to widget
        if (in_array($placement, ['CRM_LEAD_DETAIL_TAB', 'CRM_DEAL_DETAIL_TAB', 'CRM_CONTACT_DETAIL_TAB']) && $tenantId) {
            $dealId = null;
            if ($placementOptions) {
                $opts = is_array($placementOptions) ? $placementOptions : json_decode($placementOptions, true);
                $dealId = $opts['ID'] ?? $opts['id'] ?? null;
            }
            $params = [];
            if ($dealId) {
                $params['deal_id'] = $dealId;
            }
            return redirect()->route('b24.widget.show', array_merge(['tenant' => $tenantId], $params));
        }

        return redirect()->intended('/dashboard');
    }

    private function fetchBitrixCurrentUser(string $domain, string $authToken): ?array
    {
        try {
            $cleanDomain = preg_replace('#^https?://#', '', rtrim($domain, '/'));
            $url = "https://{$cleanDomain}/rest/user.current";
            $response = Http::timeout(10)->post($url, ['auth' => $authToken]);

            if ($response->successful()) {
                return $response->json('result') ?: null;
            }

            Log::warning('[Bitrix24 Auto-Login] user.current non-200', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('[Bitrix24 Auto-Login] user.current exception: ' . $e->getMessage());
        }

        return null;
    }
}


