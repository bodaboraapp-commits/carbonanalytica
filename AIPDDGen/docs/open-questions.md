# Open questions for the product owner

These block or shape later steps. Each one lists the current default so work can continue safely if you do not answer yet.

---

## 1. Which Africa-relevant methodologies ship first?

**Asked before:** step 3 (setup wizard) and step 2 (template library tags). The catalogue is already seeded as **provisional** (`methodologies.is_provisional = 1`) so the list can be replaced without a migration.

**Current default** (6 entries):

| Registry | Code | Name | Category |
| --- | --- | --- | --- |
| Gold Standard | AMS-I.C | Thermal energy for the user with or without electricity | Cookstoves / thermal |
| Gold Standard | AMS-II.G | Energy efficiency measures in thermal applications of non-renewable biomass | Cookstoves |
| Gold Standard | AR-AMS0007 | Afforestation and reforestation on lands other than wetlands | ARR |
| Verra VCS | VM0007 | REDD+ Methodology Framework (REDD-MF) | Forestry |
| Verra VCS | VM0015 | Methodology for Avoided Unplanned Deforestation | Forestry |
| Verra VCS | VM0009 | Methodology for Avoided Ecosystem Conversion | Forestry |

**What helps:** a shortlist with registry, methodology code, version, and the languages the registry accepts, plus one real project (or template) per methodology for the quality-gate sample set.

---

## 2. Does each registry accept French-language PDDs?

**Asked before:** step 6 (section generation) and step 9 (export). It changes how French drafts are labelled and whether they are exportable.

**Working assumption until answered:** Gold Standard expects English unless agreed with Gold Standard and the validator (PRD), so French output is labelled a **working draft** and the export includes that label; Verra behaviour is marked "to confirm".

**What helps:** the registry rule (or a validator note) per registry, and whether an agreed translation must be filed with the validator.

---

## 3. What does the open-source edition include, and under what licence?

**Asked before:** step 10 (billing/free tier) and before publishing the repository.

**Working assumption:** the core generator (auth, projects, inputs, fact sheet, drafting against a user-supplied template, editor, export) is open source; the hosted edition adds the curated template library, team workspaces, billing and support. Licence not chosen yet.

**What helps:** the licence, which features are excluded from the open-source edition, and whether the open-source edition may use registry templates (licensing considerations in PRD).

---

## 4. Where will the Verra VCS template and approved sample PDDs come from?

**Asked before:** step 2 (template library). The Gold Standard v1.4 structure is already seeded, but **no Verra template exists in the library**, and the quality gate needs approved public PDDs.

**Current default:** the seeded Gold Standard Template Guide v1.4 structure is marked `status = draft` with the source URL and a licence note saying permission must be confirmed before publishing. Verra is not seeded at all rather than inventing a structure.

**What helps:** links to the official Verra PDD template and 2–3 approved public PDDs, plus the permission note (public domain / permitted use) to store and use them.

---

## Not blocking this step

- Data-residency region choices for enterprise customers (step 10).
- M-Pesa merchant account details for checkout (step 10).
- Whether the free tier is defined by draft count or export count (step 10).
