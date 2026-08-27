# NKT-1

A lightweight chat interface for NKT-1, a model developed by the NikTaras AI division of NikTaras Web Studio, with optional live web search.

**Live:** https://ai.niktaras.com/NKT-1/

## Features

- Single-file frontend (`index.html`) — no build step, no framework, no dependencies.
- PHP backend proxy (`api/chat.php`) that talks to the Gemini API under the hood, keeping the API key server-side.
- Optional live web search via [Tavily](https://tavily.com) (free tier, 1,000 searches/month), toggled per-message with the "Web Access" button next to the prompt input and wired up through Gemini function calling.
- Markdown rendering and source citations in the chat UI.

## Project structure

```
.
├── index.html            # Frontend: markup, styles, and JS in one file
├── og-image.JPG           # Social preview image
├── .htaccess              # Blocks direct access to secrets/config over HTTP
└── api/
    ├── chat.php            # Backend endpoint the frontend calls
    ├── config.php           # Loads secrets from .env or server env vars
    ├── .env.example          # Template for required environment variables
    └── SYSTEM_PROMPT.txt     # System prompt sent to Gemini
```

## Setup

Requires PHP with the `curl` and `mbstring` extensions.

1. Clone the repo into your web server's document root (or a subdirectory of it).
2. Copy `api/.env.example` to `api/.env` and fill in your keys:
   ```
   GEMINI_API_KEY=your-gemini-api-key-here
   TAVILY_API_KEY=your-tavily-api-key-here   # optional, enables web search
   ```
   `api/.env` is never committed — see `.htaccess`, which also blocks direct HTTP access to it.
3. Serve the directory with Apache (or any server that honors `.htaccess` and runs PHP). Open `index.html` in a browser.

### Deploying to a subdirectory

The frontend calls the backend with a page-relative path (`api/chat.php`), so it works from whatever subdirectory you deploy it to — no code changes needed. The Open Graph tags in `index.html` (`og:image`, `og:url`) are absolute and should be updated if you deploy somewhere other than `https://ai.niktaras.com/NKT-1/`.

## How it works

1. The browser posts the conversation history to `api/chat.php`, along with whether "Web Access" is toggled on.
2. `chat.php` forwards it to the Gemini API (`gemini-3.5-flash-lite`) along with the system prompt from `api/SYSTEM_PROMPT.txt`, which instructs the model to present itself as NKT-1 rather than as Gemini.
3. If Web Access is on (and a Tavily key is configured) and the model requests a search, `chat.php` calls Tavily, feeds the results back to the model, and returns the final answer with cited sources.
