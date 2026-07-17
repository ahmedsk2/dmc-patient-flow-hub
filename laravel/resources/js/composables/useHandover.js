import { ref } from 'vue';
import { xsrf } from '@/lib/ui.js';

/**
 * Handover fetch paths (Wave 3, Item 6). The board (Patients/Index) had three copies of the same
 * fetch+xsrf+payload dance — the modal write, the inline gate-then-retry, and the bulk preflight
 * saveAllStale. This composable is the single home for those three calls so the URL + headers live
 * in ONE place; the HandoverModal and ReassignModal (Item 4) reuse it.
 *
 * URLs match routes/web.php exactly:
 *   POST   admissions/{id}/handover            → save today's handover (returns true on 2xx)
 *   GET    admissions/{id}/handover            → read the handover (+ history)
 *   GET    handovers/preflight?from_consultant_id=…  → bulk-reassign preflight rows
 *
 * NOTE the cookie-based XSRF header (X-XSRF-TOKEN) — every raw fetch() in this app uses the cookie,
 * NOT a meta tag. saveHandover returns the boolean ok flag (mirroring the old saveHandoverInline)
 * so the gate-then-retry caller can branch on success without changing behavior.
 */
export function useHandover() {
    const saving = ref(false);

    async function saveHandover(admissionId, body, checkpoints = undefined) {
        saving.value = true;
        try {
            const res = await fetch(`/admissions/${admissionId}/handover`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify(checkpoints === undefined ? { body } : { body, checkpoints }),
            });
            return res.ok;
        } finally {
            saving.value = false;
        }
    }

    async function fetchHandover(admissionId) {
        const res = await fetch(`/admissions/${admissionId}/handover`, { headers: { Accept: 'application/json' } });
        return res.json();
    }

    async function preflight(fromConsultantId) {
        const res = await fetch(`/handovers/preflight?from_consultant_id=${fromConsultantId}`, { headers: { Accept: 'application/json' } });
        return res.json();
    }

    return { saving, saveHandover, fetchHandover, preflight };
}
