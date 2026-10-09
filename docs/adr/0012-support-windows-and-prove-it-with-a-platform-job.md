# Support Windows and prove it with a platform job

Firewatch supports Windows: the whole suite runs there, file-mode assertions are skipped, the doctor reports store permissions as not applicable, the store identity check does nothing, and the SQL child's deadline uses temp-file output and process polling because `stream_select()` does not work on `proc_open` pipes there.

The alternative was to document Windows as unsupported or to have the SQL tool report itself unavailable there. The chosen path means the deadline, ceilings and abnormal-stop behaviour of the SQL access decision are implemented for a second mechanism and kept in step forever, and every POSIX assumption in the store and diagnostics stays visible. Withdrawing a stated support later breaks users who installed on that promise.

Amended: the second mechanism is not kept. The SQL child exchanges with its parent through private temp files on every platform: the request on a file as stdin, stdout and stderr on files, `proc_get_status` and the stdout size polled against the one deadline, and a kill at the deadline or the output ceiling. There is no stream_select path to keep in step, and every POSIX run of the SQL process tests exercises the mechanism Windows depends on. The cost is a 10 ms poll, and the rows of a call pass through a temp file of the user's own until the call ends.
