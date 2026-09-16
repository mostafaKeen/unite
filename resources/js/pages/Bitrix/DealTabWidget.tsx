import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { 
    Calendar, Clock, User, Phone, Mail, FileText, CheckCircle2, 
    AlertCircle, Stethoscope, Building2, CreditCard, ChevronRight, 
    RefreshCw, Sparkles, Receipt, DollarSign, X, ExternalLink
} from 'lucide-react';

interface Clinic {
    clinic_id: string;
    name: string;
    city?: string;
    phone?: string;
}

interface Doctor {
    doctor_id: string;
    name: string;
    specialty?: string;
    clinics: string[];
}

interface ItemDetail {
    clinic_id: string;
    item_code: number;
    item_description: string;
    price: number;
    average_time_in_minutes: number;
    PackageItemDetails?: { cpt_code: string; item_description: string }[];
}

interface Appointment {
    id: string;
    unite_appointment_id: string;
    b24_deal_id: string;
    clinic_id: string;
    clinic_name?: string;
    doctor_id: string;
    doctor_name?: string;
    patient_firstname: string;
    patient_lastname: string;
    patient_gender: string;
    patient_mobileno: string;
    patient_email?: string;
    start_datetime: string;
    duration_minutes: number;
    status: string;
    status_description?: string;
    remarks?: string;
    invoice_reference?: string;
    invoice_total?: number;
}

interface PatientDefaults {
    firstname?: string;
    lastname?: string;
    mobileno?: string;
    emailid?: string;
    gender?: string;
    dob?: string;
    requestedby?: string;
}

interface Props {
    tenant: {
        id: string;
        name: string;
        unite_environment: string;
        b24_domain?: string;
    };
    dealId?: string | number;
    leadId?: string | number;
    contactId?: string | number;
    dealContext?: {
        id: string | number;
        title: string;
        contact_id?: string | number;
    };
    clinics: Clinic[];
    doctors: Doctor[];
    items: ItemDetail[];
    existingAppointment?: Appointment | null;
    statusMap: Record<string, { label: string; color: string; stage?: string }>;
    patientDefaults?: PatientDefaults;
}

