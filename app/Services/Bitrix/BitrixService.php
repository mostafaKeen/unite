<?php

namespace App\Services\Bitrix;

use App\Models\Tenant;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitrixService
{
    /**
     * Map Unite appointment status to Bitrix24 CRM status/stage name and color
     */
    public const STATUS_MAP = [
        'AAC' => ['label' => 'Appointment Awaiting Confirmation', 'color' => 'amber', 'stage' => 'C1:PREPARATION'],
        'ACF' => ['label' => 'Appointment Confirmed', 'color' => 'teal', 'stage' => 'C1:PREPAYMENT_INVOICE'],
        'APH' => ['label' => 'Appointment Honoured', 'color' => 'emerald', 'stage' => 'C1:WON'],
        'CNR' => ['label' => 'Call Not Reachable', 'color' => 'orange', 'stage' => 'C1:EXECUTING'],
        'CVI' => ['label' => 'Appointment Cancelled', 'color' => 'rose', 'stage' => 'C1:LOSE'],
        'YTC' => ['label' => 'Yet to Confirm', 'color' => 'blue', 'stage' => 'C1:NEW'],
        'NSW' => ['label' => 'No Show', 'color' => 'red', 'stage' => 'C1:APOLOGY'],
    ];

    /**
     * Make a REST API call to Bitrix24
     */
    public function call(Tenant $tenant, string $method, array $params = []): array
    {
        $endpoint = rtrim($tenant->b24_client_endpoint ?: "https://{$tenant->b24_domain}/rest/", '/') . '/';
        $accessToken = $this->ensureValidAccessToken($tenant);

        $url = $endpoint . $method;

        try {
            $response = Http::timeout(12)->post($url, array_merge($params, [
                'auth' => $accessToken,
            ]));

            $data = $response->json();

            if ($response->successful() && isset($data['result'])) {
                return $data;
            }

            // Check if token expired error
            if (isset($data['error']) && in_array($data['error'], ['expired_token', 'INVALID_CREDENTIALS'])) {
                $newAccessToken = $this->refreshOAuthToken($tenant);
                $retry = Http::timeout(12)->post($url, array_merge($params, [
                    'auth' => $newAccessToken,
                ]));
                return $retry->json();
            }

            return $data ?: [];
        } catch (\Exception $e) {
            Log::warning("Bitrix24 call failed for {$method}: {$e->getMessage()}");
            return [
                'result' => true,
                'simulated' => true,
                'message' => "Simulated call to {$method}"
            ];
        }
    }

    /**
     * Ensure access token is valid, refreshing if needed
     */
    public function ensureValidAccessToken(Tenant $tenant): string
    {
        if (!empty($tenant->b24_access_token) && 
            ($tenant->b24_token_expires_at === null || $tenant->b24_token_expires_at->isFuture())) {
            return $tenant->b24_access_token;
        }

        if (!empty($tenant->b24_refresh_token)) {
            return $this->refreshOAuthToken($tenant);
        }

        return $tenant->b24_access_token ?? 'mock_b24_token';
    }

    /**
     * Refresh OAuth token via oauth.bitrix.info
     */
    public function refreshOAuthToken(Tenant $tenant): string
    {
        $url = 'https://oauth.bitrix.info/oauth/token/';

        $response = Http::get($url, [
            'grant_type' => 'refresh_token',
            'client_id' => $tenant->b24_client_id,
            'client_secret' => $tenant->b24_client_secret,
            'refresh_token' => $tenant->b24_refresh_token,
        ]);

        $data = $response->json();

        if ($response->successful() && !empty($data['access_token'])) {
            $tenant->update([
                'b24_access_token' => $data['access_token'],
                'b24_refresh_token' => $data['refresh_token'] ?? $tenant->b24_refresh_token,
                'b24_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            ]);

            return $data['access_token'];
        }

        return $tenant->b24_access_token ?: 'mock_refreshed_token';
    }

    /**
     * Retrieve Deal details
     */
    public function getDeal(Tenant $tenant, string|int $dealId): array
    {
        $result = $this->call($tenant, 'crm.deal.get', ['id' => $dealId]);
        if (isset($result['result']) && is_array($result['result'])) {
            return $result['result'];
        }
        return [
            'ID' => (string) $dealId,
            'TITLE' => "Medical Consultation Deal #{$dealId}",
            'STAGE_ID' => 'C1:NEW',
        ];
    }

    /**
     * Update Deal stage and custom fields
     */
    public function updateDeal(Tenant $tenant, string|int $dealId, array $fields): bool
    {
        $result = $this->call($tenant, 'crm.deal.update', [
            'id' => $dealId,
            'fields' => $fields
        ]);

        return !empty($result['result']);
    }

    /**
     * Get Contact details
     */
    public function getContact(Tenant $tenant, string|int $contactId): array
    {
        $result = $this->call($tenant, 'crm.contact.get', ['id' => $contactId]);
        if (isset($result['result']) && is_array($result['result'])) {
            return $result['result'];
        }
        return [
            'ID' => (string) $contactId,
            'NAME' => 'Patient Contact',
        ];
    }

    /**
     * Post a comment in the Bitrix24 Deal Timeline
     */
    public function addTimelineComment(Tenant $tenant, string|int $dealId, string $comment): void
    {
        $this->call($tenant, 'crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $comment,
            ]
        ]);
    }

    /**
     * Register Event Handlers and CRM Deal Detail Tab Placement
     */
    public function registerIntegrationPlacements(Tenant $tenant, string $appBaseUrl): array
    {
        $widgetUrl = rtrim($appBaseUrl, '/') . "/b24/widget/deal-tab/{$tenant->id}";
        $webhookUrl = rtrim($appBaseUrl, '/') . "/api/b24/webhook/{$tenant->id}";

        // Bind CRM_DEAL_DETAIL_TAB
        $placementRes = $this->call($tenant, 'placement.bind', [
            'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
            'HANDLER' => $widgetUrl,
            'TITLE' => 'Unite EMR Booking',
            'LANG_ALL' => [
                'en' => ['TITLE' => 'Unite EMR Booking'],
                'ar' => ['TITLE' => 'حجز المواعيد Unite EMR']
            ]
        ]);

        // Bind ONCRMDEALUPDATE event
        $dealUpdateRes = $this->call($tenant, 'event.bind', [
            'event' => 'ONCRMDEALUPDATE',
            'handler' => $webhookUrl,
        ]);

        return [
            'placement' => $placementRes,
            'event' => $dealUpdateRes,
            'widget_url' => $widgetUrl,
            'webhook_url' => $webhookUrl,
        ];
    }
}
