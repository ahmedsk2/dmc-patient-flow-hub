# Confirmation policy (useConfirm)

Reserve `useConfirm` for **irreversible or identity-changing** actions. Reversible/low-stakes
actions act immediately and rely on the server flash toast (with undo where a reversal exists).

## Requires a confirm (danger / bulk)
- Hard-delete of any record (admission, consultation) — `ask(…, 'danger')`. Irreversible.
- Change-patient-identity (MRN/name edit in Modify) — `ask(…, 'danger')`. Re-points/renames a patient.
- Complete-discharge — closes the clinical episode + affects statistics. (Two-step form acts as its own guard.)
- Sign-all handovers (bulk, multi-patient) — `ask(…)`. The user may not have reviewed every item.

## No confirm (reversible / low-stakes) — act + flash toast
- Single consultation sign-off (reverse-signoff is one click).
- Shuffle / auto-assign (re-shuffle or reassign to undo).
- Admit-from-ICU (icu-pull) — creates a queue entry, reversible via discharge.
- Undo medical discharge — itself a reversal; re-run medical-discharge to redo.

## Rule of thumb
Danger (red, confirmed) = destroys data or changes identity, OR a bulk action the user may not have
reviewed. Neutral (immediate) = everything reversible or low-stakes. When in doubt, prefer immediate
+ a clear toast over a dialog the user will reflexively dismiss.
