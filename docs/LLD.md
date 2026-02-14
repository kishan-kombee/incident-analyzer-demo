# Low-Level Design (LLD) — AI-Powered Incident & Root Cause Analyzer

**Version:** 1.0  
**Based on:** Current codebase (Laravel 12, PHP 8.3)  
**Principle:** *"AI suggests. Backend decides."*

---

## 1. Overview

The system exposes a single analysis API that accepts **logs** and **metrics**, preprocesses logs, sends a smart subset to an AI provider, then applies a **backend decision layer** to produce a single **likely_cause**, **confidence**, and **next_steps**. Results are persisted and returned as JSON.

---

## 2. Architecture

### 2.1 High-Level Flow

```
Client  →  POST /api/analyze  →  EnsureJsonResponse  →  IncidentController::analyze
                                                              ↓
                                                    AnalyzeRequest (validation)
                                                              ↓
                                                    IncidentAnalysisService::analyze
                                                              ↓
         ┌──────────────────────────────────────────────────────────────────────────┐
         │  1. Check at least one AI key (Gemini/Groq/Grok/OpenAI) → else 503        │
         │  2. LogPreprocessor::preprocess(logs)                                      │
         │  3. DB transaction: create Incident + Metric                              │
         │  4. AiAnalysisService::suggestCauses(preprocessed, metrics)                │
         │  5. DecisionLayer::decide(aiResponse, preprocessed, metric)                │
         │  6. Update incident with final likely_cause, confidence, next_steps        │
         │  7. Return { likely_cause, confidence, next_steps, incident_id }           │
         └──────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Component Diagram

| Layer            | Component               | Responsibility |
|------------------|-------------------------|-----------------|
| **HTTP**         | `routes/api.php`        | `POST /analyze` → `IncidentController@analyze` with `EnsureJsonResponse` |
| **HTTP**         | `EnsureJsonResponse`    | Set `Accept: application/json` so responses are always JSON |
| **HTTP**         | `IncidentController`    | Validate request, call service, return JSON; catch `AnalysisUnavailableException` → 503, other → 500 |
| **Validation**   | `AnalyzeRequest`        | `logs`: required array of strings; `metrics`: required array, optional `cpu` (0–100), `db_latency` (≥0), `requests_per_sec` (string, max 64) |
| **Orchestration** | `IncidentAnalysisService` | Check AI key → preprocess → persist incident+metric → AI → decision layer → update incident → return |
| **Preprocessing** | `LogPreprocessor`       | Dedupe, extract time, group by 5‑min window, mark severity (critical/high/medium/low) |
| **AI**           | `AiAnalysisService`     | Build smart context, call configured provider (Gemini/Groq/Grok/OpenAI), parse JSON, ground/sanitize; 429 retry once |
| **Decision**     | `DecisionLayer`        | “No incident” check → log-grounded overrides → else AI + metric correlation and next_steps refinement |
| **Persistence**  | `Incident`, `Metric`    | Store raw logs, preprocessed, final cause/confidence/next_steps; metrics 1:1 per incident |

---

## 3. Data Structures

### 3.1 API Request (POST /api/analyze)

```json
{
  "logs": ["string", "..."],
  "metrics": {
    "cpu": 85,
    "db_latency": 400,
    "requests_per_sec": "High"
  }
}
```

- **logs**: Required array of strings (raw log lines).
- **metrics**: Required object; all fields optional: `cpu` (0–100), `db_latency` (ms, ≥0), `requests_per_sec` (string, e.g. `"High"`).

### 3.2 API Response (Success)

```json
{
  "success": true,
  "data": {
    "likely_cause": "Resource not found (404)",
    "confidence": 0.88,
    "next_steps": "Verify request URL and route; check if the resource or endpoint exists.",
    "incident_id": 39,
    "provider": "groq"
  }
}
```

- **provider**: Normalized from `config('ai.provider')` (set at boot from `env('AI_PROVIDER')`).

### 3.3 Error Responses

- **503** — No AI key set: `success: false`, `message` from `AnalysisUnavailableException`, `data: null`.
- **500** — Any other exception: `success: false`, `message: "Analysis failed. Please try again."`, `data: null`.
- **422** — Validation failure (Laravel Form Request).

---

## 4. Database Schema

### 4.1 Table: `incidents`

| Column         | Type           | Description |
|----------------|----------------|-------------|
| id             | bigint, PK     | Auto-increment |
| logs           | json           | Raw log lines from request |
| preprocessed   | json, nullable | Output of LogPreprocessor (entries + summary) |
| likely_cause   | string, nullable | Final cause from decision layer |
| confidence    | decimal(5,4), nullable | 0–1 |
| next_steps     | text, nullable | Final recommended steps |
| created_at, updated_at | timestamp | Laravel timestamps |

### 4.2 Table: `metrics`

| Column         | Type           | Description |
|----------------|----------------|-------------|
| id             | bigint, PK     | Auto-increment |
| incident_id    | FK → incidents | Cascade on delete |
| cpu            | unsigned tinyint, nullable | 0–100 |
| db_latency     | unsigned int, nullable | ms |
| requests_per_sec | string(64), nullable | e.g. "High", "100" |
| created_at, updated_at | timestamp | Laravel timestamps |

**Relations:** `Incident` hasOne `Metric`; `Metric` belongsTo `Incident`.

---

## 5. Module Design

### 5.1 LogPreprocessor

**Input:** `array<int, string>` (raw log lines).

**Output:**

```php
[
    'entries' => [
        ['raw' => string, 'time' => string|null, 'window' => string, 'severity' => 'critical'|'high'|'medium'|'low'],
        ...
    ],
    'summary' => [
        'total_original' => int,
        'after_dedup' => int,
        'windows' => int,
    ],
]
```

**Steps (internal):**

1. **normalizeAndDedupe:** Trim each line; drop empty; keep first occurrence of each trimmed line.
2. **extractTime:** Per line, optional regex match for `HH:MM` or `HH:MM:SS` → `time` field.
3. **groupByTimeWindow:** Bucket by 5‑minute window (`TIME_WINDOW_SECONDS = 300`); label `window_N`.
4. **markSeverity:** Keyword-based (case-insensitive): critical (fatal, panic, critical, outage), high (error, exception, timeout, reset, failed, refused), medium (warn, warning, degraded, slow), low (info, debug). Highest match wins.

---

### 5.2 AiAnalysisService

**Role:** Build context, call one AI provider, return structured suggestion.

**Provider selection (order):** From `config('ai.provider')` (gemini | groq | grok | openai). If that provider’s key is set, use it; else return `serviceUnavailableResponse(...)`.

**Constants:**

- `MAX_LOG_LINES = 50`
- `MAX_LOG_CHARS = 4000`

**Context building (`buildSmartContext`):**

- Group entries by severity; order: critical → high → medium → low; take up to 15 medium, 10 low; cap at `MAX_LOG_LINES`.
- Build log block as `[window] severity: raw` per line; stop when total length would exceed `MAX_LOG_CHARS`.
- Return `log_lines`, `metrics` (summary), `summary` (preprocessed summary).

**Prompt:**

- **System:** Analyst role; respond with JSON only: `likely_cause`, `reasoning`, `next_steps`, `confidence` (0–1); base only on evidence.
- **User:** Cleaned logs + metrics line + “Suggest probable root cause… JSON only.”

**Provider calls:**

- **Gemini:** POST to `GEMINI_URL` (v1beta) with `key` query; payload `contents` + `generationConfig.temperature=0.2`. Parse from `candidates[0].content.parts[0].text` via `parseJsonFromText`.
- **Groq / Grok / OpenAI:** OpenAI-compatible: `messages` (system + user), `temperature=0.2`; parse `choices[0].message.content` (Groq/OpenAI JSON decode; Grok/Gemini use `parseJsonFromText`).

**Retry:** On HTTP 429, sleep 3s and retry once.

**Post-processing:** `groundAndSanitize(parsed, preprocessed)`: trim cause/reasoning/next_steps; clamp confidence 0–1; if cause words (length > 2) have no overlap with log text (or DB-related synonyms), reduce confidence by 0.2 (floor 0.3).

**Service unavailable:** Returns array with `likely_cause`, `reasoning`, `next_steps`, `confidence=0`; provider-specific messages for 429, 401, 400, 403, 404, quota, invalid key.

---

### 5.3 DecisionLayer

**Role:** Produce final `likely_cause`, `confidence`, `next_steps`. Log evidence takes precedence; metrics used to correlate and refine.

**Main flow (`decide`):**

1. **No incident:** Call `logsDescribeIncident(entries)`. If false → return `likely_cause: "No clear incident from logs"`, `confidence: 0.25`, `next_steps`: ask for error/incident logs.
2. **Log-grounded override:** `getLogGroundedCause(entries)` returns first matching signal (priority order):
   - 404 / not found → "Resource not found (404)", verify URL/route.
   - 500 / internal server error → "Internal server error (500)", check app logs.
   - timeout + (db|database|connection) → "Database timeout or overload", connection pool, slow queries.
   - connection refused → "Connection refused (service unreachable)".
   - connection reset → "Database or network connection instability".
   - timeout (generic) → "Service timeout".
   If log-grounded is not null and AI cause does not match that signal (`causeMatchesLogSignal`) → use log-grounded cause and next_steps; confidence 0.85–0.98 (slight boost if db_latency_high or cpu_high).
3. **Otherwise:** Use AI cause; `adjustConfidence(cause, baseConfidence, signals, preprocessed)`; `refineNextSteps(cause, suggestedSteps, signals)`.

**logsDescribeIncident:** Returns false if combined log text is empty, or looks like a question (trailing `?`, phrases like “what is”, “how to”, “current version”, “can you”, or (what|how|which|why|when|where) + (is|are|do|does|did)). Returns true if any of a fixed list of incident keywords appears (error, exception, fail, 404, 500, timeout, crash, refused, reset, warning, not found, etc.).

**causeMatchesLogSignal:** For each logSignal (404, 500, db_timeout, connection_refused, connection_reset, timeout), checks if AI cause string contains expected keywords so we don’t override when AI already agrees.

**Metric signals:** `db_latency_high` = db_latency > 300; `cpu_high` = cpu ≥ 80; `requests_high` = requests_per_sec in ['high','very high','spike'] (case-insensitive).

**adjustConfidence:** Base from AI; add boost per `METRIC_CORRELATIONS` (e.g. cause contains “database” and db_latency_high → +0.08); add 0.05 per high/critical severity entry (cap 3). Clamp 0–1.

**refineNextSteps:** Add hints from cause + signals (e.g. DB cause + db_latency_high → “Check connection pool…”, “Review slow queries…”); merge with AI suggested steps; deduplicate sentences; fallback “Review logs and metrics.”

---

## 6. Configuration & Environment

### 6.1 Config (`config/services.php`)

- **openai:** `key`, `model`, `url`
- **gemini:** `key`, `model`, `url` (with `%s` for model)
- **grok:** `key`, `model`, `url`
- **groq:** `key`, `model`, `url`
- **ai:** `provider` (from `AI_PROVIDER`, default `gemini`)

### 6.2 AppServiceProvider

In `boot()`: sets `Config::set('ai.provider', env('AI_PROVIDER'))` so runtime provider always follows `.env` even if config is cached.

### 6.3 Required for analysis

At least one of: `GEMINI_API_KEY`, `GROQ_API_KEY`, `GROK_API_KEY`, `OPENAI_API_KEY`. Provider chosen by `AI_PROVIDER`.

---

## 7. Exception Handling

- **AnalysisUnavailableException:** Thrown when no AI key is set. Caught in controller → 503, message in body.
- **Any other Throwable:** Reported via `report($e)`; controller returns 500 with generic message; no internal details in response.

---

## 8. File Reference

| Purpose              | Path |
|----------------------|------|
| API route            | `routes/api.php` |
| Controller           | `app/Http/Controllers/IncidentController.php` |
| Request validation   | `app/Http/Requests/AnalyzeRequest.php` |
| Middleware           | `app/Http/Middleware/EnsureJsonResponse.php` |
| Orchestration        | `app/Services/IncidentAnalysisService.php` |
| Preprocessing        | `app/Services/LogPreprocessor.php` |
| AI analysis          | `app/Services/AiAnalysisService.php` |
| Decision layer       | `app/Services/DecisionLayer.php` |
| Models               | `app/Models/Incident.php`, `app/Models/Metric.php` |
| Custom exception     | `app/Exceptions/AnalysisUnavailableException.php` |
| Config               | `config/services.php` |
| Provider boot        | `app/Providers/AppServiceProvider.php` |
| Migrations           | `database/migrations/*_create_incidents_table.php`, `*_create_metrics_table.php`, `*_add_analyzer_columns_*.php` |

---

## 9. Sequence Summary

1. Client sends POST with `logs` and `metrics`.
2. Middleware forces JSON acceptance.
3. AnalyzeRequest validates structure and ranges.
4. IncidentAnalysisService ensures an AI key exists.
5. LogPreprocessor dedupes, groups by time, assigns severity.
6. Incident and Metric created in one transaction.
7. AiAnalysisService builds context, calls provider, parses and grounds response.
8. DecisionLayer: if logs don’t describe an incident → “No clear incident”; else if strong log signal and AI cause doesn’t match → use log-grounded cause/steps; else use AI + metric correlation and refine next_steps.
9. Incident updated with final cause, confidence, next_steps.
10. Controller returns JSON with `data` including `provider`.

This LLD reflects the current implementation and can be updated when components or flows change.
