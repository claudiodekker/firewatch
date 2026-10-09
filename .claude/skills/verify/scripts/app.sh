#!/usr/bin/env bash
set -euo pipefail

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
runs="$root/.verify/runs"
evidence="$root/.verify/evidence"
skeleton="$root/vendor/orchestra/testbench-core/laravel"
cd "$root"

usage() {
  cat >&2 <<'EOF'
usage: app.sh start [port]               create a run with its own store, database and queue, and serve the workbench
       app.sh restart <run>              serve the run's workbench again, keeping its store, with the variables given now
       app.sh doctor <run>               is this run's server ours and up, and does Firewatch boot against the run's store?
       app.sh get <run> <path>           send GET <path> to the run and wait for Firewatch to store it
       app.sh artisan <run> ...          run a testbench (artisan) command against the run
       app.sh mcp <run> <tool> ['json']  call one MCP tool over a firewatch:server stdio session and print its answer
       app.sh mcp <run> --session <file> send a file of JSON-RPC lines as one firewatch:server session and print the replies
       app.sh store <run> "<query>"      query the run's Firewatch store, read-only
       app.sh stop <run>                 stop the run's server and delete its state, keeping its evidence
       app.sh list                       list runs and whether their servers are up
EOF
  exit 2
}

run_dir() {
  [[ "$1" =~ ^[0-9]{8}-[0-9]{6}-[0-9]+$ ]] || { echo "not a run id: $1" >&2; exit 1; }
  [ -d "$runs/$1" ] || { echo "no run $1 under $runs" >&2; exit 1; }
  echo "$runs/$1"
}

run_env() {
  local dir
  dir="$(run_dir "$1")"
  unset PHP_CLI_SERVER_WORKERS
  export APP_ENV="${APP_ENV:-local}" APP_URL="http://127.0.0.1:$(cat "$dir/port")"
  export FIREWATCH_DATABASE="$dir/store/firewatch.sqlite"
  export DB_CONNECTION=sqlite DB_DATABASE="$dir/app.sqlite" CACHE_STORE=array QUEUE_CONNECTION=database MAIL_MAILER=array
}

free_port() {
  local port=${1:-8300}
  while lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; do port=$((port + 1)); done
  echo "$port"
}

names() { ps -o command= -p "$1" 2>/dev/null | grep -qF -- "$2"; }

server_of() {
  local pid port
  pid="$(cat "$runs/$1/server.pid" 2>/dev/null || true)"
  port="$(cat "$runs/$1/port" 2>/dev/null || true)"
  if [ -n "$pid" ] && names "$pid" "serve --port=$port "; then echo "$pid"; fi
}

listener_of() {
  local server child
  server="$(server_of "$1")"
  [ -n "$server" ] || return 0
  for child in $(pgrep -P "$server" || true); do
    if lsof -nP -a -p "$child" -iTCP:"$(cat "$runs/$1/port")" -sTCP:LISTEN >/dev/null 2>&1; then echo "$child"; return; fi
  done
}

serving() { [ -n "$(server_of "$1")" ] || { [ -n "$2" ] && names "$2" "-S 127.0.0.1:"; }; }

# A read-only handle can't open a WAL store whose -shm file is gone, as it is after a clear, so the handle is writable and the session is not.
query() { sqlite3 -cmd '.timeout 2000' -cmd 'pragma query_only = 1' "$@"; }

# The store file exists a moment before its tables do, so a failed count reads as none yet.
stored() { query "$FIREWATCH_DATABASE" 'select count(*) from records' 2>/dev/null || echo 0; }

migrate_unrecorded() { FIREWATCH_ENABLED=false php vendor/bin/testbench migrate:fresh --no-interaction; }

