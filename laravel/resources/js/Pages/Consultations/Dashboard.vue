<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ChartFigure from '@/Components/ChartFigure.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { useReducedMotion, chartAnimations } from '@/composables/useReducedMotion';

// theme-aware chart colours (grid/axis read CSS tokens) — never local hex.
const { gridColor, axisColor, series } = useChartTheme();
const { reduced } = useReducedMotion();

const props = defineProps({
    canPick: Boolean,
    filters: Object,
    specialties: Array,
    scopeLabel: String,
    openCounts: Object,
    ageing: Object,
    today: Object,
    turnaround: Object,
    trend: Object,
    topIndications: Array,
    perConsultant: Array,
    generatedAt: String,
});

// Specialty narrowing is a non-PII filter, so it rides the query string (the PHI-free-URL rule
// only forbids patient names and MRNs there).
const pickSpecialty = (event) => {
    const value = event.target.value;
    router.visit('/consultations/dashboard', value ? { data: { specialty_id: value } } : {});
};

// Drill-through: every figure lands on the workspace list that produced it.
const openWorkspace = (data) => router.visit('/consultations', data ? { data } : {});

// `total` is deliberately the SUM of the grouped-by-status rows on the server, not
// new+active+ongoing added up client-side (see ConsultationDashboardController::openCounts): status
// has no CHECK constraint, so a hand-run SQL fix on prod could leave a value outside the three known
// states. Rendering a fourth "Total open" tile — rather than silently dropping the figure — is the
// only surface that keeps such a row visible: it would count in the ageing chart below but appear in
// none of the three named cards and none of the four workspace tabs.
const statusCards = computed(() => [
    { key: 'new', label: 'New / not seen', value: props.openCounts.new, tint: 'bg-tint-info/30', filter: { status: 'new' } },
    { key: 'active', label: 'Active (daily F/U)', value: props.openCounts.active, tint: 'bg-brand-50', filter: { status: 'active' } },
    { key: 'ongoing', label: 'Ongoing (no daily F/U)', value: props.openCounts.ongoing, tint: 'bg-ink-50', filter: { status: 'ongoing' } },
    { key: 'total', label: 'Total open', value: props.openCounts.total, tint: 'bg-tint-warning/30', filter: null },
]);

// Ageing is measured from requested_at, falling back to the historical date-only column; a consult
// with neither date is reported separately rather than being placed in a bucket it did not earn.
const ageingRows = computed(() => [
    ['0–2 days', props.ageing.b0_2],
    ['3–7 days', props.ageing.b3_7],
    ['Over 7 days', props.ageing.b8_plus],
    ['Date unknown', props.ageing.unknown],
]);
const hasAgeing = computed(() => ageingRows.value.some(([, v]) => v > 0));
const ageingOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.primary],
    plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: gridColor.value, strokeDashArray: 4 },
    xaxis: { categories: ageingRows.value.map(([label]) => label), labels: { style: { colors: axisColor.value } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { show: false },
}));
const ageingSeries = computed(() => [{ name: 'Open consults', data: ageingRows.value.map(([, v]) => v) }]);

const hasTrend = computed(() => (props.trend.labels || []).length > 0
    && (props.trend.data || []).some((v) => v > 0));
const trendOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.deep],
    plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: gridColor.value, strokeDashArray: 4 },
    xaxis: { categories: props.trend.labels, labels: { style: { colors: axisColor.value } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { show: false },
}));
const trendSeries = computed(() => [{ name: 'Consultations', data: props.trend.data }]);
const trendRows = computed(() => (props.trend.labels || []).map((m, i) => [m, props.trend.data[i] ?? 0]));

// A NULL median means "no qualifying rows yet" — rendering 0.0 would read as instant turnaround.
const hours = (value) => (value === null || value === undefined
    ? 'Not enough data yet'
    : `${Number.isInteger(value) ? value : value.toFixed(1)} h`);
const turnaroundCards = computed(() => [
    { key: 'first', label: 'Median time to first follow-up', value: props.turnaround.first_followup_hours, n: props.turnaround.first_followup_n },
    { key: 'signoff', label: 'Median time to sign-off', value: props.turnaround.signoff_hours, n: props.turnaround.signoff_n },
]);

const completenessPct = computed(() => (props.today.due > 0
    ? Math.round((props.today.seen / props.today.due) * 100) : 0));

const loadMax = computed(() => Math.max(1, ...(props.perConsultant || []).map((r) => r.c)));
</script>

