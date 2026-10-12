# Stamp a test's own work with the source between at capture

Test capture stores `source = 'between'` on every record a test body writes outside its requests and commands, where ADR 0014 left the source empty. The mapper is the only component that knows which side a record is on, and it knows when it writes the record. The tools read that one stored fact: a record is between when its source is `between`, and on the executions side otherwise. `scope` on `overview`, `rank`, `detect` and `occurrences` keeps one side, and travels on the window, so every query that reads the window is scoped by one change.

This amends ADR 0014, which gave such a record no source. The user and the stage stay empty, for the reason that ADR gives. It also adds a deviation from the wire (ADR 0003): `between` is no kind of execution, and Nightwatch never sends it.

The alternatives infer the side when reading. An empty source also holds for every user record and every error placeholder, in every store, so `scope: "executions"` would change answers that have nothing to do with tests. A record whose execution id matches no execution also describes the children of an execution that retention removed, because a request starts before its children and is pruned first, so a long-lived local store would report those children as test setup. Both give a wrong answer without saying so.

The schema version goes to 2 for `records_between`, a partial index on the between records. On a store of 100,000 records with no test work, the probe that decides whether `overview` shows its sides and the scan that finds the between sections of a run each read the whole table without it, and read an empty index with it. The store is rebuilt, not migrated, which costs nothing before the first release.

Detectors that judge executions have nothing to judge between them, so under `scope: "between"` they answer `not_evaluated` with `no_executions_in_scope`. A loop in a factory is therefore not reported as an N+1. Judging a between section as a unit of its own would change what a detector's examined count means, and is left for later.
