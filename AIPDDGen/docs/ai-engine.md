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
| Schema (few-shot examples) | `migrations/0010_ai_engine_examples.sql` |
| Schema (per-template SKILL.md) | `migrations/0011_ai_engine_skills.sql` |
| Deployment secrets/config | `config/config.php` → `ai_engine.*`, `.env.example` |
| Settings + encryption + test connection | `src/Services/AiEngineService.php` |
| OpenAI-compatible HTTP client | `src/Services/LlmClient.php` |
| Test bench + few-shot example library | `src/Services/AiEngineTestbenchService.php` |
| Template versions + SKILL.md uploads | `src/Services/TemplateLibraryService.php` |
| Guards, prompts, generation, draft view | `src/Services/DraftService.php` |
| Staff console controller | `src/Controllers/AiEngineController.php` |
| Workspace generate/view controller | `src/Controllers/DraftController.php` |
| Routes | `src/routes.php` (`/admin/ai`, `/admin/ai/test`, `/admin/ai/test-input`, `/admin/ai/examples…`, `/admin/templates` + skill upload, `/projects/{id}/draft/generate`, `/drafts/{id}`) |
| Admin page | `templates/pages/admin/ai.php` |
| Draft page | `templates/pages/draft.php` |
| Project page action | `templates/pages/project.php` |
| Tests (offline) | `tests/ai_engine_test.php`, `tests/ai_testbench_test.php` |

Request flow (staff): `GET /admin/ai` → staff guard → `AiEngineService::publicSettings()`
(a masked view only) + `AiEngineTestbenchService::examples()` → `templates/pages/admin/ai.php`.
Save, Test connection, Run test and the example actions are POSTs with CSRF
enforced by `public/index.php`.

Request flow (workspace): `GET /projects/{id}` → `ProjectAiService::overview()`
(read-only readiness conditions, honest blocker from `DraftService::readiness()`,
submitted inputs, latest drafts and the real 5-step pipeline progress) →
`templates/pages/project.php` renders the **AI drafting** card + horizontal
progress stepper. Editors submit evidence with `POST /projects/{id}/inputs`
(textarea and/or `.txt`/`.md`; CSRF; audited `project.input_added`; the
attachment is stored privately and its plaintext is read into
`inputs.source_text`, which `DraftService::inputExcerpts()` uses as drafting
context — no parsing). Drafting is the existing
`POST /projects/{id}/draft/generate` → `DraftService::generate()` → 303 to
`/drafts/{id}`; the CTA is disabled with the real translated reason when the
engine, published template, approved fact sheet or per-template SKILL.md is
missing. The pipeline shows real progress (inputs / fact sheet / draft) and
marks gap report + export as later steps. Cross-workspace projects are 404 and
viewers are read-only.

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

### Tabbed workspace (server-side, deep-linkable, no-JS)

The page is split into four **server-side tabs** selected by the `?tab=` query
string. They are ordinary links rendered by the server — no JavaScript, no
client-side routing, and every tab is a shareable URL:

| Tab | URL | Contents |
| --- | --- | --- |
| Overview | `/admin/ai` or `/admin/ai?tab=overview` | Status checklist (provider, model, API key, skills, enabled), Test connection, tab guide — also the fallback for a missing/unknown `tab` value |
| Settings | `/admin/ai?tab=settings` | Provider preset, model, base URL, API key, temperature, token cap, enable switch |
| Skill | `/admin/ai?tab=skill` | **Per-template SKILL.md list** (dynamic, grouped by registry) plus registry fallbacks and global instructions; `.md` uploads happen per template version on the Template library page |
| Bench & training | `/admin/ai?tab=bench` | Ephemeral test bench + few-shot example library |

State-changing actions keep the POST→redirect→GET pattern and return to the tab
they came from: saving Settings returns to `?tab=settings`; saving the Skill form
returns to `?tab=skill` (the form can post a hidden `_tab=settings|skill`; the
skills form is otherwise recognised by its skill inputs); **Test connection**
returns to `?tab=overview`; **Run test / save-as-example / example toggle/delete**
return to `?tab=bench` (keeping the `#test-bench` / `#examples` anchors). Tabs are
query-only — no new routes were added.

### Per-template SKILL.md and resolution order (phase 2)

