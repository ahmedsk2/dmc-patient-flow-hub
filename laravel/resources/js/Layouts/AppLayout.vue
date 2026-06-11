<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import EhcLogo from '@/Components/EhcLogo.vue';

const logout = () => router.post('/logout');

defineProps({ title: { type: String, default: '' } });

const page = usePage();
const sidebarOpen = ref(false);

// flash toast
const toast = ref(null);
let toastTimer = null;
watch(() => page.props.flash, (f) => {
    if (f && f.message) { toast.value = f; clearTimeout(toastTimer); toastTimer = setTimeout(() => (toast.value = null), 4500); }
}, { immediate: true, deep: true });

const nav = [
    { label: 'Dashboard', href: '/', icon: 'grid' },
    { label: 'New Admissions', href: '/admissions', icon: 'plus' },
    { label: 'Patients', href: '/patients', icon: 'bed' },
    { label: 'Handovers', href: '/handovers', icon: 'clipboard' },
    { label: 'Consultations', href: '/consultations', icon: 'chat' },
];
// Admin-only — Registry/Statistics/Reports + exports are restricted (PHI exposure control);
// non-admins' only analytics is the Dashboard.
const admin = [
    { label: 'Registry', href: '/registry', icon: 'search' },
    { label: 'Statistics', href: '/statistics', icon: 'chart' },
    { label: 'Reports', href: '/reports', icon: 'doc' },
    { label: 'Recent Activity', href: '/recent', icon: 'clock' },
    { label: 'Bulk Import', href: '/import', icon: 'upload' },
    { label: 'Control Panel', href: '/control', icon: 'cog' },
];

const url = computed(() => page.url);
const isActive = (href) => href === '/' ? url.value === '/' : url.value.startsWith(href);

// Heroicons-style outline paths (24x24, stroke).
const icons = {
    grid: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25A2.25 2.25 0 0 1 8.25 10.5H6A2.25 2.25 0 0 1 3.75 8.25V6Zm9.75 0A2.25 2.25 0 0 1 15.75 3.75H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6Zm-9.75 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25Zm9.75 0a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    bed: 'M3 7.5h13.5a3 3 0 0 1 3 3V18M3 7.5V18m0-10.5V6m18 12H3m3.75-6.75h.008v.008H6.75v-.008Z',
    chat: 'M8.25 8.25h7.5m-7.5 3.75h4.5m-4.69 6.31a8.25 8.25 0 1 0-3.32-3.32L3 21l4.56-1.94Z',
    search: 'M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z',
    chart: 'M3 13.5V21h4.5v-7.5H3Zm6.75-6V21h4.5V7.5h-4.5ZM16.5 3v18H21V3h-4.5Z',
    doc: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625C5.004 1.875 4.5 2.379 4.5 3v18c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    cog: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.28c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.827c-.293.241-.438.613-.43.992a7.03 7.03 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.542-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.93 6.93 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    upload: 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 7.5 12 3m0 0L7.5 7.5M12 3v13.5',
    clipboard: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z',
};

