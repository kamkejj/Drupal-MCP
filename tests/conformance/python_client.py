import asyncio
import os

import httpx2
from mcp import Client
from mcp.client.streamable_http import streamable_http_client
from mcp.types import Implementation, LATEST_PROTOCOL_VERSION


EXPECTED_VERSION = "2026-07-28"


async def main() -> None:
    server_url = os.environ.get("MCP_SERVER_URL")
    access_token = os.environ.get("MCP_ACCESS_TOKEN")
    if not server_url or not access_token:
        raise RuntimeError("MCP_SERVER_URL and MCP_ACCESS_TOKEN are required.")
    if LATEST_PROTOCOL_VERSION != EXPECTED_VERSION:
        raise RuntimeError(f"Pinned Python SDK no longer targets {EXPECTED_VERSION}.")

    async with httpx2.AsyncClient(headers={"Authorization": f"Bearer {access_token}"}) as http_client:
        transport = streamable_http_client(server_url, http_client=http_client)
        async with Client(
            transport,
            mode=EXPECTED_VERSION,
            client_info=Implementation(name="drupal-mcp-conformance-python", version="1.0.0"),
        ) as client:
            names: list[str] = []
            cursor: str | None = None
            while True:
                page = await client.list_tools(cursor=cursor, cache_mode="bypass")
                names.extend(tool.name for tool in page.tools)
                cursor = page.next_cursor
                if cursor is None:
                    break

            if "drupal_whoami" not in names:
                raise RuntimeError("tools/list did not expose drupal_whoami.")

            result = await client.call_tool("drupal_whoami", {})
            if result.is_error or result.structured_content is None:
                raise RuntimeError("drupal_whoami did not return successful structured content.")

            print(
                f"PASS Python SDK 2.2.0 ({client.protocol_version}): "
                f"{len(names)} tools; drupal_whoami succeeded"
            )


if __name__ == "__main__":
    asyncio.run(main())