`migrations/0011_ai_engine_skills.sql` adds `ai_engine_skills`:
`template_id` / `template_version_id` (NULL for registry fallbacks), `registry`,
`name`, `skill_text`, source filename/mime/size, `status ENUM('pending','ready')`,
`enabled`, creator + timestamps, and a UNIQUE key on `template_version_id`.
Adding a template version (Template library → Add template) creates its skill row
in `pending`; uploading the version's **SKILL.md** (`/admin/templates/{id}/skill`,
`.md`, ≤1 MB, read into the database and never stored as a public file) marks it
`ready` and audits `ai_engine.skill_ready`.

The drafting engine resolves the most specific **enabled** skill, in this order:

1. **Per-version** — `ai_engine_skills.template_version_id` = the project's
   published template version;
2. **Per-template** — a row with `template_id` set and `template_version_id` NULL;
3. **Registry fallback** — a row with both ids NULL for the project's registry
   (backfilled once from the AI Engine settings skills);
4. **Legacy registry skill** — the registry text still stored in
   `ai_engine_settings` (keeps deployments/tests working before a fallback row
   exists);
5. **Global instructions** — the global text from the AI Engine settings.

A **pending** row at the most specific level **blocks drafting** (it does not
silently fall through): `DraftService::readiness()` returns
`error.ai_engine_skill_pending` naming the template, so the project page and the
generate action show an actionable message instead of a 500. A missing skill at
every level is also a block (`SKILL.md required before drafting`). Disabled rows
are ignored (resolution falls through). Ready skill text is injected into the
drafting system prompt with its source label; global instructions and the
few-shot style examples are appended as before, and the safety rules stay last.

The **Skill tab** renders the dynamic payload from
`AiEngineService::skillTabPayload()`: registry groups → per-template rows with
their versions (pending/ready badge, stored-text presence, source filename,
updated timestamp) → the registry fallback row → the current global instructions.
SKILL.md content is admin guidance only and must never contain customer project
data.

1. **Configure the provider** (Settings tab). Pick a preset (it fills the suggested API root),
   then choose the **model** from the curated dropdown for that provider, or
   pick **“Other / custom model…”** and paste the exact provider model id. The
   base URL is the API root only; the client appends `/chat/completions`.
2. **Paste the API key** (Settings tab). It is validated (max length) and encrypted
   immediately. Leave the field blank to keep the stored key.
3. **Write the SKILL** (Skill tab). Free-text rules per registry plus optional global
   instructions. These are injected into the system prompt and are treated as
   admin-managed, trusted guidance. The hard safety rules are always appended
   *after* the skill, so a skill cannot override the no-invention rules.
   Each of the three skill fields (Gold Standard, Verra VCS, global) also accepts
   an **uploaded Markdown (`.md`) document** (up to 1 MB): a valid upload
   replaces that skill's text when you save. The file is read once into the
   skill text and **never stored on disk**; the normal character limits still
   apply, and other file types (PDF/Word) are rejected in this MVP.
   For an individual template version, upload its **SKILL.md** from
   `/admin/templates` (Manage → SKILL.md). Until that row is ready, drafting with
   the version is blocked with a clear message.
4. **Enable the engine** (Settings tab). Enabling is refused unless a key is available (stored
   or env fallback) — that avoids turning on a configuration that would fail on
   the first draft.
5. **Test connection** (Overview tab, optional). This is the only staff action that makes a
   real provider request outside drafting. Errors are shown as translated,
   safe messages **plus the sanitised provider reason** (for example
   “model not found”); the key, headers and raw body are never shown.
6. **Run a sample in the test bench** (Bench & training tab) to check the skill, then save a
   good pair as a few-shot example.
7. **Remove / rotate the key** (Settings tab) with the "Remove the stored API key" checkbox.

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
| `ai_engine.test_run` | Test-bench run, success and failure (registry, model, latency, character counts, error code — **never** the sample text) |
| `ai_engine.example_saved` | A few-shot example was saved (registry, title, character counts — never the text) |
| `ai_engine.example_enabled` / `ai_engine.example_disabled` | An example was switched on/off |
| `ai_engine.example_deleted` | An example was deleted |

Tenant accounts receive **404** on `/admin/ai` (the existing staff guard), like
every other console page.

---

## 4. Provider examples

The client sends `POST {base_url}/chat/completions` with
`Authorization: Bearer <key>`, the model, messages, temperature and a token cap —
the standard OpenAI shape. Presets are labels only; the base URL and model
always come from the console.

### Model selection

The model field is a **provider-aware dropdown** backed by
`AiEngineService::MODEL_PRESETS` (openai, claude, deepseek, openrouter, ollama)
plus an **“Other / custom model…”** option with a free-text id field. The
catalogue is a convenience, not a restriction: provider catalogues change over
time, so any exact model id can be stored. If the stored model is not in the
dropdown, the page selects “custom” and prefills it (nothing is lost).

