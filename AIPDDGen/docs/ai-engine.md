# AI Engine (providers, encrypted key, drafting skill, draft generation)

**Status:** MVP implemented (task-15). Admin page `/admin/ai`; workspace action
"Generate draft" on the project page; read-only draft page at `/drafts/{id}`.

This document covers the AI Engine slice: how the platform stores the LLM
provider configuration, how the admin-managed **SKILL** is used, and how a
draft is generated from an approved fact sheet against a published registry
template. It is the companion to [data-model](data-model.md),
[decisions](decisions.md), [dashboards](dashboards.md) and [PRD](PRD.md) F5/F13.

---

## 1. What it does

The product admin (platform staff) configures one **OpenAI-compatible
chat-completions endpoint** — base URL, model and API key — plus a free-text
drafting **skill** per registry (Gold Standard, Verra VCS) and optional global
instructions. Workspace editors then press **Generate draft** on a project; the
engine drafts the project's PDD section by section, citing confirmed fact ids,
and marks anything the facts cannot support as **needs input**.

Nothing is hard-coded per provider: OpenAI, Claude (via Anthropic's OpenAI-compatible
layer), DeepSeek, OpenRouter, Ollama and any
other OpenAI-compatible gateway use the same code path and only differ in base
URL/model/key.

### Where it lives

| Layer | File |
| --- | --- |
| Schema (singleton settings row) | `migrations/0009_ai_engine.sql` |
| Deployment secrets/config | `config/config.php` → `ai_engine.*`, `.env.example` |
| Settings + encryption + test connection | `src/Services/AiEngineService.php` |
| OpenAI-compatible HTTP client | `src/Services/LlmClient.php` |
| Guards, prompts, generation, draft view | `src/Services/DraftService.php` |
| Staff console controller | `src/Controllers/AiEngineController.php` |
| Workspace generate/view controller | `src/Controllers/DraftController.php` |
| Routes | `src/routes.php` (`/admin/ai`, `/admin/ai/test`, `/projects/{id}/draft/generate`, `/drafts/{id}`) |
| Admin page | `templates/pages/admin/ai.php` |
| Draft page | `templates/pages/draft.php` |
| Project page action | `templates/pages/project.php` |
| Tests (offline) | `tests/ai_engine_test.php` |

Request flow (staff): `GET /admin/ai` → staff guard → `AiEngineService::publicSettings()`
(a masked view only) → `templates/pages/admin/ai.php`. Save and Test connection
are POSTs with CSRF enforced by `public/index.php`.

Request flow (workspace): project page → `DraftService::uiState()` (read-only
readiness + latest draft) → POST `/projects/{id}/draft/generate` →
`DraftService::generate()` → 303 to `/drafts/{id}`.

---

## 2. Environment and secrets

| Variable | Required | Purpose |
| --- | --- | --- |
| `AI_ENGINE_SECRET` | Yes, before a key can be stored | AES-256-GCM key-encryption secret. Generate with `php -r "echo base64_encode(random_bytes(32));"`. Keep it in `.env.local` (gitignored) or the server environment. **Never commit it.** |
| `AI_ENGINE_API_KEY` | Optional | Server-side fallback used **only while no key is stored through the console**. Useful for self-hosted deployments; the normal workflow is the console. |
| `AI_ENGINE_TIMEOUT` | Optional (default 60) | HTTP timeout in seconds for one chat-completions request. |

- The provider base URL, model, temperature, token cap and skill live in the
  database (`ai_engine_settings`, singleton row `id = 1`), so staff change them
  without a deploy.
- If `AI_ENGINE_SECRET` is missing, `/admin/ai` shows an explicit warning and
  refuses to store a key. No silent fallback to a repository default.
- If the secret changes, previously stored ciphertext can no longer be
  decrypted: the console then reports "not configured" and staff must re-enter
  the key. This is intentional.

### Key storage

