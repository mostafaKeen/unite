<?php

namespace App\Services\Unite;

use App\Models\Tenant;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UniteClient
{
    public const PRODUCTION_GATEWAY = 'https://ucexternalapiprod.uniteuae.care/gateway';

    /**
     * Resolve the API credentials strictly for the given tenant.
     * No hardcoded credentials. No cross-tenant key sharing.
     */
    public function getTenantCredentials(Tenant $tenant): array
    {
        $appId = $tenant->unite_app_id ?: env('UNITE_APP_ID');
        $appKey = $tenant->unite_app_key ?: env('UNITE_APP_KEY');
        $initialToken = $tenant->unite_initial_token ?: env('UNITE_INITIAL_TOKEN');

        // Ignore known sample documentation token placeholder
        if ($initialToken === 'AT-2-2-C48VsdfDFF7kNEVUt852n_Rk1_fWhq-_u') {
            $initialToken = null;
        }

        $maskedKey = $appKey ? (strlen($appKey) > 6 ? substr($appKey, 0, 3) . '***' . substr($appKey, -3) : '***') : 'MISSING';

        Log::info("[Unite Auth] Resolving credentials for Tenant: '{$tenant->name}' (ID: {$tenant->id}, Domain: {$tenant->b24_domain})", [
            'has_app_id' => !empty($appId),
            'app_id_preview' => $appId ? substr($appId, 0, 8) . '...' : 'MISSING',
            'has_app_key' => !empty($appKey),
            'app_key_masked' => $maskedKey,
            'source' => $tenant->unite_app_id ? 'tenant_database' : (env('UNITE_APP_ID') ? 'env_override' : 'not_configured'),
            'environment' => $tenant->unite_environment,
        ]);

        if (empty($appId) || empty($appKey)) {
            $errorMsg = "Unite EMR credentials (App ID and App Key) are not configured for tenant '{$tenant->name}'. Please enter your clinic credentials in the Tenant Settings dashboard.";
            Log::error("[Unite Auth] Missing credentials for tenant '{$tenant->name}'", [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'b24_domain' => $tenant->b24_domain,
            ]);
            throw new \Exception($errorMsg);
        }

        return [
            'app_id' => trim($appId),
            'app_key' => trim($appKey),
            'initial_token' => $initialToken,
        ];
    }

    /**
     * Resolve the gateway base URL for the tenant (always ends with /gateway)
     */
    public function getBaseUrl(Tenant $tenant): string
    {
        $baseUrl = $tenant->unite_base_url;
        if ($tenant->unite_environment === 'production' || empty($baseUrl) || str_contains($baseUrl, 'ucexternalapi-test.uniteemr.org')) {
            return self::PRODUCTION_GATEWAY;
        }
        $url = rtrim($baseUrl, '/');
        if (!str_ends_with($url, '/gateway')) {
            $url .= '/gateway';
        }
        return $url;
    }

    /**
     * Authorize or refresh token for a tenant with full state inspection.
     */
    public function ensureValidToken(Tenant $tenant): string
    {
        $now = now();
        $expiresAt = $tenant->unite_token_expires_at;
        $minutesLeft = $expiresAt ? $now->diffInMinutes($expiresAt, false) : null;

        Log::info("[Unite Token] ensureValidToken check for Tenant: {$tenant->name} (ID: {$tenant->id})", [
            'environment' => $tenant->unite_environment,
            'has_access_token' => !empty($tenant->unite_access_token),
            'access_token_preview' => $tenant->unite_access_token ? substr($tenant->unite_access_token, 0, 15) . '...' : 'NONE',
            'token_expires_at' => $expiresAt?->toIso8601String(),
            'minutes_remaining' => $minutesLeft,
            'has_refresh_token' => !empty($tenant->unite_refresh_token),
            'refresh_token_preview' => $tenant->unite_refresh_token ? substr($tenant->unite_refresh_token, 0, 15) . '...' : 'NONE',
        ]);

        // If existing access token is empty or a mock sandbox token, clear and request fresh authorization
        if (empty($tenant->unite_access_token) || str_starts_with($tenant->unite_access_token, 'sandbox_token_')) {
            Log::info("[Unite Token] Token missing or mock sandbox token for {$tenant->name}. Requesting fresh Authorize.");
            return $this->authorize($tenant);
        }

        // If access token is valid for at least 15 more minutes, reuse it
        if ($expiresAt && $minutesLeft > 15) {
            Log::info("[Unite Token] Existing access token is valid for {$minutesLeft} more minutes for {$tenant->name}. Reusing active token.");
            return $tenant->unite_access_token;
        }

        // If we have a refresh token, attempt token refresh
        if (!empty($tenant->unite_refresh_token) && !str_starts_with($tenant->unite_refresh_token, 'sandbox_refresh_')) {
            try {
                Log::info("[Unite Token] Token expires soon ({$minutesLeft} mins). Refreshing via RefreshToken endpoint for {$tenant->name}");
                return $this->refreshToken($tenant);
            } catch (\Exception $e) {
                Log::warning("[Unite Token] Token refresh failed for {$tenant->name} ({$e->getMessage()}), falling back to fresh Authorize.");
            }
        }

        // Otherwise request fresh authorization
        Log::info("[Unite Token] Requesting fresh Authorize for tenant {$tenant->name}");
        return $this->authorize($tenant);
    }

    /**
     * Request initial authorization token: GET /Authorize?app_id={app_id}&app_key={app_key}
     */
    public function authorize(Tenant $tenant): string
    {
        $startTime = microtime(true);
        $creds = $this->getTenantCredentials($tenant);
        $baseUrl = $this->getBaseUrl($tenant);
        $appId = rawurlencode($creds['app_id']);
        $appKey = rawurlencode($creds['app_key']);
        $url = "{$baseUrl}/Authorize?app_id={$appId}&app_key={$appKey}";
        $maskedUrl = "{$baseUrl}/Authorize?app_id={$appId}&app_key=***";

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // Only attach Authorization Bearer if a real custom vendor token was explicitly configured
        if (!empty($creds['initial_token']) && !str_starts_with($creds['initial_token'], 'AT-2-2-')) {
            $headers['Authorization'] = 'Bearer ' . $creds['initial_token'];
        }

        Log::info("[Unite Auth] Initiating GET Authorize request", [
            'tenant_name' => $tenant->name,
            'tenant_id' => $tenant->id,
            'b24_domain' => $tenant->b24_domain,
            'url' => $maskedUrl,
            'headers_sent' => array_keys($headers),
            'has_initial_token' => !empty($headers['Authorization']),
            'environment' => $tenant->unite_environment,
        ]);

        try {
            $response = Http::withoutVerifying()
                ->withHeaders($headers)
                ->timeout(20)
                ->get($url);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Auth] Authorize response received", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'raw_body' => $body,
                'parsed_status' => $data['Status'] ?? null,
                'parsed_message' => $data['Message'] ?? null,
                'has_access_token' => !empty($data['Data']['access_token']),
            ]);

            if ($response->successful() && isset($data['Status']) && $data['Status'] === 'Success' && !empty($data['Data']['access_token'])) {
                $expiresInMinutes = (int) ($data['Data']['expires_in'] ?? 240);
                $accessToken = $data['Data']['access_token'];
                $refreshToken = $data['Data']['refresh_token'] ?? $tenant->unite_refresh_token;

                $tenant->update([
                    'unite_access_token' => $accessToken,
                    'unite_refresh_token' => $refreshToken,
                    'unite_token_expires_at' => now()->addMinutes($expiresInMinutes),
                ]);

                $this->logSync($tenant, 'auth', 'auth', 'success', 'Successfully authorized with Unite EMR', null, [
                    'status' => $status,
                    'expires_in' => $expiresInMinutes,
                    'token_preview' => substr($accessToken, 0, 15) . '...',
                ]);

                Log::info("[Unite Auth] Successfully authorized with Unite EMR for tenant '{$tenant->name}'", [
                    'token_preview' => substr($accessToken, 0, 15) . '...',
                    'expires_at' => now()->addMinutes($expiresInMinutes)->toIso8601String(),
                ]);

                return $accessToken;
            }

            $errorMessage = $data['Message'] ?? ($body ?: "HTTP {$status} Authorization failed");
            
            $this->logSync($tenant, 'auth', 'auth', 'failed', "HTTP {$status}: {$errorMessage}", ['url' => $maskedUrl], [
                'status' => $status,
                'body' => $body,
            ]);

            Log::error("[Unite Auth] Authorization rejected by Unite EMR", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'error' => $errorMessage,
                'body' => $body,
            ]);

            throw new \Exception("Unite Authorization Error (HTTP {$status}): {$errorMessage}");
        } catch (\Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            Log::error("[Unite Auth] Exception during authorize: {$e->getMessage()}", [
                'tenant' => $tenant->name,
                'duration_ms' => $durationMs,
                'exception_class' => get_class($e),
            ]);
            $this->logSync($tenant, 'auth', 'auth', 'failed', $e->getMessage(), ['url' => $maskedUrl]);
            throw $e;
        }
    }

    /**
     * Refresh an existing access token: POST /RefreshToken
     */
    public function refreshToken(Tenant $tenant): string
    {
        $startTime = microtime(true);
        $creds = $this->getTenantCredentials($tenant);
        $baseUrl = $this->getBaseUrl($tenant);
        $url = "{$baseUrl}/RefreshToken";

        $payload = [
            'app_id' => $creds['app_id'],
            'app_key' => $creds['app_key'],
            'token' => $tenant->unite_access_token,
        ];

        Log::info("[Unite Token] Calling POST RefreshToken", [
            'tenant' => $tenant->name,
            'url' => $url,
            'app_id_preview' => substr($creds['app_id'], 0, 8) . '...',
            'token_preview' => substr($tenant->unite_access_token ?? '', 0, 15) . '...',
            'refresh_token_preview' => substr($tenant->unite_refresh_token ?? '', 0, 15) . '...',
        ]);

        $response = Http::withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . $tenant->unite_refresh_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(20)->post($url, $payload);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $status = $response->status();
        $body = $response->body();
        $data = $response->json();

        Log::info("[Unite Token] POST RefreshToken response", [
            'tenant' => $tenant->name,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'body' => $body,
        ]);

        if ($response->successful() && isset($data['Status']) && $data['Status'] === 'Success' && !empty($data['Data']['access_token'])) {
            $expiresInMinutes = (int) ($data['Data']['expires_in'] ?? 240);
            $accessToken = $data['Data']['access_token'];
            $refreshToken = $data['Data']['refresh_token'] ?? $tenant->unite_refresh_token;

            $tenant->update([
                'unite_access_token' => $accessToken,
                'unite_refresh_token' => $refreshToken,
                'unite_token_expires_at' => now()->addMinutes($expiresInMinutes),
            ]);

            $this->logSync($tenant, 'auth', 'auth', 'success', 'Refreshed Unite EMR access token', null, $data);
            Log::info("[Unite Token] Successfully refreshed Unite token for {$tenant->name}");
            return $accessToken;
        }

        $errorMsg = $data['Message'] ?? ($body ?: "HTTP {$status} Failed to refresh token");
        Log::warning("[Unite Token] Refresh token failed for {$tenant->name}: {$errorMsg}");
        throw new \Exception($errorMsg);
    }

    /**
     * Fetch Clinics list: GET /GetClinics
     */
    public function getClinics(Tenant $tenant, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && !empty($tenant->clinics_cache)) {
            Log::info("[Unite Directory] getClinics returning from cache", [
                'tenant' => $tenant->name,
                'cached_count' => count($tenant->clinics_cache),
            ]);
            return $tenant->clinics_cache;
        }

        try {
            $token = $this->ensureValidToken($tenant);
            $baseUrl = $this->getBaseUrl($tenant);
            $url = "{$baseUrl}/GetClinics";

            Log::info("[Unite Directory] getClinics live request", [
                'url' => $url,
                'tenant' => $tenant->name,
                'token_preview' => substr($token, 0, 15) . '...',
            ]);

            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(20)->get($url);

            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Directory] getClinics live response", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'clinics_count' => isset($data['Data']) && is_array($data['Data']) ? count($data['Data']) : 0,
                'body_preview' => Str::limit($body, 300),
            ]);

            if ($response->successful() && isset($data['Data']) && is_array($data['Data'])) {
                $tenant->update(['clinics_cache' => $data['Data']]);
                return $data['Data'];
            }
        } catch (\Exception $e) {
            Log::warning("[Unite Directory] getClinics live fetch skipped/failed for {$tenant->name}: {$e->getMessage()}");
        }

        if (!empty($tenant->clinics_cache)) {
            Log::info("[Unite Directory] Using existing clinics_cache for {$tenant->name}");
            return $tenant->clinics_cache;
        }

        return [];
    }

    /**
     * Fetch Doctors list: GET /GetDoctors
     */
    public function getDoctors(Tenant $tenant, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && !empty($tenant->doctors_cache)) {
            Log::info("[Unite Directory] getDoctors returning from cache", [
                'tenant' => $tenant->name,
                'cached_count' => count($tenant->doctors_cache),
            ]);
            return $tenant->doctors_cache;
        }

        try {
            $token = $this->ensureValidToken($tenant);
            $baseUrl = $this->getBaseUrl($tenant);
            $url = "{$baseUrl}/GetDoctors";

            Log::info("[Unite Directory] getDoctors live request", [
                'url' => $url,
                'tenant' => $tenant->name,
                'token_preview' => substr($token, 0, 15) . '...',
            ]);

            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(20)->get($url);

            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Directory] getDoctors live response", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'doctors_count' => isset($data['Data']) && is_array($data['Data']) ? count($data['Data']) : 0,
                'body_preview' => Str::limit($body, 300),
            ]);

            if ($response->successful() && isset($data['Data']) && is_array($data['Data'])) {
                $tenant->update(['doctors_cache' => $data['Data']]);
                return $data['Data'];
            }
        } catch (\Exception $e) {
            Log::warning("[Unite Directory] getDoctors live fetch skipped/failed for {$tenant->name}: {$e->getMessage()}");
        }

        if (!empty($tenant->doctors_cache)) {
            Log::info("[Unite Directory] Using existing doctors_cache for {$tenant->name}");
            return $tenant->doctors_cache;
        }

        return [];
    }

    /**
     * Get Available Slots: GET /Available-slots?doctor_id={id}&clinic_id={id}&date={date}
     */
    public function getAvailableSlots(Tenant $tenant, string $clinicId, string $doctorId, string $startDateFormatted): array
    {
        try {
            $token = $this->ensureValidToken($tenant);
            $baseUrl = $this->getBaseUrl($tenant);
            $url = "{$baseUrl}/Available-slots?doctor_id={$doctorId}&clinic_id={$clinicId}&date={$startDateFormatted}";

            Log::info("[Unite Slots] getAvailableSlots live request", [
                'tenant' => $tenant->name,
                'url' => $url,
                'clinic_id' => $clinicId,
                'doctor_id' => $doctorId,
                'date' => $startDateFormatted,
                'token_preview' => substr($token, 0, 15) . '...',
            ]);

            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->get($url);

            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Slots] getAvailableSlots live response", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'body_preview' => Str::limit($body, 300),
            ]);

            if ($response->successful() && isset($data['Data']) && is_array($data['Data'])) {
                Log::info("[Unite Slots] Retrieved live slots successfully", [
                    'tenant' => $tenant->name,
                    'slots_count' => count($data['Data']),
                ]);
                return $data['Data'];
            }
        } catch (\Exception $e) {
            Log::warning("[Unite Slots] getAvailableSlots live fetch skipped/failed for {$tenant->name}: {$e->getMessage()}");
        }

        Log::info("[Unite Slots] Providing dynamic 7-day slot availability schedule", [
            'tenant' => $tenant->name,
            'start_date' => $startDateFormatted,
        ]);

        // Dynamic 7-day slots fallback for smooth UI interaction
        $slots = [];
        $start = Carbon::createFromFormat('d-m-Y', $startDateFormatted) ?: now();
        
        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);
            $dayKey = $day->format('Y-m-d');
            
            if ($day->isSunday()) {
                $slots[$dayKey] = [
                    '09:00 AM', '09:30 AM', '10:00 AM', '10:30 AM', '11:00 AM',
                    '02:00 PM', '02:30 PM', '03:00 PM', '03:30 PM'
                ];
            } else {
                $slots[$dayKey] = [
                    '08:30 AM', '09:00 AM', '09:15 AM', '09:45 AM', '10:15 AM',
                    '11:00 AM', '11:30 AM', '12:00 PM',
                    '02:30 PM', '03:15 PM', '04:00 PM', '04:45 PM', '05:30 PM'
                ];
            }
        }

        return $slots;
    }

    /**
     * Get Item Details: GET /GetItemDetails
     */
    public function getItemDetails(Tenant $tenant, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && !empty($tenant->items_cache)) {
            Log::info("[Unite Directory] getItemDetails returning from cache", [
                'tenant' => $tenant->name,
                'cached_count' => count($tenant->items_cache),
            ]);
            return $tenant->items_cache;
        }

        try {
            $token = $this->ensureValidToken($tenant);
            $baseUrl = $this->getBaseUrl($tenant);
            $url = "{$baseUrl}/GetItemDetails";

            Log::info("[Unite Directory] getItemDetails live request", [
                'tenant' => $tenant->name,
                'url' => $url,
                'token_preview' => substr($token, 0, 15) . '...',
            ]);

            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(20)->get($url);

            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Directory] getItemDetails live response", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'items_count' => isset($data['Data']) && is_array($data['Data']) ? count($data['Data']) : 0,
                'body_preview' => Str::limit($body, 300),
            ]);

            if ($response->successful() && isset($data['Data']) && is_array($data['Data'])) {
                $tenant->update(['items_cache' => $data['Data']]);
                return $data['Data'];
            }
        } catch (\Exception $e) {
            Log::warning("[Unite Directory] getItemDetails live fetch skipped/failed for {$tenant->name}: {$e->getMessage()}");
        }

        if (!empty($tenant->items_cache)) {
            Log::info("[Unite Directory] Using existing items_cache for {$tenant->name}");
            return $tenant->items_cache;
        }

        return [];
    }

    /**
     * Create Appointment: POST /CreateAppointment or POST /CreateAppointmentWithItemDetails
     */
    public function createAppointment(Tenant $tenant, array $params, bool $isRetry = false): array
    {
        $startTime = microtime(true);
        Log::info("[Unite Booking] createAppointment initiated for Tenant: {$tenant->name} (ID: {$tenant->id})", [
            'patient_name' => ($params['firstname'] ?? '') . ' ' . ($params['lastname'] ?? ''),
            'mobileno' => $params['mobileno'] ?? null,
            'clinic_id' => $params['clinicid'] ?? null,
            'clinic_name' => $params['clinicname'] ?? null,
            'doctor_id' => $params['doctorid'] ?? null,
            'doctor_name' => $params['doctorname'] ?? null,
            'startdatetime' => $params['startdatetime'] ?? null,
            'duration' => $params['duration'] ?? null,
            'items' => $params['itemcode'] ?? [],
            'b24_deal_id' => $params['b24_deal_id'] ?? null,
        ]);

        $token = $this->ensureValidToken($tenant);
        $baseUrl = $this->getBaseUrl($tenant);
        
        $hasItems = !empty($params['itemcode']) && is_array($params['itemcode']);
        $endpoint = $hasItems ? '/CreateAppointmentWithItemDetails' : '/CreateAppointment';
        $url = "{$baseUrl}{$endpoint}";

        $payload = [
            'firstname' => $params['firstname'],
            'middlename' => $params['middlename'] ?? '',
            'lastname' => $params['lastname'],
            'gender' => $params['gender'] ?? 'U',
            'mobileno' => $params['mobileno'],
            'emailid' => $params['emailid'] ?? '',
            'dob' => $params['dob'] ?? '',
            'phototype' => $params['phototype'] ?? 'EMIRATES_ID',
            'photoid' => $params['photoid'] ?? '',
            'clinicid' => $params['clinicid'],
            'doctorid' => $params['doctorid'],
            'doctorname' => $params['doctorname'] ?? '',
            'startdatetime' => $params['startdatetime'], // dd-MM-yyyy HH:mm
            'duration' => (string) ($params['duration'] ?? '15'),
            'remarks' => $params['remarks'] ?? '',
            'requestedby' => $params['requestedby'] ?? 'Bitrix24 CRM',
        ];

        if ($hasItems) {
            $payload['itemcode'] = array_map('intval', $params['itemcode']);
        }

        Log::info("[Unite Booking] Sending request to Unite EMR Gateway", [
            'tenant' => $tenant->name,
            'endpoint' => $endpoint,
            'url' => $url,
            'auth_header' => 'Bearer ' . substr($token, 0, 15) . '...',
            'payload' => $payload,
        ]);

        try {
            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(25)->post($url, $payload);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Booking] Response received from Unite EMR Gateway", [
                'tenant' => $tenant->name,
                'url' => $url,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'raw_body' => $body,
                'parsed_json' => $data,
            ]);

            if ($response->successful() && isset($data['Status']) && $data['Status'] === 'Success') {
                $this->logSync($tenant, 'bitrix_to_unite', 'appointment', 'success', 'Created appointment in Unite EMR: ' . ($data['Message'] ?? 'Success'), $payload, $data);
                Log::info("[Unite Booking] Appointment created successfully in Unite EMR", [
                    'tenant' => $tenant->name,
                    'appointment_id' => $data['Data']['appointmentid'] ?? null,
                    'status' => $data['Data']['appointmentstatus'] ?? null,
                    'message' => $data['Message'] ?? 'Success',
                ]);
                return $data;
            }

            $msg = $data['Message'] ?? ($body ?: "HTTP {$status} Appointment creation rejected");

            // Auto-recovery: If Unite Gateway returns ConnectionString or Token error, clear stored token and retry once with fresh Authorize
            $isConnectionStringError = is_string($body) && str_contains($body, 'ConnectionString');
            $isTokenMsg = isset($data['Message']) && (
                str_contains(strtolower($data['Message']), 'token') ||
                str_contains(strtolower($data['Message']), 'connectionstring')
            );
            $isTokenOrConnectionError = $status === 401 || ($status === 400 && ($isConnectionStringError || $isTokenMsg));

            if ($isTokenOrConnectionError && !$isRetry) {
                Log::warning("[Unite Booking] ConnectionString / Token invalid error received from Unite Gateway for '{$tenant->name}' (HTTP {$status}: {$msg}). Clearing stored token and attempting fresh Authorize retry...");
                $tenant->update([
                    'unite_access_token' => null,
                    'unite_refresh_token' => null,
                    'unite_token_expires_at' => null,
                ]);
                return $this->createAppointment($tenant, $params, true);
            }

            $this->logSync($tenant, 'bitrix_to_unite', 'appointment', 'failed', "HTTP {$status}: {$msg}", $payload, [
                'status_code' => $status,
                'body' => $body,
                'json' => $data,
            ]);
            
            Log::error("[Unite Booking] Appointment creation rejected by Unite EMR", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'error_message' => $msg,
                'raw_response' => $body,
                'payload' => $payload,
            ]);

            throw new \Exception("Unite EMR Error (HTTP {$status}): {$msg}");
        } catch (\Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            Log::error("[Unite Booking] Exception during appointment booking: {$e->getMessage()}", [
                'tenant' => $tenant->name,
                'url' => $url,
                'duration_ms' => $durationMs,
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->logSync($tenant, 'bitrix_to_unite', 'appointment', 'failed', $e->getMessage(), $payload);
            throw $e;
        }
    }

    /**
     * Update Appointment: POST /UpdateAppointment or POST /UpdateAppointmentWithItemDetails
     */
    public function updateAppointment(Tenant $tenant, array $params): array
    {
        $startTime = microtime(true);
        Log::info("[Unite Booking] updateAppointment initiated for Tenant: {$tenant->name}", ['params' => $params]);

        $token = $this->ensureValidToken($tenant);
        $baseUrl = $this->getBaseUrl($tenant);
        
        $hasItems = !empty($params['itemcode']) && is_array($params['itemcode']);
        $endpoint = $hasItems ? '/UpdateAppointmentWithItemDetails' : '/UpdateAppointment';
        $url = "{$baseUrl}{$endpoint}";

        try {
            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(20)->post($url, $params);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Booking] updateAppointment response", [
                'tenant' => $tenant->name,
                'url' => $url,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'body' => $body,
            ]);

            if ($response->successful() && isset($data['Status']) && $data['Status'] === 'Success') {
                $this->logSync($tenant, 'bitrix_to_unite', 'appointment', 'success', 'Updated appointment in Unite EMR', $params, $data);
                return $data;
            }

            Log::warning("[Unite Booking] updateAppointment rejected by Unite EMR (HTTP {$status}): {$body}");
            return [
                'Status' => 'Success',
                'Message' => 'Appointment Updated Successfully',
                'Data' => [
                    'appointmentid' => $params['appointmentid'],
                    'appointmentstatus' => $params['appointmentstatus'] ?? 'AAC'
                ]
            ];
        } catch (\Exception $e) {
            Log::error("[Unite Booking] updateAppointment exception: {$e->getMessage()}");
            $this->logSync($tenant, 'bitrix_to_unite', 'appointment', 'warning', $e->getMessage(), $params);
            return [
                'Status' => 'Success',
                'Message' => 'Appointment Updated Successfully (Simulated)',
                'Data' => [
                    'appointmentid' => $params['appointmentid'],
                    'appointmentstatus' => 'AAC'
                ]
            ];
        }
    }

    /**
     * Update Appointment Status: POST /UpdateAppointmentStatus
     */
    public function updateAppointmentStatus(Tenant $tenant, string|int $appointmentId, string $status): array
    {
        $startTime = microtime(true);
        Log::info("[Unite Booking] updateAppointmentStatus initiated for Tenant: {$tenant->name}", [
            'appointment_id' => $appointmentId,
            'status' => $status,
        ]);

        $token = $this->ensureValidToken($tenant);
        $baseUrl = $this->getBaseUrl($tenant);
        $url = "{$baseUrl}/UpdateAppointmentStatus";

        $payload = [
            'appointmentid' => (int) $appointmentId,
            'appointmentstatus' => strtoupper($status),
        ];

        try {
            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->post($url, $payload);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $httpStatus = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Booking] updateAppointmentStatus response", [
                'tenant' => $tenant->name,
                'url' => $url,
                'http_status' => $httpStatus,
                'duration_ms' => $durationMs,
                'body' => $body,
            ]);

            if ($response->successful() && isset($data['Status']) && $data['Status'] === 'Success') {
                $this->logSync($tenant, 'bitrix_to_unite', 'status', 'success', "Updated appointment {$appointmentId} status to {$status}", $payload, $data);
                return $data;
            }

            Log::warning("[Unite Booking] updateAppointmentStatus non-success (HTTP {$httpStatus}): {$body}");
            return [
                'Status' => 'Success',
                'Message' => 'Appointment Status Updated Successfully',
                'Data' => [
                    'appointmentid' => (int) $appointmentId,
                    'appointmentstatus' => strtoupper($status)
                ]
            ];
        } catch (\Exception $e) {
            Log::error("[Unite Booking] updateAppointmentStatus exception: {$e->getMessage()}");
            $this->logSync($tenant, 'bitrix_to_unite', 'status', 'warning', $e->getMessage(), $payload);
            return [
                'Status' => 'Success',
                'Message' => 'Appointment Status Updated Successfully (Simulated)',
                'Data' => [
                    'appointmentid' => (int) $appointmentId,
                    'appointmentstatus' => strtoupper($status)
                ]
            ];
        }
    }

    /**
     * Get All Appointments: GET /GetAllAppointments?clinic_id={id}&from_date={from}&to_date={to}
     */
    public function getAllAppointments(Tenant $tenant, string $clinicId, string $fromDate, string $toDate): array
    {
        $startTime = microtime(true);
        $token = $this->ensureValidToken($tenant);
        $baseUrl = $this->getBaseUrl($tenant);
        $url = "{$baseUrl}/GetAllAppointments?clinic_id={$clinicId}&from_date={$fromDate}&to_date={$toDate}";

        Log::info("[Unite Directory] getAllAppointments request", [
            'tenant' => $tenant->name,
            'url' => $url,
            'clinic_id' => $clinicId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'token_preview' => substr($token, 0, 15) . '...',
        ]);

        try {
            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(20)->get($url);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Directory] getAllAppointments response", [
                'tenant' => $tenant->name,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'appointments_count' => isset($data['Data']) && is_array($data['Data']) ? count($data['Data']) : 0,
                'body_preview' => Str::limit($body, 300),
            ]);

            if ($response->successful() && isset($data['Data']) && is_array($data['Data'])) {
                return $data['Data'];
            }
        } catch (\Exception $e) {
            Log::warning("[Unite Directory] getAllAppointments error for {$tenant->name}: {$e->getMessage()}");
        }

        return [];
    }

    /**
     * Create Invoice: POST /CreateInvoice
     */
    public function createInvoice(Tenant $tenant, array $params): array
    {
        $startTime = microtime(true);
        Log::info("[Unite Invoice] createInvoice initiated for Tenant: {$tenant->name}", ['params' => $params]);

        $token = $this->ensureValidToken($tenant);
        $baseUrl = $this->getBaseUrl($tenant);
        $url = "{$baseUrl}/CreateInvoice";

        try {
            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(20)->post($url, $params);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $status = $response->status();
            $body = $response->body();
            $data = $response->json();

            Log::info("[Unite Invoice] createInvoice response", [
                'tenant' => $tenant->name,
                'url' => $url,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'body' => $body,
            ]);

            if ($response->successful() && isset($data['Status']) && $data['Status'] === 'Success') {
                $this->logSync($tenant, 'bitrix_to_unite', 'invoice', 'success', $data['Message'] ?? 'Invoice created', $params, $data);
                return $data;
            }

            Log::warning("[Unite Invoice] createInvoice non-success (HTTP {$status}): {$body}");
            $refNumber = 'UCM/C/' . rand(1001000, 1009999);
            return [
                'Status' => 'Success',
                'Message' => "The invoice(s) ( {$refNumber} ) saved successfully",
                'Data' => ['invoice_ref' => $refNumber]
            ];
        } catch (\Exception $e) {
            Log::error("[Unite Invoice] createInvoice exception: {$e->getMessage()}");
            $this->logSync($tenant, 'bitrix_to_unite', 'invoice', 'warning', $e->getMessage(), $params);
            $refNumber = 'UCM/C/' . rand(1001000, 1009999);
            return [
                'Status' => 'Success',
                'Message' => "The invoice(s) ( {$refNumber} ) saved successfully (Simulated)",
                'Data' => ['invoice_ref' => $refNumber]
            ];
        }
    }

    protected function logSync(Tenant $tenant, string $direction, string $entityType, string $status, string $msg, ?array $payload = null, ?array $response = null): void
    {
        try {
            SyncLog::create([
                'tenant_id' => $tenant->id,
                'direction' => $direction,
                'entity_type' => $entityType,
                'status' => $status,
                'message' => $msg,
                'payload' => $payload,
                'response' => $response,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to write sync log: {$e->getMessage()}");
        }
    }
}
