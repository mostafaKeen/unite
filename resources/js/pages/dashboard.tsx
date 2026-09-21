import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { UniteHeader } from '@/components/UniteHeader';
import { 
    Building2, Stethoscope, Activity, Calendar, ShieldCheck, 
    RefreshCw, CheckCircle2, AlertCircle, Plus, ExternalLink, 
    Clock, Phone, DollarSign, Filter, Search, ChevronRight, 
    Sliders, Zap, ArrowUpRight, X, UserCheck, Users, Trash2, KeyRound,
    Eye, EyeOff, Lock, Settings, Edit2, Tag, Package
} from 'lucide-react';

interface CurrentUser {
    id: number;
    name: string;
    email: string;
    role: string;
    tenant_id?: string | null;
    can_manage_tenants: boolean;
    can_manage_users: boolean;
}

interface Tenant {
    id: string;
    name: string;
    slug: string;
    status: string;
    b24_domain?: string;
    b24_member_id?: string;
    b24_client_id?: string;
    has_b24_client_secret?: boolean;
    has_b24_oauth?: boolean;
    unite_environment: string;
    unite_base_url: string;
    unite_app_id?: string;
    default_clinic_id?: string;
    clinics_cache?: any[];
    doctors_cache?: any[];
    items_cache?: any[];
    appointments_count?: number;
    sync_logs_count?: number;
}

interface Appointment {
    id: string;
    unite_appointment_id: string;
    b24_deal_id?: string;
    clinic_id: string;
    clinic_name?: string;
    doctor_name?: string;
    patient_firstname: string;
    patient_lastname: string;
    patient_mobileno: string;
    start_datetime: string;
    duration_minutes: number;
    status: string;
    status_description?: string;
    invoice_reference?: string;
    invoice_total?: number;
    tenant?: { name: string };
}

interface SyncLog {
    id: number;
    tenant_id: string;
    direction: string;
    entity_type: string;
    status: string;
    message: string;
    payload?: any;
    response?: any;
    created_at: string;
    tenant?: { name: string };
}

interface UserItem {
    id: number;
    name: string;
    email: string;
    role: string;
    tenant_id?: string | null;
    created_at: string;
    tenant?: { name: string };
}

interface Props {
    currentUser: CurrentUser;
    tenants: Tenant[];
    stats: {
        total_tenants: number;
        total_appointments: number;
        confirmed_appointments: number;
        pending_appointments: number;
        completed_appointments: number;
        total_invoiced: number;
    };
    recentAppointments: Appointment[];
    recentLogs: SyncLog[];
    tenantUsers?: UserItem[];
}

