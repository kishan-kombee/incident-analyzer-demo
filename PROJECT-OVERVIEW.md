# AI-Powered Incident & Root Cause Analyzer

## Main Goal

**Help engineers find the probable cause of production failures faster** by combining:

- **Logs** (e.g. "12:00 DB timeout", "12:02 DB connection reset")
- **System metrics** (e.g. CPU 85%, DB latency 400 ms, requests/sec High)
- **AI** to suggest possible root causes
- **Backend logic** to rank causes, add confidence, and tie them to metrics

In short: **AI suggests. Backend decides.**  
The system does not blindly trust the AI; it checks suggestions against your data and adjusts confidence and next steps.

---

## What This Project Does

| Step | What happens |
|------|----------------|
| **1. You send data** | You call the API with an array of log lines and a metrics object (CPU, DB latency, requests/sec). |
| **2. Store** | Raw logs and metrics are saved in the database (one incident per request). |
| **3. Preprocess** | Logs are cleaned: duplicates removed, grouped by time window (e.g. 5 min), and tagged with severity (critical / high / medium / low). |
| **4. AI analysis** | Only a **smart subset** of the preprocessed logs (plus a short metric summary) is sent to an AI. The AI is asked: “What is the most likely root cause, and what should we do next?” It returns a structured answer (cause, reasoning, next steps, confidence). |
| **5. Decision layer** | The **backend** takes that answer and: ranks/refines the cause, adjusts **confidence** using metrics (e.g. “DB” cause + high DB latency → stronger signal), and refines **next steps** (e.g. “Check connection pool”, “Review slow queries”). |
| **6. Response** | You get a single, clear result: **likely_cause**, **confidence** (0–1), **next_steps**, and the stored **incident_id**. |

So: you give logs + metrics → the system suggests a probable cause and what to do next, with the backend ensuring the answer is grounded in your data.

---

## What You Need To Do (As a User / Developer)

### 1. Run the application

- Install dependencies, set up `.env`, run migrations (see [TESTING.md](TESTING.md) or README).
- Start the server: `php artisan serve`.

### 2. Call the API

- Send a **POST** request to **`/api/analyze`** with:
  - **logs**: array of strings (your log lines).
  - **metrics**: object with optional `cpu`, `db_latency`, `requests_per_sec`.

Example:

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

### 3. Use the response

- Read **likely_cause** (what probably went wrong), **confidence** (how sure the system is), and **next_steps** (what to do next).
- Use **incident_id** if you want to look up the stored incident and metrics in your database or UI.

### 4. Required: OPENAI_API_KEY for automatic analysis

- **Responses are generated automatically by the AI** from your logs and metrics (no hardcoded causes).
- You **must** set **OPENAI_API_KEY** in `.env` for analysis; without it the API returns **503**. Use OPENAI_URL in .env for a compatible API if needed. “”
---

## Summary

| Question | Answer |
|----------|--------|
| **Main goal?** | Help find probable root causes of production failures by analyzing logs + metrics, using AI plus backend reasoning. |
| **What do I do?** | POST logs and metrics to `/api/analyze`; get back likely cause, confidence, and next steps. |
| **Who decides the final answer?** | The backend (decision layer) decides, using AI only as a suggestion and correlating with your metrics. |

For step-by-step testing (curl, Postman, etc.), see **[TESTING.md](TESTING.md)**.
