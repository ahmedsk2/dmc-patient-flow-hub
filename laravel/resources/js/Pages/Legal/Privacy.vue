<script setup>
import { ref, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import EhcLogo from '@/Components/EhcLogo.vue';
import NoticeText from '@/Components/NoticeText.vue';

// Public PDPL privacy notice (GET /privacy). Both languages arrive as props from
// resources/lang/{en,ar}/privacy.php via LegalController, so the wording is maintained there and
// never in this file. The page sits OUTSIDE AppLayout on purpose: it must render for a patient or
// visitor with no session (same standalone shell as Pages/Auth/Login.vue), and it carries no PHI.
const props = defineProps({
    en: { type: Object, required: true },
    ar: { type: Object, required: true },
});

const locale = ref('en');
const notice = computed(() => (locale.value === 'ar' ? props.ar : props.en));

// A signed-in staff member arrives from the sidebar link and should be sent back into the hub;
// everyone else came from (or belongs on) the sign-in screen.
const signedIn = computed(() => Boolean(usePage().props?.auth?.user));

const anchor = (section) => `${notice.value.code}-${section.id}`;
</script>

<template>
    <Head :title="notice.title" />
    <div class="min-h-screen bg-app">
        <header class="border-b border-line bg-card">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-2xl bg-brand-50 p-1.5 ring-1 ring-brand-100"><EhcLogo class="h-7 w-7" /></div>
                    <div class="leading-tight">
                        <div class="font-display text-sm font-bold text-ink-900">DMC <span class="text-brand-700">Internal Medicine</span></div>
                        <div class="text-[10px] uppercase tracking-[0.18em] text-ink-400">Patient-Flow Hub</div>
                    </div>
                </div>

                <div role="group" :aria-label="`${en.labels.language} / ${ar.labels.language}`" class="flex rounded-xl bg-app p-0.5 ring-1 ring-line">
                    <button type="button" data-testid="lang-en" lang="en" @click="locale = 'en'" :aria-pressed="locale === 'en'"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                        :class="locale === 'en' ? 'bg-brand-solid text-white' : 'text-ink-600 hover:bg-ink-100'">English</button>
                    <button type="button" data-testid="lang-ar" lang="ar" dir="rtl" @click="locale = 'ar'" :aria-pressed="locale === 'ar'"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                        :class="locale === 'ar' ? 'bg-brand-solid text-white' : 'text-ink-600 hover:bg-ink-100'">العربية</button>
                </div>
            </div>
        </header>

        <main id="main-content" class="mx-auto max-w-3xl px-6 py-8">
            <article :key="notice.code" :lang="notice.code" :dir="notice.dir" data-testid="privacy-notice"
                class="rounded-2xl bg-card p-6 shadow-card ring-1 ring-line sm:p-10">
                <p role="note" class="rounded-xl bg-tint-warning px-4 py-3 text-sm font-semibold text-on-warning">{{ notice.draft_banner }}</p>

                <h1 class="mt-6 font-display text-3xl font-extrabold leading-tight text-ink-900">{{ notice.title }}</h1>
                <p class="mt-1 text-ink-500">{{ notice.subtitle }}</p>

                <dl class="mt-5 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="font-semibold text-ink-500">{{ notice.labels.version }}</dt><dd class="text-ink-800">{{ notice.meta.version }} · {{ notice.labels.drafted }} {{ notice.meta.drafted }}</dd></div>
                    <div><dt class="font-semibold text-ink-500">{{ notice.labels.effective }}</dt><dd class="text-ink-800"><NoticeText :text="notice.meta.effective" :marker-label="notice.labels.review_marker" /></dd></div>
                    <div class="sm:col-span-2"><dt class="font-semibold text-ink-500">{{ notice.labels.controller }}</dt><dd class="text-ink-800"><NoticeText :text="notice.meta.controller" :marker-label="notice.labels.review_marker" /></dd></div>
                </dl>

                <nav :aria-label="notice.labels.contents" class="mt-6 rounded-xl bg-app p-4 text-sm">
                    <p class="font-semibold text-ink-700">{{ notice.labels.contents }}</p>
                    <ol class="mt-2 grid gap-1 sm:grid-cols-2">
                        <li v-for="section in notice.sections" :key="section.id">
                            <a :href="`#${anchor(section)}`" class="text-brand-700 hover:underline">{{ section.heading }}</a>
                        </li>
                    </ol>
                </nav>

                <section v-for="section in notice.sections" :key="section.id" :id="anchor(section)" class="mt-8 scroll-mt-6">
                    <h2 class="text-xl font-bold text-ink-900">{{ section.heading }}</h2>
                    <template v-for="(block, i) in section.blocks" :key="i">
                        <p v-if="block.type === 'p'" class="mt-3 leading-relaxed text-ink-700">
                            <NoticeText :text="block.text" :marker-label="notice.labels.review_marker" />
                        </p>
                        <ul v-else-if="block.type === 'ul'" class="mt-3 list-disc space-y-2 ps-6 leading-relaxed text-ink-700">
                            <li v-for="(item, j) in block.items" :key="j"><NoticeText :text="item" :marker-label="notice.labels.review_marker" /></li>
                        </ul>
                        <div v-else-if="block.type === 'table'" class="mt-3 overflow-x-auto rounded-xl ring-1 ring-line">
                            <table class="w-full text-sm">
                                <thead class="bg-app text-start text-ink-600">
                                    <tr><th v-for="(h, j) in block.head" :key="j" scope="col" class="px-3 py-2 text-start font-semibold">{{ h }}</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, r) in block.rows" :key="r" class="border-t border-line align-top">
                                        <td v-for="(cell, c) in row" :key="c" class="px-3 py-2 text-ink-700"><NoticeText :text="cell" :marker-label="notice.labels.review_marker" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </section>

                <footer class="mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-6 text-sm text-ink-500">
                    <span>{{ notice.labels.version }} {{ notice.meta.version }} · {{ notice.labels.effective }}: <NoticeText :text="notice.meta.effective" :marker-label="notice.labels.review_marker" /></span>
                    <Link :href="signedIn ? '/' : '/login'" data-testid="back-link" class="font-semibold text-brand-700 hover:underline">{{ signedIn ? notice.labels.back_app : notice.labels.back_login }}</Link>
                </footer>
            </article>
        </main>
    </div>
</template>