<template>
    <AppLayout title="Consultation Dashboard">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="font-display text-xl font-bold text-ink-800">Consultation dashboard</h1>
                <p class="text-sm text-ink-500">{{ scopeLabel }} · updated {{ generatedAt }}</p>
            </div>
            <div class="flex items-center gap-3">
                <select v-if="canPick" data-testid="specialty-picker"
                        aria-label="Specialty"
                        class="rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-ink-600 shadow-sm"
                        :value="filters.specialty_id ?? ''" @change="pickSpecialty">
                    <option value="">All specialties</option>
                    <option v-for="s in specialties" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <button type="button" @click="openWorkspace(null)"
                        class="rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-ink-600 shadow-sm transition hover:border-brand-300 hover:text-brand-700">
                    Open workspace →
                </button>
            </div>
        </div>

        <!-- open counts by status -->
        <h2 class="sr-only">Open consultations by status</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <button v-for="c in statusCards" :key="c.key" type="button"
                    :data-testid="`status-card-${c.key}`"
                    :aria-label="`${c.value} ${c.label} — open the workspace filtered to this`"
                    class="rounded-2xl p-5 text-start ring-1 ring-line transition hover:ring-brand-300"
                    :class="c.tint" @click="openWorkspace(c.filter)">
                <p class="font-display nums text-3xl font-extrabold text-ink-900">{{ c.value }}</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-ink-500">{{ c.label }}</p>
            </button>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- today's follow-up completeness -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Today's follow-ups</h2>
                <p data-testid="today-completeness" class="font-display nums text-3xl font-extrabold text-ink-900">
                    Seen {{ today.seen }} of {{ today.due }}
                </p>
                <p class="mt-1 text-xs text-ink-500">Active consults only — ongoing consults carry no daily follow-up.</p>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-ink-50"
                     role="img" :aria-label="`${completenessPct} percent of today's active consults have been seen`">
                    <div class="h-full rounded-full bg-brand-500" :style="{ width: completenessPct + '%' }"></div>
                </div>
            </div>

            <!-- turnaround medians + the cutover caveat -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Turnaround</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="t in turnaroundCards" :key="t.key">
                        <p data-testid="turnaround-value" class="font-display nums text-2xl font-extrabold text-ink-900">{{ hours(t.value) }}</p>
                        <p class="mt-1 text-xs text-ink-500">{{ t.label }}</p>
                        <p class="nums text-xs text-ink-400">n = {{ t.n }}</p>
                    </div>
                </div>
                <!-- The caveat is VISIBLE, always, and names the excluded count: historical rows have
                     no request timestamp at all, and averaging a fabricated one in would be a lie. -->
                <p data-testid="cutover-note" class="mt-3 rounded-xl bg-tint-info/30 px-3 py-2 text-xs font-semibold text-on-info">
                    Measured from cutover onward. {{ turnaround.legacy_excluded }} historical consultations have no recorded request time and are excluded.
                </p>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- ageing buckets -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Ageing <span class="font-normal text-ink-400">(open consults)</span></h2>
                <ChartFigure title="Ageing of open consultations"
                             caption="Open consultations grouped by how long they have been open, measured from the request time or, for historical rows, the consultation date."
                             :columns="['Age', 'Open consults']" :rows="ageingRows">
                    <apexchart v-if="hasAgeing" type="bar" height="260" :options="ageingOptions" :series="ageingSeries" aria-label="Bar chart: open consultations by age" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No open consultations.</p>
                </ChartFigure>
                <!-- VISIBLE, not just the chart's sr-only caption: this is the honesty statement that a
                     consult with neither a request time nor a consultation date is reported separately
                     rather than being given a day-count it never earned. -->
                <p class="mt-3 text-xs text-ink-500">
                    Age is measured from the request time or, for historical rows, the consultation date.
                    Rows with neither are reported as "Date unknown" rather than being given a date they never had.
                </p>
            </div>

            <!-- six-month volume -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Volume <span class="font-normal text-ink-400">(last 6 months)</span></h2>
                <ChartFigure title="Consultation volume"
                             caption="Consultations received per month over the last six months."
                             :columns="['Month', 'Consultations']" :rows="trendRows">
                    <apexchart v-if="hasTrend" type="bar" height="260" :options="trendOptions" :series="trendSeries" aria-label="Bar chart: consultations per month over the last six months" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- top indications -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-4 font-semibold text-ink-700">Top indications <span class="font-normal text-ink-400">(last 6 months)</span></h2>
                <div class="space-y-2">
                    <div v-for="(row, i) in topIndications" :key="row.label" data-testid="indication-row" class="flex items-center gap-3">
                        <div class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{{ i + 1 }}</div>
                        <div class="min-w-0 flex-1 truncate text-sm text-ink-700">{{ row.label }}</div>
                        <div class="nums text-sm font-bold text-brand-700">{{ row.value }}</div>
                    </div>
                    <p v-if="!topIndications.length" class="text-sm text-ink-400">No indications recorded in this period.</p>
                </div>
            </div>

            <!-- per-consultant load -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-4 font-semibold text-ink-700">Open load by consultant</h2>
                <div class="space-y-3">
                    <button v-for="row in perConsultant" :key="row.id" type="button" data-testid="load-row"
                            class="flex w-full items-center gap-3 rounded-lg px-1 py-0.5 text-start transition hover:bg-brand-50/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            :aria-label="`${row.name}, ${row.c} open consultations — open the workspace filtered to this consultant`"
                            @click="openWorkspace({ consultant_id: row.id })">
                        <div class="w-40 shrink-0 truncate text-sm font-medium text-ink-600">{{ row.name }}</div>
                        <div class="relative h-2.5 flex-1 overflow-hidden rounded-full bg-ink-50">
                            <div class="h-full rounded-full bg-brand-500" :style="{ width: (row.c / loadMax * 100) + '%' }"></div>
                        </div>
                        <div class="nums w-8 text-end text-sm font-bold text-ink-800">{{ row.c }}</div>
                    </button>
                    <p v-if="!perConsultant.length" class="text-sm text-ink-400">No open consultations assigned.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
