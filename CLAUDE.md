# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

NKT-1 is a single-page AI chat interface deployed at `https://ai.niktaras.com/NKT-1/`. It presents itself to users as "NKT-1," a model from the "NikTaras AI division of NikTaras Web Studio," while the actual backend model is Google's Gemini API. It optionally supports live web search via Tavily, wired through Gemini function calling, and optional user accounts (email/password, MySQL-backed) that unlock saved chat history — chat itself works fully anonymously either way. A separate admin panel at `/admin/` (gated by its own `ADMIN_CODE`, distinct from the chat rate-limit's `UNLOCK_CODE`) lets the site owner browse accounts, edit per-account rate limits, and read both saved and anonymous chat history. No package.json, no build tooling, no framework — plain HTML/CSS/JS on the frontend and plain PHP on the backend.

## Architecture

- `index.html` — the entire frontend (markup, CSS, JS) in one file. An IIFE near the bottom handles rendering chat turns, a hand-rolled markdown renderer (`renderMarkdown`), the "Web Access" toggle (`webAccess`, client-side boolean sent with each request), the account UI (login/signup modal, saved-chats panel), and posting to the backend via the page-relative `ENDPOINT = "api/chat.php"`.
- `api/chat.php` — the chat endpoint. Receives `{messages, webAccess, unlockCode}`, forwards to Gemini's `generateContent` (model constant `MODEL = 'gemini-3.5-flash-lite'`) along with the system prompt from `SYSTEM_PROMPT.txt`. When `webAccess` is true and a Tavily key is configured, it registers a `web_search` function declaration with Gemini; if Gemini responds with a function call, `chat.php` calls Tavily itself, feeds the results back to Gemini as a `functionResponse` in a second `generateContent` call, and returns the final text plus a deduped `sources` list. Before any of that, it enforces the rate limit (see below); after a successful reply, it best-effort logs anonymous (signed-out) chats to `anonymous_chats` for the admin panel. It knows nothing about accounts (login) beyond reading `current_user()` for rate-limit purposes — saving a logged-in user's conversation is a separate call the frontend makes to `conversations.php`.
- `api/config.php` — resolves all secrets/settings (`GEMINI_API_KEY`, `TAVILY_API_KEY`, `DB_*`, `SITE_URL`, `MAIL_FROM`, `UNLOCK_CODE`, `ADMIN_CODE`) from real server env vars first, falling back to `api/.env` (gitignored, parsed by a small inline dotenv parser in this file). Never put secrets directly in this file.
- `api/helpers.php` — shared `load_config()` (used by `chat.php` and the DB layer) and `send_verification_email()`.
- `api/db.php` — lazy PDO MySQL connection (`get_pdo()`); throws if `DB_NAME`/`DB_USER` aren't set.
- `api/auth_helpers.php` — session bootstrap (`bootstrap_session()`), CSRF issuance/check (`csrf_token()`/`require_csrf()`), `current_user()`/`require_login()`, `is_admin()`/`require_admin()` (checks `$_SESSION['is_admin']`), and `db_or_fail()` (wraps `get_pdo()` so an unconfigured DB fails as a clean JSON error instead of a PHP fatal — accounts/admin are optional, so this must degrade gracefully).
- `api/register.php` / `api/login.php` / `api/logout.php` / `api/me.php` — account endpoints. `me.php` is what the frontend calls on every page load to learn login state and fetch a CSRF token (issued even for anonymous sessions, since register/login themselves are CSRF-protected).
- `api/verify.php` — the emailed verification link lands here; renders a small standalone HTML page (not JSON) confirming or rejecting the token.
- `api/conversations.php` — GET (list/single)/POST (save, upsert by id)/DELETE, all requiring login and scoped to the session's `user_id`. Conversations are stored as a `LONGTEXT` JSON blob of `{role, text}` messages (see `api/schema.sql`), not a normalized messages table.
- `api/admin_me.php` / `api/admin_login.php` / `api/admin_logout.php` — mirror the `me.php`/`login.php`/`logout.php` pattern, but for `$_SESSION['is_admin']` instead of a user account, and checked against `ADMIN_CODE` (not `UNLOCK_CODE` — deliberately separate, see Decisions Made) instead of a password. `admin_logout.php` only `unset()`s the flag (not `session_destroy()`), so it doesn't log out a concurrent regular user session in the same browser.
- `api/admin_users.php` — GET (list all accounts, or `?id=` for one plus its conversations list) / POST (`{id, rateLimit}`, updates `users.rate_limit`), all behind `require_admin()`.
- `api/admin_conversation.php` — GET `?id=` any account's full conversation, bypassing the ownership check `conversations.php` enforces (that's the point: admin can read anyone's).
- `api/admin_anon_chats.php` — GET (list, or `?id=` for one) rows from `anonymous_chats`.
- `admin/index.html` — the admin panel frontend: an `ADMIN_CODE` gate, then Accounts / Anonymous Chats tabs. Self-contained like `index.html`, but simpler (no markdown rendering, no Gemini calls) since it's a plain data-browsing tool.
- `api/schema.sql` — full current schema (`users`, `conversations`, `anonymous_chats`) for a fresh database. Not applied automatically; the user runs it by hand (e.g. phpMyAdmin's SQL tab).
- `api/migrate_001_admin.sql` — `ALTER TABLE`/`CREATE TABLE` statements to bring a database that already had the pre-admin-panel `schema.sql` applied up to the current schema. Not idempotent (plain `ALTER TABLE ADD COLUMN`, no `IF NOT EXISTS`) — meant to be run once.
- `api/SYSTEM_PROMPT.txt` — the persona prompt; instructs the model to present itself as NKT-1, never as Gemini.
- `.htaccess` — denies direct HTTP access to `.env*`, `config.php`, `SYSTEM_PROMPT.txt`, `helpers.php`, `db.php`, `auth_helpers.php`, `schema.sql`, and `migrate_001_admin.sql`, and disables directory listing. This is the only thing stopping those files from being served as plain text, so any new include-only PHP file or `.sql` file (or rename of an existing one) must be mirrored here. `admin/index.html` is deliberately NOT blocked — its data endpoints are what's gated, same as the main app.

### Request flow (chat)

1. Browser POSTs the full message history plus the `webAccess` flag to `api/chat.php`.
2. `chat.php` calls Gemini once.
3. If Gemini asks to call `web_search` (only offered when `webAccess` is on and a Tavily key exists), `chat.php` calls Tavily, then calls Gemini a second time with the search results attached — this is the only case where two upstream calls happen for one user turn.
4. The final reply (and sources, if any) go back to the browser as JSON.
5. If the user is logged in, the frontend separately POSTs the updated message array to `api/conversations.php` to persist it (fire-and-forget; a failure here must never interrupt the chat itself).

### Rate limiting

`chat.php` calls `bootstrap_session()` itself (independent of login) and enforces a rolling 1-hour window, default cap `RATE_LIMIT = 5`, via `check_unlock_code()`:
- **Logged-in users**: persisted in the `users` table (`rate_limit`, `rate_count`, `rate_window_start`) instead of the session, so the limit is per-*account* and admin-adjustable (`admin_users.php`). If the DB call throws for any reason, `chat.php` catches it and silently falls back to the session-based path below, rather than breaking chat.
- **Anonymous users** (and the DB-error fallback above): `$_SESSION['rate_count']`/`rate_window_start`, always capped at the hardcoded `RATE_LIMIT` — this is deliberately per-*browser session*, not per-IP, so clearing cookies resets it. There's no per-anonymous-visitor override.

Once spent, every further message needs a correct `unlockCode` (checked with `hash_equals()` against `UNLOCK_CODE` in `.env`) — a correct code lets exactly that one message through without resetting the counter, so the *next* message needs the code again too. A blocked request gets `HTTP 429` with `{"error": ..., "needsCode": true}`; the frontend uses that flag (not just the status code) to open the access-code modal, and distinguishes "first hit" vs "wrong code" by whether a code was already being retried (see `attemptSend()`/`pendingSend` in `index.html`).

### Accounts flow

Register → `send_verification_email()` emails a link with a random token (only its SHA-256 hash is stored, 24h expiry) → `verify.php` marks the account verified → login is refused until verified → `login.php` starts a PHP session (`session_regenerate_id` on success) → `me.php` reports `{loggedIn, email, csrfToken}` on every page load. All state-changing account/conversation requests require an `X-CSRF-Token` header matching the session's token (`require_csrf()`); this covers register/login too, since a session (and its token) exists before login.

### Admin panel

`/admin/index.html` gates on `ADMIN_CODE` (a separate secret from the chat rate-limit's `UNLOCK_CODE` — see Decisions Made) via `admin_login.php`, which sets `$_SESSION['is_admin']`. Every `admin_*.php` endpoint calls `require_admin()`. The panel has two tabs: Accounts (list → click a row → `admin_users.php?id=` for detail + rate-limit editor + that user's saved conversations → `admin_conversation.php?id=` for the full transcript) and Anonymous Chats (`admin_anon_chats.php`, list → full transcript). Anonymous chats only exist because `chat.php` now upserts a row into `anonymous_chats` (keyed by PHP session id) after every reply to a signed-out request — before this feature, anonymous chats were never persisted anywhere.

## Decisions Made

- **The endpoint path must stay page-relative** (`api/chat.php` in `index.html`, not an absolute path like `/ai/api/chat.php`) — the live deploy path is `/NKT-1/`, and a relative path survives a subdirectory or domain change. `og:image`/`og:url` are the deliberate exception: they're absolute because social scrapers don't resolve relative URLs.
- **No build step, by design** — edits to `index.html` and `api/*.php` are deployed as-is; introducing a bundler or framework would be a scope change, not a fix. This is also why verification email goes through plain PHP `mail()` rather than PHPMailer/SMTP — the latter would require introducing Composer to the project for the first time.
- **Error messages returned to the client never name Gemini or leak server details** (see the comment above `fail()` in `chat.php`) — the NKT-1 persona must hold even when something breaks. Internal detail goes to `error_log` via `$internalDetail`, never into the response body.
- **Env var precedence**: real server environment variables win over `api/.env` (see `niktaras_env()` in `config.php`), so a host's env config can override the local `.env` file without editing it.
- **Accounts are strictly additive, never required** — every account/DB code path must fail gracefully (see `db_or_fail()`) so the core chat feature keeps working even if the database is never configured. Don't gate `chat.php` behind login.
- **Chat conversations are stored as an opaque JSON blob**, not normalized into a messages table — the frontend already treats `history` as an array of `{role, text}`, so the backend just persists that shape as-is rather than introducing a schema the app doesn't otherwise need. `sources` are intentionally not persisted (dropped on reload) to keep this simple.
- **CSS rules for `#composer` and `.overlay` must stay ID/class-scoped, not bare element selectors** (`form`, etc.) — the page now has multiple `<form>` elements (composer + auth modal) and multiple things using the `hidden` attribute; a bare `form { position: absolute; ... }` rule silently broke the auth modal's layout during development, and `.overlay { display: flex }` without a `.overlay[hidden] { display: none }` override silently defeated `hidden` entirely (author CSS beats the `[hidden]` UA rule at equal specificity). Watch for this pattern before adding new modals/forms — `admin/index.html` already includes a global `[hidden] { display: none !important; }` rule to avoid it upfront.
- **The admin panel uses its own `ADMIN_CODE`, separate from `UNLOCK_CODE`** — the first version reused `UNLOCK_CODE` per the initial request, but that meant anyone given the unlock code (to bypass the chat rate limit) could also open `/admin/` and read every account's and every anonymous visitor's chat history; the user asked for it split out once that tradeoff was flagged. Keep them separate going forward — don't let `admin_login.php` fall back to `UNLOCK_CODE`.
- **Anonymous chats are now persisted server-side** (`anonymous_chats`, keyed by PHP session id) purely so the admin panel has something to show under "Anonymous Chats" — before this, signed-out chats existed only in the browser and were never sent anywhere for storage. This is a real, if minor, privacy-relevant behavior change for anonymous visitors (who by definition didn't create an account) worth keeping in mind if the site ever gets a privacy notice.
- **Per-account rate limits live on the `users` row itself** (`rate_limit`/`rate_count`/`rate_window_start`), not in the session, specifically so an admin-set limit is stable across devices/browsers and survives the user clearing cookies — the tradeoff is `chat.php` now needs a DB round-trip for every message from a logged-in user (wrapped in try/catch, falling back to the old session-based limiting if that DB call fails, so a DB hiccup degrades the limit rather than breaking chat).

## Current State

Frontend and backend are both functional: chat with markdown rendering, source citations, optional Tavily-backed web search, optional accounts with email-verified signup/login/logout/saved chat history, per-account/session rate limiting with a code-based bypass, and an admin panel (accounts list + detail + rate-limit editing + anonymous chats). Manually tested end-to-end via a local PHP server + browser for: register/login error paths and modal rendering, the full rate-limit → code-modal → re-block cycle (including wrong-code and cancel paths), and the admin gate/tabs/logout flow. None of this has been tested against a real MySQL instance since none was available in the dev environment — the DB-dependent parts (account creation through email, per-account rate limits actually persisting, admin account/conversation/anonymous-chat listings returning real rows) only degrade gracefully so far, not been exercised against real data. No automated tests, linter, or CI are configured in this repo.

## What's Left To Do

- Verify the full accounts flow against the actual cPanel MySQL database and mail delivery (register → click real email link → login → save/load/delete a conversation).
- Run `api/migrate_001_admin.sql` against the live database (it already had the pre-admin-panel schema applied), then verify in `/admin/`: accounts list populates, rate-limit edits actually change chat behavior, saved conversations open, and anonymous chats show up after a signed-out message.
- Nothing else tracked in-repo (no issue tracker, TODOs, or roadmap found). Treat any further gaps as open scope to confirm with the user before changing.

## How To Continue

- There are no build/lint/test commands in this repo. For a quick sanity check on a PHP edit, `php -l api/<file>.php` catches syntax errors. For the frontend, `php -S localhost:8000` from the repo root plus a browser is the fastest way to manually verify a change (this is how the accounts UI was tested during development — it caught two real CSS bugs that `php -l` couldn't).
- Local setup for manual testing requires PHP with the `curl`, `mbstring`, and `pdo_mysql` extensions, served by something that honors `.htaccess` (e.g. Apache), with `api/.env` populated from `api/.env.example`. Accounts-related endpoints degrade to a clean JSON error (not a crash) if `DB_NAME`/`DB_USER` are left blank, so the rest of the app is still testable without a database.
- **Never stage, commit, or push on your own initiative.** The user commits and pushes manually — leave finished changes in the working tree and describe what changed; don't run `git add`/`git commit`/`git push` unless explicitly asked to in that specific turn.
