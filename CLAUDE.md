# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

NKT-1 is a single-page AI chat interface deployed at `https://ai.niktaras.com/NKT-1/`. It presents itself to users as "NKT-1," a model from the "NikTaras AI division of NikTaras Web Studio," while the actual backend model is Google's Gemini API. It optionally supports live web search via Tavily, wired through Gemini function calling, and optional user accounts (email/password, MySQL-backed) that unlock saved chat history — chat itself works fully anonymously either way. No package.json, no build tooling, no framework — plain HTML/CSS/JS on the frontend and plain PHP on the backend.

## Architecture

- `index.html` — the entire frontend (markup, CSS, JS) in one file. An IIFE near the bottom handles rendering chat turns, a hand-rolled markdown renderer (`renderMarkdown`), the "Web Access" toggle (`webAccess`, client-side boolean sent with each request), the account UI (login/signup modal, saved-chats panel), and posting to the backend via the page-relative `ENDPOINT = "api/chat.php"`.
- `api/chat.php` — the chat endpoint. Receives `{messages, webAccess, unlockCode}`, forwards to Gemini's `generateContent` (model constant `MODEL = 'gemini-3.5-flash-lite'`) along with the system prompt from `SYSTEM_PROMPT.txt`. When `webAccess` is true and a Tavily key is configured, it registers a `web_search` function declaration with Gemini; if Gemini responds with a function call, `chat.php` calls Tavily itself, feeds the results back to Gemini as a `functionResponse` in a second `generateContent` call, and returns the final text plus a deduped `sources` list. Before any of that, it enforces a 5-messages/hour rate limit (see below). It knows nothing about accounts (login) — saving conversations is a separate call the frontend makes.
- `api/config.php` — resolves all secrets/settings (`GEMINI_API_KEY`, `TAVILY_API_KEY`, `DB_*`, `SITE_URL`, `MAIL_FROM`) from real server env vars first, falling back to `api/.env` (gitignored, parsed by a small inline dotenv parser in this file). Never put secrets directly in this file.
- `api/helpers.php` — shared `load_config()` (used by `chat.php` and the DB layer) and `send_verification_email()`.
- `api/db.php` — lazy PDO MySQL connection (`get_pdo()`); throws if `DB_NAME`/`DB_USER` aren't set.
- `api/auth_helpers.php` — session bootstrap (`bootstrap_session()`), CSRF issuance/check (`csrf_token()`/`require_csrf()`), `current_user()`/`require_login()`, and `db_or_fail()` (wraps `get_pdo()` so an unconfigured DB fails as a clean JSON error instead of a PHP fatal — accounts are optional, so this must degrade gracefully).
- `api/register.php` / `api/login.php` / `api/logout.php` / `api/me.php` — account endpoints. `me.php` is what the frontend calls on every page load to learn login state and fetch a CSRF token (issued even for anonymous sessions, since register/login themselves are CSRF-protected).
- `api/verify.php` — the emailed verification link lands here; renders a small standalone HTML page (not JSON) confirming or rejecting the token.
- `api/conversations.php` — GET (list/single)/POST (save, upsert by id)/DELETE, all requiring login and scoped to the session's `user_id`. Conversations are stored as a `LONGTEXT` JSON blob of `{role, text}` messages (see `api/schema.sql`), not a normalized messages table.
- `api/schema.sql` — the `users` and `conversations` tables. Not applied automatically; the user runs it by hand against their cPanel MySQL database.
- `api/SYSTEM_PROMPT.txt` — the persona prompt; instructs the model to present itself as NKT-1, never as Gemini.
- `.htaccess` — denies direct HTTP access to `.env*`, `config.php`, `SYSTEM_PROMPT.txt`, `helpers.php`, `db.php`, `auth_helpers.php`, and `schema.sql`, and disables directory listing. This is the only thing stopping those files from being served as plain text, so any new include-only PHP file (or rename of an existing one) must be mirrored here.

### Request flow (chat)

1. Browser POSTs the full message history plus the `webAccess` flag to `api/chat.php`.
2. `chat.php` calls Gemini once.
3. If Gemini asks to call `web_search` (only offered when `webAccess` is on and a Tavily key exists), `chat.php` calls Tavily, then calls Gemini a second time with the search results attached — this is the only case where two upstream calls happen for one user turn.
4. The final reply (and sources, if any) go back to the browser as JSON.
5. If the user is logged in, the frontend separately POSTs the updated message array to `api/conversations.php` to persist it (fire-and-forget; a failure here must never interrupt the chat itself).

### Rate limiting

