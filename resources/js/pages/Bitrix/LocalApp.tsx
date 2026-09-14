import React, { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { 
    CheckCircle2, 
    AlertCircle, 
    RefreshCw, 
    Layers, 
    Building2, 
    ShieldCheck, 
    Activity
} from 'lucide-react';

interface TenantInfo {
    id: string;
    name: string;
    b24_domain: string;
    b24_member_id: string;
    b24_token_expires_at: string | null;
}

interface B24Context {
    domain: string | null;
    member_id: string | null;
    placement: string;
    placement_options: string | null;
    app_sid: string | null;
}

interface Props {
    tenant: TenantInfo | null;
    b24Context: B24Context;
    isConfigured: boolean;
}

declare global {
    interface Window {
        BX24?: {
            init: (callback: () => void) => void;
            installFinish: () => void;
            fitWindow: () => void;
            callMethod: (method: string, params: object, callback: (result: any) => void) => void;
        };
    }
}

export default function LocalApp({ tenant, b24Context, isConfigured }: Props) {
    const [loading, setLoading] = useState(false);
    const [statusMessage, setStatusMessage] = useState<string | null>(null);
    const [statusType, setStatusType] = useState<'success' | 'error' | 'info'>('info');
    const [bxInitialized, setBxInitialized] = useState(false);

    useEffect(() => {
        // Load Bitrix24 JS SDK if available
        const scriptId = 'bitrix-js-sdk';
        if (!document.getElementById(scriptId)) {
            const script = document.createElement('script');
            script.id = scriptId;
            script.src = '//api.bitrix24.com/api/v1/';
            script.async = true;
            script.onload = () => {
                if (window.BX24) {
                    window.BX24.init(() => {
                        setBxInitialized(true);
                        try {
                            window.BX24?.fitWindow();
                            window.BX24?.installFinish();
                        } catch (e) {
                            console.log('BX24 installFinish exception:', e);
                        }
                    });
                }
            };
            document.head.appendChild(script);
        } else if (window.BX24) {
            window.BX24.init(() => {
                setBxInitialized(true);
                try {
                    window.BX24?.fitWindow();
                    window.BX24?.installFinish();
                } catch (e) {
                    console.log('BX24 installFinish exception:', e);
                }
            });
        }
    }, []);

    const handleRebindPlacements = () => {
        if (!tenant) return;
        setLoading(true);
        setStatusMessage(null);

        router.post(
            `/tenants/${tenant.id}/bind-placements`,
            {},
            {
                onSuccess: () => {
                    setLoading(false);
                    setStatusType('success');
                    setStatusMessage('CRM Placements (Lead, Deal, Contact) successfully registered with Bitrix24 portal!');
                },
                onError: (errors) => {
                    setLoading(false);
                    setStatusType('error');
                    setStatusMessage(Object.values(errors).join(', ') || 'Failed to bind placements.');
                },
            }
        );
    };

    const handleTestConnection = () => {
        if (!tenant) return;
        setLoading(true);
        setStatusMessage(null);

        router.post(
            `/tenants/${tenant.id}/test-unite`,
            {},
            {
                onSuccess: () => {
                    setLoading(false);
                    setStatusType('success');
                    setStatusMessage('Unite EMR API connection verified successfully!');
                },
                onError: (errors) => {
                    setLoading(false);
                    setStatusType('error');
                    setStatusMessage(Object.values(errors).join(', ') || 'Unite EMR API connection test failed.');
                },
            }
        );
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 p-4 md:p-6 font-sans">
            <Head title="Unite EMR - Bitrix24 Local App" />

            <div className="max-w-4xl mx-auto space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-lg">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-lg bg-teal-500/20 border border-teal-500/30 flex items-center justify-center">
                            <svg className="w-6 h-6 text-teal-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m-8-8h16" />
                            </svg>
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-slate-100">Unite EMR Integration</h1>
                            <p className="text-xs text-slate-400">Bitrix24 Local App Management Portal</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {isConfigured ? (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                <CheckCircle2 className="w-3.5 h-3.5" /> Connected
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                <AlertCircle className="w-3.5 h-3.5" /> Authorization Needed
                            </span>
                        )}
                    </div>
                </div>

                {/* Status Alert Message */}
                {statusMessage && (
                    <div className={`p-4 rounded-lg border text-sm flex items-start gap-3 ${
                        statusType === 'success' 
                            ? 'bg-emerald-950/40 border-emerald-800/60 text-emerald-300' 
                            : statusType === 'error'
                            ? 'bg-rose-950/40 border-rose-800/60 text-rose-300'
                            : 'bg-slate-900 border-slate-700 text-slate-300'
                    }`}>
                        {statusType === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" />}
                        {statusType === 'error' && <AlertCircle className="w-5 h-5 text-rose-400 shrink-0 mt-0.5" />}
                        {statusType === 'info' && <Activity className="w-5 h-5 text-blue-400 shrink-0 mt-0.5" />}
                        <div>{statusMessage}</div>
                    </div>
                )}

                {/* Main Content Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Tenant Details */}
                    <div className="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                        <div className="flex items-center gap-2 border-b border-slate-800 pb-3">
                            <Building2 className="w-5 h-5 text-teal-400" />
                            <h2 className="font-semibold text-slate-200">Active Tenant Profile</h2>
                        </div>

                        {tenant ? (
                            <div className="space-y-3 text-sm">
                                <div>
                                    <label className="text-xs text-slate-400 block">Facility Name</label>
                                    <p className="font-medium text-slate-200">{tenant.name}</p>
                                </div>
                                <div>
                                    <label className="text-xs text-slate-400 block">Bitrix24 Portal Domain</label>
                                    <p className="font-mono text-slate-300 text-xs bg-slate-950 px-2.5 py-1.5 rounded border border-slate-800 mt-1">
                                        {tenant.b24_domain || b24Context.domain || 'Not bound'}
                                    </p>
                                </div>
                                <div>
                                    <label className="text-xs text-slate-400 block">Member ID</label>
                                    <p className="font-mono text-slate-300 text-xs bg-slate-950 px-2.5 py-1.5 rounded border border-slate-800 mt-1">
                                        {tenant.b24_member_id || b24Context.member_id || 'N/A'}
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <div className="text-center py-6 text-slate-400 text-sm">
                                No active tenant associated with this Bitrix24 request.
                            </div>
                        )}
                    </div>

                    {/* Placements & Features */}
                    <div className="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                        <div className="flex items-center gap-2 border-b border-slate-800 pb-3">
                            <Layers className="w-5 h-5 text-teal-400" />
                            <h2 className="font-semibold text-slate-200">CRM Placements & Widgets</h2>
                        </div>

                        <div className="space-y-2 text-xs">
                            <div className="flex items-center justify-between p-2.5 rounded-lg bg-slate-950 border border-slate-800">
                                <span className="text-slate-300 font-medium">CRM Lead Tab (`CRM_LEAD_DETAIL_TAB`)</span>
                                <span className="text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20 font-mono">Active</span>
                            </div>
                            <div className="flex items-center justify-between p-2.5 rounded-lg bg-slate-950 border border-slate-800">
                                <span className="text-slate-300 font-medium">CRM Deal Tab (`CRM_DEAL_DETAIL_TAB`)</span>
                                <span className="text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20 font-mono">Active</span>
                            </div>
                            <div className="flex items-center justify-between p-2.5 rounded-lg bg-slate-950 border border-slate-800">
                                <span className="text-slate-300 font-medium">CRM Contact Tab (`CRM_CONTACT_DETAIL_TAB`)</span>
                                <span className="text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20 font-mono">Active</span>
                            </div>
                        </div>

                        {tenant && (
                            <div className="pt-2 flex flex-col gap-2">
                                <button
                                    onClick={handleRebindPlacements}
                                    disabled={loading}
                                    className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-teal-600 hover:bg-teal-500 text-white font-medium rounded-lg text-sm transition disabled:opacity-50 cursor-pointer"
                                >
                                    <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                                    Re-Bind CRM Placements
                                </button>

                                <button
                                    onClick={handleTestConnection}
                                    disabled={loading}
                                    className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium rounded-lg text-sm transition disabled:opacity-50 cursor-pointer"
                                >
                                    <ShieldCheck className="w-4 h-4 text-teal-400" />
                                    Test Unite EMR API
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Diagnostics Footer */}
                <div className="bg-slate-900/60 border border-slate-800 rounded-xl p-4 text-xs text-slate-400 flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>Bitrix24 SDK: {bxInitialized ? 'Initialized (BX24.ready)' : 'Standalone Iframe'}</span>
                    </div>
                    <div>
                        Current Placement Context: <code className="bg-slate-950 px-2 py-0.5 rounded text-teal-300 font-mono">{b24Context.placement}</code>
                    </div>
                </div>
            </div>
        </div>
    );
}