- Encryption: `openssl_encrypt(..., 'aes-256-gcm', hash('sha256', secret, true), OPENSSL_RAW_DATA, nonce)`.
- Stored value: `v1:` + base64(`12-byte nonce | 16-byte tag | ciphertext`).
- Only `api_key_ciphertext` and `api_key_last4` are stored. The plaintext key
  never appears in the database, session, HTML, audit metadata or logs.
- The console field is **write-only**: a new key can be pasted (or the stored
  key removed); the page itself only ever renders `••••••••` + last four.
- Test: `tests/ai_engine_test.php` asserts the ciphertext is versioned, does not
  contain the plaintext, decrypts only with the same secret, and that neither the
  public settings payload nor the audit trail contains the key.

---

## 3. Admin workflow (`/admin/ai`, staff only)

1. **Configure the provider.** Pick a preset (it fills the suggested API root),
   then set the base URL and model. The base URL is the API root only; the
   client appends `/chat/completions`.
2. **Paste the API key.** It is validated (max length) and encrypted
   immediately. Leave the field blank to keep the stored key.
3. **Write the SKILL.** Free-text rules per registry plus optional global
   instructions. These are injected into the system prompt and are treated as
   admin-managed, trusted guidance. The hard safety rules are always appended
   *after* the skill, so a skill cannot override the no-invention rules.
4. **Enable the engine.** Enabling is refused unless a key is available (stored
   or env fallback) — that avoids turning on a configuration that would fail on
   the first draft.
5. **Test connection** (optional). This is the only staff action that makes a
   real provider request outside drafting. Errors are shown as translated,
   safe messages; response bodies are never echoed.
6. **Remove / rotate the key** with the "Remove the stored API key" checkbox.

Status badges on the page:

| Badge | Meaning |
| --- | --- |
| **Not configured** | No key available. The honest default for a new deployment; drafting is refused. |
| **Disabled** | A key is available but the engine is switched off. Drafting is refused. |
| **Ready** | Enabled with a usable key. Editors can generate drafts. |

Audit actions written by this page (in `audit_logs`, platform-level, never
containing the key):

| Action | When |
| --- | --- |
| `ai_engine.settings_saved` | Every successful save (metadata: provider, model, enabled, `key_set`, `key_cleared`, which skills are set) |
| `ai_engine.connection_tested` | Test connection, success and failure (`ok`, `error` code) |

Tenant accounts receive **404** on `/admin/ai` (the existing staff guard), like
every other console page.

---

## 4. Provider examples

The client sends `POST {base_url}/chat/completions` with
`Authorization: Bearer <key>`, the model, messages, temperature and max_tokens —
the standard OpenAI shape. Presets are labels only; the base URL and model
always come from the console.

| Provider | Base URL | Model example | Notes |
| --- | --- | --- | --- |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` | Standard OpenAI key. |
| Claude (Anthropic) | `https://api.anthropic.com/v1` | a current Claude model name, e.g. `claude-sonnet-4-5` | Anthropic's **OpenAI SDK compatibility layer**: same chat-completions shape, Anthropic hoists `system` messages and accepts `temperature` only in 0–1 (drafting defaults to 0.2, so leave it ≤1). Anthropic documents the layer for testing/comparison rather than production; use OpenRouter (`anthropic/claude-*`) for a production gateway if preferred — both work here. Model names come from Anthropic's model catalogue; check the console before saving. |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` | OpenAI-compatible. |
| OpenRouter | `https://openrouter.ai/api/v1` | `openai/gpt-4o-mini` | Model names include the vendor prefix (e.g. `anthropic/claude-sonnet-4.5`). |
| Ollama (local) | `http://127.0.0.1:11434/v1` | `llama3.1` | Local model; any non-empty key works; no data leaves the machine. |
| Other gateway | operator-provided root | operator-provided | Any endpoint that implements the OpenAI chat-completions contract. |

