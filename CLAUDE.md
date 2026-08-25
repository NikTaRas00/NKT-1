# NKT-1

A single-page AI chat interface, deployed at `https://ai.niktaras.com/NKT-1/`.

## Structure

- `index.html` — the entire frontend: markup, styles, and JS in one file. No build step, no framework.
- `api/chat.php` — backend endpoint the frontend calls. Proxies chat requests to the Gemini API (`gemini-3.5-flash-lite`), and optionally calls Tavily for live web search via Gemini function calling.
- `api/config.php` — loads secrets from `api/.env` (gitignored) or real server env vars. Never put secrets directly in this file.
- `api/.env.example` — documents the expected `.env` keys (`GEMINI_API_KEY`, `TAVILY_API_KEY`).
- `api/SYSTEM_PROMPT.txt` — system prompt sent to Gemini.
- `.htaccess` — blocks direct HTTP access to `.env*`, `config.php`, and `SYSTEM_PROMPT.txt`; disables directory listing.

## Key facts

- The frontend calls the backend via a **page-relative** path: `var ENDPOINT = "api/chat.php";` in `index.html`. Keep it relative — do not hardcode a leading-slash absolute path (e.g. `/ai/api/chat.php`), since that breaks whenever the deployed subdirectory or domain changes. The live deploy path is `/NKT-1/`, not `/ai/`.
- `og:image` and `og:url` meta tags in `index.html` are necessarily absolute (`https://ai.niktaras.com/NKT-1/...`) since social scrapers don't resolve relative URLs.
- No package.json, no build tooling — edits to `index.html` and `api/*.php` are deployed as-is.

## Git workflow

**Never stage, commit, or push on your own initiative.** The user commits and pushes manually. Leave finished changes in the working tree and describe what changed; don't run `git add`/`git commit`/`git push` unless explicitly asked to in that specific turn.
