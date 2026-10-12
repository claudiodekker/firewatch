# Record a job a test dispatches itself as a job attempt

Under `testing`, test capture records a job as a job attempt of its own when the test itself runs it on the sync queue: a dispatch on a connection of the `sync` driver, or `dispatchSync()`. ADR 0014 holds Nightwatch in request mode, so Nightwatch's job hooks, which it registers only for a worker command, never run in a test. Nightwatch's sensor also drops every attempt on the `sync` connection. A suite that tests its jobs this way therefore left nothing for `n-plus-one`, `failing-jobs` and `rank` to judge.

Test capture listens for Laravel's own queue events. At `JobProcessing` for a sync job it swaps in a command state with the source `job`, as it does for a command, and calls `Core::prepareForJob()`. At `JobProcessed`, or at `JobExceptionOccurred` when the job threw, it writes the attempt through `Core::jobAttempt()`. The sync queue raises one of these two for every job, also for one that failed or deleted itself, so the attempt always ends. The attempt keeps the trace of the test's own work that dispatched it, as a job keeps the trace of its dispatch.

Three things follow from how Nightwatch and the sync queue work:

- The sensor drops an event that names the `sync` connection, and reads the attempt's own connection from the job. Firewatch hands it an event without the connection name, and the stored attempt still says `sync`.
- The sync queue throws a failure back at the test, where no exception handler reports it. Firewatch reports that exception inside the attempt.
- The sync queue marks a job that throws as failed only after `JobExceptionOccurred`. Firewatch marks it first, so the attempt's status is `failed`.

A job that a request, a command or another job runs on the sync queue stays part of that execution and has no attempt record. This is the rule ADR 0014 gives a request dispatched inside another request, and what Nightwatch records in a served application. A second execution would take the job's queries away from the execution that ran them. A chain is therefore one attempt, named after its first job, because Laravel dispatches each next job from inside the one before. Outside a test run nothing changes, so the `sync-jobs-unrecorded` blind spot still holds there, and it now names the exception.

Three kinds of job stay in the test's between section. A job the test calls through `handle()` or sends through `Bus::dispatchNow()` raises no queue event, so nothing tells it from the rest of the test's own work. A job dispatched while the test fakes `JobProcessed` or `JobExceptionOccurred` has no attempt either. These two events are the only sign the sync queue gives that a job ended, and an attempt that never ended would take every later record of the test. Test capture therefore asks Laravel's event fake, through its protected `shouldFakeEvent()`, whether it keeps either event from its listeners, and starts no attempt when it does. A job a worker command runs in a test is left as it was: a worker reports a job's exception after the events that end the attempt, so recording it needs the worker's own lifecycle, which test capture does not follow. Judging a between section as a unit, which ADR 0015 leaves for later, would cover all three.

The sync queue raises no `JobQueued` event, so a job a test dispatches on it has an attempt and no dispatch. `trace` shows that lineage as `no_dispatch` without calling it partial, because an inline connection never records a dispatch.

The queue events live in `illuminate/queue`, which the package now requires. Every application already has it through `laravel/framework`.
