# AI-Powered Incident & Root Cause Analyzer

<p align="center">
  <a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="320" alt="Laravel"></a>
</p>

API that analyzes **logs** and **system metrics** and returns a probable root cause, confidence score, and recommended next steps—using AI plus backend decision logic. **AI suggests. Backend decides.**

---

## Quick links

| Document | Description |
|----------|-------------|
| **[Project overview](PROJECT-OVERVIEW.md)** | Goal, flow, and how to use the API as a developer. |
| **[Low-Level Design (LLD)](docs/LLD.md)** | Architecture, data structures, modules, and file reference. |
| **[Postman collection](postman/Incident-Analyzer-API.postman_collection.json)** | Ready-to-import requests for `POST /api/analyze` (success, minimal metrics, validation). |

---

## API

**Endpoint:** `POST /api/analyze`

Submit an array of log lines and a metrics object; get back a single **likely_cause**, **confidence** (0–1), **next_steps**, and **incident_id**.

### Request

```json
{
  "logs": [
    "12:00 DB timeout",
    "12:01 DB timeout",
    "12:02 DB connection reset"
  ],
  "metrics": {
    "cpu": 85,
    "db_latency": 400,
    "requests_per_sec": "High"
  }
}
```

| Field | Type | Required | Notes |
|-------|------|----------|--------|
| `logs` | array of strings | Yes | Raw log lines. |
| `metrics` | object | Yes | Can be `{}`. |
| `metrics.cpu` | integer | No | 0–100. |
| `metrics.db_latency` | integer | No | Milliseconds, ≥ 0. |
| `metrics.requests_per_sec` | string | No | e.g. `"High"`, `"100"`. |

### Response (success)

```json
{
  "success": true,
  "data": {
    "likely_cause": "Database timeout or overload",
    "confidence": 0.88,
    "next_steps": "Check connection pool and DB health. Review slow queries and indexes.",
    "incident_id": 1,
    "provider": "groq"
  }
}
```

- **provider** — AI provider used: `gemini`, `groq`, `grok`, or `openai` (from `AI_PROVIDER`).

### Errors

- **503** — No AI API key configured. Set one of `GEMINI_API_KEY`, `GROQ_API_KEY`, `GROK_API_KEY`, `OPENAI_API_KEY` in `.env`.
- **500** — Analysis failed (see `storage/logs/laravel.log`).
- **422** — Validation failed (e.g. invalid `logs` or `metrics`).

---

## Flow (high level)

1. **Intake** — Raw logs and metrics stored in DB (one incident per request).
2. **Preprocessing** — Dedupe, group by 5‑min time window, assign severity (critical/high/medium/low).
3. **AI analysis** — Smart subset of logs + metrics sent to the configured LLM; returns cause, reasoning, next steps, confidence (JSON).
4. **Decision layer** — Backend grounds result in log evidence, overrides when logs clearly indicate a cause (e.g. 404), adjusts confidence with metric correlation, refines next steps.
5. **Response** — Final `likely_cause`, `confidence`, `next_steps` returned and persisted.

Details: [Project overview](PROJECT-OVERVIEW.md) · [LLD](docs/LLD.md).

---

## Setup

1. **Install and env**
   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   ```
2. **Database** — Set DB_* in `.env` (default SQLite). Run:
   ```bash
   php artisan migrate
   ```
3. **AI provider (required for analysis)**  
   Set **one** of the following in `.env` and optionally set `AI_PROVIDER`:

   | Provider | Env vars | Free tier |
   |----------|-----------|-----------|
   | **Gemini** | `GEMINI_API_KEY`, `AI_PROVIDER=gemini` | [Get key](https://aistudio.google.com/app/apikey) |
   | **Groq** | `GROQ_API_KEY`, `AI_PROVIDER=groq` | [Get key](https://console.groq.com/keys) |
   | **Grok (xAI)** | `GROK_API_KEY`, `AI_PROVIDER=grok` | [Get key](https://console.x.ai/team/default/api-keys) |
   | **OpenAI** | `OPENAI_API_KEY`, `AI_PROVIDER=openai` | Paid |

   Without any key, `POST /api/analyze` returns **503**.

---

## Run

```bash
php artisan serve
```

Then call `POST http://localhost:8000/api/analyze` with a JSON body (see **Request** above), or import the [Postman collection](postman/Incident-Analyzer-API.postman_collection.json) and use the `baseUrl` variable (default `http://127.0.0.1:8000`).

---

## Tech stack

- **Laravel** 12.x, PHP 8.3+
- **AI:** Gemini, Groq, Grok, or OpenAI (configurable via `AI_PROVIDER`)
- **Database:** MySQL / PostgreSQL / SQLite (Laravel default)

---

## Documentation

- **[PROJECT-OVERVIEW.md](PROJECT-OVERVIEW.md)** — What the project does and how to use it.
- **[docs/LLD.md](docs/LLD.md)** — Low-level design: components, schema, modules, config.
- **[postman/Incident-Analyzer-API.postman_collection.json](postman/Incident-Analyzer-API.postman_collection.json)** — Postman collection for the API.

---

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT). Laravel is licensed under the [MIT license](https://opensource.org/licenses/MIT) as well.
