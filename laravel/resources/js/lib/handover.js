// Canonical handover checkpoint shape — the ONE definition shared by HandoverCapture,
// HandoverModal and CheckpointChips. Server mirror: HandoverController::normalizeCheckpoints().
export const CHECKPOINT_FIELDS = [
    { key: 'vte_completed', label: 'VTE prophylaxis', short: 'VTE' },
    { key: 'ready_for_discharge', label: 'Ready for discharge', short: 'D/C ready' },
    { key: 'high_risk', label: 'High-risk', short: 'High-risk' },
    { key: 'needs_workup', label: 'Needs more workup', short: 'Needs workup' },
    { key: 'workup_pending', label: 'Workup pending', short: 'Workup pending' },
];

export const CODE_STATUS_OPTIONS = [
    { value: null, label: 'None' },
    { value: 'full', label: 'Full' },
    { value: 'dnr', label: 'DNR' },
    { value: 'dni', label: 'DNI' },
];

export const defaultCheckpoints = () => ({
    vte_completed: false, ready_for_discharge: false, high_risk: false,
    needs_workup: false, workup_pending: false, code_status: null,
});

/** Spread a fetched (possibly null/partial) payload over the canonical shape. */
export const withCheckpointDefaults = (cp) => ({ ...defaultCheckpoints(), ...(cp || {}) });
