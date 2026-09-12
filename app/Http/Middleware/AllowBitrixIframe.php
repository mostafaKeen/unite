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
     * Protects main portal against clickjacking while allowing authorized Bitrix24 iframes for widgets.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Security headers applied globally
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Check if route is a Bitrix24 iframe widget
        if ($request->is('b24/widget/*')) {
            $response->headers->remove('X-Frame-Options');

            // Dynamic tenant domain support for on-premise or cloud
            $tenant = $request->route('tenant');
            $customDomain = '';
            if ($tenant instanceof Tenant && !empty($tenant->b24_domain)) {
                $cleanHost = preg_replace('#^https?://#', '', rtrim($tenant->b24_domain, '/'));
                $customDomain = "https://{$cleanHost} ";
            }

            $allowedAncestors = "'self' {$customDomain}https://*.bitrix24.com https://*.bitrix24.eu https://*.bitrix24.ae https://*.bitrix24.ru https://*.bitrix24.in https://*.bitrix24.de https://*.bitrix24.com.br";
            $response->headers->set('Content-Security-Policy', "frame-ancestors {$allowedAncestors};");
        } else {
            // Main portal pages: block framing completely (Anti-Clickjacking)
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none';");
        }

        return $response;
    }
}