HTTP behaviour: connect timeout 10 s, request timeout `AI_ENGINE_TIMEOUT`
(default 60 s), one retry on a 5xx or a network failure, no redirect following.
Some current models reject the optional `temperature` parameter (Anthropic's
newest Claude models answer HTTP 400 "`temperature` is deprecated"): the client
retries once without temperature and keeps omitting it for that client instance,
so one generic configuration works across models. Responses are validated
(`choices[0].message.content`); anything else becomes a structured, user-safe
error. The client is injectable, so tests use a mocked transport and never touch
the network.

---

## 5. Drafting: guards, prompts and citations

### Guards (in order)

`DraftService::generate($actorUserId, $projectId, ?LlmClient $client = null)`
enforces, in this order:

1. **Tenant + role** — `Tenancy::projectForUser(..., 'editor')`. Another
   workspace's project is a **404**; a viewer is **403**. The project page
   disables the button for viewers.
2. **Registry selected** — a project with no registry cannot be drafted
   (`error.ai_engine_registry_missing`).
3. **Published template** — there must be a `template_versions` row with
   `status = 'published'` for the project's registry and a `pdd_template`
   template. The project's recorded version is used when published, otherwise
   the newest published one. The seeded Gold Standard v1.4 structure is
   **draft**, so generation is refused with
   `error.ai_engine_no_published_template` until the step-2 publishing flow
   exists. Verra is not seeded at all yet.
4. **Approved fact sheet** — the newest `fact_sheet_versions` row with
   `status = 'approved'` for the project. Without one:
   `error.ai_engine_no_approved_fact_sheet` ("approve a fact sheet first").
5. **Engine enabled + key** — `enabled = 1` and a usable key (stored or env
   fallback), otherwise `error.ai_engine_disabled` /
   `error.ai_engine_not_configured`.

Every refusal is an `AppError` with a translated message (EN + FR); the POST
redirects back to the project with a flash message — never a 500.

### Prompt construction

Per template section (ordered by `template_sections.ordinal`):

- **System prompt**: role, project language, the admin global instructions, the
  registry skill, then the non-negotiable safety rules and the strict JSON
  output contract.
- **User prompt**: project name/registry/country/type/language, the section
  (`stable_key`, heading, level, required flag, `guidance`, `format_rules` JSON,
  `template_fields` guidance), the **confirmed facts with ids** (only
  `confirmed`/`corrected`; never suggested/rejected), and parsed input excerpts
  as context. Template rules stay data — no prompt text is hard-coded per
  template.
- The model must answer with one JSON object:
  `{"status":"generated","content":"…","citations":["<fact-id>"]}` or
  `{"status":"needs_input","reason":"…"}`. Markdown fences are tolerated and
  stripped.

### Post-processing (safety enforcement)

The answer is accepted as **generated** only when all of these hold; otherwise
the section is stored as **needs input**:

| Check | Failure reason stored |
| --- | --- |
| Answer is a readable JSON object | `invalid_json` |
| Status is `generated` and content is non-empty | `empty_content` |
| It cites at least one fact id that was actually provided | `missing_citation` |
| Every number in the text exists in the facts/inputs/template corpus (commas removed, trailing zeros normalised, list markers ignored) | `unsupported_number` |

When a project has **no confirmed facts and no parsed input text**, the service
does not call the model at all: every section is `needs_input` with reason
`no_facts`. This is the "do not invent facts" rule made structural.

### Persistence

One transaction writes:

- `drafts` — `template_version_id`, `fact_sheet_version_id`, language, status
  `draft`, project-scoped `version`, **`is_labeled_draft = 'draft'`**,
  creator;
- `draft_sections` — one row per template section: heading, `content_text` +
  `content_json`, status `generated`/`needs_input`, citations JSON
  (`factId`, `factKey`, `label`), `generated_at`;
- `draft_versions` — snapshot of the generated draft (sections, model,
  versions, timestamp), version 1;
- the project moves to `drafting` when it had not progressed further;
- audit `draft.generated` (section counts, model, version ids — never the key or
  prompt contents).

