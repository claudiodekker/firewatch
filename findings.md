# MCP protocol and laravel/mcp: findings

Facts only, no decisions. Legend: **[code]** read in `laravel/mcp` source; **[run]** executed in a scratch app (`laravel/mcp` v1.0.1, commit 92987a9, Laravel 13.34.0, PHP 8.5.8); **[doc]** primary documentation; **[inf]** inferred.

## MCP specification

Source: https://modelcontextprotocol.io/specification/2026-07-28/

- Latest revision is **2026-07-28** and it is **stateless**: no `initialize`, no session. Every request carries `_meta` keys `io.modelcontextprotocol/protocolVersion` and `io.modelcontextprotocol/clientCapabilities`. `server/discover` is required. An unsupported version gets error `-32022` with data `{supported, requested}`. [doc: /basic/versioning]
- The legacy era is 2025-11-25 and earlier: `initialize`, then `notifications/initialized`. A dual-era client probes with `server/discover` and falls back to `initialize` on any non-modern error or timeout. [doc: /basic/transports/stdio]
- **stdio transport**: newline-delimited JSON-RPC, no embedded newlines. The server MUST NOT write anything but MCP messages to stdout; stderr is free for logs and clients must not treat it as an error. Shutdown: the client closes stdin, waits, then SIGTERM/SIGKILL; the server SHOULD exit on stdin EOF. Cancellation is `notifications/cancelled`. The client restarts the server if it dies. [doc]
- **Tools**: fields are `name`, `title`, `description`, `inputSchema` (object, JSON Schema 2020-12), `outputSchema`, `annotations`, `_meta`, `icons`. Names SHOULD be 1-128 chars of `A-Za-z0-9_-`. Result content types: text, image, audio, resource_link, resource, plus `structuredContent` and `isError`. Two error channels: protocol errors are JSON-RPC (unknown tool, malformed request); tool execution errors are a result with `isError: true` (validation, business logic). [doc: /server/tools]
- **Not verified**: the default values of the annotation hints (the 2026-07-28 schema page returned no `ToolAnnotations`).

## Clients

| Client | Primitives supported | Stdio configuration |
|---|---|---|
| Claude Code | tools, elicitation; v2 runtime supports revision 2026-07-28 (MCP TypeScript SDK 2.0). The fetched page said nothing on prompts or resources. https://code.claude.com/docs/en/mcp | `.mcp.json` or `~/.claude.json`, `mcpServers.{command,args,env}`; `claude mcp add --transport stdio <name> -- <cmd> <args>` |
| Cursor | tools, prompts, resources, roots, elicitation, Apps | `.cursor/mcp.json` or `~/.cursor/mcp.json`, `mcpServers` with `type: "stdio"`, `command`, `args`, `env`, `envFile` |
| VS Code | tools, prompts, resources, Apps | `.vscode/mcp.json`, top-level `servers` (not `mcpServers`), with `cwd` and `envFile` |
| Codex | tools only (https://learn.chatgpt.com/docs/extend/mcp) | `~/.codex/config.toml` or `.codex/config.toml` (trusted projects only), `[mcp_servers.<name>]` with `command`, `args`, `env_vars`, `cwd` |
| Claude Desktop | tools only (local-server guide) | `~/Library/Application Support/Claude/claude_desktop_config.json`; needs a full restart and absolute paths; logs in `~/Library/Logs/Claude/mcp-server-<NAME>.log` |

### Limits

- **Claude Code output**: `MAX_MCP_OUTPUT_TOKENS` defaults to 25k, warning at 10k; oversize output is saved to a file and the path is sent instead. A tool can raise its own cap with `_meta["anthropic/maxResultSizeChars"]`, hard ceiling 500k characters.
- **Claude Code time**: `MCP_TIMEOUT` (startup), `MCP_TOOL_TIMEOUT` (default about 28 hours), idle timeout 30 minutes for stdio.
- **Codex**: `startup_timeout_sec` 10, `tool_timeout_sec` 60, per-tool `output_token_limit`.
- **VS Code**: a 128-tool limit is referenced.
- **Cursor, Claude Desktop**: no documented output or time limits. A third-party claim of a 40-tool cap for Cursor is unverified.
- **Not established**: which spec revision Cursor, VS Code, Codex and Claude Desktop negotiate.
- **Approval**: Claude Code prompts for project `.mcp.json` servers only in interactive sessions. Cursor asks before using MCP tools by default. VS Code servers inherit workspace trust.

## laravel/mcp v1.0.1

- **Floors** [code]: PHP `^8.2`; `illuminate/*` `^11.45.3|^12.41.1|^13.0`, but `illuminate/json-schema` is `^12.41.1|^13.0` and has no 11.x release [run]. So the effective floor is Laravel 12.41.1 [inf]. Also requires `symfony/process` `^7.4.5|^8.0.5`. The Laravel docs page says it speaks spec 2026-07-28.
- **Setup** [code, run]: servers extend `Laravel\Mcp\Server` (attributes `#[Name]`, `#[Version]`, `#[Instructions]`; `$tools`, `$resources`, `$prompts`; a `boot()` hook). Register with `Mcp::local('handle', Server::class)` in `routes/ai.php` (publish with `vendor:publish --tag=ai-routes`). Run with `php artisan mcp:start <handle>`, which boots the full Laravel application; boot plus one call took about 0.14 s [run]. `mcp:inspector` runs the MCP inspector.
- **Tools** [code, run]: extend `Tool` with `handle(Request)` resolved through the container, `schema(JsonSchema)`, optional `outputSchema()`, `#[Name]`/`#[Title]`/`#[Description]`. The default name is the kebab-case of the class (`PingTool` becomes `ping-tool`). Annotations are class attributes `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]` (hints `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`; bool, default true). Responses: `Response::text|json|image|audio|resourceLink|error|structured`, `Response::make()->withStructuredContent()`, and generators that yield progress notifications. Default `tools/list` page size is 15 (max 50). Testing: `Server::tool(Tool::class, $args)->assertOk()`.
- **Errors measured over stdio** [run]: `Response::error()` gives a result with `isError: true`. A thrown exception gives `isError: true` with the generic text "An internal server error occurred." (masked even though the scratch `.env` had `APP_DEBUG=true`; unexplained). An unknown tool gives JSON-RPC `-32602`. An unsupported version gives `-32022` with data.
- **Versions** [code, run]: the server advertises only 2026-07-28 for modern requests. A request with no `_meta` version keys is treated as legacy and served. `initialize` always answers 2025-11-25, whatever version the client asked for. Notifications are silently ignored, so cancellation is not honoured.
- **stdio transport** [code, run]: a non-blocking STDIN loop with a 10 ms sleep, handling one line at a time, synchronously. It exits on EOF. It writes via `fwrite(STDOUT)` and does no stderr logging. A stray `echo` in a tool corrupted the stream (`STRAY{"jsonrpc":...` on one line) [run]. Stdio has no authentication by default [inf]. There is no size cap on ordinary tool results; `config/mcp.php` `tool_search.max_output_bytes` (65536) applies only to tool search.

## Open items

- Default values of the tool annotation hints under 2026-07-28.
- Negotiated spec revision for non-Claude clients.
- Cursor, VS Code and Claude Desktop output and time limits.
- Behaviour of the debug-mode rethrow path on stdout.
- The exception-masking mismatch noted above.
