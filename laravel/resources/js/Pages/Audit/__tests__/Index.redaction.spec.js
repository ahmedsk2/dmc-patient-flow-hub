import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// 2026-09-23 walkthrough: consultation.reverse_signoff keeps the cleared response note as ciphertext
// under `note_encrypted`. The details panel must show a placeholder, never the ciphertext, and must
// print every other key (note_length included) exactly as stored.
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a href="#"><slot /></a>' },
    router: { get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));

import AuditIndex from '@/Pages/Audit/Index.vue';

const CIPHERTEXT = 'eyJpdiI6IkFBQUEiLCJ2YWx1ZSI6IkJCQkIiLCJtYWMiOiJDQ0NDIn0=';

const mountWith = (details) => mount(AuditIndex, {
    props: {
        logs: {
            data: [{ id: 1, created_at: '2026-09-23T10:00:00Z', actor_name: 'Dr A', action: 'consultation.reverse_signoff',
                category: 'consultation', entity_type: 'consultation', entity_id: 7, details, ip: '10.0.0.1' }],
            total: 1, from: 1, to: 1, last_page: 1, links: [],
        },
        filters: {}, actors: [], entityTypes: ['consultation'], categories: ['consultation'],
        integrityThrough: null,
    },
});

const expandedText = async (w) => {
    await w.findAll('button').find((b) => b.text() === 'View').trigger('click');
    await w.vm.$nextTick();
    return w.find('pre').text();
};

describe('Audit/Index — encrypted detail values are redacted', () => {
    it('shows a placeholder instead of note_encrypted ciphertext and keeps the other keys', async () => {
        const text = await expandedText(mountWith({ disposition: 'advice', note_encrypted: CIPHERTEXT, note_length: 42 }));
        expect(text).not.toContain(CIPHERTEXT);
        expect(text).toContain('"note_encrypted": "[encrypted note]"');
        expect(text).toContain('"note_length": 42');
        expect(text).toContain('"disposition": "advice"');
    });

    it('leaves a null note_encrypted as null (no note was cleared)', async () => {
        const text = await expandedText(mountWith({ note_encrypted: null, note_length: 0 }));
        expect(text).toContain('"note_encrypted": null');
    });
});