The draft page (`/drafts/{id}`) shows the labelled-draft banner, per-section
status, citations (labels + fact keys), needs-input reasons and the template /
fact-sheet versions used. It is read-only in this MVP; inline editing, per
section regeneration and comments are later PRD steps (F7).

---

## 6. Safety and privacy rules

- **No invented facts or numbers**: confirmed facts only; numeric post-check;
  unsupported → needs input.
- **Every generated section cites a confirmed fact id**; uncited answers are
  rejected.
- **No drafting before the fact sheet is approved**, and no drafting against an
  unpublished (unlicensed/unverified) template version.
- **AI output is always a draft**: `drafts.is_labeled_draft` stays `draft`;
  the draft page carries a prominent "expert review" banner.
- **Secrets**: the key is encrypted at rest, write-only in the UI, never
  returned, logged or rendered; only last four characters are shown.
- **Staff only**: `/admin/ai` returns 404 to tenant accounts; tenant data never
  reaches the platform settings.
- **Tenant isolation**: the generator resolves the project through `Tenancy`
  and writes `team_id`-scoped rows; another tenant's project is a 404.
- **Template library boundary**: skills/instruments are admin text; customer
  project facts and uploads never write into the template library.
- **No model training**: the provider is called with a single request per
  section; nothing is persisted provider-side by this application beyond the
  provider's own terms.

---

## 7. Verification

```powershell
php bin/migrate.php                 # applies 0009_ai_engine.sql (idempotent)
php -l src/Services/AiEngineService.php
php bin/test.php ai_engine          # 17 tests, offline (mocked LLM transport)
php bin/test.php unit_i18n          # EN/FR parity
```

HTTP checks (dev server on port 8000): staff `GET /admin/ai` → 200 with the
configuration form; tenant account → 404; a demo project with no registry (or no
published template / no approved fact sheet) → clear flash refusal, never a 500.

The tests cover: settings save/encrypt/mask, key never exposed, encryption
round-trip and secret change, env fallback precedence, clear key, LLM retry and
structured errors, staff guard on settings, every generation refusal, the happy
path (citations, snapshot, audit, labelled draft, project status), needs-input
when facts are missing, unsupported-number and missing-citation rejection, editor
role requirement, cross-tenant 404s and draft version increments.

---

## 8. Limitations / deferred work

| Deferred | Notes |
| --- | --- |
| **Fact-sheet confirmation UI** | Generation requires an approved `fact_sheet_versions` row; the demo seed provides one. A dedicated fact-sheet review/approval UI is the next task. Until it exists real projects cannot reach "approved" through the UI. |
| **Template publishing UI** | The seeded Gold Standard v1.4 structure stays `status = draft`; the publish/retire flow is a step-2 admin task. Until a version is published, generation is refused with that clear message (by design, not a bug). |
| **Verra VCS template** | Not seeded (no official template/permission yet — see [open questions](open-questions.md)). The per-registry skill field is ready; generation is blocked until a published Verra `pdd_template` exists. |
| **Synchronous generation** | No queue/worker: one section = one HTTP call, executed during the POST. A long draft can take a while; the provider timeout and one retry bound each call. An async job is the later step 4/7 work. |
| **No per-section regenerate / edit / comment** | Draft page is read-only; editor, comments and per-section regeneration are PRD F7, step 8. |
| **No streaming, token accounting or cost display** | Not in the MVP. |
| **No RAG/embeddings** | The prompt uses confirmed facts + stored input excerpts; lexical retrieval/complete fact extraction arrive with steps 3–4. |
| **Single provider config** | One global engine for the whole deployment (no per-workspace BYOK). |
| **Azure-specific parameters** | `api-version` / deployment-name style endpoints are not modelled; use the OpenAI-compatible base URL. |
| **French output** | French drafts are working drafts; the registry language is English unless agreed (labelled on the draft page). |