// ---- notification bell (handover transfers) ---------------------------------------------------
// Badge count comes from the shared `unreadNotifications` prop (refreshed by every Inertia visit —
// no polling timer); opening the dropdown fetches the latest 15 and marks everything read.
const bellOpen = ref(false);
const bellLoading = ref(false);
const notifications = ref([]);
const readOverride = ref(false);   // optimistic zero after read-all, until the next shared-prop refresh
const unread = computed(() => (readOverride.value ? 0 : (page.props.unreadNotifications || 0)));
watch(() => page.props.unreadNotifications, () => (readOverride.value = false));

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const toggleBell = async () => {
    bellOpen.value = !bellOpen.value;
    if (!bellOpen.value) return;
    bellLoading.value = true;
    try {
        const d = await (await fetch('/api/notifications', { headers: { Accept: 'application/json' } })).json();
        notifications.value = d.notifications || [];
        if (d.unread > 0) {
            await fetch('/notifications/read-all', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
        }
        readOverride.value = true;
    } finally {
        bellLoading.value = false;
    }
};
const goInbox = () => { bellOpen.value = false; router.visit('/handovers'); };
const notifText = (n) => {
    const p = n.payload || {};
    if (p.count) return `Dr. ${p.from_name || 'A consultant'} handed over ${p.count} patient(s) to you`;
    return `Dr. ${p.from_name || 'A consultant'} handed over ${p.patient_name || 'a patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''}`;
};
const relTime = (iso) => {
    if (!iso) return '';
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    if (mins < 60 * 24) return `${Math.round(mins / 60)}h ago`;
    return `${Math.round(mins / (60 * 24))}d ago`;
};
</script>

<template>
    <div class="min-h-full">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full bg-gradient-to-b from-navy-900 to-navy-950 text-navy-100 transition-transform lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen }"
        >
            <div class="flex h-16 items-center gap-3 px-5 border-b border-white/5">
                <div class="grid h-9 w-9 place-items-center rounded-xl bg-white p-1 shadow-lg shadow-brand-950/40"><EhcLogo class="h-7 w-7" /></div>
                <div class="leading-tight">
                    <div class="text-sm font-bold text-white tracking-wide">DMC <span class="text-brand-300">IM</span></div>
                    <div class="text-[10px] uppercase tracking-[0.18em] text-navy-400">Patient Flow</div>
                </div>
            </div>

            <nav class="px-3 py-5 space-y-1">
                <p class="px-3 pb-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-navy-400">Clinical</p>
                <Link v-for="item in nav" :key="item.label" :href="item.href"
                    class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
                    :class="isActive(item.href) ? 'bg-white/10 text-white shadow-inner' : 'text-navy-200 hover:bg-white/5 hover:text-white'">
                    <svg class="h-5 w-5 shrink-0" :class="isActive(item.href) ? 'text-brand-300' : 'text-navy-400 group-hover:text-brand-300'" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
                    </svg>
                    {{ item.label }}
                    <span v-if="isActive(item.href)" class="ml-auto h-1.5 w-1.5 rounded-full bg-brand-400"></span>
                </Link>

                <template v-if="page.props.auth?.user?.is_admin">
                    <p class="px-3 pt-5 pb-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-navy-400">Administration</p>
                    <Link v-for="item in admin" :key="item.label" :href="item.href"
                        class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
                        :class="isActive(item.href) ? 'bg-white/10 text-white shadow-inner' : 'text-navy-200 hover:bg-white/5 hover:text-white'">
                        <svg class="h-5 w-5 shrink-0" :class="isActive(item.href) ? 'text-brand-300' : 'text-navy-400 group-hover:text-brand-300'" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
                        </svg>
                        {{ item.label }}
                    </Link>
                </template>
            </nav>

            <div class="absolute bottom-0 left-0 right-0 p-4">
                <div class="rounded-2xl bg-white/5 p-4 text-center">
                    <p class="text-[11px] text-navy-300">Eastern Health Cluster</p>
                    <p class="text-[11px] font-semibold text-brand-300">تجمع الشرقية الصحي</p>
                </div>
            </div>
        </aside>

        <!-- Backdrop (mobile) -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-navy-950/50 lg:hidden" @click="sidebarOpen = false"></div>

        <!-- Main -->
        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-ink-100 bg-white/80 px-5 backdrop-blur">
                <button class="lg:hidden text-ink-500" @click="sidebarOpen = true" aria-label="Open navigation menu">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" /></svg>
                </button>
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-bold text-ink-900">{{ title }}</h1>
                </div>
                <div class="ml-auto flex items-center gap-3">
                    <div class="hidden items-center gap-2 rounded-full bg-success-100 px-3 py-1 text-xs font-semibold text-success-600 sm:flex">
                        <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success-500 opacity-60"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-success-500"></span></span>
                        Live
                    </div>
                    <!-- notification bell -->
                    <div class="relative">
                        <button @click="toggleBell" aria-label="Notifications" title="Notifications" class="relative grid h-9 w-9 place-items-center rounded-full text-ink-400 transition hover:bg-ink-50 hover:text-ink-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                            <span v-if="unread > 0" class="nums absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-danger-600 px-1 text-[10px] font-bold leading-none text-white">{{ unread > 9 ? '9+' : unread }}</span>
                        </button>
                        <div v-if="bellOpen" class="fixed inset-0 z-40" @click="bellOpen = false"></div>
                        <div v-if="bellOpen" class="absolute right-0 top-11 z-50 w-80 overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-ink-100">
                            <div class="flex items-center justify-between border-b border-ink-100 px-4 py-2.5">
                                <span class="text-sm font-bold text-ink-800">Notifications</span>
                                <button @click="goInbox" class="text-xs font-semibold text-brand-600 hover:underline">Handover inbox →</button>
                            </div>
                            <div v-if="bellLoading" class="px-4 py-6 text-center text-sm text-ink-400">Loading…</div>
                            <ul v-else-if="notifications.length" class="max-h-80 divide-y divide-ink-50 overflow-auto">
                                <li v-for="n in notifications" :key="n.id">
                                    <button @click="goInbox" class="w-full px-4 py-3 text-left transition hover:bg-brand-50/40" :class="{ 'bg-brand-50/30': !n.read_at }">
                                        <p class="text-sm leading-snug text-ink-700">{{ notifText(n) }}</p>
                                        <p class="nums mt-0.5 text-xs text-ink-400">{{ relTime(n.created_at) }}</p>
                                    </button>
                                </li>
                            </ul>
                            <div v-else class="px-4 py-6 text-center text-sm text-ink-400">No notifications yet.</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 border-l border-ink-100 pl-3">
                        <Link href="/profile" class="flex items-center gap-3 rounded-xl px-1 py-1 transition hover:bg-ink-50">
                            <div class="grid h-9 w-9 place-items-center rounded-full bg-brand-600 text-sm font-semibold text-white">
                                {{ (page.props.auth?.user?.name || 'DMC').slice(0, 2).toUpperCase() }}
                            </div>
                            <div class="hidden leading-tight sm:block">
                                <div class="text-sm font-semibold text-ink-800">{{ page.props.auth?.user?.name || 'DMC Staff' }}</div>
                                <div class="text-xs text-ink-400">{{ page.props.auth?.user?.role_label || 'Internal Medicine' }}</div>
                            </div>
                        </Link>
                        <button @click="logout" title="Sign out" aria-label="Sign out" class="ml-1 grid h-9 w-9 place-items-center rounded-full text-ink-400 transition hover:bg-danger-100 hover:text-danger-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
                        </button>
                    </div>
                </div>
            </header>

            <main class="p-5 lg:p-7">
                <slot />
            </main>
        </div>

        <!-- Flash toast -->
        <Transition enter-active-class="transition duration-300" enter-from-class="translate-y-3 opacity-0" leave-active-class="transition duration-300" leave-to-class="translate-y-3 opacity-0">
            <div v-if="toast" class="fixed bottom-6 right-6 z-50 flex items-center gap-3 rounded-2xl px-5 py-3.5 text-sm font-semibold text-white shadow-2xl"
                :class="toast.type === 'error' ? 'bg-danger-600' : 'bg-success-600'">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path v-if="toast.type === 'error'" stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    <path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                {{ toast.message }}
            </div>
        </Transition>
    </div>
</template>

