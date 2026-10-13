#!/usr/bin/env bash
# T4 check, runs INSIDE the container: start the compiled server, start two
# runs, stream both concurrently over SSE, cancel the second one mid-run.
set -uo pipefail
BIN="${1:-./deployer}"
PORT=8080
stamp() { while IFS= read -r l; do [ -n "$l" ] && printf '%s %s %s\n' "$(date +%T.%3N)" "$1" "$l"; done; }

"$BIN" serve "$PORT" > /tmp/server.log 2>&1 &
SRV=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.1; done
echo "--- server log (startup)"; cat /tmp/server.log

R1=$(curl -s -X POST "http://127.0.0.1:$PORT/runs?count=8")
R2=$(curl -s -X POST "http://127.0.0.1:$PORT/runs?count=20")
echo "--- POST /runs -> $R1 $R2"
curl -sN "http://127.0.0.1:$PORT/runs/run1/stream" | stamp client:run1 > /tmp/s1.log &
C1=$!
curl -sN "http://127.0.0.1:$PORT/runs/run2/stream" | stamp client:run2 > /tmp/s2.log &
C2=$!
sleep 2
echo "--- $(date +%T.%3N) POST /runs/run2/cancel -> $(curl -s -X POST "http://127.0.0.1:$PORT/runs/run2/cancel")"
wait "$C1" "$C2"
echo "--- merged client view (sorted by arrival time)"
sort /tmp/s1.log /tmp/s2.log
echo "--- 404 check: $(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/runs/nope/stream")"
kill "$SRV" 2>/dev/null; wait "$SRV" 2>/dev/null
echo "--- server log (final)"; cat /tmp/server.log