`chat.php` calls `bootstrap_session()` itself (independent of login) and tracks `$_SESSION['rate_count']` / `rate_window_start`, a rolling 1-hour window capped at `RATE_LIMIT = 5`. Once spent, every further message in that window must include a correct `unlockCode` (checked with `hash_equals()` against `UNLOCK_CODE` in `.env`) — a correct code lets exactly that one message through without resetting the counter, so the *next* message needs the code again too. This is intentionally per-*session*, not per-account or per-IP: it applies equally to anonymous and logged-in users, and clearing cookies/using a different browser resets it. A blocked request gets `HTTP 429` with `{"error": ..., "needsCode": true}`; the frontend uses that flag (not just the status code) to open the access-code modal, and distinguishes "first hit" vs "wrong code" by whether a code was already being retried (see `attemptSend()`/`pendingSend` in `index.html`).

### Accounts flow

Register → `send_verification_email()` emails a link with a random token (only its SHA-256 hash is stored, 24h expiry) → `verify.php` marks the account verified → login is refused until verified → `login.php` starts a PHP session (`session_regenerate_id` on success) → `me.php` reports `{loggedIn, email, csrfToken}` on every page load. All state-changing account/conversation requests require an `X-CSRF-Token` header matching the session's token (`require_csrf()`); this covers register/login too, since a session (and its token) exists before login.

## Decisions Made

- **The endpoint path must stay page-relative** (`api/chat.php` in `index.html`, not an absolute path like `/ai/api/chat.php`) — the live deploy path is `/NKT-1/`, and a relative path survives a subdirectory or domain change. `og:image`/`og:url` are the deliberate exception: they're absolute because social scrapers don't resolve relative URLs.
- **No build step, by design** — edits to `index.html` and `api/*.php` are deployed as-is; introducing a bundler or framework would be a scope change, not a fix. This is also why verification email goes through plain PHP `mail()` rather than PHPMailer/SMTP — the latter would require introducing Composer to the project for the first time.
- **Error messages returned to the client never name Gemini or leak server details** (see the comment above `fail()` in `chat.php`) — the NKT-1 persona must hold even when something breaks. Internal detail goes to `error_log` via `$internalDetail`, never into the response body.
- **Env var precedence**: real server environment variables win over `api/.env` (see `niktaras_env()` in `config.php`), so a host's env config can override the local `.env` file without editing it.
- **Accounts are strictly additive, never required** — every account/DB code path must fail gracefully (see `db_or_fail()`) so the core chat feature keeps working even if the database is never configured. Don't gate `chat.php` behind login.
- **Chat conversations are stored as an opaque JSON blob**, not normalized into a messages table — the frontend already treats `history` as an array of `{role, text}`, so the backend just persists that shape as-is rather than introducing a schema the app doesn't otherwise need. `sources` are intentionally not persisted (dropped on reload) to keep this simple.
- **CSS rules for `#composer` and `.overlay` must stay ID/class-scoped, not bare element selectors** (`form`, etc.) — the page now has multiple `<form>` elements (composer + auth modal) and multiple things using the `hidden` attribute; a bare `form { position: absolute; ... }` rule silently broke the auth modal's layout during development, and `.overlay { display: flex }` without a `.overlay[hidden] { display: none }` override silently defeated `hidden` entirely (author CSS beats the `[hidden]` UA rule at equal specificity). Watch for this pattern before adding new modals/forms.

## Current State

Frontend and backend are both functional: chat with markdown rendering, source citations, optional Tavily-backed web search, and optional accounts with email-verified signup, login/logout, and saved/loadable/deletable chat history. Manually tested end-to-end via a local PHP server + browser (register/login error paths, modal rendering, session/CSRF plumbing); full DB-backed flow (register → verify → login → save) has not been tested against a real MySQL instance since none was available in the dev environment — worth a real run against the cPanel database before relying on it. No automated tests, linter, or CI are configured in this repo.

## What's Left To Do

- Verify the full accounts flow against the actual cPanel MySQL database and mail delivery (register → click real email link → login → save/load/delete a conversation).
- Nothing else tracked in-repo (no issue tracker, TODOs, or roadmap found). Treat any further gaps as open scope to confirm with the user before changing.

## How To Continue

- There are no build/lint/test commands in this repo. For a quick sanity check on a PHP edit, `php -l api/<file>.php` catches syntax errors. For the frontend, `php -S localhost:8000` from the repo root plus a browser is the fastest way to manually verify a change (this is how the accounts UI was tested during development — it caught two real CSS bugs that `php -l` couldn't).
- Local setup for manual testing requires PHP with the `curl`, `mbstring`, and `pdo_mysql` extensions, served by something that honors `.htaccess` (e.g. Apache), with `api/.env` populated from `api/.env.example`. Accounts-related endpoints degrade to a clean JSON error (not a crash) if `DB_NAME`/`DB_USER` are left blank, so the rest of the app is still testable without a database.
- **Never stage, commit, or push on your own initiative.** The user commits and pushes manually — leave finished changes in the working tree and describe what changed; don't run `git add`/`git commit`/`git push` unless explicitly asked to in that specific turn.
