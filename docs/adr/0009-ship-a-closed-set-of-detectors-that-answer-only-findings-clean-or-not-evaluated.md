# Ship a closed set of detectors that answer only findings, clean or not evaluated

Firewatch's detectors are a closed set of named, fixed-shape judgements, each answering with exactly one of `findings`, `clean` or `not_evaluated`, and `clean` only when the detector's own input exists in the window. Slowness is deliberately not a detector: a fixed threshold would assert a deadline for an application Firewatch knows nothing about, so slow work is ranked, never judged.

The alternative was an open or configurable rule set with severity levels and slow-work rules. A closed set keeps `clean` meaning the same thing on every call and every answer honest about what was examined; the cost is that a custom judgement needs ranking or SQL, and a new detector is a contract change.
