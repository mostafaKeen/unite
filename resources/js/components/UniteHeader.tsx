import React from 'react';
import { Link } from '@inertiajs/react';
import { Activity, Building2, ShieldCheck, Stethoscope, LogOut, User } from 'lucide-react';

interface CurrentUser {
    id: number;
    name: string;
    email: string;
    role: string;
    tenant_id?: string | null;
    can_manage_tenants: boolean;
    can_manage_users: boolean;
}

interface UniteHeaderProps {
    title?: string;
    subtitle?: string;
    currentUser?: CurrentUser | null;
}

export const UniteHeader: React.FC<UniteHeaderProps> = ({
    title = "Multi-Tenant Integration Gateway",
    subtitle = "Bitrix24 CRM ⇄ Unite EMR Healthcare Platform",
    currentUser = null,
}) => {
    return (
        <header className="bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-teal-100 dark:border-teal-900/40 sticky top-0 z-40">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex justify-between items-center h-20">
                    {/* Brand Logo & Slogan */}
                    <div className="flex items-center gap-4">
                        <Link href="/dashboard" className="flex items-center gap-3 group">
                            <div className="bg-[#00a5b5] text-white font-bold px-3.5 py-2 rounded-xl flex items-center shadow-lg shadow-[#00a5b5]/25 group-hover:scale-105 transition-transform duration-200">
                                <span className="text-2xl tracking-tight font-extrabold">Unite</span>
                                <span className="text-2xl text-white font-black leading-none ml-0.5 -mt-2.5">+</span>
                            </div>
                            <div>
                                <p className="text-[10px] tracking-[0.2em] font-bold text-[#00a5b5] dark:text-cyan-400 uppercase leading-none">
                                    OPTIMIZING HEALTHCARE PROCESS
                                </p>
                                <p className="text-sm font-bold text-slate-800 dark:text-slate-100 leading-tight mt-0.5">
                                    {title}
                                </p>
                            </div>
                        </Link>

                        {/* Visual divider line from PDF cover */}
                        <div className="hidden md:block w-px h-8 bg-[#ea580c]/60 mx-1"></div>

                        <span className="hidden md:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-teal-50 text-teal-700 border border-teal-200 dark:bg-teal-950/50 dark:text-teal-300 dark:border-teal-800">
                            <ShieldCheck className="w-3.5 h-3.5 text-[#00a5b5]" />
                            API v0.0.5 Ready
                        </span>
                    </div>

                    {/* User profile & quick actions */}
                    <div className="flex items-center gap-3">
                        {currentUser ? (
                            <div className="flex items-center gap-3">
                                <div className="hidden sm:flex flex-col text-right">
                                    <span className="text-xs font-bold text-slate-800 dark:text-slate-200 leading-tight">
                                        {currentUser.name}
                                    </span>
                                    <div className="flex items-center justify-end gap-1 mt-0.5">
                                        <span className={`px-2 py-0.2 rounded text-[10px] font-bold ${
                                            currentUser.role === 'super_admin'
                                                ? 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300'
                                                : currentUser.role === 'tenant_admin'
                                                ? 'bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300'
                                                : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                        }`}>
                                            {currentUser.role === 'super_admin' ? 'Super Admin' : currentUser.role === 'tenant_admin' ? 'Tenant Admin' : 'Staff User'}
                                        </span>
                                    </div>
                                </div>

                                <Link
                                    method="post"
                                    href="/logout"
                                    as="button"
                                    className="p-2 rounded-xl text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/50 transition-colors"
                                    title="Sign out"
                                >
                                    <LogOut className="w-4 h-4" />
                                </Link>
                            </div>
                        ) : (
                            <Link
                                href="/login"
                                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-[#00a5b5] hover:bg-[#008f9c] shadow-md shadow-[#00a5b5]/20 transition-all duration-150"
                            >
                                <User className="w-4 h-4" />
                                Sign In
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </header>
    );
};
