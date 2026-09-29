# Attribute work only by recorded user and one dispatch hop, never by trace, and count what is unattributable

Firewatch attributes an execution to a person only through the user recorded on it, a job attempt's dispatch (one hop), or a child that carried the user inside a command or scheduled task; it never infers attribution from `trace_id`, `caused_by`, IP or timing, and every actor answer counts the executions it could not attribute.

A shared trace looks like proof of who caused work, but a dispatcher without Context breaks the chain, so inference would credit a person with work they did not cause and present guesses as facts. The cost is that some real activity is reported as unattributable, which the answer states as numbers. Loosening this later would change what every actor answer means, so it is fixed here.
