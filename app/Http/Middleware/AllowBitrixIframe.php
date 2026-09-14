<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowBitrixIframe
{
    /**
     * Handle incoming request with security headers.
     * Protects main portal against clickjacking while allowing authorized Bitrix24 iframes for widgets and OAuth installation.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Security headers applied globally
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Allow Bitrix24 iframe embedding across the application
        $response->headers->remove('X-Frame-Options');

        $tenant = $request->route('tenant');
        $customDomain = '';
        if ($tenant instanceof Tenant && !empty($tenant->b24_domain)) {
            $cleanHost = preg_replace('#^https?://#', '', rtrim($tenant->b24_domain, '/'));
            $customDomain = "https://{$cleanHost} ";
        }

        $allowedAncestors = "'self' {$customDomain}https://*.bitrix24.net https://www.bitrix24.net https://bitrix24.net https://*.bitrix24.com https://*.bitrix24.eu https://*.bitrix24.ae https://*.bitrix24.ru https://*.bitrix24.in https://*.bitrix24.de https://*.bitrix24.com.br https://*.bitrix24.me";
        $response->headers->set('Content-Security-Policy', "frame-ancestors {$allowedAncestors};");

        return $response;
    }
}