Rules of thumb:

- Store the **API model id** (`claude-haiku-4-5`, `gpt-4o-mini`,
  `deepseek-chat`), never a display label such as “Claude Haiku 5.5”. A display
  name is rejected by the provider (the live bug this page now prevents).
- Changing the provider does not rewrite the stored model; pick the matching
  model (or custom id) and save.
- The list is static and local: rendering the page never calls the provider.
  Use “Other / custom model…” for a model released after this list.

| Provider | Base URL | Model examples | Notes |
| --- | --- | --- | --- |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini`, `gpt-4o`, `gpt-4.1`, `gpt-4.1-mini`, `o3-mini` | Standard OpenAI key. `o*` reasoning models automatically use `max_completion_tokens`. |
| Claude (Anthropic) | `https://api.anthropic.com/v1` | `claude-sonnet-4-5`, `claude-opus-4-1`, `claude-haiku-4-5` | Anthropic's **OpenAI SDK compatibility layer**: same chat-completions shape, Anthropic hoists `system` messages, accepts `temperature` only in 0–1 and rejects it on the newest 5.x models (the client retries without it). Anthropic documents the layer for testing/comparison rather than production; OpenRouter (`anthropic/claude-*`) is the production-gateway alternative — both work here. |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat`, `deepseek-reasoner` | OpenAI-compatible. |
| OpenRouter | `https://openrouter.ai/api/v1` | `anthropic/claude-sonnet-4-5`, `openai/gpt-4o`, `deepseek/deepseek-chat` | Model names include the vendor prefix. |
| Ollama (local) | `http://127.0.0.1:11434/v1` | `llama3.1`, `mistral`, `qwen2.5` | Local model; any non-empty key works; no data leaves the machine. |
| Other gateway | operator-provided root | operator-provided | Any endpoint that implements the OpenAI chat-completions contract. |

HTTP behaviour: connect timeout 10 s, request timeout `AI_ENGINE_TIMEOUT`
(default 60 s), one retry on a 5xx or a network failure, no redirect following.
The base URL is normalised before use (spaces trimmed, trailing `/` removed, an
already-appended `/chat/completions` stripped), so a pasted full endpoint still
works. For Anthropic hosts / `provider=claude` the request also carries
`x-api-key` and `anthropic-version: 2023-06-01` alongside Bearer. `o1`/`o3`/`o4`
reasoning models use `max_completion_tokens`; a provider that rejects
`max_tokens` gets one retry with the newer field. Some current models reject the
optional `temperature` parameter (Anthropic's newest Claude models answer HTTP
400 "`temperature` is deprecated"): the client retries once without temperature
and keeps omitting it for that client instance, so one generic configuration
works across models. Responses are validated (`choices[0].message.content`).

### Troubleshooting provider errors

Non-2xx provider bodies are parsed for a short, safe explanation
(`error.message` / `type` / `param` / `code`, whitespace-collapsed, truncated to
200 characters, API keys and token-looking runs replaced with `[redacted]`).
The admin sees the translated message **plus that detail**, for example:

> The AI provider does not accept this model or base URL… Provider said: model: claude-typo

| Symptom / code | Likely cause | What to do |
| --- | --- | --- |
| `ai_engine_llm_auth` (401/403) | Wrong/revoked key, or the key lacks the model | Re-paste the key; check the provider console. Detail often says “invalid x-api-key”. |
| `ai_engine_llm_model` (404, or 400 mentioning the model) | Base URL points at the wrong API root, or the model id does not exist for that provider/account | Check the API root (`…/v1`) and pick the model from the dropdown or paste the exact id (never a display label). |
| `ai_engine_llm_request` (400/422/5xx) | Invalid parameter, model mismatch, content filter, provider outage | Read the appended provider detail; adjust the model/settings or retry. |
| `ai_engine_llm_rate_limited` (429) | Provider quota / rate limit | Wait and retry; check the provider plan. |
| `ai_engine_llm_timeout` (408/504) | Provider slow / network | Retry; raise `AI_ENGINE_TIMEOUT` if the provider is consistently slow. |
| `ai_engine_llm_network` | DNS/connection failure | Check the base URL and this server's network. |
| `ai_engine_llm_response` | 2xx but unreadable/empty body | The endpoint is not really OpenAI-compatible; check the base URL. |
| `ai_engine_base_url` | Stored base URL is not an http(s) URL | Fix the URL in the console. |

