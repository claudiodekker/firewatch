# Record what was removed as coverage markers instead of inferring absence

Retention and clears write recorded markers (a monotonic pruned-through instant with its reason, a cleared instant and per-type cleared instants) in the same transaction as the removal, and every answer derives a per-type coverage start with a named reason from them.

The alternative is to estimate the retained window from configuration or from the oldest surviving record, which is wrong as soon as configuration changes, records arrive out of order or a type is cleared alone; an assistant would then read "removed" as "never happened" or "clean". The cost is a few `meta` keys, a rule that a clear writes its marker before deleting, and analyses that must clip windows to coverage.
