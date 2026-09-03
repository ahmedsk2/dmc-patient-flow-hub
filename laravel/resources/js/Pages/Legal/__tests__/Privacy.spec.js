import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// Public PDPL privacy notice (Pages/Legal/Privacy.vue). The page is data-driven — both languages
// arrive as props from resources/lang/{en,ar}/privacy.php — so these fixtures stand in for the
// real arrays and exercise every block type the page knows how to render.

const pageProps = vi.hoisted(() => ({ auth: { user: null } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: pageProps }),
}));

import Privacy from '@/Pages/Legal/Privacy.vue';

const labels = (o) => ({
    language: 'Language', version: 'Version', drafted: 'Drafted', effective: 'Effective date',
    controller: 'Controller', contents: 'Contents', back_login: 'Back to sign in', back_app: 'Back to the hub',
    review_marker: 'Open review item', ...o,
});

const en = {
    code: 'en', dir: 'ltr', title: 'Privacy Notice', subtitle: 'DMC Internal Medicine Patient-Flow Hub',
    draft_banner: 'DRAFT — for review by the hospital legal / data-protection officer; not legal advice.',
    meta: { version: '0.1-draft', drafted: '2026-09-03', effective: '[EFFECTIVE DATE — on approval]', controller: '[HOSPITAL LEGAL NAME]' },
    labels: labels({}),
    sections: [
        { id: 'about', heading: '1. About this notice', blocks: [
            { type: 'p', text: 'English paragraph with a marker [VERIFY ARTICLE] inside.' },
            { type: 'ul', items: ['first bullet', 'second bullet [NEEDS LEGAL CONFIRMATION]'] },
            { type: 'table', head: ['Provider', 'Where'], rows: [['Oracle Cloud Infrastructure', 'Riyadh region']] },
        ] },
        { id: 'rights', heading: '2. Your rights', blocks: [{ type: 'p', text: 'Rights paragraph.' }] },
    ],
};

const ar = {
    code: 'ar', dir: 'rtl', title: 'إشعار الخصوصية', subtitle: 'منصة تدفّق المرضى لقسم الباطنة',
    draft_banner: 'مسودة — للمراجعة من قبل الإدارة القانونية / مسؤول حماية البيانات؛ وليست استشارة قانونية.',
    meta: { version: '0.1-مسودة', drafted: '2026-09-03', effective: '[تاريخ السريان]', controller: '[الاسم القانوني للمستشفى]' },
    labels: labels({ language: 'اللغة', version: 'الإصدار', drafted: 'تاريخ الإعداد', effective: 'تاريخ السريان', controller: 'جهة التحكم', contents: 'المحتويات', back_login: 'العودة إلى تسجيل الدخول', back_app: 'العودة إلى المنصة', review_marker: 'بند مفتوح للمراجعة' }),
    sections: [
        { id: 'about', heading: '1. عن هذا الإشعار', blocks: [
            { type: 'p', text: 'فقرة عربية تحتوي على علامة [تحقّق من رقم المادة] للمراجعة.' },
            { type: 'ul', items: ['البند الأول', 'البند الثاني'] },
            { type: 'table', head: ['مقدّم الخدمة', 'الموقع'], rows: [['أوراكل', 'منطقة الرياض']] },
        ] },
        { id: 'rights', heading: '2. حقوقك', blocks: [{ type: 'p', text: 'فقرة الحقوق.' }] },
    ],
};

const mountPage = () => mount(Privacy, { props: { en, ar } });
const article = (w) => w.get('[data-testid="privacy-notice"]');
const langButton = (w, code) => w.get(`[data-testid="lang-${code}"]`);

beforeEach(() => {
    pageProps.auth = { user: null };
});