Known example from this deployment: `model = "Claude Haiku  5.5"` (a display
label) made every Anthropic call fail with a generic error; the correct id is
`claude-haiku-4-5`. After this change the page shows the provider's own reason
and offers the id in the model dropdown. The client is injectable, so tests use
a mocked transport and never touch the network.

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
- **Few-shot examples**: admin-only, in-context only (no fine-tuning, no weights
  change), never customer/personal data, never rendered to tenant accounts, and
  injected as style guidance only — the safety rules always follow them.
- **No model training**: the provider is called with a single request per
  section; nothing is persisted provider-side by this application beyond the
  provider's own terms.

---

## 7. Verification

```powershell
php bin/migrate.php                 # applies pending migrations (0009/0010, idempotent)
php -l src/Services/AiEngineService.php
php bin/test.php ai_engine          # settings/drafting tests, offline (mocked LLM transport)
php bin/test.php ai_testbench       # bench + few-shot examples, offline
php bin/test.php unit_i18n          # EN/FR parity
```

HTTP checks (dev server on port 8000): staff `GET /admin/ai` and each
`?tab=overview|settings|skill|bench` → 200 (an unknown/missing `tab` falls back to
overview); the page carries the configuration form, a `select[name=model]` with
the provider presets plus the custom-model option, the Test bench panel and the
examples library; POST save/test/bench redirects carry the originating `?tab=…`;
tenant account → 404; a demo project with no registry (or no published template /
no approved fact sheet) → clear flash refusal, never a 500; Test connection shows
the sanitised provider reason.

The tests cover: settings save/encrypt/mask, key never exposed, encryption
round-trip and secret change, env fallback precedence, clear key, model presets
and custom ids, LLM retry and structured errors, base-URL normalisation,
Anthropic headers, reasoning-model token fields, sanitised provider details,
staff guard on settings, every generation refusal, the happy
path (citations, snapshot, audit, labelled draft, project status), needs-input
when facts are missing, unsupported-number and missing-citation rejection, editor
role requirement, cross-tenant 404s and draft version increments; **and for the
bench**: ephemeral run + audit without input text, .txt/.md validation and size
caps, example save/enable/disable/delete with audit, prompt budget/cap, disabled
and cross-registry examples excluded, and DraftService prompts including enabled
examples only. The tab contract is covered too: `?tab=` validation/defaulting,
save-origin detection (settings vs skill), and the PRG redirect target for
save/test-connection/bench actions. **Per-template skills** are covered by the
resolution test: version → template → registry → legacy settings → global order,
pending blocking with the template named, ready skill text reaching the draft
prompt, disabled rows skipped, missing skill blocking, and the dynamic Skill tab
payload structure (`skillTabPayload()`).

---

## 8. Test bench and few-shot skill examples (“train the skill”, not fine-tuning)

`/admin/ai` has a **Test bench** panel below Test connection and a **Few-shot
skill examples** library below the bench.

### Test bench (ephemeral)

1. Choose the registry skill (Gold Standard / Verra VCS).
2. Paste a staff test snippet and/or upload a `.txt`/`.md` file (≤ 1 MB; no
   PDF/Word text extraction in this MVP), optionally with expected output/notes.
3. **Run test** sends one chat completion with the registry skill plus bench
   instructions and shows the PDD-style output, the model and the latency.

While that synchronous request is in flight, a **progress bar** (external
`public/assets/ai-bench.js` + `ai-bench.css`, same-origin so the `self` CSP
holds) shows staged status text — sending, waiting for the provider, taking
longer — plus an elapsed-seconds counter, and the Run button is disabled until
the page navigates to the result. It is **progressive enhancement only**: without
JavaScript the form submits exactly as before, and the progress markup stays
hidden. No server progress endpoint/polling is involved (the run is one request).

The run is **ephemeral**: it is never saved as a draft, never touches a project or
fact sheet, and never enters the shared template library. The result lives in the
staff session for the next page render only. The `ai_engine.test_run` audit entry
records shape metadata only (registry, model, latency, character counts), never
the sample text.

### Few-shot examples (in-context skill tuning, NOT training)

A staff member can save a reviewed input/output pair with a title for a registry.
The library lists examples with enable/disable and delete actions.

**Enabled examples are injected into future drafting prompts** for that registry:
at most `AiEngineTestbenchService::MAX_EXAMPLES_IN_PROMPT` (3) examples, oldest
first (by `ordinal`), capped at `MAX_EXAMPLE_PROMPT_CHARS` (2000) characters in
total. They appear as a clearly labelled *FEW-SHOT STYLE EXAMPLES* block, and the
non-negotiable safety rules follow them: the model is told to copy their tone,
structure and level of detail only — never their facts, figures, names or claims.

