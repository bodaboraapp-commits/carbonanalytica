# PRD: AI PDD Generator for Carbon Projects

Nov 2, 2026

## Problem and goals

A web app that turns raw project inputs (descriptions, meeting transcripts, screen recordings, existing documents) into a first-draft carbon project development document (PDD) in days instead of weeks.

**Problem.** Carbon project developers and consultants spend 3 to 8 weeks assembling a PDD for registries such as Verra (VCS) or Gold Standard. Most of that time goes to collecting scattered technical and operational information, mapping it to the registry template, and fixing inconsistencies.

**MVP goals**

- Generate a complete, template-aligned PDD draft from mixed inputs in under 30 minutes.
- Flag missing or inconsistent information before the draft is exported.
- Let users edit, comment and export to Word/PDF for validator submission.

## Users and use cases

Two launch segments share one product; enterprise adds team controls.

| User | Who they are | Main need |
| --- | --- | --- |
| Independent consultant | Freelance carbon specialist handling 2 to 10 projects a year | Cut drafting time, reuse past content, look professional to clients |
| Enterprise project team | In-house developers, technical and operations staff, reviewers | Collaborate, keep a consistent PDD standard, control access to sensitive project data |

**Key use cases**

1. Draft a new PDD from a project idea note plus site and operations documents.
2. Extract project facts (baseline, boundary, activities, monitoring) from stakeholder meeting transcripts.
3. Convert a recorded site or process walkthrough into operational descriptions.
4. Restructure an existing PDD into another registry's template.

## MVP scope

| In scope | Out of scope (later) |
| --- | --- |
| User-selected registry and methodology per project; launch with Gold Standard and Verra VCS, plus Africa-relevant methodologies | Remaining registries and methodologies, added over time |
| Reference templates: official registry/methodology templates and sample PDDs | Public template marketplace |
| Inputs: text, transcripts (.txt/.docx/.vtt), PDFs/Word files, screenshots, short screen recordings | Live meeting bots, long video processing |
| AI section drafting with source citations | Automatic emission reduction calculations |
| Gap and consistency checker | Direct submission to the registry |
| Editor, comments, version history, Word/PDF export | Mobile apps |
| Individual accounts and one team workspace with roles; free tier and per-PDD checkout | SSO/SAML, custom enterprise integrations |

## Registries, templates, market and pricing

**Registry and methodology.** Users choose a registry and methodology when they create a project, and the generator loads the matching template and rules. Launch registries are Gold Standard and Verra VCS, each with a short list of Africa-relevant methodologies (e.g. cookstoves, afforestation/reforestation, renewable energy); more are added by demand.

**Reference templates.** Users can attach reference material to a project: an official registry or methodology template, or a past approved PDD as a guide. The AI follows the selected template's structure and requirements, and uses references for structure and wording only, never to copy project facts.

