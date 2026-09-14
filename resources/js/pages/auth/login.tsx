import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { ShieldCheck, Lock } from 'lucide-react';
import React from 'react';

interface TenantOption {
    id: string;
    name: string;
    b24_domain: string | null;
    slug: string;
}

type Props = {
    status?: string;
    canResetPassword: boolean;
    tenants?: TenantOption[];
};

export default function Login({ status, canResetPassword, tenants = [] }: Props) {
    const [selectedTenantId, setSelectedTenantId] = React.useState<string>(tenants[0]?.id || '');

    const handleBitrixLogin = () => {
        if (!selectedTenantId) return;
        window.location.href = `/b24/auth/user-redirect/${selectedTenantId}`;
    };

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col justify-center items-center p-4 sm:p-6 font-sans">
            <Head title="Secure Sign In | Unite EMR & Bitrix24 Integration Platform" />

            <div className="w-full max-w-md">
                {/* Brand Logo Header */}
                <div className="text-center mb-8">
                    <div className="inline-flex items-center gap-3 mb-3">
                        <div className="bg-[#00a5b5] text-white font-bold px-4 py-2 rounded-2xl flex items-center shadow-lg shadow-[#00a5b5]/25">
                            <span className="text-2xl tracking-tight font-extrabold">Unite</span>
                            <span className="text-2xl text-white font-black leading-none ml-0.5 -mt-2.5">+</span>
                        </div>
                    </div>
                    <p className="text-[10px] tracking-[0.25em] font-bold text-[#00a5b5] uppercase leading-none">
                        OPTIMIZING HEALTHCARE PROCESS
                    </p>
                    <h1 className="text-lg font-bold text-slate-800 dark:text-slate-100 mt-2">
                        Healthcare Integration Platform
                    </h1>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        Secure Multi-Tenant Gateway • Authorized Access Only
                    </p>
                </div>

                {/* Login Card */}
                <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-3xl p-6 sm:p-8 shadow-xl shadow-teal-950/5 mb-6 space-y-6">
                    {status && (
                        <div className="p-3 rounded-xl bg-teal-50 text-teal-800 border border-teal-200 text-xs font-semibold text-center">
                            {status}
                        </div>
                    )}

                    {/* 1. Bitrix24 OAuth Sign-In Section */}
                    {tenants.length > 0 && (
                        <div className="bg-gradient-to-br from-cyan-950/20 to-teal-950/20 dark:from-slate-950 dark:to-slate-950 border border-cyan-500/20 dark:border-cyan-500/30 rounded-2xl p-4 space-y-3">
                            <div className="flex items-center gap-2">
                                <div className="w-7 h-7 rounded-lg bg-[#2fc6f6]/20 border border-[#2fc6f6]/30 flex items-center justify-center">
                                    <svg className="w-4 h-4 text-[#2fc6f6]" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 className="text-xs font-bold text-slate-800 dark:text-slate-100">Sign In with Bitrix24 OAuth</h2>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400">Multi-tenant User Detection</p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <label className="text-[11px] font-semibold text-slate-600 dark:text-slate-300 block">
                                    Select Facility / Tenant Portal:
                                </label>
                                <select
                                    value={selectedTenantId}
                                    onChange={(e) => setSelectedTenantId(e.target.value)}
                                    className="w-full text-xs rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950 px-3 py-2 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#00a5b5]"
                                >
                                    {tenants.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name} ({t.b24_domain || 'Bitrix24 Portal'})
                                        </option>
                                    ))}
                                </select>

                                <button
                                    type="button"
                                    onClick={handleBitrixLogin}
                                    className="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl font-bold text-white bg-[#2fc6f6] hover:bg-[#20b3e2] shadow-md shadow-[#2fc6f6]/20 transition-all text-xs cursor-pointer"
                                >
                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12H3m0 0l4-4m-4 4l4 4m14-9v10a2 2 0 01-2 2H11" />
                                    </svg>
                                    Authorize via Bitrix24 Portal
                                </button>
                            </div>
                        </div>
                    )}

                    {tenants.length > 0 && (
                        <div className="relative flex items-center justify-center">
                            <div className="border-t border-slate-200 dark:border-slate-800 w-full"></div>
                            <span className="bg-white dark:bg-slate-900 px-3 text-[10px] uppercase font-bold tracking-wider text-slate-400 shrink-0">
                                Or Sign In with Platform Account
                            </span>
                            <div className="border-t border-slate-200 dark:border-slate-800 w-full"></div>
                        </div>
                    )}

                    {/* 2. Platform Email / Password Form */}
                    <Form
                        {...store.form()}
                        resetOnSuccess={['password']}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor="email" className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Work Email Address
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="email"
                                        placeholder="name@company.ae"
                                        className="rounded-xl border-slate-200 dark:border-slate-700 text-sm focus:ring-2 focus:ring-[#00a5b5]"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="space-y-1.5">
                                    <div className="flex items-center justify-between">
                                        <Label htmlFor="password" className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                            Account Password
                                        </Label>
                                        {canResetPassword && (
                                            <TextLink href={request()} className="text-[11px] text-[#00a5b5] hover:underline">
                                                Forgot password?
                                            </TextLink>
                                        )}
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        tabIndex={2}
                                        autoComplete="current-password"
                                        placeholder="••••••••••••"
                                        className="rounded-xl border-slate-200 dark:border-slate-700 text-sm focus:ring-2 focus:ring-[#00a5b5]"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="flex items-center space-x-2 my-1">
                                    <Checkbox id="remember" name="remember" tabIndex={3} />
                                    <Label htmlFor="remember" className="text-xs text-slate-500 font-medium">
                                        Remember this device
                                    </Label>
                                </div>

                                <Button
                                    type="submit"
                                    className="w-full py-2.5 rounded-xl font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] shadow-md shadow-[#00a5b5]/25 transition-all text-xs mt-2"
                                    tabIndex={4}
                                    disabled={processing}
                                >
                                    {processing ? <Spinner className="w-4 h-4 mr-2" /> : null}
                                    Sign In to Platform
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                {/* Security Badge Footer */}
                <div className="text-center text-xs text-slate-400 space-y-1">
                    <p className="flex items-center justify-center gap-1.5 text-[11px]">
                        <ShieldCheck className="w-3.5 h-3.5 text-[#00a5b5]" />
                        End-to-End Encrypted Session • Bitrix24 OAuth Verified
                    </p>
                    <p className="text-[10px] text-slate-400">
                        Unauthorized access is prohibited and monitored.
                    </p>
                </div>
            </div>
        </div>
    );
}