doctor() {
  local run="$1" port server listener listing tools ok=1
  run_env "$run"
  port="$(cat "$runs/$run/port")"
  server="$(server_of "$run")"
  listener="$(listener_of "$run")"
  if [ -n "$server" ]; then echo "ok   server process $server is running"; else echo "FAIL the run's server process is not running"; ok=0; fi
  if [ -n "$listener" ]; then
    echo "ok   port $port is served by our php -S ($listener)"
  else
    echo "FAIL port $port is not served by a child of the run's server"; ok=0
  fi
  listing="$(php vendor/bin/testbench firewatch:server --list --json 2>/dev/null || true)"
  tools="$(printf '%s' "$listing" | jq -r '.tools | map(.name) | join(" ")' 2>/dev/null || true)"
  if [ -n "$tools" ]; then
    echo "ok   firewatch:server $(printf '%s' "$listing" | jq -r '.server.version') lists: $tools"
  else
    echo "FAIL firewatch:server --list printed no tools; Firewatch stepped aside or did not boot"; ok=0
  fi
  if [ -f "$skeleton/bootstrap/cache/testbench.yaml" ]; then
    echo "ok   the skeleton holds testbench.yaml, so the workbench routes are loaded"
  else
    echo "FAIL $skeleton/bootstrap/cache/testbench.yaml is gone, so every route answers 404; another testbench process removed it"; ok=0
  fi
  if [ ! -e "$FIREWATCH_DATABASE" ]; then
    echo "ok   no store yet at $FIREWATCH_DATABASE; the first captured batch creates it"
  elif count="$(query "$FIREWATCH_DATABASE" 'select count(*) from records' 2>/dev/null)"; then
    echo "ok   the store at $FIREWATCH_DATABASE holds $count records"
  else
    echo "FAIL the store at $FIREWATCH_DATABASE can't be read"; ok=0
  fi
  [ "$ok" = 1 ]
}

halt() {
  local run="$1" server listener pid
  server="$(server_of "$run")"
  listener="$(listener_of "$run")"
  # On SIGTERM testbench deletes the skeleton's .env and testbench.yaml, which another run or composer serve may be serving from.
  for pid in $server $listener; do kill -KILL "$pid" 2>/dev/null || true; done
  for _ in $(seq 1 20); do
    serving "$run" "$listener" || break
    sleep 0.25
  done
  if serving "$run" "$listener"; then echo "the server of $run would not stop; left $runs/$run in place" >&2; exit 1; fi
}

serve() {
  local run="$1"
  php vendor/bin/testbench serve --port="$(cat "$runs/$run/port")" --no-reload >> "$evidence/$run/server.log" 2>&1 &
  echo $! > "$runs/$run/server.pid"
  for _ in $(seq 1 40); do
    [ -n "$(listener_of "$run")" ] && break
    [ -n "$(server_of "$run")" ] || break
    sleep 0.25
  done
}

stop() {
  local run="$1" dir
  dir="$(run_dir "$run")"
  halt "$run"
  mkdir -p "$evidence/$run"
  if [ -f "$dir/app.sqlite" ]; then cp "$dir/app.sqlite" "$evidence/$run/"; fi
  if [ -d "$dir/store" ]; then cp -R "$dir/store" "$evidence/$run/"; fi
  rm -rf "$dir"
  echo "stopped $run; evidence kept in $evidence/$run"
}

cmd="${1:-}"
[ -n "$cmd" ] || usage
shift