The first built-in reference is the Gold Standard [Template Guide v1.4: Key Project Information and Project Design Document](https://globalgoals.goldstandard.org/standards/TGuide-PreReview_V1.4-Project-Design-Document.pdf) (published 21/06/2023). Its structure is the Gold Standard template the generator follows:

- Key Project Information table
- A. Description of project (purpose and eligibility, ownership, location, technologies, scale, funding)
- B. Methodology and SDG contributions (baseline, boundary, additionality, ex ante estimates, monitoring plan)
- C. Duration and crediting period
- D. Safeguarding principles and gender summary
- E. Local stakeholder consultation
- Appendices: 1 Safeguarding assessment, 2 Contacts, 3 Land-use and forests information, 4 Design changes

Template rules the format checker enforces: headings and table columns unchanged; dates as DD/MM/YYYY; thousands separated by commas and decimals by a point; unique IDs on figures and tables; maps with required elements; sections that do not apply stated as intentionally blank; separate public and confidential versions where needed.

The template expects English unless another language is agreed with Gold Standard and the validator (VVB), so French drafts are for working use unless that agreement exists.

**Backend template library (admin-managed).** Your team loads and maintains the material the AI draws on: official registry templates, methodology documents and approved public PDDs. The AI retrieves from this library when it drafts (retrieval-augmented generation), so no model is retrained and updates apply immediately.

- **Admin console:** upload, tag (registry, methodology, version, language, effective date), preview, publish and retire templates.
- **Versioning:** each template keeps its version; every project records the version it was drafted against.
- **Parsing:** templates are split into sections, fields and rules (required headings, format rules) and indexed for retrieval.
- **Quality gate:** a new or changed template is tested on a fixed set of sample projects before it is published.
- **Data boundary:** only admin-curated material enters the library; customer uploads stay private and are never used to train models or shared with other customers.
- **Licensing:** confirm each registry's terms allow storing and using its templates, and record the source link and permission for each.

**Market.** Global product, Africa first: English and French (interface and generated text) at launch, M-Pesa and card payments, and Africa-relevant methodologies and examples prioritized.

**Pricing and licensing (proposed).** Pay per PDD, with a free tier (limited drafts) and an open-source edition of the core generator that users can self-host with their own AI keys. Paid hosting adds team workspaces, the template library and support.

## Core user flow

1. **Create project.** Enter name, country, project type (e.g. cookstoves, ARR, renewable energy) and methodology.
2. **Add inputs.** Paste text, upload documents, transcripts, screenshots or recordings.
3. **Extract facts.** AI pulls project data into a structured fact sheet; the user confirms or corrects it.
4. **Generate draft.** AI writes each PDD section from confirmed facts, with a citation to its source input.
5. **Review gaps.** The checker lists missing fields, conflicting values and weak evidence.
6. **Edit and collaborate.** Edit inline, comment, resolve gaps, regenerate single sections.
7. **Export.** Download Word/PDF with a gap report attached.

## Functional requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| F1 | Email/password and Google sign-in; projects owned by a user or a team | Must |
| F2 | Project setup wizard: user selects registry, methodology, country and project type | Must |
| F3 | Upload and parse .txt, .docx, .pdf, .vtt, images; transcribe audio/video | Must |
| F4 | Structured fact sheet with confidence level and source link per fact | Must |
| F5 | Section-by-section PDD generation following the selected registry template and methodology | Must |
| F6 | Gap and consistency checker (missing data, mismatched numbers, dates, locations) | Must |
| F7 | Rich-text editor with inline comments and per-section regenerate | Must |
| F8 | Export to .docx and PDF with template formatting | Must |
| F9 | Version history and restore | Should |
| F10 | Team workspace: roles (admin, editor, viewer), audit log | Should |
| F11 | Reusable library of past project text and boilerplate | Could |
| F12 | Per-PDD checkout, free tier with usage limits, open-source core edition | Should |
| F13 | Reference templates: users attach or pick templates and sample PDDs; admins manage a backend template library that guides drafting and checks | Must |

## AI, data and non-functional requirements

**AI behavior**

- Every generated claim cites the input it came from; anything unsupported is marked "needs input", never invented.
- Numbers (hectares, tCO2e, dates, coordinates) are copied from the fact sheet, not generated freely.
- The user approves the fact sheet before drafting; AI output is always labeled as a draft.
- Template and methodology rules live as editable data, not hard-coded prompts, so updates need no code change.

**Non-functional**

| Area | Target |
| --- | --- |
| Performance | Full draft in under 30 minutes; single section regenerated in under 60 seconds |
| Security | Encryption in transit and at rest; per-project access control; no use of customer data to train models |
| Privacy | Data stored in a region the customer picks where possible; delete-on-request within 30 days |
| Reliability | 99.5% monthly uptime; autosave every 30 seconds |
| Accessibility | WCAG 2.1 AA for the editor and forms |
| Connectivity | Works on typical African broadband; resumable uploads for large files |

## Technical approach

A standard web stack with an asynchronous AI pipeline: upload, parse, extract facts, draft sections, check gaps.

| Layer | Proposed choice |
| --- | --- |
| Frontend | React or Next.js, TypeScript, rich-text editor (e.g. TipTap) |
| Backend | Node.js or Python (FastAPI) REST API with a job queue for long tasks |
| Database | PostgreSQL with pgvector for retrieval over project inputs |
| File storage | S3-compatible object storage with signed URLs |
| AI | LLM API for extraction, drafting and checking; speech-to-text for audio; vision model for screenshots and video frames |
| Export | Template-driven .docx generation, PDF via conversion |
| Auth and billing | Managed auth provider; per-PDD checkout by card and M-Pesa |

**Pipeline.** Inputs are parsed and chunked, then embedded. A fact-extraction step fills a typed schema (project, baseline, additionality, monitoring, stakeholders). Section generators read only confirmed facts plus retrieved passages, and the checker runs rule-based and AI-based comparisons across sections.

## Metrics, milestones, risks and open questions

**Success metrics (first 6 months after launch)**

| Metric | Target |
| --- | --- |
| Time to first full draft | Under 30 minutes |
| Draft sections accepted with minor edits | 70% or more |
| Validator-reported gaps vs manual baseline | 30% fewer |
| Paid PDDs purchased | 50 |
| 90-day retention of active users | 40% |

**Milestones (weeks from kickoff)**

1. Weeks 1 to 3: template analysis, fact schema, designs.
2. Weeks 4 to 9: auth, project setup, upload and parsing, fact sheet.
3. Weeks 10 to 14: section generation, citations, editor.
4. Weeks 15 to 17: gap checker, export, team roles.
5. Weeks 18 to 20: pilot with 5 consultants and 1 enterprise team; fix and launch.

**Risks**

| Risk | Mitigation |
| --- | --- |
| AI invents or misstates technical facts | Fact-sheet approval, citations, "needs input" markers, number locking |
| Registry templates or rules change | Template and rules stored as versioned data; owner assigned to track updates |
| Confidential project data exposure | Access control, encryption, no training on customer data, clear data policy |
| Poor transcription or video understanding | Limit video length at MVP; let users correct extracted facts |
| Validators reject AI-drafted text | Pilot with real validation cases; keep human review central |

**Open questions**

- [x] Decided: Gold Standard and Verra VCS at launch. Still open: which Africa-relevant methodologies ship first.
- [x] Decided: global with Africa first; English and French; M-Pesa and card. Still open: whether each registry accepts French-language PDDs.
- [x] Decided: per-PDD pricing plus a free open-source version. Still open: what the open-source edition includes and its license.
- [x] Provided: Gold Standard Template Guide v1.4. Still needed: the Verra VCS PDD template and a few approved sample PDDs as quality benchmarks.
