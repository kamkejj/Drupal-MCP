#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"

BASE="${MCP_BASE_URL:-https://drupalmcp.ddev.site}"
BASE="${BASE%/}"
USERNAME="${MCP_USERNAME:-mcp_reader_user}"
CLIENT_ID="${MCP_CLIENT_ID:-mcp-inspector}"
REDIRECT_URI="${MCP_REDIRECT_URI:-http://127.0.0.1:6274/oauth/callback}"
SCOPE="${MCP_SCOPE:-mcp:read}"
CREDENTIALS_FILE="${MCP_CREDENTIALS_FILE:-$PROJECT_ROOT/secrets/mcp-test-credentials.txt}"

if [[ -z "${MCP_PASSWORD:-}" ]]; then
  if [[ ! -r "$CREDENTIALS_FILE" ]]; then
    echo "Set MCP_PASSWORD or provide a readable MCP_CREDENTIALS_FILE." >&2
    exit 2
  fi
  MCP_PASSWORD="$(awk -v username="$USERNAME" '$1 == username ":" { password = $2 } END { print password }' "$CREDENTIALS_FILE")"
fi

if [[ -z "$MCP_PASSWORD" ]]; then
  echo "No password found for MCP_USERNAME=$USERNAME." >&2
  exit 2
fi

urlencode() {
  python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$1"
}

JAR="$(mktemp)"
HEADERS="$(mktemp)"
BODY="$(mktemp)"
trap 'rm -f "$JAR" "$HEADERS" "$BODY"' EXIT

VERIFIER="$(openssl rand -hex 32)"
CHALLENGE="$(printf %s "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')"
STATE="$(openssl rand -hex 16)"

LOGIN_HTML="$(curl --silent --show-error --cookie-jar "$JAR" "$BASE/user/login")"
LOGIN_FIELDS="$(printf '%s' "$LOGIN_HTML" | python3 -c '
import html
import re
import sys

source = sys.stdin.read()
form = re.search(r"<form[^>]*action=\"([^\"]*user/login[^\"]*)\"[^>]*>(.*?)</form>", source, re.S)
if form is None:
    raise SystemExit("Drupal login form not found")
print("action\t" + html.unescape(form.group(1)))
for name, value in re.findall(r"name=\"([^\"]+)\" value=\"([^\"]*)\"", form.group(2)):
    if name in {"form_id", "form_build_id", "form_token", "destination"}:
        print(name + "\t" + html.unescape(value))
')"

LOGIN_ACTION="$(printf '%s\n' "$LOGIN_FIELDS" | awk -F '\t' '$1 == "action" { print $2 }')"
LOGIN_ARGS=(--silent --show-error --cookie "$JAR" --cookie-jar "$JAR" --output /dev/null)
while IFS=$'\t' read -r name value; do
  [[ "$name" == "action" ]] && continue
  LOGIN_ARGS+=(--data-urlencode "$name=$value")
done <<< "$LOGIN_FIELDS"
LOGIN_ARGS+=(--data-urlencode "name=$USERNAME" --data-urlencode "pass=$MCP_PASSWORD")
curl "${LOGIN_ARGS[@]}" "$BASE$LOGIN_ACTION"

AUTHORIZE_URL="$BASE/oauth/authorize?client_id=$(urlencode "$CLIENT_ID")&response_type=code&redirect_uri=$(urlencode "$REDIRECT_URI")&scope=$(urlencode "$SCOPE")&state=$(urlencode "$STATE")&code_challenge=$(urlencode "$CHALLENGE")&code_challenge_method=S256&resource=$(urlencode "$BASE/mcp")"
curl --silent --show-error --dump-header "$HEADERS" --output "$BODY" --cookie "$JAR" --cookie-jar "$JAR" "$AUTHORIZE_URL"
LOCATION="$(awk 'tolower($1) == "location:" { print $2 }' "$HEADERS" | tr -d '\r' | tail -1)"
CODE="$(printf '%s' "$LOCATION" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')"

if [[ -z "$CODE" ]]; then
  CONSENT_FIELDS="$(python3 - "$BODY" <<'PY'
import html
import re
import sys

source = open(sys.argv[1], encoding="utf-8").read()
forms = re.findall(r'<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>', source, re.S)
form = next((candidate for candidate in forms if 'value="simple_oauth_authorize_form"' in candidate[1]), None)
if form is None:
    raise SystemExit("OAuth consent form not found")
print("action\t" + html.unescape(form[0]))
for tag in re.findall(r'<input[^>]*type="hidden"[^>]*>', form[1]):
    name = re.search(r'name="([^"]+)"', tag)
    value = re.search(r'value="([^"]*)"', tag)
    if name:
        print("field\t" + name.group(1) + "\t" + html.unescape(value.group(1) if value else ""))
PY
)"
  CONSENT_ACTION="$(printf '%s\n' "$CONSENT_FIELDS" | awk -F '\t' '$1 == "action" { print $2 }')"
  CONSENT_ARGS=(--silent --show-error --dump-header "$HEADERS" --output "$BODY" --cookie "$JAR" --cookie-jar "$JAR")
  while IFS=$'\t' read -r kind name value; do
    [[ "$kind" == "action" ]] && continue
    CONSENT_ARGS+=(--data-urlencode "$name=$value")
  done <<< "$CONSENT_FIELDS"
  CONSENT_ARGS+=(--data-urlencode "op=Allow")
  curl "${CONSENT_ARGS[@]}" "$BASE$CONSENT_ACTION"
  LOCATION="$(awk 'tolower($1) == "location:" { print $2 }' "$HEADERS" | tr -d '\r' | tail -1)"
  CODE="$(printf '%s' "$LOCATION" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')"
fi

if [[ -z "$CODE" ]]; then
  echo "OAuth authorization did not return a code." >&2
  exit 1
fi

TOKEN_RESPONSE="$(curl --silent --show-error --fail-with-body "$BASE/oauth/token" \
  --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$CODE" \
  --data-urlencode "client_id=$CLIENT_ID" \
  --data-urlencode "redirect_uri=$REDIRECT_URI" \
  --data-urlencode "code_verifier=$VERIFIER")"

printf '%s' "$TOKEN_RESPONSE" | python3 -c '
import json
import sys

payload = json.load(sys.stdin)
token = payload.get("access_token")
if not token:
    raise SystemExit("Token endpoint response did not contain access_token")
print(token)
'
