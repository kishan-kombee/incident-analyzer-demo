# How to Test the Incident Analyzer API

Follow these steps to run and test the **POST /api/analyze** endpoint.

---

## Step 1: Environment

1. **Copy env and generate key (if not done):**
   ```bash
   copy .env.example .env
   php artisan key:generate
   ```

2. **Database:**  
   Default is SQLite. Ensure `database/database.sqlite` exists:
   ```bash
   type nul > database\database.sqlite
   ```
   Or use MySQL in `.env`:
   ```
   DB_CONNECTION=mysql
   DB_DATABASE=incident_analyzer
   DB_USERNAME=root
   DB_PASSWORD=
   ```

3. **Run migrations:**
   ```bash
   php artisan migrate
   ```

4. **(Optional) OpenAI:**  
   For AI analysis, set in `.env`:
   ```
   OPENAI_API_KEY=sk-your-key-here
   ```
   If you leave it empty, the app uses **rule-based fallback** (no API key needed).

---

## Step 2: Start the server

From the project root:

```bash
php artisan serve
```

You should see:

```
INFO  Server running on [http://127.0.0.1:8000]
```

Keep this terminal open.

---

## Step 3: Call the API

### Option A — cURL (PowerShell or Git Bash)

**PowerShell:**

```powershell
$body = @{
  logs = @(
    "12:00 DB timeout",
    "12:01 DB timeout",
    "12:02 DB connection reset"
  )
  metrics = @{
    cpu = 85
    db_latency = 400
    requests_per_sec = "High"
  }
} | ConvertTo-Json -Depth 4

Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/analyze" -Method Post -Body $body -ContentType "application/json"
```

**Git Bash / Linux / WSL (curl):**

```bash
curl -X POST http://127.0.0.1:8000/api/analyze \
  -H "Content-Type: application/json" \
  -d "{\"logs\":[\"12:00 DB timeout\",\"12:01 DB timeout\",\"12:02 DB connection reset\"],\"metrics\":{\"cpu\":85,\"db_latency\":400,\"requests_per_sec\":\"High\"}}"
```

### Option B — Postman / Insomnia

1. **Method:** `POST`
2. **URL:** `http://127.0.0.1:8000/api/analyze`
3. **Headers:**  
   - `Content-Type`: `application/json`
4. **Body (raw JSON):**

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

5. Send the request.

### Option C — PHP artisan (quick check)

```bash
php artisan tinker
```

Then in Tinker:

```php
$r = \Illuminate\Support\Facades\Http::post('http://127.0.0.1:8000/api/analyze', [
    'logs' => ['12:00 DB timeout', '12:01 DB timeout', '12:02 DB connection reset'],
    'metrics' => ['cpu' => 85, 'db_latency' => 400, 'requests_per_sec' => 'High'],
]);
$r->json();
```

---

## Step 4: Expected response

**Success (200):**

```json
{
  "success": true,
  "data": {
    "likely_cause": "Database timeout or overload",
    "confidence": 0.98,
    "next_steps": "Check connection pool and DB health. Review slow queries and indexes. ...",
    "incident_id": 1
  }
}
```

- **likely_cause** — Most probable root cause.
- **confidence** — 0–1 (backend can adjust it using metrics).
- **next_steps** — Suggested actions.
- **incident_id** — Stored incident row (logs + metrics saved in DB).

---

## Step 5: Validation errors (optional check)

Send invalid payload to see validation:

**Missing `logs`:**
```bash
curl -X POST http://127.0.0.1:8000/api/analyze -H "Content-Type: application/json" -d "{\"metrics\":{\"cpu\":50}}"
```

You should get **422** with messages about `logs` (and optionally `metrics`).

---

## Step 6: Verify data in DB (optional)

```bash
php artisan tinker
```

```php
\App\Models\Incident::with('metric')->latest()->first();
```

You should see the last incident with its logs and metric (cpu, db_latency, etc.).

---

## Quick checklist

| Step | Action |
|------|--------|
| 1 | `.env` exists, `php artisan key:generate` run |
| 2 | DB configured; `php artisan migrate` run |
| 3 | `php artisan serve` running |
| 4 | POST to `http://127.0.0.1:8000/api/analyze` with JSON body |
| 5 | Response has `success: true` and `data.likely_cause`, `data.confidence`, `data.next_steps`, `data.incident_id` |

If anything fails, check `storage/logs/laravel.log` for errors.
