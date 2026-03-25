# Working Rules

These are shared general working rules for TextWhisper.

## Working Order

1. Check invariants before tuning behavior.
2. Check bounds before changing formulas.
3. Check unit consistency before adding special cases.
4. Check one end-to-end state model before patching visible symptoms.

## Core Rules

1. If two parts of the system represent the same thing, their limits must match.
   Example: gesture zoom limits and render zoom limits.

2. Do not patch symptoms before checking model consistency.
   Smoothing, freezing, and special-case branches are usually compensations, not fixes.

3. Verify cross-module contracts first.
   Check:
   - units
   - bounds
   - coordinate spaces
   - ownership of state
   - source of truth

4. Prefer structural explanations over behavioral explanations.
   Many UX bugs are really mismatched rules between modules.

5. When behavior gets complicated, first look for one violated contract.
   One wrong bound, unit, or assumption can create many misleading symptoms.

6. Keep debugging order strict:
   - invariants
   - clamps
   - defaults
   - state sources
   - formulas
   - smoothing/special behavior

7. Do not add a workaround until the base representation is coherent.

8. If one module uses a different representation than another, write down the conversion.
   Example: scale vs margin, pixels vs percent.

9. Less is more in code.
   Change only what is needed. Prefer the smallest correct fix before adding new branches, state, or behavior.

10. Before adding code, check for existing code that already solves the same problem.
    Avoid duplicate logic.

11. Avoid double coding and double event binding.
    Reuse existing paths and ensure one clear owner for each event flow.

12. Always collect trash.
    Remove dead code, temporary patches, duplicate paths, and debug leftovers once the real fix is in.

## Practical Rule

Before editing interaction logic, explicitly verify:

1. minimum value
2. maximum value
3. clamping path
4. storage value
5. render value
6. conversion between them

If those are not aligned, fix that first.
