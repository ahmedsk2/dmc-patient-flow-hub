import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));

import SecurityIndex from '@/Pages/Security/Index.vue';

// 2026-09-23 walkthrough (B): MFA is mandatory for every user regardless of settings.mfa_enforcement
// (EnsureMfaEnrolled ignores it), but this page used to say "MFA enforcement is off — nothing to
// flag" whenever the (default, live) enforcement value was 0 — implying nobody was being checked
// when SecurityController was in fact computing compliance for nobody. These prove the page now
// states MFA is mandatory and shows the real compliance status off the (now always-populated) list.
describe('Security/Index — MFA compliance messaging (walkthrough B)', () => {
    const mountPage = (props) => mount(SecurityIndex, { props: { failedClusters: [], firstSeenIps: [], mfaNonCompliant: [], mfaEnforcement: 0, notifyThreshold: 5, ...props } });

    it('states MFA is mandatory for every active user when mfaMandatory is true, even with enforcement=0', () => {
        const w = mountPage({ mfaMandatory: true, mfaEnforcement: 0 });
        expect(w.text()).toContain('two-factor is mandatory for every active user');
        expect(w.text()).not.toContain('nothing to flag');
    });

    it('shows the real non-compliant count/list instead of "nothing to flag" when enforcement is 0', () => {
        const nonCompliant = [
            { id: 1, username: 'drx', name: 'Dr X', role_label: 'Consultant' },
            { id: 2, username: 'dry', name: 'Dr Y', role_label: 'Resident' },
        ];
        const w = mountPage({ mfaMandatory: true, mfaEnforcement: 0, mfaNonCompliant: nonCompliant });
        expect(w.text()).toContain('MFA non-compliant');
        expect(w.text()).toContain('(2)');
        expect(w.text()).toContain('drx');
        expect(w.text()).toContain('dry');
        expect(w.text()).not.toContain('nothing to flag');
    });

    it('reports full compliance accurately once the list is empty', () => {
        const w = mountPage({ mfaMandatory: true, mfaEnforcement: 0, mfaNonCompliant: [] });
        expect(w.text()).toContain('All active users are enrolled.');
    });

    it('defaults mfaMandatory to true when the prop is omitted', () => {
        const w = mount(SecurityIndex, { props: { failedClusters: [], firstSeenIps: [], mfaNonCompliant: [], mfaEnforcement: 0, notifyThreshold: 5 } });
        expect(w.text()).toContain('two-factor is mandatory for every active user');
    });
});