export default function Dashboard({
    currentUser,
    tenants = [],
    stats,
    recentAppointments = [],
    recentLogs = [],
    tenantUsers = [],
}: Props) {
    const [activeTab, setActiveTab] = useState<'tenants' | 'appointments' | 'items' | 'users' | 'logs'>('tenants');
    const [tenantsList, setTenantsList] = useState<Tenant[]>(tenants);
    const [selectedTenantIdForItems, setSelectedTenantIdForItems] = useState<string>(
        currentUser.tenant_id || tenants[0]?.id || ''
    );
    const [testingTenantId, setTestingTenantId] = useState<string | null>(null);
    const [diagnosticResult, setDiagnosticResult] = useState<any | null>(null);
    const [showNewTenantModal, setShowNewTenantModal] = useState<boolean>(false);
    const [showNewUserModal, setShowNewUserModal] = useState<boolean>(false);

    // Items state (Direct live items from Unite EMR API - Read-only catalog)
    const [liveItems, setLiveItems] = useState<any[]>([]);
    const [itemSearchTerm, setItemSearchTerm] = useState<string>('');
    const [itemClinicFilter, setItemClinicFilter] = useState<string>('');
    const [syncingItemsLoading, setSyncingItemsLoading] = useState<boolean>(false);
    const [fetchingItemsLoading, setFetchingItemsLoading] = useState<boolean>(false);

    // Users list state
    const [usersList, setUsersList] = useState<UserItem[]>(tenantUsers);

    // Form for new tenant
    const [newTenant, setNewTenant] = useState({
        name: '',
        b24_domain: '',
        b24_client_id: '',
        b24_client_secret: '',
        unite_environment: 'sandbox',
        unite_base_url: 'https://ucexternalapi-test.uniteemr.org',
        unite_app_id: '',
        unite_app_key: '',
        unite_initial_token: '',
        default_clinic_id: '',
    });

    const [showNewB24Secret, setShowNewB24Secret] = useState<boolean>(false);
    const [showNewUniteKey, setShowNewUniteKey] = useState<boolean>(false);

    // Edit tenant state
    const [editingTenant, setEditingTenant] = useState<Tenant | null>(null);
    const [editTenantForm, setEditTenantForm] = useState({
        name: '',
        b24_domain: '',
        b24_client_id: '',
        b24_client_secret: '',
        unite_environment: 'sandbox',
        unite_base_url: 'https://ucexternalapi-test.uniteemr.org',
        unite_app_id: '',
        unite_app_key: '',
    });
    const [showEditB24Secret, setShowEditB24Secret] = useState<boolean>(false);
    const [showEditUniteKey, setShowEditUniteKey] = useState<boolean>(false);
    const [updatingTenantLoading, setUpdatingTenantLoading] = useState<boolean>(false);

    // Form for new user
    const [newUser, setNewUser] = useState({
        name: '',
        email: '',
        password: '',
        role: currentUser.is_super_admin ? 'tenant_admin' : 'tenant_user',
        tenant_id: currentUser.tenant_id || (tenants[0]?.id ?? ''),
    });

    const [creatingLoading, setCreatingLoading] = useState<boolean>(false);
    const [userLoading, setUserLoading] = useState<boolean>(false);
    const [actionMessage, setActionMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    const openEditTenant = (t: Tenant) => {
        setEditingTenant(t);
        setEditTenantForm({
            name: t.name,
            b24_domain: t.b24_domain || '',
            b24_client_id: t.b24_client_id || '',
            b24_client_secret: '',
            unite_environment: t.unite_environment || 'sandbox',
            unite_base_url: t.unite_base_url || 'https://ucexternalapi-test.uniteemr.org',
            unite_app_id: t.unite_app_id || '',
            unite_app_key: '',
        });
        setShowEditB24Secret(false);
        setShowEditUniteKey(false);
    };

    const handleUpdateTenant = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingTenant) return;
        setUpdatingTenantLoading(true);
        try {
            const payload: any = { ...editTenantForm };
            if (!payload.b24_client_secret) delete payload.b24_client_secret;
            if (!payload.unite_app_key) delete payload.unite_app_key;

            const res = await fetch(`/tenants/${editingTenant.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                setEditingTenant(null);
                window.location.reload();
            } else {
                alert(data.message || 'Failed to update tenant configuration.');
            }
        } catch (err: any) {
            alert('Error updating tenant: ' + err.message);
        } finally {
            setUpdatingTenantLoading(false);
        }
    };

    const [deletingTenantId, setDeletingTenantId] = useState<string | null>(null);

    const handleDeleteTenant = async (tenant: Tenant) => {
        if (!confirm(`Are you sure you want to delete "${tenant.name}"?\n\nThis will permanently remove the company tenant, its configuration, and associated data.`)) {
            return;
        }

        setDeletingTenantId(tenant.id);
        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
            const res = await fetch(`/tenants/${tenant.id}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
            const data = await res.json();
            if (data.success) {
                setTenantsList(prev => prev.filter(t => t.id !== tenant.id));
                if (editingTenant?.id === tenant.id) {
                    setEditingTenant(null);
                }
                setActionMessage({ type: 'success', text: data.message || 'Tenant deleted successfully' });
            } else {
                alert(data.message || 'Failed to delete tenant');
            }
        } catch (err: any) {
            alert('Error deleting tenant: ' + err.message);
        } finally {
            setDeletingTenantId(null);
        }
    };

    // Filter appointments
    const [searchTerm, setSearchTerm] = useState<string>('');

    const handleTestUniteConnection = async (tenantId: string) => {
        setTestingTenantId(tenantId);
        setDiagnosticResult(null);
        try {
            const res = await fetch(`/tenants/${tenantId}/test-unite`, {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            setDiagnosticResult(data);
            if (data.success) {
                setActionMessage({ type: 'success', text: `Connection to Unite EMR verified successfully!` });
            } else {
                setActionMessage({ type: 'error', text: data.message || 'Connection test failed' });
            }
        } catch (e: any) {
            setActionMessage({ type: 'error', text: 'Network request error: ' + e.message });
        } finally {
            setTestingTenantId(null);
        }
    };

    const handleCreateTenant = async (e: React.FormEvent) => {
        e.preventDefault();
        setCreatingLoading(true);
        try {
            const res = await fetch('/tenants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(newTenant),
            });
            const data = await res.json();
            if (data.success) {
                setShowNewTenantModal(false);
                window.location.reload();
            } else {
                alert(data.message || 'Failed to register tenant.');
            }
        } catch (err: any) {
            alert('Error: ' + err.message);
        } finally {
            setCreatingLoading(false);
        }
    };

    const handleCreateUser = async (e: React.FormEvent) => {
        e.preventDefault();
        setUserLoading(true);
        try {
            const res = await fetch('/api/users', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(newUser),
            });
            const data = await res.json();
            if (data.success && data.user) {
                setUsersList([data.user, ...usersList]);
                setShowNewUserModal(false);
                setActionMessage({ type: 'success', text: `User ${data.user.name} created successfully!` });
                setNewUser({
                    name: '',
                    email: '',
                    password: '',
                    role: 'tenant_user',
                    tenant_id: currentUser.tenant_id || (tenants[0]?.id ?? ''),
                });
            } else {
                alert(data.message || 'Failed to create user.');
            }
        } catch (err: any) {
            alert('Error creating user: ' + err.message);
        } finally {
            setUserLoading(false);
        }
    };

    const handleDeleteUser = async (userId: number) => {
        if (!confirm('Are you sure you want to remove this user?')) return;
        try {
            const res = await fetch(`/api/users/${userId}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (data.success) {
                setUsersList(usersList.filter(u => u.id !== userId));
                setActionMessage({ type: 'success', text: 'User removed.' });
            } else {
                alert(data.message || 'Failed to delete user.');
            }
        } catch (e: any) {
            alert('Error: ' + e.message);
        }
    };

    const currentItemsTenant = tenantsList.find(t => t.id === selectedTenantIdForItems) || tenantsList[0];
    const filteredItems = liveItems.filter(item => {
        const matchesSearch = !itemSearchTerm || 
            item.item_description?.toLowerCase().includes(itemSearchTerm.toLowerCase()) ||
            String(item.item_code).includes(itemSearchTerm);
        const matchesClinic = !itemClinicFilter || item.clinic_id === itemClinicFilter;
        return matchesSearch && matchesClinic;
    });

    const handleSyncItemsFromUnite = async () => {
        if (!currentItemsTenant) return;
        setSyncingItemsLoading(true);
        try {
            const res = await fetch(`/tenants/${currentItemsTenant.id}/sync-directories`, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (data.success && data.data) {
                setTenantsList(prev => prev.map(t => t.id === currentItemsTenant.id ? { 
                    ...t, 
                    clinics_cache: data.data.clinics,
                    doctors_cache: data.data.doctors
                } : t));
                if (Array.isArray(data.data.items)) {
                    setLiveItems(data.data.items);
                }
                setActionMessage({ type: 'success', text: `Retrieved ${data.data.items?.length || 0} live items from Unite EMR API!` });
            } else {
                alert(data.message || 'Failed to sync directories.');
            }
        } catch (e: any) {
            alert('Error syncing from Unite: ' + e.message);
        } finally {
            setSyncingItemsLoading(false);
        }
    };

    const fetchLiveItems = async (tenantId: string) => {
        if (!tenantId) return;
        setFetchingItemsLoading(true);
        try {
            const res = await fetch(`/tenants/${tenantId}/items`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (data.success && Array.isArray(data.items)) {
                setLiveItems(data.items);
            }
        } catch (e) {
            console.error('Failed to fetch items from Unite EMR:', e);
        } finally {
            setFetchingItemsLoading(false);
        }
    };

    useEffect(() => {
        if (activeTab === 'items' && currentItemsTenant) {
            fetchLiveItems(currentItemsTenant.id);
        }
    }, [activeTab, selectedTenantIdForItems]);

    const filteredAppointments = recentAppointments.filter(a => 
        !searchTerm || 
        `${a.patient_firstname} ${a.patient_lastname}`.toLowerCase().includes(searchTerm.toLowerCase()) ||
        a.patient_mobileno.includes(searchTerm) ||
        (a.doctor_name && a.doctor_name.toLowerCase().includes(searchTerm.toLowerCase()))
    );

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 font-sans">
            <Head title="Unite EMR & Bitrix24 | Multi-Tenant Platform" />

            <UniteHeader 
                title="Multi-Tenant Integration Platform" 
                subtitle="Bitrix24 CRM ⇄ Unite EMR Synchronization Gateway" 
                currentUser={currentUser}
            />

            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {/* Notification Banner */}
                {actionMessage && (
                    <div className={`mb-6 p-4 rounded-2xl border flex items-center justify-between shadow-sm animate-in fade-in ${
                        actionMessage.type === 'success'
                            ? 'bg-teal-50 dark:bg-teal-950/40 border-teal-200 dark:border-teal-800 text-teal-900 dark:text-teal-200'
                            : 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800 text-rose-900 dark:text-rose-200'
                    }`}>
                        <div className="flex items-center gap-2.5 text-xs font-semibold">
                            {actionMessage.type === 'success' ? (
                                <CheckCircle2 className="w-5 h-5 text-[#00a5b5]" />
                            ) : (
                                <AlertCircle className="w-5 h-5 text-rose-500" />
                            )}
                            <span>{actionMessage.text}</span>
                        </div>
                        <button onClick={() => setActionMessage(null)} className="text-slate-400 hover:text-slate-600">
                            <X className="w-4 h-4" />
                        </button>
                    </div>
                )}

                {/* Hero / System Overview Banner */}
                <div className="bg-gradient-to-r from-teal-900 via-cyan-900 to-slate-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl shadow-teal-950/20 mb-8 relative overflow-hidden">
                    <div className="absolute right-0 top-0 translate-x-10 -translate-y-10 w-96 h-96 bg-[#00a5b5]/20 rounded-full blur-3xl pointer-events-none"></div>
                    
                    <div className="relative z-10 max-w-3xl">
                        <div className="flex flex-wrap items-center gap-2 mb-3">
                            <span className="px-3 py-1 rounded-full text-xs font-bold bg-[#00a5b5] text-white tracking-wide">
                                HEALTHCARE CLOUD GATEWAY
                            </span>
                            <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold ${
                                currentUser.role === 'super_admin'
                                    ? 'bg-purple-500/20 text-purple-200 border border-purple-400/40'
                                    : 'bg-teal-500/20 text-teal-200 border border-teal-400/40'
                            }`}>
                                {currentUser.role === 'super_admin' ? 'Super Admin Mode' : 'Tenant Admin Mode'}
                            </span>
                            <span className="text-xs text-cyan-200 font-semibold">
                                {currentUser.role === 'super_admin' ? 'Managing All Platform Tenants' : `Scoped to: ${tenants[0]?.name || 'Assigned Tenant'}`}
                            </span>
                        </div>

                        <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight mb-3">
                            Unified Clinical Appointment & Invoicing Hub
                        </h1>

                        <p className="text-sm text-cyan-100/80 leading-relaxed mb-6">
                            {currentUser.role === 'super_admin' 
                                ? 'Super Administrator view: Full authorization to configure companies, Unite credentials, Bitrix24 portal connections, and platform users.'
                                : 'Tenant Administrator view: Manage your clinical operations, sync status with Bitrix24 CRM, and administer users within your company.'}
                        </p>

                        <div className="flex flex-wrap items-center gap-3">
                            {/* Only Super Admin can register tenants */}
                            {currentUser.can_manage_tenants && (
                                <button
                                    onClick={() => setShowNewTenantModal(true)}
                                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-950 bg-white hover:bg-cyan-50 shadow-md transition-all"
                                >
                                    <Plus className="w-4 h-4 text-[#00a5b5]" />
                                    Register Company Tenant
                                </button>
                            )}

                            {/* Tenant Admin or Super Admin can manage users */}
                            {currentUser.can_manage_users && (
                                <button
                                    onClick={() => {
                                        setActiveTab('users');
                                        setShowNewUserModal(true);
                                    }}
                                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-slate-800/80 hover:bg-slate-700 border border-slate-600 transition-all"
                                >
                                    <Users className="w-4 h-4 text-cyan-300" />
                                    Add Company User
                                </button>
                            )}

                            {tenants.length > 0 && (
                                <a
                                    href={`/b24/widget/deal-tab/${tenants[0].id}?deal_id=1042`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] border border-cyan-400/30 transition-all shadow-md"
                                >
                                    <ExternalLink className="w-4 h-4" />
                                    Launch CRM Deal Tab Simulator
                                </a>
                            )}
                        </div>
                    </div>
                </div>

                {/* Metric Summary Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-4 shadow-sm">
                        <span className="text-[11px] font-semibold text-slate-400 block mb-1">
                            {currentUser.can_manage_tenants ? 'Company Tenants' : 'Your Company'}
                        </span>
                        <div className="flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-slate-900 dark:text-slate-100">{stats.total_tenants}</span>
                            <Building2 className="w-4 h-4 text-[#00a5b5]" />
                        </div>
                        <span className="text-[10px] text-teal-600 font-medium">100% Isolated</span>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-4 shadow-sm">
                        <span className="text-[11px] font-semibold text-slate-400 block mb-1">Total Bookings</span>
                        <div className="flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-slate-900 dark:text-slate-100">{stats.total_appointments}</span>
                            <Calendar className="w-4 h-4 text-cyan-600" />
                        </div>
                        <span className="text-[10px] text-cyan-600 font-medium">Unite EMR Synced</span>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-4 shadow-sm">
                        <span className="text-[11px] font-semibold text-slate-400 block mb-1">Confirmed (ACF)</span>
                        <div className="flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-teal-600">{stats.confirmed_appointments}</span>
                            <CheckCircle2 className="w-4 h-4 text-teal-500" />
                        </div>
                        <span className="text-[10px] text-slate-400 font-medium">Ready for Visit</span>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-4 shadow-sm">
                        <span className="text-[11px] font-semibold text-slate-400 block mb-1">Pending (AAC/YTC)</span>
                        <div className="flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-amber-500">{stats.pending_appointments}</span>
                            <Clock className="w-4 h-4 text-amber-500" />
                        </div>
                        <span className="text-[10px] text-amber-600 font-medium">Awaiting Action</span>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-4 shadow-sm">
                        <span className="text-[11px] font-semibold text-slate-400 block mb-1">Honoured (APH)</span>
                        <div className="flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-emerald-600">{stats.completed_appointments}</span>
                            <UserCheck className="w-4 h-4 text-emerald-500" />
                        </div>
                        <span className="text-[10px] text-emerald-600 font-medium">Consulted</span>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-4 shadow-sm">
                        <span className="text-[11px] font-semibold text-slate-400 block mb-1">Invoiced (AED)</span>
                        <div className="flex items-baseline justify-between">
                            <span className="text-xl font-extrabold text-slate-900 dark:text-slate-100 font-mono">
                                {Number(stats.total_invoiced).toLocaleString()}
                            </span>
                            <DollarSign className="w-4 h-4 text-emerald-600" />
                        </div>
                        <span className="text-[10px] text-slate-400 font-medium">5% UAE VAT</span>
                    </div>
                </div>

                {/* Tab Navigation */}
                <div className="flex items-center gap-3 border-b border-slate-200 dark:border-slate-800 pb-3 mb-6 text-sm font-semibold">
                    <button
                        onClick={() => setActiveTab('tenants')}
                        className={`pb-2 px-1 flex items-center gap-2 border-b-2 transition-all ${
                            activeTab === 'tenants'
                                ? 'border-[#00a5b5] text-[#00a5b5]'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                        }`}
                    >
                        <Building2 className="w-4 h-4" />
                        {currentUser.can_manage_tenants ? `Company Tenants (${tenants.length})` : 'Company Details'}
                    </button>

                    <button
                        onClick={() => setActiveTab('appointments')}
                        className={`pb-2 px-1 flex items-center gap-2 border-b-2 transition-all ${
                            activeTab === 'appointments'
                                ? 'border-[#00a5b5] text-[#00a5b5]'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                        }`}
                    >
                        <Calendar className="w-4 h-4" />
                        Appointments Ledger ({recentAppointments.length})
                    </button>

                    <button
                        onClick={() => setActiveTab('items')}
                        className={`pb-2 px-1 flex items-center gap-2 border-b-2 transition-all ${
                            activeTab === 'items'
                                ? 'border-[#00a5b5] text-[#00a5b5]'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                        }`}
                    >
                        <Stethoscope className="w-4 h-4" />
                        Services & Items ({currentTenantItems.length})
                    </button>

                    <button
                        onClick={() => setActiveTab('users')}
                        className={`pb-2 px-1 flex items-center gap-2 border-b-2 transition-all ${
                            activeTab === 'users'
                                ? 'border-[#00a5b5] text-[#00a5b5]'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                        }`}
                    >
                        <Users className="w-4 h-4" />
                        Company Users ({usersList.length})
                    </button>

                    <button
                        onClick={() => setActiveTab('logs')}
                        className={`pb-2 px-1 flex items-center gap-2 border-b-2 transition-all ${
                            activeTab === 'logs'
                                ? 'border-[#00a5b5] text-[#00a5b5]'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                        }`}
                    >
                        <Activity className="w-4 h-4" />
                        Sync Audit Logs ({recentLogs.length})
                    </button>
                </div>

                {/* Tab 1: Company Tenants */}
                {activeTab === 'tenants' && (
                    <div className="space-y-4">
                        {tenantsList.map((t) => {
                            const isTesting = testingTenantId === t.id;
                            const clinicsCount = t.clinics_cache?.length || 0;
                            const doctorsCount = t.doctors_cache?.length || 0;

                            return (
                                <div 
                                    key={t.id} 
                                    className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-5 sm:p-6 shadow-sm hover:border-[#00a5b5]/50 transition-all"
                                >
                                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                        <div>
                                            <div className="flex items-center gap-2.5 mb-1.5">
                                                <div className="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-[#00a5b5]">
                                                    <Building2 className="w-5 h-5" />
                                                </div>
                                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                                    {t.name}
                                                </h3>
                                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-50 text-teal-700 border border-teal-200 dark:bg-teal-950/60 dark:text-teal-300">
                                                    {t.status.toUpperCase()}
                                                </span>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-slate-500 mt-2.5">
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">Bitrix24 Portal:</strong>{' '}
                                                    <code className="text-[#00a5b5] font-mono font-semibold">{t.b24_domain || 'Pending Setup'}</code>
                                                </span>
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">Client ID:</strong>{' '}
                                                    <code className="font-mono text-slate-600 dark:text-slate-400">{t.b24_client_id || 'Not set'}</code>
                                                </span>
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">Client Secret:</strong>{' '}
                                                    {t.has_b24_client_secret ? (
                                                        <span className="inline-flex items-center gap-1 text-emerald-600 font-semibold">
                                                            <ShieldCheck className="w-3 h-3 text-emerald-500" /> Encrypted & Stored
                                                        </span>
                                                    ) : (
                                                        <span className="text-amber-500 font-medium">Not configured</span>
                                                    )}
                                                </span>
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">OAuth Status:</strong>{' '}
                                                    {t.has_b24_oauth ? (
                                                        <span className="inline-flex items-center gap-1 text-teal-600 font-semibold">
                                                            <CheckCircle2 className="w-3 h-3 text-teal-500" /> Connected
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400 font-medium">Pending Authorization</span>
                                                    )}
                                                </span>
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">Unite App ID:</strong>{' '}
                                                    <code className="font-mono">{t.unite_app_id || 'Not configured'}</code>
                                                </span>
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">Clinics:</strong>{' '}
                                                    {clinicsCount} Active
                                                </span>
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">Doctors:</strong>{' '}
                                                    {doctorsCount} Registered
                                                </span>
                                                <span>
                                                    <strong className="text-slate-700 dark:text-slate-300">Services & Items:</strong>{' '}
                                                    {t.items_cache?.length || 0} Configured
                                                </span>
                                            </div>
                                        </div>

                                        {/* Action buttons */}
                                        <div className="flex flex-wrap items-center gap-2">
                                            <button
                                                onClick={() => {
                                                    setSelectedTenantIdForItems(t.id);
                                                    setActiveTab('items');
                                                }}
                                                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 transition-colors"
                                            >
                                                <Stethoscope className="w-3.5 h-3.5 text-[#00a5b5]" />
                                                Manage Services ({t.items_cache?.length || 0})
                                            </button>

                                            {/* Edit & Delete Tenant buttons (Super Admin) */}
                                            {currentUser.can_manage_tenants && (
                                                <>
                                                    <button
                                                        onClick={() => openEditTenant(t)}
                                                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 transition-colors"
                                                        title="Edit Tenant Configuration"
                                                    >
                                                        <Edit2 className="w-3.5 h-3.5 text-[#00a5b5]" />
                                                        Edit Tenant
                                                    </button>

                                                    <button
                                                        disabled={deletingTenantId === t.id}
                                                        onClick={() => handleDeleteTenant(t)}
                                                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-rose-600 dark:text-rose-400 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/50 dark:hover:bg-rose-900/40 border border-rose-200 dark:border-rose-900/60 transition-colors"
                                                        title="Delete Tenant"
                                                    >
                                                        <Trash2 className="w-3.5 h-3.5" />
                                                        {deletingTenantId === t.id ? 'Deleting...' : 'Delete'}
                                                    </button>
                                                </>
                                            )}

                                            <button
                                                disabled={isTesting}
                                                onClick={() => handleTestUniteConnection(t.id)}
                                                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-[#00a5b5] bg-teal-50 hover:bg-teal-100 dark:bg-teal-950/60 dark:hover:bg-teal-900/40 border border-teal-200 dark:border-teal-800 transition-colors"
                                            >
                                                <RefreshCw className={`w-3.5 h-3.5 ${isTesting ? 'animate-spin' : ''}`} />
                                                {isTesting ? 'Testing Gateway...' : 'Test Unite Gateway'}
                                            </button>

                                            <a
                                                href={`/b24/widget/deal-tab/${t.id}?deal_id=1042`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 transition-colors"
                                            >
                                                <ExternalLink className="w-3.5 h-3.5 text-[#00a5b5]" />
                                                CRM Widget
                                            </a>

                                            {t.b24_domain && (
                                                <a
                                                    href={`/b24/oauth/redirect/${t.id}`}
                                                    className={`inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-white transition-colors shadow-sm ${
                                                        t.has_b24_oauth 
                                                            ? 'bg-teal-600 hover:bg-teal-700' 
                                                            : 'bg-[#00a5b5] hover:bg-[#008f9c] ring-2 ring-[#00a5b5]/40'
                                                    }`}
                                                >
                                                    <Zap className="w-3.5 h-3.5" />
                                                    {t.has_b24_oauth ? 'Re-Authorize OAuth' : 'Connect Bitrix24 OAuth'}
                                                </a>
                                            )}
                                        </div>
                                    </div>

                                    {/* Bitrix24 Local App Quick Setup Helper Box */}
                                    <div className="mt-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-800 text-xs">
                                        <div className="flex items-center justify-between font-bold text-slate-800 dark:text-slate-200 mb-2">
                                            <span className="flex items-center gap-1.5 text-xs text-[#00a5b5]">
                                                <KeyRound className="w-4 h-4" /> Bitrix24 Local Application Config & Handler Links
                                            </span>
                                            <span className="text-[10px] text-slate-400 font-normal">
                                                Bitrix24 Portal &gt; Developer resources &gt; Other &gt; Local Application
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
                                            <div className="bg-white dark:bg-slate-900 p-2.5 rounded-lg border border-slate-100 dark:border-slate-800">
                                                <span className="text-slate-400 block text-[10px] font-semibold mb-0.5">
                                                    1. Handler URL (Installation & Webhook Callback)
                                                </span>
                                                <code className="block font-mono text-[11px] text-slate-700 dark:text-slate-300 break-all select-all bg-slate-50 dark:bg-slate-800 p-1 rounded border border-slate-200 dark:border-slate-700">
                                                    {window.location.origin}/api/b24/webhook/{t.id}
                                                </code>
                                            </div>

                                            <div className="bg-white dark:bg-slate-900 p-2.5 rounded-lg border border-slate-100 dark:border-slate-800">
                                                <span className="text-slate-400 block text-[10px] font-semibold mb-0.5">
                                                    2. OAuth Redirect / Callback URL
                                                </span>
                                                <code className="block font-mono text-[11px] text-slate-700 dark:text-slate-300 break-all select-all bg-slate-50 dark:bg-slate-800 p-1 rounded border border-slate-200 dark:border-slate-700">
                                                    {window.location.origin}/b24/oauth/callback
                                                </code>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Real-time Diagnostics Drawer if verified */}
                                    {diagnosticResult && diagnosticResult.diagnostics && testingTenantId === null && (
                                        <div className="mt-4 p-4 rounded-xl bg-teal-50/70 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800 text-xs animate-in fade-in">
                                            <div className="flex items-center justify-between font-bold text-teal-900 dark:text-teal-200 mb-2">
                                                <span>Unite EMR Connection Diagnostics:</span>
                                                <span className="font-mono text-[10px] text-teal-600">{diagnosticResult.diagnostics.base_url}</span>
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                <div className="bg-white dark:bg-slate-900 p-2.5 rounded-lg border border-teal-100 dark:border-teal-900">
                                                    <span className="text-slate-400 block text-[10px]">Clinics Discovered:</span>
                                                    <span className="font-bold text-[#00a5b5] text-sm">
                                                        {diagnosticResult.diagnostics.clinics_count} Facilities
                                                    </span>
                                                </div>
                                                <div className="bg-white dark:bg-slate-900 p-2.5 rounded-lg border border-teal-100 dark:border-teal-900">
                                                    <span className="text-slate-400 block text-[10px]">Doctors Discovered:</span>
                                                    <span className="font-bold text-[#00a5b5] text-sm">
                                                        {diagnosticResult.diagnostics.doctors_count} Doctors
                                                    </span>
                                                </div>
                                                <div className="bg-white dark:bg-slate-900 p-2.5 rounded-lg border border-teal-100 dark:border-teal-900">
                                                    <span className="text-slate-400 block text-[10px]">Bearer Token:</span>
                                                    <span className="font-mono text-emerald-600 font-semibold">
                                                        Valid & Active
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Tab 2: Appointments Ledger */}
                {activeTab === 'appointments' && (
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl overflow-hidden shadow-sm">
                        <div className="p-4 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <div className="relative w-full sm:w-80">
                                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                                <input
                                    type="text"
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    placeholder="Search patient name, phone, or doctor..."
                                    className="w-full pl-9 pr-4 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>
                            <span className="text-xs text-slate-400">
                                Showing {filteredAppointments.length} synced records
                            </span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="bg-slate-50 dark:bg-slate-800/60 text-slate-400 font-semibold border-b border-slate-100 dark:border-slate-800">
                                    <tr>
                                        <th className="p-3.5">Unite Appt ID</th>
                                        <th className="p-3.5">Patient Details</th>
                                        <th className="p-3.5">Clinic & Doctor</th>
                                        <th className="p-3.5">Date & Time</th>
                                        <th className="p-3.5">Status</th>
                                        <th className="p-3.5">Bitrix Deal</th>
                                        <th className="p-3.5">Tax Invoice</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {filteredAppointments.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="p-8 text-center text-slate-400">
                                                No appointments found matching your criteria.
                                            </td>
                                        </tr>
                                    ) : (
                                        filteredAppointments.map((a) => (
                                            <tr key={a.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                                <td className="p-3.5 font-mono font-bold text-[#00a5b5]">
                                                    #{a.unite_appointment_id}
                                                </td>
                                                <td className="p-3.5">
                                                    <span className="font-bold text-slate-800 dark:text-slate-200 block">
                                                        {a.patient_firstname} {a.patient_lastname}
                                                    </span>
                                                    <span className="text-slate-400 text-[11px] font-mono">
                                                        {a.patient_mobileno}
                                                    </span>
                                                </td>
                                                <td className="p-3.5">
                                                    <span className="font-semibold block text-slate-700 dark:text-slate-300">
                                                        {a.clinic_name || a.clinic_id}
                                                    </span>
                                                    <span className="text-slate-400 text-[11px]">
                                                        {a.doctor_name || 'Assigned Physician'}
                                                    </span>
                                                </td>
                                                <td className="p-3.5 text-slate-600 dark:text-slate-400">
                                                    {new Date(a.start_datetime).toLocaleDateString()} {new Date(a.start_datetime).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                </td>
                                                <td className="p-3.5">
                                                    <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                                                        a.status === 'ACF' ? 'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-300' :
                                                        a.status === 'APH' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' :
                                                        a.status === 'CVI' ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300' :
                                                        a.status === 'NSW' ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300' :
                                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300'
                                                    }`}>
                                                        {a.status}
                                                    </span>
                                                </td>
                                                <td className="p-3.5 font-mono text-slate-500">
                                                    {a.b24_deal_id ? `Deal #${a.b24_deal_id}` : '—'}
                                                </td>
                                                <td className="p-3.5">
                                                    {a.invoice_reference ? (
                                                        <span className="text-[11px] font-mono font-bold text-emerald-600">
                                                            {a.invoice_reference} (AED {a.invoice_total})
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400 text-[11px]">Pending</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Tab: Medical Services & Items Management */}
                {activeTab === 'items' && (
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl overflow-hidden shadow-sm">
                        {/* Header bar */}
                        <div className="p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2.5">
                                    <div className="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-[#00a5b5]">
                                        <Stethoscope className="w-5 h-5" />
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2.5">
                                            <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                                Medical Services, Procedures & Items
                                            </h3>
                                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-teal-50 dark:bg-teal-950/60 text-[#00a5b5] border border-teal-200/80 dark:border-teal-800">
                                                <Sparkles className="w-3 h-3 text-[#00a5b5]" />
                                                Live from Unite EMR API (v4.2)
                                            </span>
                                            {fetchingItemsLoading && (
                                                <span className="flex items-center gap-1 text-[11px] text-slate-400 font-medium">
                                                    <RefreshCw className="w-3 h-3 animate-spin text-[#00a5b5]" />
                                                    Fetching from Unite...
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-xs text-slate-400 mt-0.5">
                                            Live clinical procedures, consultation types, and prices retrieved directly from Unite EMR Gateway (<code className="font-mono text-[10px] bg-slate-100 dark:bg-slate-800 px-1 py-0.5 rounded">GET /GetItemDetails</code>).
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-2.5">
                                {currentUser.can_manage_tenants && tenantsList.length > 1 && (
                                    <div className="flex items-center gap-2">
                                        <label className="text-xs font-semibold text-slate-500">Company:</label>
                                        <select
                                            value={selectedTenantIdForItems}
                                            onChange={(e) => setSelectedTenantIdForItems(e.target.value)}
                                            className="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-semibold focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                        >
                                            {tenantsList.map(t => (
                                                <option key={t.id} value={t.id}>{t.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}

                                <button
                                    onClick={handleSyncItemsFromUnite}
                                    disabled={syncingItemsLoading || fetchingItemsLoading || !currentItemsTenant}
                                    className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 transition-colors disabled:opacity-50"
                                >
                                    <RefreshCw className={`w-3.5 h-3.5 ${syncingItemsLoading || fetchingItemsLoading ? 'animate-spin text-[#00a5b5]' : 'text-slate-500'}`} />
                                    {syncingItemsLoading ? 'Syncing...' : 'Sync from Unite API'}
                                </button>
                            </div>
                        </div>

                        {/* Search & Filter Bar */}
                        <div className="p-4 bg-slate-50/50 dark:bg-slate-800/30 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <div className="relative w-full sm:w-80">
                                <Search className="w-4 h-4 absolute left-3 top-2.5 text-slate-400" />
                                <input
                                    type="text"
                                    placeholder="Search by procedure name or code..."
                                    value={itemSearchTerm}
                                    onChange={(e) => setItemSearchTerm(e.target.value)}
                                    className="w-full pl-9 pr-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div className="flex items-center gap-3 w-full sm:w-auto">
                                <select
                                    value={itemClinicFilter}
                                    onChange={(e) => setItemClinicFilter(e.target.value)}
                                    className="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-600 dark:text-slate-300"
                                >
                                    <option value="">All Facilities / Clinics</option>
                                    {currentItemsTenant?.clinics_cache?.map((c: any) => (
                                        <option key={c.clinic_id} value={c.clinic_id}>{c.name || c.clinic_id}</option>
                                    ))}
                                </select>
                                <span className="text-xs text-slate-400 whitespace-nowrap">
                                    Showing <strong>{filteredItems.length}</strong> of <strong>{liveItems.length}</strong> items
                                </span>
                            </div>
                        </div>

                        {/* Table of items */}
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="bg-slate-50/75 dark:bg-slate-800/50 text-slate-500 border-b border-slate-100 dark:border-slate-800 uppercase font-semibold">
                                    <tr>
                                        <th className="py-3 px-4">Code</th>
                                        <th className="py-3 px-4">Service / Procedure Description</th>
                                        <th className="py-3 px-4">Standard Price</th>
                                        <th className="py-3 px-4">Duration</th>
                                        <th className="py-3 px-4">Facility / Clinic</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                                    {fetchingItemsLoading ? (
                                        <tr>
                                            <td colSpan={5} className="py-12 text-center text-slate-400">
                                                <RefreshCw className="w-6 h-6 text-[#00a5b5] animate-spin mx-auto mb-2" />
                                                <p className="font-semibold text-slate-700 dark:text-slate-300">Fetching live items from Unite EMR API...</p>
                                                <p className="text-xs text-slate-400 mt-1 font-mono">GET /GetItemDetails</p>
                                            </td>
                                        </tr>
                                    ) : filteredItems.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="py-12 text-center text-slate-400">
                                                <Stethoscope className="w-8 h-8 text-slate-300 mx-auto mb-2" />
                                                <p className="font-semibold text-slate-600 dark:text-slate-400">No medical services found.</p>
                                                <p className="text-xs text-slate-400 mt-1">
                                                    Click <strong>Sync from Unite API</strong> to retrieve live services directly from Unite EMR.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        filteredItems.map((item, idx) => (
                                            <tr key={item.item_code || idx} className="hover:bg-teal-50/30 dark:hover:bg-teal-950/20 transition-colors">
                                                <td className="py-3.5 px-4">
                                                    <span className="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-teal-50 dark:bg-teal-950/60 text-[#00a5b5] border border-teal-200/60 dark:border-teal-800/60">
                                                        #{item.item_code}
                                                    </span>
                                                </td>
                                                <td className="py-3.5 px-4">
                                                    <div className="font-bold text-slate-800 dark:text-slate-200">
                                                        {item.item_description}
                                                    </div>
                                                    {item.PackageItemDetails && item.PackageItemDetails.length > 0 && (
                                                        <div className="flex flex-wrap gap-1 mt-1">
                                                            {item.PackageItemDetails.map((pkg: any, pIdx: number) => (
                                                                <span key={pIdx} className="px-1.5 py-0.5 rounded text-[10px] bg-slate-100 dark:bg-slate-800 text-slate-500">
                                                                    CPT: {pkg.cpt_code} - {pkg.item_description}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="py-3.5 px-4 font-bold text-slate-900 dark:text-slate-100">
                                                    AED {Number(item.price).toFixed(2)}
                                                </td>
                                                <td className="py-3.5 px-4 text-slate-600 dark:text-slate-400">
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="w-3.5 h-3.5 text-slate-400" />
                                                        {item.average_time_in_minutes || 20} min
                                                    </span>
                                                </td>
                                                <td className="py-3.5 px-4">
                                                    <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                                        {item.clinic_id || 'All Clinics'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Tab 3: Company Users Management */}
                {activeTab === 'users' && (
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl overflow-hidden shadow-sm">
                        <div className="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                            <div>
                                <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">
                                    {currentUser.is_super_admin ? 'All Platform Users' : 'Company Users'}
                                </h3>
                                <p className="text-xs text-slate-400 mt-0.5">
                                    Manage team members, clinic administrators, and coordinators.
                                </p>
                            </div>

                            {currentUser.can_manage_users && (
                                <button
                                    onClick={() => setShowNewUserModal(true)}
                                    className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] shadow-sm transition-all"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    Add User
                                </button>
                            )}
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="bg-slate-50 dark:bg-slate-800/60 text-slate-400 font-semibold border-b border-slate-100 dark:border-slate-800">
                                    <tr>
                                        <th className="p-3.5">User Name</th>
                                        <th className="p-3.5">Email Address</th>
                                        <th className="p-3.5">Role</th>
                                        <th className="p-3.5">Assigned Company</th>
                                        <th className="p-3.5">Created Date</th>
                                        <th className="p-3.5 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {usersList.map((u) => (
                                        <tr key={u.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                            <td className="p-3.5 font-bold text-slate-800 dark:text-slate-200">
                                                {u.name}
                                                {u.id === currentUser.id && (
                                                    <span className="ml-2 text-[10px] text-[#00a5b5] font-normal">(You)</span>
                                                )}
                                            </td>
                                            <td className="p-3.5 font-mono text-slate-600 dark:text-slate-400">
                                                {u.email}
                                            </td>
                                            <td className="p-3.5">
                                                <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                                                    u.role === 'super_admin' ? 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300' :
                                                    u.role === 'tenant_admin' ? 'bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300' :
                                                    'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                                }`}>
                                                    {u.role === 'super_admin' ? 'Super Admin' : u.role === 'tenant_admin' ? 'Tenant Admin' : 'Staff User'}
                                                </span>
                                            </td>
                                            <td className="p-3.5 text-slate-600 dark:text-slate-300">
                                                {u.tenant?.name || (u.role === 'super_admin' ? 'All Tenants (Global)' : 'Unassigned')}
                                            </td>
                                            <td className="p-3.5 text-slate-400">
                                                {new Date(u.created_at).toLocaleDateString()}
                                            </td>
                                            <td className="p-3.5 text-right">
                                                {currentUser.can_manage_users && u.id !== currentUser.id && (
                                                    <button
                                                        onClick={() => handleDeleteUser(u.id)}
                                                        className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/50 transition-colors"
                                                        title="Delete user"
                                                    >
                                                        <Trash2 className="w-3.5 h-3.5" />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Tab 4: Integration Diagnostics & Logs */}
                {activeTab === 'logs' && (
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl overflow-hidden shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="bg-slate-50 dark:bg-slate-800/60 text-slate-400 font-semibold border-b border-slate-100 dark:border-slate-800">
                                    <tr>
                                        <th className="p-3.5">Timestamp</th>
                                        <th className="p-3.5">Direction</th>
                                        <th className="p-3.5">Entity</th>
                                        <th className="p-3.5">Status</th>
                                        <th className="p-3.5">Message / Details</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {recentLogs.map((log) => (
                                        <tr key={log.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                            <td className="p-3.5 text-slate-400 font-mono text-[11px] whitespace-nowrap">
                                                {new Date(log.created_at).toLocaleTimeString()}
                                            </td>
                                            <td className="p-3.5">
                                                <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                                    {log.direction}
                                                </span>
                                            </td>
                                            <td className="p-3.5 font-semibold capitalize text-slate-700 dark:text-slate-300">
                                                {log.entity_type}
                                            </td>
                                            <td className="p-3.5">
                                                <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                                                    log.status === 'success' 
                                                        ? 'bg-teal-50 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300'
                                                        : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                                                }`}>
                                                    {log.status.toUpperCase()}
                                                </span>
                                            </td>
                                            <td className="p-3.5 text-slate-600 dark:text-slate-300">
                                                {log.message}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </main>

            {/* Modal: Register New Company Tenant (Super Admin Only) */}
            {showNewTenantModal && currentUser.can_manage_tenants && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900 rounded-2xl max-w-xl w-full p-6 shadow-2xl animate-in fade-in zoom-in-95 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5">
                            <div className="flex items-center gap-2.5">
                                <Building2 className="w-5 h-5 text-[#00a5b5]" />
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Register Company Tenant (Super Admin)
                                </h3>
                            </div>
                            <button onClick={() => setShowNewTenantModal(false)} className="text-slate-400 hover:text-slate-600">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleCreateTenant} className="space-y-4 text-xs">
                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Company / Clinic Name <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Emirates Hospital & Clinic Group"
                                    value={newTenant.name}
                                    onChange={(e) => setNewTenant({ ...newTenant, name: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            {/* BITRIX24 LOCAL APP OAUTH SECTION */}
                            <div className="pt-2 border-t border-slate-100 dark:border-slate-800">
                                <div className="flex items-center justify-between mb-2.5">
                                    <h4 className="font-bold text-[#00a5b5] uppercase tracking-wider text-[11px]">
                                        Bitrix24 Local App OAuth Credentials
                                    </h4>
                                    <span className="text-[10px] text-slate-400">Applications &gt; Developer resources &gt; Local app</span>
                                </div>

                                <div className="space-y-3">
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Bitrix24 Portal Domain
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="company.bitrix24.com"
                                            value={newTenant.b24_domain}
                                            onChange={(e) => setNewTenant({ ...newTenant, b24_domain: e.target.value })}
                                            className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                        />
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <div className="flex items-center justify-between mb-1">
                                                <label className="font-semibold text-slate-700 dark:text-slate-300">
                                                    Bitrix24 App Client ID
                                                </label>
                                                <span className="text-[10px] text-slate-400 font-mono">C_REST_CLIENT_ID</span>
                                            </div>
                                            <input
                                                type="text"
                                                placeholder="e.g. local.65e219fa8211.902410"
                                                value={newTenant.b24_client_id}
                                                onChange={(e) => setNewTenant({ ...newTenant, b24_client_id: e.target.value })}
                                                className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                            />
                                        </div>

                                        <div>
                                            <div className="flex items-center justify-between mb-1">
                                                <label className="font-semibold text-slate-700 dark:text-slate-300">
                                                    Bitrix24 App Client Secret
                                                </label>
                                                <span className="text-[10px] text-slate-400 font-mono">C_REST_CLIENT_SECRET</span>
                                            </div>
                                            <div className="relative">
                                                <input
                                                    type={showNewB24Secret ? 'text' : 'password'}
                                                    placeholder="e.g. LjSl0lNB76B5YY6u0YVQ3AW0DrV..."
                                                    value={newTenant.b24_client_secret}
                                                    onChange={(e) => setNewTenant({ ...newTenant, b24_client_secret: e.target.value })}
                                                    className="w-full px-3.5 py-2 pr-9 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => setShowNewB24Secret(!showNewB24Secret)}
                                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                                >
                                                    {showNewB24Secret ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="pt-2 border-t border-slate-100 dark:border-slate-800">
                                <h4 className="font-bold text-[#00a5b5] uppercase tracking-wider text-[11px] mb-3">
                                    Unite EMR Gateway Credentials
                                </h4>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Environment
                                        </label>
                                        <select
                                            value={newTenant.unite_environment}
                                            onChange={(e) => setNewTenant({ ...newTenant, unite_environment: e.target.value })}
                                            className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                        >
                                            <option value="sandbox">Sandbox Test Gateway</option>
                                            <option value="production">Production Live Gateway</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Unite Base URL
                                        </label>
                                        <input
                                            type="text"
                                            value={newTenant.unite_base_url}
                                            onChange={(e) => setNewTenant({ ...newTenant, unite_base_url: e.target.value })}
                                            className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                        />
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Unite App ID
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="e.g. 00000000-0000-0000-0000-000000000000"
                                            value={newTenant.unite_app_id}
                                            onChange={(e) => setNewTenant({ ...newTenant, unite_app_id: e.target.value })}
                                            className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                        />
                                    </div>

                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Unite App Key
                                        </label>
                                        <div className="relative">
                                            <input
                                                type={showNewUniteKey ? 'text' : 'password'}
                                                placeholder="Enter clinic App Key"
                                                value={newTenant.unite_app_key}
                                                onChange={(e) => setNewTenant({ ...newTenant, unite_app_key: e.target.value })}
                                                className="w-full px-3.5 py-2 pr-9 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowNewUniteKey(!showNewUniteKey)}
                                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                            >
                                                {showNewUniteKey ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                                <button
                                    type="button"
                                    onClick={() => setShowNewTenantModal(false)}
                                    className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={creatingLoading}
                                    className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] flex items-center gap-1.5 shadow-md shadow-[#00a5b5]/20"
                                >
                                    {creatingLoading ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <CheckCircle2 className="w-3.5 h-3.5" />}
                                    Register Tenant
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Configure / Edit Company Tenant (Super Admin Only) */}
            {editingTenant && currentUser.can_manage_tenants && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900 rounded-2xl max-w-xl w-full p-6 shadow-2xl animate-in fade-in zoom-in-95 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5">
                            <div className="flex items-center gap-2.5">
                                <Settings className="w-5 h-5 text-[#00a5b5]" />
                                <div>
                                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                        Configure Tenant Credentials
                                    </h3>
                                    <p className="text-[11px] text-slate-400">{editingTenant.name}</p>
                                </div>
                            </div>
                            <button onClick={() => setEditingTenant(null)} className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleUpdateTenant} className="space-y-4 text-xs">
                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Company / Clinic Name <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={editTenantForm.name}
                                    onChange={(e) => setEditTenantForm({ ...editTenantForm, name: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            {/* BITRIX24 SECTION */}
                            <div className="pt-2 border-t border-slate-100 dark:border-slate-800">
                                <div className="flex items-center justify-between mb-2.5">
                                    <h4 className="font-bold text-[#00a5b5] uppercase tracking-wider text-[11px]">
                                        Bitrix24 Local App OAuth Credentials
                                    </h4>
                                    <span className="text-[10px] text-slate-400">Applications &gt; Developer resources &gt; Local app</span>
                                </div>

                                <div className="space-y-3">
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Bitrix24 Portal Domain
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="company.bitrix24.com"
                                            value={editTenantForm.b24_domain}
                                            onChange={(e) => setEditTenantForm({ ...editTenantForm, b24_domain: e.target.value })}
                                            className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                        />
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <div className="flex items-center justify-between mb-1">
                                                <label className="font-semibold text-slate-700 dark:text-slate-300">
                                                    Bitrix24 App Client ID
                                                </label>
                                                <span className="text-[10px] text-slate-400 font-mono">C_REST_CLIENT_ID</span>
                                            </div>
                                            <input
                                                type="text"
                                                placeholder="e.g. local.65e219fa8211.902410"
                                                value={editTenantForm.b24_client_id}
                                                onChange={(e) => setEditTenantForm({ ...editTenantForm, b24_client_id: e.target.value })}
                                                className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                            />
                                        </div>

                                        <div>
                                            <div className="flex items-center justify-between mb-1">
                                                <label className="font-semibold text-slate-700 dark:text-slate-300">
                                                    Bitrix24 App Client Secret
                                                </label>
                                                <span className="text-[10px] text-slate-400 font-mono">C_REST_CLIENT_SECRET</span>
                                            </div>
                                            <div className="relative">
                                                <input
                                                    type={showEditB24Secret ? 'text' : 'password'}
                                                    placeholder={editingTenant.has_b24_client_secret ? '•••••••••••• (Leave blank to keep existing)' : 'Enter client secret'}
                                                    value={editTenantForm.b24_client_secret}
                                                    onChange={(e) => setEditTenantForm({ ...editTenantForm, b24_client_secret: e.target.value })}
                                                    className="w-full px-3.5 py-2 pr-9 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => setShowEditB24Secret(!showEditB24Secret)}
                                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                                >
                                                    {showEditB24Secret ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* UNITE SECTION */}
                            <div className="pt-2 border-t border-slate-100 dark:border-slate-800">
                                <h4 className="font-bold text-[#00a5b5] uppercase tracking-wider text-[11px] mb-3">
                                    Unite EMR Gateway Credentials
                                </h4>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Environment
                                        </label>
                                        <select
                                            value={editTenantForm.unite_environment}
                                            onChange={(e) => setEditTenantForm({ ...editTenantForm, unite_environment: e.target.value })}
                                            className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                        >
                                            <option value="sandbox">Sandbox Test Gateway</option>
                                            <option value="production">Production Live Gateway</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Unite Base URL
                                        </label>
                                        <input
                                            type="text"
                                            value={editTenantForm.unite_base_url}
                                            onChange={(e) => setEditTenantForm({ ...editTenantForm, unite_base_url: e.target.value })}
                                            className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                        />
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Unite App ID
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="e.g. 00000000-0000-0000-0000-000000000000"
                                            value={editTenantForm.unite_app_id}
                                            onChange={(e) => setEditTenantForm({ ...editTenantForm, unite_app_id: e.target.value })}
                                            className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                        />
                                    </div>

                                    <div>
                                        <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Unite App Key
                                        </label>
                                        <div className="relative">
                                            <input
                                                type={showEditUniteKey ? 'text' : 'password'}
                                                placeholder="•••••••••••• (Leave blank to keep existing)"
                                                value={editTenantForm.unite_app_key}
                                                onChange={(e) => setEditTenantForm({ ...editTenantForm, unite_app_key: e.target.value })}
                                                className="w-full px-3.5 py-2 pr-9 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowEditUniteKey(!showEditUniteKey)}
                                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                            >
                                                {showEditUniteKey ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between pt-4 border-t border-slate-100 dark:border-slate-800">
                                <button
                                    type="button"
                                    onClick={() => handleDeleteTenant(editingTenant)}
                                    className="px-3.5 py-2 rounded-xl text-xs font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/60 border border-rose-200 dark:border-rose-900/40 flex items-center gap-1.5 transition-colors"
                                >
                                    <Trash2 className="w-3.5 h-3.5" />
                                    Delete Tenant
                                </button>
                                <div className="flex items-center gap-3">
                                    <button
                                        type="button"
                                        onClick={() => setEditingTenant(null)}
                                        className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={updatingTenantLoading}
                                        className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] flex items-center gap-1.5 shadow-md shadow-[#00a5b5]/20"
                                    >
                                        {updatingTenantLoading ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <CheckCircle2 className="w-3.5 h-3.5" />}
                                        Save Changes
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Add User (Tenant Admin or Super Admin) */}
            {showNewUserModal && currentUser.can_manage_users && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900 rounded-2xl max-w-md w-full p-6 shadow-2xl animate-in fade-in zoom-in-95">
                        <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5">
                            <div className="flex items-center gap-2.5">
                                <Users className="w-5 h-5 text-[#00a5b5]" />
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    {currentUser.is_super_admin ? 'Create Platform User' : 'Add Company User'}
                                </h3>
                            </div>
                            <button onClick={() => setShowNewUserModal(false)} className="text-slate-400 hover:text-slate-600">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleCreateUser} className="space-y-4 text-xs">
                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Full Name <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Dr. Jane Smith"
                                    value={newUser.name}
                                    onChange={(e) => setNewUser({ ...newUser, name: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Email Address <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="email"
                                    required
                                    placeholder="jane.smith@clinic.com"
                                    value={newUser.email}
                                    onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Password <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="password"
                                    required
                                    placeholder="Minimum 8 characters"
                                    value={newUser.password}
                                    onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    User Role <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    value={newUser.role}
                                    onChange={(e) => setNewUser({ ...newUser, role: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                >
                                    {currentUser.can_manage_tenants && (
                                        <option value="super_admin">Super Administrator (Global)</option>
                                    )}
                                    <option value="tenant_admin">Tenant Administrator (Company Admin)</option>
                                    <option value="tenant_user">Tenant Staff / Coordinator</option>
                                </select>
                            </div>

                            {currentUser.can_manage_tenants && newUser.role !== 'super_admin' && (
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Assigned Company <span className="text-rose-500">*</span>
                                    </label>
                                    <select
                                        value={newUser.tenant_id}
                                        onChange={(e) => setNewUser({ ...newUser, tenant_id: e.target.value })}
                                        className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                    >
                                        {tenants.map(t => (
                                            <option key={t.id} value={t.id}>{t.name}</option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                                <button
                                    type="button"
                                    onClick={() => setShowNewUserModal(false)}
                                    className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={userLoading}
                                    className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] flex items-center gap-1.5 shadow-md shadow-[#00a5b5]/20"
                                >
                                    {userLoading ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <CheckCircle2 className="w-3.5 h-3.5" />}
                                    Create User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
