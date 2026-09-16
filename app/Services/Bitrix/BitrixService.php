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

        $url = $endpoint . $method . '?auth=' . urlencode($accessToken);

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
                $retryUrl = $endpoint . $method . '?auth=' . urlencode($newAccessToken);
                $retry = Http::timeout(12)->post($retryUrl, array_merge($params, [
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
     * Retrieve Lead details
     */
    public function getLead(Tenant $tenant, string|int $leadId): array
    {
        $result = $this->call($tenant, 'crm.lead.get', ['id' => $leadId]);
        if (isset($result['result']) && is_array($result['result'])) {
            return $result['result'];
        }
        return [
            'ID' => (string) $leadId,
            'TITLE' => "Medical Consultation Lead #{$leadId}",
        ];
    }

    /**
     * Retrieve current logged-in Bitrix24 User details
     */
    public function getCurrentUser(Tenant $tenant): array
    {
        $result = $this->call($tenant, 'user.current', []);
        if (isset($result['result']) && is_array($result['result'])) {
            return $result['result'];
        }
        return [];
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
     * Create a Smart Invoice (crm.item.add with entityTypeId: 31) linked to Lead or Deal
     */
    public function createSmartInvoice(Tenant $tenant, array $invoiceParams): array
    {
        $fields = [
            'title' => $invoiceParams['title'] ?? 'Unite EMR Medical Tax Invoice',
            'opportunity' => $invoiceParams['opportunity'] ?? 0,
            'currencyId' => 'AED',
        ];

        if (!empty($invoiceParams['lead_id'])) {
            $fields['parentId1'] = (int) $invoiceParams['lead_id'];
        }

        if (!empty($invoiceParams['deal_id'])) {
            $fields['parentId2'] = (int) $invoiceParams['deal_id'];
        }

        if (!empty($invoiceParams['contact_id'])) {
            $fields['contactId'] = (int) $invoiceParams['contact_id'];
        }

        $res = $this->call($tenant, 'crm.item.add', [
            'entityTypeId' => 31, // Smart Invoice
            'fields' => $fields,
        ]);

        Log::info("Bitrix24 Smart Invoice created for tenant {$tenant->name}:", ['response' => $res, 'fields' => $fields]);

        return $res;
    }

    /**
     * Post a comment in the Bitrix24 Deal or Lead Timeline
     */
    public function addTimelineComment(Tenant $tenant, string|int $entityId, string $comment, string $entityType = 'deal'): void
    {
        $this->call($tenant, 'crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $entityId,
                'ENTITY_TYPE' => strtolower($entityType) === 'lead' ? 'lead' : 'deal',
                'COMMENT' => $comment,
            ]
        ]);
    }

    /**
     * Register Event Handlers and CRM Lead, Deal & Contact Tab Placements
     */
    public function registerIntegrationPlacements(Tenant $tenant, string $appBaseUrl): array
    {
        $widgetUrl = rtrim($appBaseUrl, '/') . "/b24/widget/deal-tab/{$tenant->id}";
        $webhookUrl = rtrim($appBaseUrl, '/') . "/api/b24/webhook/{$tenant->id}";

        Log::info("Starting Bitrix24 Placement & Event binding for Tenant: {$tenant->name} ({$tenant->id})");

        $results = [];

        // Bind CRM Placements: Lead, Deal, Contact
        $placementsToBind = [
            'CRM_LEAD_DETAIL_TAB' => 'Unite EMR Booking (Lead)',
            'CRM_DEAL_DETAIL_TAB' => 'Unite EMR Booking (Deal)',
            'CRM_CONTACT_DETAIL_TAB' => 'Unite EMR Booking (Contact)',
        ];

        foreach ($placementsToBind as $placement => $title) {
            $bindRes = $this->call($tenant, 'placement.bind', [
                'PLACEMENT' => $placement,
                'HANDLER' => $widgetUrl,
                'TITLE' => $title,
                'LANG_ALL' => [
                    'en' => ['TITLE' => $title],
                    'ar' => ['TITLE' => "حجز المواعيد Unite EMR - {$placement}"]
                ]
            ]);
            $results["placement_{$placement}"] = $bindRes;
            Log::info("Bound placement {$placement} for tenant {$tenant->name}:", ['response' => $bindRes]);
        }

        // Bind CRM Events: ONCRMDEALUPDATE & ONCRMLEADUPDATE
        $eventsToBind = ['ONCRMDEALUPDATE', 'ONCRMLEADUPDATE'];
        foreach ($eventsToBind as $event) {
            $eventRes = $this->call($tenant, 'event.bind', [
                'event' => $event,
                'handler' => $webhookUrl,
            ]);
            $results["event_{$event}"] = $eventRes;
            Log::info("Bound event {$event} for tenant {$tenant->name}:", ['response' => $eventRes]);
        }

        $results['widget_url'] = $widgetUrl;
        $results['webhook_url'] = $webhookUrl;

        return $results;
    }
}