case "$cmd" in
  start)
    [ -d vendor ] || { echo "run composer install first" >&2; exit 1; }
    for tool in sqlite3 jq curl lsof pgrep; do
      command -v "$tool" >/dev/null || { echo "$tool is not installed" >&2; exit 1; }
    done
    run="$(date +%Y%m%d-%H%M%S)-$$"
    dir="$runs/$run"
    logs="$evidence/$run"
    [[ "${1:-8300}" =~ ^[0-9]+$ ]] || { echo "not a port: $1" >&2; exit 1; }
    port="$(free_port "${1:-8300}")"
    mkdir -p "$dir" "$logs"
    echo "$port" > "$dir/port"
    touch "$dir/app.sqlite"
    run_env "$run"

    started=0
    trap '[ "$started" = 1 ] || { stop "$run" >/dev/null; echo "start failed, see $logs" >&2; }' EXIT

    migrate_unrecorded > "$logs/migrate.log" 2>&1
    serve "$run"

    doctor "$run" > "$logs/doctor.log"
    started=1
    echo "run=$run url=$APP_URL evidence=$logs"
    ;;

  restart)
    run="${1:?run id}"
    run_env "$run"
    halt "$run"
    serve "$run"
    doctor "$run" >> "$evidence/$run/doctor.log" || { echo "restart failed, see $evidence/$run" >&2; exit 1; }
    echo "run=$run url=$APP_URL evidence=$evidence/$run"
    ;;

  doctor)
    doctor "${1:?run id}"
    ;;

  get)
    run="${1:?run id}"
    path="${2:?path}"
    run_env "$run"
    before="$(stored)"
    status="$(curl -s -o /dev/null -A firewatch-verify --max-time 30 -w '%{http_code}' "$APP_URL$path" || true)"
    if [ "$status" = 000 ]; then echo "GET $path -> no response" | tee -a "$evidence/$run/requests.log"; exit 1; fi
    # Nightwatch flushes after the response is sent, so the records land a moment after curl returns.
    captured="not stored within 5s"
    last="$before"
    for _ in $(seq 1 20); do
      sleep 0.25
      after="$(stored)"
      if [ "$after" -gt "$before" ] && [ "$after" = "$last" ]; then captured="stored $((after - before)) records"; break; fi
      last="$after"
    done
    echo "GET $path -> $status, $captured" | tee -a "$evidence/$run/requests.log"
    ;;

  artisan)
    run="${1:?run id}"
    shift
    run_env "$run"
    log="$evidence/$run/artisan.log"
    echo "\$ testbench $*" >> "$log"
    status=0
    php vendor/bin/testbench "$@" 2> "$runs/$run/stderr" | tee -a "$log" || status=$?
    tee -a "$log" < "$runs/$run/stderr" >&2
    echo "exit $status" >> "$log"
    exit "$status"
    ;;

  mcp)
    run="${1:?run id}"
    tool="${2:?tool name or --session}"
    [[ "$tool" =~ ^(--session|[a-z-]+)$ ]] || { echo "not a tool name: $tool" >&2; exit 1; }
    run_env "$run"
    mkdir -p "$evidence/$run/mcp"
    number=1
    until mkdir "$evidence/$run/mcp/$(printf '%02d' "$number")" 2>/dev/null; do number=$((number + 1)); done
    call="$evidence/$run/mcp/$(printf '%02d' "$number")/${tool#--}"
    if [ "$tool" = --session ]; then
      cp "${3:?file of JSON-RPC lines}" "$call.request.jsonl"
    else
      arguments="${3:-}"
      [ -n "$arguments" ] || arguments='{}'
      {
        echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"verify","version":"1"}}}'
        echo '{"jsonrpc":"2.0","method":"notifications/initialized"}'
        jq -cn --arg tool "$tool" --argjson arguments "$arguments" '{jsonrpc: "2.0", id: 2, method: "tools/call", params: {name: $tool, arguments: $arguments}}'
      } > "$call.request.jsonl"
    fi
    status=0
    php vendor/bin/testbench firewatch:server < "$call.request.jsonl" > "$call.reply.jsonl" 2> "$call.stderr" || status=$?
    echo "saved $call.{request,reply}.jsonl and .stderr; firewatch:server exited $status" >&2
    if [ "$tool" = --session ]; then
      cat "$call.reply.jsonl"
      exit "$status"
    fi
    jq -e 'select(.id == 2)' "$call.reply.jsonl" >/dev/null || { echo "no reply to tools/call" >&2; exit 1; }
    jq -r 'select(.id == 2) | .error.message // .result.content[0].text' "$call.reply.jsonl"
    jq -e 'select(.id == 2) | .error == null and .result.isError != true' "$call.reply.jsonl" >/dev/null
    ;;

  store)
    run="${1:?run id}"
    run_env "$run"
    [ -f "$FIREWATCH_DATABASE" ] || { echo "no store at $FIREWATCH_DATABASE" >&2; exit 1; }
    query -header -column "$FIREWATCH_DATABASE" "${2:?query}"
    ;;

  stop)
    stop "${1:?run id}"
    ;;

  list)
    for dir in "$runs"/*/; do
      [ -d "$dir" ] || continue
      run="$(basename "$dir")"
      if [ -n "$(listener_of "$run")" ]; then state=up; else state=down; fi
      echo "$run $state http://127.0.0.1:$(cat "$dir/port" 2>/dev/null || echo '?')"
    done
    ;;

  *) usage ;;
esac