**This is explicitly not fine-tuning or model training.** No model weights
change, no training endpoint is called and nothing is shared with other
customers. Saved examples **are transmitted to the configured LLM provider inside
those prompts** (inference only — the same provider that drafts the section), so
they leave the application; they are never sent to a training service and this
application never uses any data for training. It “trains the skill” purely by
in-context prompt tuning, which is why the UI says “Not fine-tuning”.

**Privacy:** never save customer project data, personal data or confidential
documents as examples. Examples are staff-authored test snippets you are allowed
to reuse and that may be sent to the configured provider whenever drafts run; the
UI repeats this warning. The feature never accepts a project id,
and `ai_engine_examples` is separate from `inputs`/`facts` (no path for customer
data into the example library or the template library).

### Management rules

- Staff only (`/admin/ai` returns 404 to tenant accounts).
- Enable/disable and delete are audited (`ai_engine.example_*`); the audit
  metadata never contains the example text.
- A disabled example is not injected into prompts (tested).
- An example for Gold Standard is never injected into a Verra prompt (tested).
- Examples are data, not schema: adding/removing one needs no migration or deploy.

---

## 9. Limitations / deferred work

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
| **Skill documents are Markdown only** | The three skill fields accept pasted text (registry skills up to 100,000 characters; global instructions up to 20,000) or a `.md` upload (≤1 MB); PDF/Word extraction needs a parser dependency, so it is out of scope. Uploaded skill text is stored in the settings row, not on disk. |
| **Test bench is text only** | Paste or `.txt`/`.md` upload (≤1 MB, ≤100,000 characters); no PDF/Word extraction (that needs a parser dependency per the no-new-runtime rule). Bench output is ephemeral and not reviewable/exported. |
| **Few-shot examples are prompt text, not training** | Enabled examples are appended to drafting prompts (max 3 / ~2000 chars) and are therefore sent to the configured LLM provider with those requests; they are not used to train or fine-tune any model and no weights change. They cannot teach new facts — manage/curate them by hand. |
| **No example reordering UI** | Order is creation order (`ordinal`); reorder is deferred. Enable/disable/delete are supported. |
| **No instance-level fallback override** | The per-template SKILL.md list and the registry fallbacks are managed from the console; resolution is automatic and there is no per-project skill override (not needed for the MVP). |
| **Per-template SKILL.md is mandatory for new versions** | A template version added through the library starts **pending**; drafting with it is refused (`error.ai_engine_skill_pending`) until its SKILL.md is uploaded and ready. Versions inserted directly (seeds/tests) have no row and fall back to the registry-level/legacy skill, which keeps the seeded Gold Standard flow working. SKILL.md upload/replace lives on `/admin/templates`; the AI Engine Skill tab lists status and the fallback/global text. |
| **Skill edits are Markdown uploads only** | Per-template skills are uploaded as `.md` files (read into the database, never stored as a public file); there is no rich-text editor or per-template textarea in the MVP. |
| **Azure-specific parameters** | `api-version` / deployment-name style endpoints are not modelled; use the OpenAI-compatible base URL. |
| **French output** | French drafts are working drafts; the registry language is English unless agreed (labelled on the draft page). |

### Limit and counter messages (field-specific, tabbed UI)

The owner reported the generic “This value is too long.” line for oversized
skill/bench input. The controller keeps the **existing validation codes**
(`too_long`, `invalid_choice`, `required`) — tests and API behaviour are unchanged
— and the tabbed template (task-25) maps the code per field to actionable copy,
passing the configured cap as a placeholder (`t($key, ['used' => …, 'max' => …])`):

| Key | EN copy (FR mirrored) | Used for | Cap |
| --- | --- | --- | --- |
| `ai.char_counter` | `{used} / {max} characters` | server-rendered counter under a skill/test textarea | per field |
| `ai.skill_too_long` | `Skill text is too long (max {max} characters).` | skill/global textarea over the limit | 100,000 (registry skill) / 20,000 (global) |
| `ai.skill_upload_too_large` | `The uploaded Markdown file is too large (max {max} KB).` | `.md` skill upload over the limit | 1 MB (1,024 KB) |
| `ai.bench_input_too_long` | `Sample input is too long (max {max} characters).` | test-bench sample input over the limit | 100,000 |

These are copy-only keys: no new routes, no validation-code change, and the
server limits remain the source of truth (extensions still `.md` only for skill
uploads; `.txt`/`.md` for the bench).
