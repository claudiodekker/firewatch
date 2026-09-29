# Keep every record in one table and expose typed per-type views

Firewatch stores all twelve record types (and unknown input) in a single `records` table with a few common columns and a JSON `data` column, and gives the assistant one generated view per type with typed columns as the documented query surface. One table per type would give plain columns but makes executions, traces and timelines a twelve-way union, splits retention across twelve populations, collides store ids across types and turns every Nightwatch field addition into a schema change.

The single table trades a JSON extraction on type-specific fields (bounded by the `(type, started_at)` index, with promotion to indexed generated columns as the upgrade path) for one indexed lookup by `execution_id`, one ordering for pruning, and additive drift.
