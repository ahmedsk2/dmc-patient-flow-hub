// Canonical handover checkpoint shape. Server mirror: HandoverController::normalizeCheckpoints().
//
// CHECKPOINT_FIELDS / CODE_STATUS_OPTIONS (the field/option LISTS, used to render labels) are shared
// by HandoverCapture and CheckpointChips only. HandoverModal does NOT import them — its edit-mode
// template hardcodes its own checkbox labels and <select> options inline; it, ActionModal, and
// ReassignModal import only defaultCheckpoints()/withCheckpointDefaults() from here, for the
// checkpoint SHAPE (defaults + null-safety), not the label strings.
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
