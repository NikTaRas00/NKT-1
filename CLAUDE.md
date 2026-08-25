# NKT-1 Chatbot (`/ai`)

A single-page chat UI backed by one PHP endpoint that proxies Google Gemini.
Deployed at <https://web.niktaras.com/ai/> on Apache shared hosting.

**Scope: this folder only.** The rest of the repo (`../game-3d-1`, `../games`,
`../apo`, the root landing page) is separate work — don't read, touch, or
reason about it unless explicitly asked.

## Layout

```
index.html              entire frontend — markup, CSS and JS in one file
.htaccess               blocks .env, config.php, SYSTEM_PROMPT.txt; disables indexes
og-image.JPG            social preview image
api/chat.php            the only endpoint: POST -> Gemini -> JSON reply
api/config.php          loads secrets, returns them as an array (no logic)
api/.env                real secrets — gitignored, NEVER commit or print
api/.env.example        the expected key names
api/SYSTEM_PROMPT.txt   server-side persona, sent as systemInstruction
```

No build step, no package manager, no dependencies. Edit the files, push, done.

## Request flow

1. `index.html` POSTs `{ messages: [{ role, text }], system }` to `/ai/api/chat.php`.
   `role` is `"user"` or `"model"`. The `system` field is **ignored** by the
   server — `SYSTEM_PROMPT` in the JS is dead code left over from an earlier
   version. Persona lives in `api/SYSTEM_PROMPT.txt`, server-side, so it can't be
   read or overridden from the browser. Keep it that way.
2. `chat.php` trims history to the last `MAX_MESSAGES` (40) and each message to
   `MAX_CHARS` (4000), then calls `generateContent` on `MODEL`
   (`gemini-3.5-flash-lite`) with `x-goog-api-key`.
3. If `TAVILY_API_KEY` is set, the request also declares a `web_search` function
   tool. When Gemini answers with a `functionCall`, the server runs the Tavily
   search, appends the call as a `model` turn and the result as a **`user`** turn
   (`functionResponse`), and calls Gemini a second time. That `user` role is not a
   typo — the current API rejects `function`; see commit `0c102ff`.
4. Response is `{ reply }`, plus `{ sources: [{ title, uri }] }` when a search ran.

Conversation history lives only in the browser's `history` array. Nothing is
stored server-side, there is no session, and Clear wipes it.

## Rules that matter

- **NEVER commit, stage, or push. No exceptions.** Do not run `git add`,
  `git commit`, `git push`, `git restore`, `git reset`, or anything else that
  writes to the index, to history, or to the remote — not even when a change is
  finished, obviously correct, or explicitly "ready". Read-only git (`status`,
  `diff`, `log`, `show`) is fine. Edit the working tree, say what changed, and
  leave every git write to the user. If you think something should be committed,
  say so and stop there.
- **The GitHub repo is PUBLIC** (since 2026-08-25). Anything committed here is
  world-readable and permanent — history included. Treat every commit accordingly.
- **Secrets never enter the repo.** `api/.env` lives only on cPanel; it is
  gitignored, and env vars set on the host take precedence over it. If you add a
  server file holding anything secret, add it to the `FilesMatch` deny block in
  `.htaccess` in the same change.
  A Gemini key was committed once in `ai/api/config.php` (removed in `c34f4ea`,
  still visible at `cad47eb`). That key has been deleted upstream and is dead —
  no action needed, but don't repeat the pattern.
- **Never say Gemini.** The product is "NKT-1 by NikTaRas AI". That's enforced by
  `SYSTEM_PROMPT.txt` and by the UI labels — don't leak the upstream model name
  into user-visible strings or error text.
- **Model output is untrusted HTML.** `renderMarkdown()` escapes with
  `escapeHtml()` *first*, then converts a small whitelisted markdown subset to a
  fixed tag set. Any new markdown feature must keep that order. Never
  `innerHTML` raw model text.
- **`ENDPOINT` is the absolute path** `/ai/api/chat.php`, so the app only works
  when served from the site root — not from inside `ai/`.
- **Errors are JSON.** `fail($status, $message)` on the server; the frontend shows
  `data.error` as an error turn and pops the failed user message back off
  `history` so a retry doesn't duplicate it.

## Running it locally

There is no PHP on this machine, so **the chat cannot be exercised locally** —
`chat.php` only runs on the live host. For UI work, serve the repo root (not this
folder, because of the absolute endpoint path) and expect the send button to fail:

```
py -m http.server 8000     # run from ../ , then open http://localhost:8000/ai/
```

Verify PHP changes by reading them carefully; there is no linter or test suite.

## Conventions

- Frontend JS: one IIFE, `"use strict"`, `var` + function expressions, double
  quotes, 2-space indent. `async/await` is used for `fetch`. Match that style
  rather than modernising it piecemeal.
- PHP: `declare(strict_types=1)`, `snake_case` functions, tunables as `const` at
  the top of the file.
- CSS: custom properties on `:root`, serif body / mono for metadata — a warm paper
  palette. Keep new UI inside that vocabulary.
- Commits: imperative, sentence case, one line (`Fix function response role for
  current Gemini API`).