export default function DealTabWidget({
    tenant,
    dealId,
    leadId,
    contactId,
    dealContext,
    clinics = [],
    doctors = [],
    items = [],
    existingAppointment: initialAppointment,
    statusMap = {},
    patientDefaults,
}: Props) {
    const [appointment, setAppointment] = useState<Appointment | null>(initialAppointment || null);
    const [showBookingForm, setShowBookingForm] = useState(!initialAppointment);

    // Booking state
    const [selectedClinicId, setSelectedClinicId] = useState<string>(clinics[0]?.clinic_id || '');
    const [selectedDoctorId, setSelectedDoctorId] = useState<string>('');
    const [selectedDate, setSelectedDate] = useState<string>(() => {
        const d = new Date();
        const dd = String(d.getDate()).padStart(2, '0');
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const yyyy = d.getFullYear();
        return `${dd}-${mm}-${yyyy}`;
    });
    const [availableSlots, setAvailableSlots] = useState<Record<string, string[]>>({});
    const [selectedSlotDate, setSelectedSlotDate] = useState<string>('');
    const [selectedSlotTime, setSelectedSlotTime] = useState<string>('');
    const [loadingSlots, setLoadingSlots] = useState<boolean>(false);
    const [bookingLoading, setBookingLoading] = useState<boolean>(false);
    const [statusLoading, setStatusLoading] = useState<boolean>(false);
    const [successMessage, setSuccessMessage] = useState<string>('');
    const [errorMessage, setErrorMessage] = useState<string>('');

    // Patient info pre-filled from Bitrix24 default fields
    const [patientData, setPatientData] = useState({
        firstname: patientDefaults?.firstname || '',
        middlename: '',
        lastname: patientDefaults?.lastname || '',
        gender: patientDefaults?.gender || 'M',
        mobileno: patientDefaults?.mobileno || '',
        emailid: patientDefaults?.emailid || '',
        dob: patientDefaults?.dob || '',
        phototype: 'EMIRATES_ID',
        photoid: '',
        remarks: '',
        requestedby: patientDefaults?.requestedby || 'Bitrix24 CRM Agent',
    });

    useEffect(() => {
        if (patientDefaults) {
            setPatientData(prev => ({
                ...prev,
                firstname: patientDefaults.firstname || prev.firstname,
                lastname: patientDefaults.lastname || prev.lastname,
                mobileno: patientDefaults.mobileno || prev.mobileno,
                emailid: patientDefaults.emailid || prev.emailid,
                gender: patientDefaults.gender || prev.gender,
                dob: patientDefaults.dob || prev.dob,
                requestedby: patientDefaults.requestedby || prev.requestedby,
            }));
        }
    }, [patientDefaults]);

    // Procedures / Items selected
    const [selectedItemCodes, setSelectedItemCodes] = useState<number[]>([]);

    // Invoice modal state
    const [showInvoiceModal, setShowInvoiceModal] = useState<boolean>(false);
    const [invoicePaymentMode, setInvoicePaymentMode] = useState<string>('CARD');
    const [invoiceRefNum, setInvoiceRefNum] = useState<string>('ENBD-' + Math.floor(100000 + Math.random() * 900000));
    const [invoiceLoading, setInvoiceLoading] = useState<boolean>(false);

    // Filter doctors by selected clinic
    const availableDoctors = doctors.filter(d => 
        !selectedClinicId || (d.clinics && d.clinics.includes(selectedClinicId))
    );

    // Console Debug Logging for Getting Data and Current User
    useEffect(() => {
        console.log('📌 [Bitrix Widget Data Loaded]', {
            dealId,
            leadId,
            contactId,
            patientDefaults,
            activePatientData: patientData,
        });
        console.log('👤 [Bitrix Logged-in User]', {
            requestedby: patientData.requestedby,
        });
    }, [patientDefaults, patientData.requestedby]);

    // Initialize Bitrix24 JS SDK & Client-Side Fallback Fetching
    useEffect(() => {
        const initBX24 = () => {
            if (!window.BX24) return;
            window.BX24.init(() => {
                try {
                    window.BX24?.fitWindow();
                } catch (e) {}

                // Fetch current user via BX24 JS SDK
                window.BX24.callMethod('user.current', {}, (res: any) => {
                    if (res && typeof res.data === 'function') {
                        const userData = res.data();
                        if (userData) {
                            const userFullName = [userData.NAME, userData.LAST_NAME].filter(Boolean).join(' ');
                            console.log('👤 [BX24 SDK] Current Logged-in User:', userFullName, userData);
                            if (userFullName) {
                                setPatientData(prev => ({
                                    ...prev,
                                    requestedby: prev.requestedby && prev.requestedby !== 'Bitrix24 CRM Agent' ? prev.requestedby : userFullName,
                                }));
                            }
                        }
                    }
                });

                // Fetch entity data via BX24 JS SDK if patient fields are empty
                try {
                    const placementInfo = window.BX24.placement.info();
                    console.log('📌 [BX24 SDK] Placement Info:', placementInfo);
                    const entityId = placementInfo?.options?.ID || placementInfo?.options?.id || dealId || leadId || contactId;

                    if (entityId) {
                        let method = 'crm.deal.get';
                        if (String(placementInfo?.placement || '').includes('LEAD') || leadId) method = 'crm.lead.get';
                        else if (String(placementInfo?.placement || '').includes('CONTACT') || contactId) method = 'crm.contact.get';

                        window.BX24.callMethod(method, { id: entityId }, (res: any) => {
                            if (res && typeof res.data === 'function') {
                                const data = res.data();
                                console.log(`📋 [BX24 SDK] Fetched ${method} Entity Data:`, data);
                                if (data) {
                                    const extractPhone = (arr: any) => Array.isArray(arr) && arr.length ? arr[0].VALUE : (typeof arr === 'string' ? arr : '');
                                    const extractEmail = (arr: any) => Array.isArray(arr) && arr.length ? arr[0].VALUE : (typeof arr === 'string' ? arr : '');

                                    setPatientData(prev => ({
                                        ...prev,
                                        firstname: prev.firstname || data.NAME || '',
                                        lastname: prev.lastname || data.LAST_NAME || '',
                                        mobileno: prev.mobileno || extractPhone(data.PHONE) || '',
                                        emailid: prev.emailid || extractEmail(data.EMAIL) || '',
                                        gender: (data.GENDER_ID && ['M','F','U'].includes(data.GENDER_ID)) ? data.GENDER_ID : prev.gender,
                                        dob: data.BIRTHDATE ? String(data.BIRTHDATE) : prev.dob,
                                    }));
                                }
                            }
                        });
                    }
                } catch (e) {
                    console.log('[BX24 SDK] Placement info check exception:', e);
                }
            });
        };

        const scriptId = 'bitrix-js-sdk';
        if (!document.getElementById(scriptId)) {
            const script = document.createElement('script');
            script.id = scriptId;
            script.src = '//api.bitrix24.com/api/v1/';
            script.async = true;
            script.onload = initBX24;
            document.head.appendChild(script);
        } else {
            initBX24();
        }
    }, []);

    // Default doctor if current doctor not available for selected clinic
    useEffect(() => {
        if (availableDoctors.length > 0 && (!selectedDoctorId || !availableDoctors.some(d => d.doctor_id === selectedDoctorId))) {
            const defaultDocId = availableDoctors[0].doctor_id;
            console.log(`[Unite EMR Widget] Auto-selecting default doctor '${availableDoctors[0].name}' (ID: ${defaultDocId})`);
            setSelectedDoctorId(defaultDocId);
        }
    }, [selectedClinicId, availableDoctors]);

    // Fetch slots when clinic, doctor, or date changes
    useEffect(() => {
        if (selectedClinicId && selectedDoctorId && selectedDate) {
            fetchSlots(selectedClinicId, selectedDoctorId, selectedDate);
        }
    }, [selectedClinicId, selectedDoctorId, selectedDate]);

    const fetchSlots = async (clinicId: string, doctorId: string, date: string) => {
        setLoadingSlots(true);
        const slotUrl = `/b24/widget/deal-tab/${tenant.id}/slots?clinic_id=${encodeURIComponent(clinicId)}&doctor_id=${encodeURIComponent(doctorId)}&date=${encodeURIComponent(date)}`;
        
        console.group('📅 [Unite EMR Widget] Fetching Available Time Slots');
        console.log('Clinic ID:', clinicId);
        console.log('Doctor ID:', doctorId);
        console.log('Requested Date:', date);
        console.log('Endpoint URL:', slotUrl);
        
        try {
            const res = await fetch(slotUrl, {
                headers: {
                    'Accept': 'application/json',
                },
            });

            if (!res.ok) {
                console.warn(`[Slots] HTTP ${res.status} received when fetching slots`);
            }

            const contentType = res.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const json = await res.json();
                console.log('Slots Received Response:', json);
                
                if (json.success && json.data) {
                    setAvailableSlots(json.data);
                    // Set first slot if available
                    const dates = Object.keys(json.data);
                    if (dates.length > 0) {
                        const firstDate = dates[0];
                        if (json.data[firstDate]?.length > 0) {
                            setSelectedSlotDate(firstDate);
                            setSelectedSlotTime(json.data[firstDate][0]);
                            console.log(`Selected slot: ${firstDate} at ${json.data[firstDate][0]}`);
                        } else {
                            setSelectedSlotDate('');
                            setSelectedSlotTime('');
                        }
                    } else {
                        setSelectedSlotDate('');
                        setSelectedSlotTime('');
                    }
                }
            }
        } catch (e) {
            console.error('Failed to fetch slots:', e);
        } finally {
            console.groupEnd();
            setLoadingSlots(false);
        }
    };

    const handleBookAppointment = async (e: React.FormEvent) => {
        e.preventDefault();
        setBookingLoading(true);
        setErrorMessage('');
        setSuccessMessage('');

        const selectedClinic = clinics.find(c => c.clinic_id === selectedClinicId);
        const selectedDoctor = doctors.find(d => d.doctor_id === selectedDoctorId);

        const appointmentDate = selectedSlotDate ? formatToDdMmYyyy(selectedSlotDate) : selectedDate;
        const appointmentTime = selectedSlotTime ? convertTo24Hour(selectedSlotTime) : '10:00';

        const payload = {
            b24_deal_id: dealId ? String(dealId) : null,
            b24_lead_id: leadId ? String(leadId) : null,
            b24_contact_id: contactId ? String(contactId) : (dealContext?.contact_id ? String(dealContext.contact_id) : null),
            clinicid: selectedClinicId,
            clinicname: selectedClinic?.name || selectedClinicId,
            doctorid: selectedDoctorId,
            doctorname: selectedDoctor?.name || selectedDoctorId,
            startdatetime: `${appointmentDate} ${appointmentTime}`,
            duration: '30',
            itemcode: selectedItemCodes,
            ...patientData,
        };

        console.group('🚀 [Unite EMR Widget] Submitting Appointment Booking Request');
        console.log('Selected Clinic:', selectedClinic);
        console.log('Selected Doctor Object:', selectedDoctor);
        console.log('Doctor ID Passed:', selectedDoctorId);
        console.log('Doctor Name Passed:', selectedDoctor?.name || selectedDoctorId);
        console.log('Full Request Payload:', payload);

        try {
            const res = await fetch(`/b24/widget/deal-tab/${tenant.id}/book`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            });

            const json = await res.json();
            console.log('Server Booking Response:', json);

            if (json.success && json.appointment) {
                console.log('✅ Booking Successfully Recorded:', json.appointment);
                setAppointment(json.appointment);
                setShowBookingForm(false);
                setSuccessMessage(json.message || 'Appointment booked and scheduled successfully!');
            } else {
                console.error('❌ Booking Rejected / Error:', json.message, json);
                setErrorMessage(json.message || 'Failed to schedule appointment.');
            }
        } catch (err: any) {
            console.error('💥 Booking Request Exception:', err);
            setErrorMessage('Network error during booking: ' + err.message);
        } finally {
            console.groupEnd();
            setBookingLoading(false);
        }
    };

    const handleUpdateStatus = async (newStatus: string) => {
        if (!appointment) return;
        setStatusLoading(true);
        try {
            const res = await fetch(`/b24/widget/deal-tab/${tenant.id}/status/${appointment.id}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ status: newStatus }),
            });
            const json = await res.json();
            if (json.success && json.appointment) {
                setAppointment(json.appointment);
                setSuccessMessage(`Status updated to ${statusMap[newStatus]?.label || newStatus}`);
            }
        } catch (err: any) {
            setErrorMessage('Failed to update status: ' + err.message);
        } finally {
            setStatusLoading(false);
        }
    };

    const handleGenerateInvoice = async () => {
        if (!appointment) return;
        setInvoiceLoading(true);

        const selectedServices = items.filter(i => selectedItemCodes.includes(i.item_code));
        const invoiceItems = selectedServices.length > 0 
            ? selectedServices.map(i => {
                const vat = (i.price * 0.05);
                return {
                    item_code: i.item_code,
                    item_price: i.price,
                    line_qty: 1,
                    line_gross_amt: i.price,
                    line_disc_amt: 0,
                    line_net_amt: i.price,
                    vat_per: 5,
                    vat_amt: vat,
                };
            })
            : [{
                item_code: 101,
                item_price: 250,
                line_qty: 1,
                line_gross_amt: 250,
                line_disc_amt: 0,
                line_net_amt: 250,
                vat_per: 5,
                vat_amt: 12.5,
            }];

        const totalGross = invoiceItems.reduce((acc, curr) => acc + curr.line_gross_amt, 0);
        const totalVat = invoiceItems.reduce((acc, curr) => acc + curr.vat_amt, 0);
        const totalNet = totalGross + totalVat;

        const invoiceDate = selectedSlotDate ? formatToDdMmYyyy(selectedSlotDate) : selectedDate;
        const payload = {
            invoiceDetails: invoiceItems,
            invoicePayments: [
                {
                    payment_mode: invoicePaymentMode,
                    paid_amt: totalNet,
                    paid_date: `${invoiceDate} 11:30`,
                    payment_reference_number: invoiceRefNum,
                    bank_name: 'ENBD Dubai',
                    transaction_card_type: 'VISA',
                }
            ]
        };

        try {
            const res = await fetch(`/b24/widget/deal-tab/${tenant.id}/invoice/${appointment.id}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (json.success && json.appointment) {
                setAppointment(json.appointment);
                setShowInvoiceModal(false);
                setSuccessMessage(`Tax Invoice ${json.invoice_reference} generated and linked to Bitrix Deal!`);
            }
        } catch (err: any) {
            setErrorMessage('Invoice generation failed: ' + err.message);
        } finally {
            setInvoiceLoading(false);
        }
    };

    const formatDisplayDate = (dateStr: string) => {
        try {
            const parts = dateStr.split('-');
            if (parts.length === 3 && parts[0].length === 4) {
                // YYYY, MM, DD
                const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
            }
            return new Date(dateStr).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        } catch {
            return dateStr;
        }
    };

    const formatToDdMmYyyy = (dateStr: string) => {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            if (parts[0].length === 4) {
                // YYYY-MM-DD -> DD-MM-YYYY
                return `${parts[2].padStart(2, '0')}-${parts[1].padStart(2, '0')}-${parts[0]}`;
            }
            if (parts[2].length === 4) {
                // Already DD-MM-YYYY
                return dateStr;
            }
        }
        const d = new Date(dateStr);
        if (!isNaN(d.getTime())) {
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const yyyy = d.getFullYear();
            return `${dd}-${mm}-${yyyy}`;
        }
        return dateStr;
    };

    const convertTo24Hour = (timeStr: string) => {
        if (!timeStr) return '10:00';
        const parts = timeStr.trim().split(/\s+/);
        if (parts.length === 1) {
            return parts[0];
        }
        const [time, modifier] = parts;
        let [hours, minutes] = time.split(':');
        let h = parseInt(hours, 10);
        if (modifier && modifier.toUpperCase() === 'PM' && h < 12) h += 12;
        if (modifier && modifier.toUpperCase() === 'AM' && h === 12) h = 0;
        return `${String(h).padStart(2, '0')}:${minutes || '00'}`;
    };

    const calculateSubtotal = () => {
        return items
            .filter(i => selectedItemCodes.includes(i.item_code))
            .reduce((sum, item) => sum + Number(item.price), 0);
    };

    const subtotal = calculateSubtotal();
    const vatAmount = subtotal * 0.05;
    const grandTotal = subtotal + vatAmount;

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 font-sans p-4 sm:p-6">
            <Head title="Unite EMR Booking Widget | Bitrix24" />

            {/* Top Brand Header Bar */}
            <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-4 sm:p-5 shadow-sm mb-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex items-center gap-3.5">
                        {/* Signature Unite Logo */}
                        <div className="bg-[#00a5b5] text-white font-bold px-3 py-1.5 rounded-xl flex items-center shadow-md shadow-[#00a5b5]/25">
                            <span className="text-xl tracking-tight font-extrabold">Unite</span>
                            <span className="text-xl text-white font-black leading-none ml-0.5 -mt-2">+</span>
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] tracking-[0.2em] font-bold text-[#00a5b5] uppercase">
                                    OPTIMIZING HEALTHCARE PROCESS
                                </span>
                                <span className="px-1.5 py-0.5 text-[10px] font-semibold bg-cyan-50 dark:bg-cyan-950/60 text-[#00a5b5] rounded border border-cyan-200 dark:border-cyan-800">
                                    {tenant.unite_environment.toUpperCase()}
                                </span>
                            </div>
                            <h1 className="text-base font-bold text-slate-800 dark:text-slate-100">
                                Clinical Appointment & EMR Gateway
                            </h1>
                        </div>
                    </div>

                    {/* Bitrix Deal Context & Tenant Info */}
                    <div className="flex items-center gap-2.5">
                        {dealId && (
                            <div className="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs">
                                <span className="text-slate-400 font-medium mr-1">Bitrix Deal:</span>
                                <span className="font-bold text-[#00a5b5]">#{dealId}</span>
                            </div>
                        )}
                        <div className="px-3 py-1.5 rounded-lg bg-teal-50 dark:bg-teal-950/60 border border-teal-200 dark:border-teal-800 text-xs text-teal-800 dark:text-teal-300 font-semibold flex items-center gap-1.5">
                            <Building2 className="w-3.5 h-3.5 text-[#00a5b5]" />
                            {tenant.name}
                        </div>
                    </div>
                </div>

                {/* Notifications */}
                {successMessage && (
                    <div className="mt-4 p-3 rounded-xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800 flex items-center justify-between text-xs text-teal-800 dark:text-teal-200">
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="w-4 h-4 text-[#00a5b5]" />
                            <span>{successMessage}</span>
                        </div>
                        <button onClick={() => setSuccessMessage('')} className="text-teal-600 hover:text-teal-800">
                            <X className="w-3.5 h-3.5" />
                        </button>
                    </div>
                )}

                {errorMessage && (
                    <div className="mt-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 flex items-center justify-between text-xs text-rose-800 dark:text-rose-200">
                        <div className="flex items-center gap-2">
                            <AlertCircle className="w-4 h-4 text-rose-500" />
                            <span>{errorMessage}</span>
                        </div>
                        <button onClick={() => setErrorMessage('')} className="text-rose-600 hover:text-rose-800">
                            <X className="w-3.5 h-3.5" />
                        </button>
                    </div>
                )}
            </div>

            {/* If Appointment Exists: Display Active Appointment Card */}
            {appointment && !showBookingForm && (
                <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-6 shadow-sm mb-6">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-5 border-b border-slate-100 dark:border-slate-800">
                        <div className="flex items-start gap-3">
                            <div className="p-2.5 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-[#00a5b5]">
                                <Stethoscope className="w-6 h-6" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2.5">
                                    <span className="text-xs font-semibold text-slate-400">EMR Appointment ID:</span>
                                    <span className="text-base font-bold text-slate-900 dark:text-slate-100 font-mono">
                                        #{appointment.unite_appointment_id}
                                    </span>
                                    <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold ${
                                        appointment.status === 'ACF' ? 'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-300' :
                                        appointment.status === 'APH' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' :
                                        appointment.status === 'CVI' ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300' :
                                        appointment.status === 'NSW' ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300' :
                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300'
                                    }`}>
                                        {statusMap[appointment.status]?.label || appointment.status}
                                    </span>
                                </div>
                                <p className="text-sm font-semibold text-slate-700 dark:text-slate-200 mt-1">
                                    {appointment.patient_firstname} {appointment.patient_lastname} • {appointment.patient_mobileno}
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => setShowInvoiceModal(true)}
                                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold text-[#00a5b5] bg-teal-50 hover:bg-teal-100 dark:bg-teal-950/60 dark:hover:bg-teal-900/50 transition-colors"
                            >
                                <Receipt className="w-4 h-4" />
                                {appointment.invoice_reference ? `Invoice: ${appointment.invoice_reference}` : 'Create Tax Invoice'}
                            </button>
                            <button
                                onClick={() => setShowBookingForm(true)}
                                className="px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 transition-colors"
                            >
                                Re-Schedule / New
                            </button>
                        </div>
                    </div>

                    {/* Details Grid */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 py-5 border-b border-slate-100 dark:border-slate-800 text-xs">
                        <div>
                            <span className="text-slate-400 block mb-1">Clinic Facility</span>
                            <span className="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-1">
                                <Building2 className="w-3.5 h-3.5 text-[#00a5b5]" />
                                {appointment.clinic_name || appointment.clinic_id}
                            </span>
                        </div>
                        <div>
                            <span className="text-slate-400 block mb-1">Attending Doctor</span>
                            <span className="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-1">
                                <User className="w-3.5 h-3.5 text-[#00a5b5]" />
                                {appointment.doctor_name || appointment.doctor_id}
                            </span>
                        </div>
                        <div>
                            <span className="text-slate-400 block mb-1">Scheduled Slot</span>
                            <span className="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-1">
                                <Clock className="w-3.5 h-3.5 text-[#00a5b5]" />
                                {new Date(appointment.start_datetime).toLocaleString()} ({appointment.duration_minutes} min)
                            </span>
                        </div>
                        <div>
                            <span className="text-slate-400 block mb-1">Bi-Directional Sync</span>
                            <span className="font-semibold text-teal-600 dark:text-teal-400 flex items-center gap-1">
                                <RefreshCw className="w-3.5 h-3.5 text-[#00a5b5]" />
                                Synced with Bitrix24 Deal
                            </span>
                        </div>
                    </div>

                    {/* Status update controls */}
                    <div className="pt-5 flex flex-wrap items-center justify-between gap-3">
                        <span className="text-xs font-semibold text-slate-500">Update Status in Unite & Bitrix:</span>
                        <div className="flex flex-wrap gap-2">
                            <button
                                disabled={statusLoading || appointment.status === 'ACF'}
                                onClick={() => handleUpdateStatus('ACF')}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-teal-600 hover:bg-teal-700 text-white disabled:opacity-40 transition-colors"
                            >
                                Confirm (ACF)
                            </button>
                            <button
                                disabled={statusLoading || appointment.status === 'APH'}
                                onClick={() => handleUpdateStatus('APH')}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white disabled:opacity-40 transition-colors"
                            >
                                Honoured / Attended (APH)
                            </button>
                            <button
                                disabled={statusLoading || appointment.status === 'CNR'}
                                onClick={() => handleUpdateStatus('CNR')}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-600 hover:bg-amber-700 text-white disabled:opacity-40 transition-colors"
                            >
                                Call Unreachable (CNR)
                            </button>
                            <button
                                disabled={statusLoading || appointment.status === 'NSW'}
                                onClick={() => handleUpdateStatus('NSW')}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-600 hover:bg-red-700 text-white disabled:opacity-40 transition-colors"
                            >
                                No Show (NSW)
                            </button>
                            <button
                                disabled={statusLoading || appointment.status === 'CVI'}
                                onClick={() => handleUpdateStatus('CVI')}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white disabled:opacity-40 transition-colors"
                            >
                                Cancel (CVI)
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Booking Form (Visible if no appointment or rescheduling) */}
            {showBookingForm && (
                <form onSubmit={handleBookAppointment} className="space-y-6">
                    {/* Section 1: Facility & Doctor Selection */}
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-5 sm:p-6 shadow-sm">
                        <div className="flex items-center gap-2 mb-4">
                            <div className="w-1.5 h-5 bg-[#ea580c] rounded-full"></div>
                            <h2 className="text-sm font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
                                1. Select Clinic & Attending Doctor
                            </h2>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {/* Multiple clinics support */}
                            <div>
                                <label className="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                                    Clinic Facility <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    value={selectedClinicId}
                                    onChange={(e) => setSelectedClinicId(e.target.value)}
                                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                >
                                    {clinics.map(c => (
                                        <option key={c.clinic_id} value={c.clinic_id}>
                                            {c.name} ({c.clinic_id})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Doctors */}
                            <div>
                                <label className="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                                    Doctor / Physician <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    value={selectedDoctorId}
                                    onChange={(e) => setSelectedDoctorId(e.target.value)}
                                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                >
                                    {availableDoctors.map(d => (
                                        <option key={d.doctor_id} value={d.doctor_id}>
                                            {d.name} {d.specialty ? `— ${d.specialty}` : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Section 2: 7-Day Available Slots Picker */}
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-5 sm:p-6 shadow-sm">
                        <div className="flex items-center justify-between gap-2 mb-4">
                            <div className="flex items-center gap-2">
                                <div className="w-1.5 h-5 bg-[#00a5b5] rounded-full"></div>
                                <h2 className="text-sm font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
                                    2. Available 7-Day Appointment Slots
                                </h2>
                            </div>
                            <div className="flex items-center gap-2 text-xs">
                                <label className="text-slate-500 font-medium">Start Date:</label>
                                <input
                                    type="date"
                                    defaultValue={new Date().toISOString().split('T')[0]}
                                    onChange={(e) => {
                                        if (e.target.value) {
                                            const [yyyy, mm, dd] = e.target.value.split('-');
                                            setSelectedDate(`${dd}-${mm}-${yyyy}`);
                                        }
                                    }}
                                    className="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                />
                            </div>
                        </div>

                        {loadingSlots ? (
                            <div className="flex items-center justify-center py-8 text-slate-400 gap-2 text-xs">
                                <RefreshCw className="w-4 h-4 animate-spin text-[#00a5b5]" />
                                Loading real-time doctor availability from Unite EMR...
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {Object.keys(availableSlots).length === 0 ? (
                                    <p className="text-xs text-slate-400 py-4 text-center">No slots found for this date range.</p>
                                ) : (
                                    Object.entries(availableSlots).map(([dateKey, slots]) => (
                                        <div key={dateKey} className="border border-slate-100 dark:border-slate-800 rounded-xl p-3.5">
                                            <div className="flex items-center justify-between mb-2.5">
                                                <span className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1.5">
                                                    <Calendar className="w-3.5 h-3.5 text-[#00a5b5]" />
                                                    {formatDisplayDate(dateKey)}
                                                </span>
                                                <span className="text-[10px] text-slate-400 font-medium">{slots.length} slots available</span>
                                            </div>

                                            <div className="flex flex-wrap gap-2">
                                                {slots.map((slot) => {
                                                    const isSelected = selectedSlotDate === dateKey && selectedSlotTime === slot;
                                                    return (
                                                        <button
                                                            key={`${dateKey}-${slot}`}
                                                            type="button"
                                                            onClick={() => {
                                                                setSelectedSlotDate(dateKey);
                                                                setSelectedSlotTime(slot);
                                                            }}
                                                            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                                                                isSelected
                                                                    ? 'bg-[#00a5b5] text-white shadow-sm shadow-[#00a5b5]/30 ring-2 ring-[#00a5b5]/50'
                                                                    : 'bg-slate-100 hover:bg-teal-50 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300'
                                                            }`}
                                                        >
                                                            {slot}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    ))
                                )}

                                {selectedSlotDate && selectedSlotTime && (
                                    <div className="mt-4 p-3.5 rounded-xl bg-teal-50 dark:bg-teal-950/50 border border-teal-200 dark:border-teal-800/60 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                        <div className="flex items-center gap-2.5 text-xs text-teal-900 dark:text-teal-200 font-medium">
                                            <CheckCircle2 className="w-4 h-4 text-[#00a5b5] shrink-0" />
                                            <span>
                                                Selected Appointment: <strong className="font-semibold">{formatDisplayDate(selectedSlotDate)}</strong> at <strong className="text-[#00a5b5] font-bold font-mono">{selectedSlotTime}</strong>
                                            </span>
                                        </div>
                                        <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-[#00a5b5] text-white shadow-sm w-fit">
                                            Ready to Book
                                        </span>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Section 3: Patient Information */}
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-5 sm:p-6 shadow-sm">
                        <div className="flex items-center gap-2 mb-4">
                            <div className="w-1.5 h-5 bg-[#ea580c] rounded-full"></div>
                            <h2 className="text-sm font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
                                3. Patient Information
                            </h2>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    First Name <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={patientData.firstname}
                                    onChange={(e) => setPatientData({ ...patientData, firstname: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Last Name <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={patientData.lastname}
                                    onChange={(e) => setPatientData({ ...patientData, lastname: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Mobile Phone (Direct Input) <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={patientData.mobileno}
                                    placeholder="e.g. 971-501234567"
                                    onChange={(e) => setPatientData({ ...patientData, mobileno: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Email Address
                                </label>
                                <input
                                    type="email"
                                    value={patientData.emailid}
                                    onChange={(e) => setPatientData({ ...patientData, emailid: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Gender <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    value={patientData.gender}
                                    onChange={(e) => setPatientData({ ...patientData, gender: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                >
                                    <option value="M">Male (M)</option>
                                    <option value="F">Female (F)</option>
                                    <option value="U">Unknown (U)</option>
                                </select>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Date of Birth (dd-MM-yyyy)
                                </label>
                                <input
                                    type="text"
                                    value={patientData.dob}
                                    placeholder="14-03-1990"
                                    onChange={(e) => setPatientData({ ...patientData, dob: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Photo ID Type
                                </label>
                                <select
                                    value={patientData.phototype}
                                    onChange={(e) => setPatientData({ ...patientData, phototype: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                >
                                    <option value="EMIRATES_ID">Emirates ID</option>
                                    <option value="Passport">Passport</option>
                                    <option value="Driving License">Driving License</option>
                                    <option value="GCC_ID">GCC ID</option>
                                </select>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Photo ID Number
                                </label>
                                <input
                                    type="text"
                                    value={patientData.photoid}
                                    placeholder="784199060660000"
                                    onChange={(e) => setPatientData({ ...patientData, photoid: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                    Requested By
                                </label>
                                <input
                                    type="text"
                                    value={patientData.requestedby}
                                    onChange={(e) => setPatientData({ ...patientData, requestedby: e.target.value })}
                                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                                />
                            </div>
                        </div>

                        <div className="mt-4">
                            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">
                                Clinical / CRM Notes
                            </label>
                            <textarea
                                rows={2}
                                value={patientData.remarks}
                                onChange={(e) => setPatientData({ ...patientData, remarks: e.target.value })}
                                className="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs focus:ring-2 focus:ring-[#00a5b5] focus:outline-none"
                            />
                        </div>
                    </div>

                    {/* Section 4: Procedures & UAE VAT Pricing */}
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900/40 rounded-2xl p-5 sm:p-6 shadow-sm">
                        <div className="flex items-center gap-2 mb-4">
                            <div className="w-1.5 h-5 bg-[#00a5b5] rounded-full"></div>
                            <h2 className="text-sm font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
                                4. Select Medical Procedures & Services
                            </h2>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mb-5">
                            {items.map((item) => {
                                const isChecked = selectedItemCodes.includes(item.item_code);
                                return (
                                    <div
                                        key={item.item_code}
                                        onClick={() => {
                                            if (isChecked) {
                                                setSelectedItemCodes(selectedItemCodes.filter(c => c !== item.item_code));
                                            } else {
                                                setSelectedItemCodes([...selectedItemCodes, item.item_code]);
                                            }
                                        }}
                                        className={`cursor-pointer p-3.5 rounded-xl border transition-all flex items-start justify-between ${
                                            isChecked
                                                ? 'border-[#00a5b5] bg-teal-50/50 dark:bg-teal-950/30'
                                                : 'border-slate-200 dark:border-slate-700 hover:border-slate-300'
                                        }`}
                                    >
                                        <div className="flex items-start gap-2.5">
                                            <input
                                                type="checkbox"
                                                checked={isChecked}
                                                readOnly
                                                className="mt-1 rounded text-[#00a5b5] focus:ring-[#00a5b5]"
                                            />
                                            <div>
                                                <span className="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                                                    {item.item_description}
                                                </span>
                                                <span className="text-[10px] text-slate-400">
                                                    Code: #{item.item_code} • {item.average_time_in_minutes || 20} min
                                                </span>
                                            </div>
                                        </div>
                                        <span className="text-xs font-bold text-[#00a5b5]">
                                            AED {Number(item.price).toFixed(2)}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Calculation summary */}
                        <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row justify-between items-center gap-4 text-xs">
                            <div>
                                <span className="text-slate-500">Selected Services: </span>
                                <span className="font-bold text-slate-800 dark:text-slate-200">{selectedItemCodes.length} items</span>
                            </div>
                            <div className="flex items-center gap-6">
                                <div>
                                    <span className="text-slate-400 block">Subtotal:</span>
                                    <span className="font-bold">AED {subtotal.toFixed(2)}</span>
                                </div>
                                <div>
                                    <span className="text-slate-400 block">UAE VAT (5%):</span>
                                    <span className="font-bold">AED {vatAmount.toFixed(2)}</span>
                                </div>
                                <div>
                                    <span className="text-slate-400 block">Grand Total:</span>
                                    <span className="text-base font-extrabold text-[#00a5b5]">AED {grandTotal.toFixed(2)}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Booking Action Bar */}
                    <div className="flex items-center justify-end gap-3 pt-2">
                        {appointment && (
                            <button
                                type="button"
                                onClick={() => setShowBookingForm(false)}
                                className="px-5 py-2.5 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            >
                                Cancel
                            </button>
                        )}

                        <button
                            type="submit"
                            disabled={bookingLoading || !selectedSlotDate || !selectedSlotTime}
                            className="px-6 py-3 rounded-xl text-sm font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] disabled:opacity-50 shadow-lg shadow-[#00a5b5]/30 flex items-center gap-2 transition-all"
                        >
                            {bookingLoading ? (
                                <>
                                    <RefreshCw className="w-4 h-4 animate-spin" />
                                    Communicating with Unite EMR...
                                </>
                            ) : (
                                <>
                                    <CheckCircle2 className="w-4 h-4" />
                                    Book & Synchronize Appointment
                                </>
                            )}
                        </button>
                    </div>
                </form>
            )}

            {/* Tax Invoice Generation Modal */}
            {showInvoiceModal && appointment && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white dark:bg-slate-900 border border-teal-100 dark:border-teal-900 rounded-2xl max-w-lg w-full p-6 shadow-2xl animate-in fade-in zoom-in-95">
                        <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-4">
                            <div className="flex items-center gap-2.5">
                                <Receipt className="w-5 h-5 text-[#00a5b5]" />
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Generate Unite EMR Tax Invoice
                                </h3>
                            </div>
                            <button onClick={() => setShowInvoiceModal(false)} className="text-slate-400 hover:text-slate-600">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="space-y-4 text-xs">
                            <div className="p-3 bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800 rounded-xl">
                                <span className="text-teal-800 dark:text-teal-200 font-semibold block">
                                    Appointment Reference: #{appointment.unite_appointment_id}
                                </span>
                                <span className="text-teal-600 dark:text-teal-400">
                                    Patient: {appointment.patient_firstname} {appointment.patient_lastname}
                                </span>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Payment Method <span className="text-rose-500">*</span>
                                </label>
                                <select
                                    value={invoicePaymentMode}
                                    onChange={(e) => setInvoicePaymentMode(e.target.value)}
                                    className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-semibold"
                                >
                                    <option value="CARD">Credit / Debit Card (VISA / MASTER)</option>
                                    <option value="CASH">Cash Collection at Clinic Desk</option>
                                    <option value="CHEQUE">Bank Cheque</option>
                                </select>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Transaction / Auth Reference
                                </label>
                                <input
                                    type="text"
                                    value={invoiceRefNum}
                                    onChange={(e) => setInvoiceRefNum(e.target.value)}
                                    className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                                />
                            </div>

                            <div className="p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
                                <div className="flex justify-between py-1">
                                    <span className="text-slate-400">Consultation / Services:</span>
                                    <span className="font-semibold">AED 250.00</span>
                                </div>
                                <div className="flex justify-between py-1">
                                    <span className="text-slate-400">UAE VAT (5%):</span>
                                    <span className="font-semibold">AED 12.50</span>
                                </div>
                                <div className="flex justify-between py-1.5 border-t border-slate-200 dark:border-slate-700 mt-1">
                                    <span className="font-bold text-slate-800 dark:text-slate-200">Total Net Amount:</span>
                                    <span className="font-extrabold text-[#00a5b5]">AED 262.50</span>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 mt-6">
                            <button
                                type="button"
                                onClick={() => setShowInvoiceModal(false)}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={invoiceLoading}
                                onClick={handleGenerateInvoice}
                                className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-[#00a5b5] hover:bg-[#008f9c] flex items-center gap-1.5 shadow-md shadow-[#00a5b5]/20"
                            >
                                {invoiceLoading ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <Receipt className="w-3.5 h-3.5" />}
                                Issue Invoice
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
