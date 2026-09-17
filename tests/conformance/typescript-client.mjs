import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { LATEST_PROTOCOL_VERSION } from '@modelcontextprotocol/sdk/types.js';

const expectedVersion = '2025-11-25';
const serverUrl = process.env.MCP_SERVER_URL;
const accessToken = process.env.MCP_ACCESS_TOKEN;

if (!serverUrl || !accessToken) {
  throw new Error('MCP_SERVER_URL and MCP_ACCESS_TOKEN are required.');
}
if (LATEST_PROTOCOL_VERSION !== expectedVersion) {
  throw new Error(`Pinned TypeScript SDK no longer targets ${expectedVersion}.`);
}

const transport = new StreamableHTTPClientTransport(new URL(serverUrl), {
  requestInit: {
    headers: { Authorization: `Bearer ${accessToken}` },
  },
});
const client = new Client({ name: 'drupal-mcp-conformance-typescript', version: '1.0.0' });

try {
  await client.connect(transport);

  const names = [];
  let cursor;
  do {
    const page = await client.listTools(cursor ? { cursor } : undefined);
    names.push(...page.tools.map((tool) => tool.name));
    cursor = page.nextCursor;
  } while (cursor);

  if (!names.includes('drupal_whoami')) {
    throw new Error('tools/list did not expose drupal_whoami.');
  }

  const result = await client.callTool({ name: 'drupal_whoami', arguments: {} });
  if (result.isError || !result.structuredContent) {
    throw new Error('drupal_whoami did not return successful structured content.');
  }

  console.log(`PASS TypeScript SDK 1.30.0 (${expectedVersion}): ${names.length} tools; drupal_whoami succeeded`);
} finally {
  await client.close();
}
