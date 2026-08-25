<?php
// Secrets are NOT stored here. They live in api/.env (gitignored, never committed)
// or in real server environment variables, which take precedence.
// See api/.env.example for the expected format.

function niktaras_load_dotenv(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $vars;
}

function niktaras_env(string $name, array $dotenv): string
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return trim($value);
    }
    return trim((string)($dotenv[$name] ?? ''));
}

$dotenv = niktaras_load_dotenv(__DIR__ . '/.env');

return [
    'GEMINI_API_KEY' => niktaras_env('GEMINI_API_KEY', $dotenv),
    // Optional: enables live web search via Tavily (free, no billing
    // required, 1,000 searches/month). Leave blank to disable.
    'TAVILY_API_KEY' => niktaras_env('TAVILY_API_KEY', $dotenv),
];
