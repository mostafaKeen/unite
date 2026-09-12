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

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
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
                <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-3xl p-6 sm:p-8 shadow-xl shadow-teal-950/5 mb-6">
                    {status && (
                        <div className="mb-4 p-3 rounded-xl bg-teal-50 text-teal-800 border border-teal-200 text-xs font-semibold text-center">
                            {status}
                        </div>
                    )}

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
