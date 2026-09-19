#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER_URL="${MCP_SERVER_URL:-https://drupalmcp.ddev.site/mcp}"
TOKEN_CAPABILITY="${MCP_TOKEN_CAPABILITY:-read}"

case "$TOKEN_CAPABILITY" in
  read)
    DEFAULT_USERNAME="mcp_reader_user"
    DEFAULT_SCOPE="mcp:read"
    ;;
  write)
    DEFAULT_USERNAME="mcp_writer_user"
    DEFAULT_SCOPE="mcp:write"
    ;;
  *)
    echo "MCP_TOKEN_CAPABILITY must be read or write." >&2
    exit 2
    ;;
esac

for command in curl node npm python3 uv; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Required command not found: $command" >&2
    exit 2
  fi
done

if [[ -n "${MCP_CA_FILE:-}" ]]; then
  if [[ ! -r "$MCP_CA_FILE" ]]; then
    echo "MCP_CA_FILE is not readable: $MCP_CA_FILE" >&2
    exit 2
  fi
  export NODE_EXTRA_CA_CERTS="$MCP_CA_FILE"
  export SSL_CERT_FILE="$MCP_CA_FILE"
  export CURL_CA_BUNDLE="$MCP_CA_FILE"
fi

# Node otherwise ignores trusted certificates installed in the macOS/system
# trust store. This keeps TLS verification enabled for local DDEV certificates.
if node --help 2>&1 | grep -q -- '--use-system-ca'; then
  export NODE_OPTIONS="${NODE_OPTIONS:+$NODE_OPTIONS }--use-system-ca"
fi

if [[ -n "${MCP_ACCESS_TOKEN:-}" ]]; then
  ACCESS_TOKEN=$MCP_ACCESS_TOKEN
else
  if [[ "$SERVER_URL" != */mcp ]]; then
    echo "Set MCP_BASE_URL when MCP_SERVER_URL does not end in /mcp." >&2
    exit 2
  fi
  BASE_URL="${MCP_BASE_URL:-${SERVER_URL%/mcp}}"
  ACCESS_TOKEN="$(MCP_BASE_URL="$BASE_URL" MCP_USERNAME="${MCP_USERNAME:-$DEFAULT_USERNAME}" MCP_SCOPE="${MCP_SCOPE:-$DEFAULT_SCOPE}" "$SCRIPT_DIR/oauth-token.sh")"
fi

if [[ "$ACCESS_TOKEN" != *.*.* ]]; then
  echo "OAuth helper did not return a JWT access token." >&2
  exit 1
fi

TEMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TEMP_DIR"' EXIT

mkdir -p "$TEMP_DIR/typescript"
cp "$SCRIPT_DIR/typescript-client.mjs" "$TEMP_DIR/typescript/client.mjs"
npm install --silent --ignore-scripts --no-save \
  --prefix "$TEMP_DIR/typescript" '@modelcontextprotocol/sdk@1.30.0'

MCP_SERVER_URL="$SERVER_URL" MCP_ACCESS_TOKEN="$ACCESS_TOKEN" \
  node "$TEMP_DIR/typescript/client.mjs"

MCP_SERVER_URL="$SERVER_URL" MCP_ACCESS_TOKEN="$ACCESS_TOKEN" \
  uv run --quiet --with 'mcp==2.2.0' python "$SCRIPT_DIR/python_client.py"

echo "PASS independent-client conformance: both advertised revisions succeeded"