describe('Privacy — default (English) rendering', () => {
    it('renders the English notice, marked as a draft, as an LTR English block', () => {
        const w = mountPage();
        const a = article(w);
        expect(a.attributes('lang')).toBe('en');
        expect(a.attributes('dir')).toBe('ltr');
        expect(a.text()).toContain('Privacy Notice');
        expect(a.text()).toContain('DRAFT — for review by the hospital legal / data-protection officer');
        expect(a.text()).not.toContain('إشعار الخصوصية');
    });

    it('offers both languages in the toggle, with the English button pressed', () => {
        const w = mountPage();
        expect(langButton(w, 'en').text()).toBe('English');
        expect(langButton(w, 'ar').text()).toBe('العربية');
        expect(langButton(w, 'ar').attributes('lang')).toBe('ar');
        expect(langButton(w, 'ar').attributes('dir')).toBe('rtl');
        expect(langButton(w, 'en').attributes('aria-pressed')).toBe('true');
        expect(langButton(w, 'ar').attributes('aria-pressed')).toBe('false');
    });

    it('renders paragraph, bullet-list and table blocks, plus a contents list', () => {
        const w = mountPage();
        expect(w.text()).toContain('English paragraph with a marker');
        expect(w.findAll('ul li').map((li) => li.text())).toEqual(['first bullet', 'second bullet [NEEDS LEGAL CONFIRMATION]']);
        expect(w.findAll('th').map((th) => th.text())).toEqual(['Provider', 'Where']);
        expect(w.findAll('td').map((td) => td.text())).toEqual(['Oracle Cloud Infrastructure', 'Riyadh region']);
        expect(w.findAll('nav a').map((a) => a.attributes('href'))).toEqual(['#en-about', '#en-rights']);
        expect(w.find('#en-about').exists()).toBe(true);
    });

    it('highlights every square-bracketed review marker so reviewers can find them', () => {
        const w = mountPage();
        const marks = w.findAll('mark').map((m) => m.text());
        expect(marks).toContain('[VERIFY ARTICLE]');
        expect(marks).toContain('[NEEDS LEGAL CONFIRMATION]');
        expect(marks).toContain('[HOSPITAL LEGAL NAME]');
        expect(marks).toContain('[EFFECTIVE DATE — on approval]');
    });
});

describe('Privacy — language toggle', () => {
    it('switching to Arabic renders the Arabic notice with dir=rtl and lang=ar', async () => {
        const w = mountPage();
        await langButton(w, 'ar').trigger('click');

        const a = article(w);
        expect(a.attributes('lang')).toBe('ar');
        expect(a.attributes('dir')).toBe('rtl');
        expect(a.text()).toContain('إشعار الخصوصية');
        expect(a.text()).toContain('مسودة — للمراجعة');
        expect(a.text()).not.toContain('Privacy Notice');
        expect(langButton(w, 'ar').attributes('aria-pressed')).toBe('true');
        expect(langButton(w, 'en').attributes('aria-pressed')).toBe('false');
        expect(w.findAll('nav a').map((x) => x.attributes('href'))).toEqual(['#ar-about', '#ar-rights']);
        expect(w.findAll('mark').map((m) => m.text())).toContain('[تحقّق من رقم المادة]');
    });

    it('switching back restores the English LTR block', async () => {
        const w = mountPage();
        await langButton(w, 'ar').trigger('click');
        await langButton(w, 'en').trigger('click');

        const a = article(w);
        expect(a.attributes('lang')).toBe('en');
        expect(a.attributes('dir')).toBe('ltr');
        expect(a.text()).toContain('Privacy Notice');
    });
});

describe('Privacy — back link', () => {
    it('sends a visitor to sign-in', () => {
        const w = mountPage();
        const back = w.get('[data-testid="back-link"]');
        expect(back.attributes('href')).toBe('/login');
        expect(back.text()).toBe('Back to sign in');
    });

    it('sends a signed-in staff member back into the hub, in the active language', async () => {
        pageProps.auth = { user: { id: 1, name: 'Dr Test' } };
        const w = mountPage();
        expect(w.get('[data-testid="back-link"]').attributes('href')).toBe('/');
        expect(w.get('[data-testid="back-link"]').text()).toBe('Back to the hub');

        await langButton(w, 'ar').trigger('click');
        expect(w.get('[data-testid="back-link"]').text()).toBe('العودة إلى المنصة');
    });
});
