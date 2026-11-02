---
name: pdd-generator-builder
description: Build or extend the AI PDD Generator web app, which turns text, transcripts, screenshots, recordings and documents into carbon project development documents (PDDs) for Gold Standard and Verra VCS. Use when coding any part of this product: auth, uploads, fact extraction, template library, section generation, gap checker, editor, export, billing.
---

# AI PDD Generator: build guide

The full requirements are in `references/PRD.md`. Read it before writing code, and re-read the relevant section before each feature.

## Product in one paragraph

A web app for carbon consultants and enterprise project teams. Users pick a registry (Gold Standard or Verra VCS) and methodology, add mixed inputs, confirm an AI-extracted fact sheet, then get a template-aligned PDD draft with citations, a gap report, an editor and Word/PDF export. Global product, Africa first; English and French; M-Pesa and card payments; pay per PDD with a free tier and an open-source core.

## Build order

1. Data model and auth: users, teams, roles, projects, inputs, facts, drafts, template versions.
2. Admin template library: upload, tag, parse into sections/fields/rules, version, publish, retire.
3. Project wizard: registry, methodology, country, project type.
4. Input ingestion: .txt, .docx, .pdf, .vtt, images, short audio/video; parse, chunk, embed.
5. Fact extraction into a typed schema; fact sheet UI where the user confirms or corrects.
6. Section generation per template section, from confirmed facts plus retrieved template passages.
7. Gap and consistency checker (rules first, then AI checks).
8. Editor with comments and per-section regenerate; version history.
9. Export to .docx and PDF using template formatting.
10. Billing (per-PDD checkout, card and M-Pesa), free-tier limits, team roles and audit log.

## Hard rules (never break)

- Every generated claim carries a citation to the input or fact it came from. If unsupported, write "needs input"; never invent.
- Numbers (areas, tCO2e, dates, coordinates) are copied from the confirmed fact sheet; the model never creates them.
- Drafts are always labeled as drafts; the user approves the fact sheet before drafting.
- Customer data is private: never used to train models, never added to the shared template library, deletable on request within 30 days.
- Only admin-curated material (official templates, methodology documents, approved public PDDs) goes into the backend template library. Record source link and permission for each item. The AI retrieves from it at draft time; do not fine-tune on it.
- Template and methodology rules are stored as versioned data, not hard-coded prompts. Each project records the template version used.
- Follow the selected registry template exactly. For Gold Standard v1.4: keep headings and table columns unchanged; dates DD/MM/YYYY; commas for thousands and a point for decimals; unique IDs on figures and tables; mark non-applicable sections as intentionally blank; support public and confidential versions.
- Gold Standard expects English unless another language is agreed with Gold Standard and the validator, so French output is for working drafts and must be labeled so.

## Defaults for unspecified choices

React or Next.js with TypeScript; Node.js or FastAPI backend with a job queue; PostgreSQL with pgvector; S3-compatible storage with signed URLs; managed auth; LLM API for extraction, drafting and checking; speech-to-text for audio; vision model for screenshots. Change only with a stated reason.

## Definition of done for each feature

- Matches the PRD requirement ID (F1 to F13) it implements; name the ID in the commit or PR.
- Has tests for happy path and for the hard rules above (citations present, no unsupported numbers, tenant isolation).
- Works on slow connections; large uploads are resumable.
- Accessible forms and editor (WCAG 2.1 AA).

## Out of scope for the MVP

Other registries, public template marketplace, live meeting bots, long video, emission reduction calculations, direct registry submission, mobile apps, SSO/SAML.

## Open items to ask the owner before building the affected part

- Which Africa-relevant methodologies ship first.
- Whether each registry accepts French-language PDDs.
- What the open-source edition includes, and its license.
- The Verra VCS PDD template and approved sample PDDs.
