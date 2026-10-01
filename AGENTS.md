<!-- antislop:start -->
## antislop
For UI, copy, people, mobile layout, or code comments work, read these installed skill files directly (use these paths even if a same-named global skill exists):
- Core filter, always on: `antislop`: `.agents/skills/antislop/SKILL.md`
- UI / visual: `antislop-ui`: `.agents/skills/antislop-ui/SKILL.md`
- Copy & text: `antislop-copywriting`: `.agents/skills/antislop-copywriting/SKILL.md`
- People: `antislop-human`: `.agents/skills/antislop-human/SKILL.md`
- Mobile / responsive: `antislop-layoutmobile`: `.agents/skills/antislop-layoutmobile/SKILL.md`
- Code comments: `antislop-code`: `.agents/skills/antislop-code/SKILL.md`
Before starting, follow the core's "Two Usage Modes" section in strict order: explicit session instruction first, then global preference, then ask. A session instruction always wins. For a resolved mode, say `antislop active: <mode> (session override).` or `antislop active: <mode> (global preference).` once before presenting findings or making edits, using the actual mode and source. Acknowledging the user's request without naming the source does not replace this notice.
Only an explicit choice of antislop during or after selects a session mode. A request to review, audit, or avoid file edits does not select a mode; read the global preference in that case. Another skill's mode does not select antislop's mode.
If the mode is unresolved, ask during/after and end the response; wait for the answer before any UI review, planning, or concept. For read-only tasks, put the active-mode notice only at the start of the final answer, never in progress messages. For editing tasks, announce before the first edit and omit it from the final answer.
To update antislop later: `npx antislop-ai --update`, or run `npx antislop-ai` and pick Overwrite them.
<!-- antislop:end -->

# DRAGONMART POS — PROJECT RULES & DEVELOPMENT WORKFLOW

## Product Context and Sources

DragonMart POS is a single-store retail point-of-sale project with separate Laravel backend and Next.js frontend applications. These instructions are aligned with PRD version 2.0 dated 2026-10-01. They describe how to work; the PRD defines the detailed product behavior, data model, calculations, limits, and acceptance cases.

### Source of truth

- Read `PRD_DragonMart_POS_v2.md` before planning or implementing a product change. Read its relevant requirements and acceptance cases again when the task changes.
- If the owner installed that document as `PRD.md`, verify that it is the revised DragonMart PRD version 2.0. The original Antigravity PRD is superseded. Do not select an older document just because it has the shorter filename.
- Follow explicit user instructions first. Use the current PRD for product scope and business rules; use this file for repository workflow and installed skill paths. The PRD does not override protection of `main`.
- If the authoritative PRD cannot be located, report the missing source and continue independent inspection only; do not invent dependent product requirements.
- Keep `AGENTS.md` and `GEMINI.md` semantically synchronized. Their antislop skill directories are intentionally different: `.agents/skills/` and `.gemini/skills/`. Preserve each installed antislop block and its local paths.
- Write project instruction documents and technical identifiers in English. The application uses Indonesian labels, `id-ID` formatting, IDR, and the `Asia/Makassar` store timezone.

### Version 1 boundary

| Area | Required baseline |
|---|---|
| Business and inventory | General retail; one store; one sellable inventory location; multiple registered cashier terminals. |
| Quantities and packaging | Whole-unit quantities; one independent selling unit per SKU; flat categories; no automatic unit conversion. |
| Roles | `owner`, `manager`, `cashier`; permissions and record ownership checked by the backend. |
| POS | Multi-item cart, server quote, authorized discounts, tax snapshots, atomic checkout, stock concurrency protection, and durable idempotency/recovery. |
| Payments | Exactly one method per sale: cash or manually confirmed QRIS/EDC/bank transfer. |
| Inventory operations | Receiving/opening balances, stock ledger, reasoned adjustments, owner-only cost revaluation, moving weighted-average costing, and store-wide stocktake pause. |
| Returns and cash | Approved full/partial returns, recorded refunds, cash in/out, one open shift per user/terminal, X reports, and immutable Z reports. |
| Outputs | Browser-dialog receipt printing for 58 mm/80 mm layouts, historical reprints, operational reports, XLSX/PDF exports, and audit history. |
| Reliability | Online finalization, local non-sensitive cart/recovery state, backup/restore procedure, and evidence-backed release checks. |

Do not add multi-branch operations, separate warehouses, weighed goods, unit conversion, purchase orders, supplier accounts payable/returns, loyalty points, split payments, pay-later sales, payment gateway integration, checkout offline, direct ESC/POS commands, automatic drawer opening, exchanges, restaurant workflows, or AI features unless the user expands the scope. Do not present deferred controls or mock results as implemented features.

### Technical baseline

| Layer | PRD baseline |
|---|---|
| Backend | Laravel 13.x; PHP 8.3 or newer within the framework's supported range; Eloquent, Form Requests, Policies, and domain services. |
| Database | MySQL 8.4 / InnoDB; UTC event timestamps; actual MySQL tests for database locking guarantees. |
| Frontend | Next.js 16.x App Router, TypeScript, Tailwind CSS, schema validation, centralized API client; Node.js 24 baseline. |
| Authentication | Sanctum first-party SPA session/cookie authentication with CSRF handling. |
| Authorization | Compatible Spatie Laravel Permission release as the role/permission authority. |
| Background work | Laravel database queue and operated worker for exports; Redis and WebSocket broadcasts are optional after measurement. |
| Audit and media | Compatible Spatie packages may be used when they meet the PRD requirements; installing a package does not prove business correctness. |

Inspect actual runtime versions, source, and lockfiles before changes. Resolve and pin compatible dependencies during foundation work. Record any mismatch with the PRD; do not silently substitute an older stack or perform an unrelated framework upgrade during a small feature fix.

### Before every development task

1. Inspect the current branch, Git status, applicable repository instructions, and affected implementation. Preserve unrelated work and never discard it to switch branches.
2. Identify the relevant PRD FR/NFR IDs, acceptance cases, and affected business records. Treat legacy source claims as unverified until supported by inspected code.
3. Decide whether the task belongs to `fase/backend`, `fase/frontend`, or both. For shared documentation/configuration, use a relevant phase branch and integrate the shared change once; avoid divergent copies.
4. Make the smallest complete change that satisfies the request. Read the installed antislop skills when their scope applies, following the preserved block above and explicit session instructions.
5. Run checks appropriate to the behavior changed before committing, pushing, or integrating. A failed or unavailable required check must be reported, not marked as passed.
6. Keep `projectkasir_old` / `kasirdragon` unchanged as a reference. Historical migration is a separate explicit task, not a routine seeder side effect.

---

## 1. GIT BRANCHING STRATEGY & WORKSPACE RULES

This project uses a structured Git branching strategy to maintain code stability:

### Branch Structure
1. **`main`** (PRODUCTION - STRICTLY PROTECTED):
   - Working on, committing to, merging into, or pushing directly to the `main` branch is **STRICTLY PROHIBITED**.
   - The `main` branch **MAY ONLY be modified** following an explicit, direct instruction from the user (for example, "push to main" or "merge into main").
2. **`development`** (MAIN INTEGRATION BRANCH):
   - The main branch for integrating all completed features and phases.
   - All changes from `fase/backend` and `fase/frontend` must be merged into this branch after passing tests and being pushed to their respective phase branches.
3. **`fase/backend`** (BACKEND WORKSPACE):
   - A dedicated workspace for implementation, fixes, revisions, and new modules or features on the backend (Laravel).
4. **`fase/frontend`** (FRONTEND WORKSPACE):
   - A dedicated workspace for implementation, fixes, revisions, UI/UX changes, styling, and components on the frontend (Next.js).

---

## 2. TASK EXECUTION PROTOCOL (DEVELOPMENT WORKFLOW)

Whenever the user requests a fix, revision, or code implementation:

### A. Backend Development Protocol
1. **Switch to the Backend Workspace**: Check out the `fase/backend` branch (`git checkout fase/backend`).
2. **Implementation & Fixes**: Implement the code according to the relevant backend architecture phase.
3. **Commit & Push to the Phase Branch**:
   - Stage and commit the changes with a clear commit message.
   - Push directly to the `fase/backend` branch:
     ```bash
     git push origin fase/backend
     ```
4. **Merge into the Development Branch**:
   - Switch to the `development` branch (`git checkout development`).
   - Merge the changes from `fase/backend`:
     ```bash
     git merge fase/backend
     git push origin development
     ```
5. **Return to the Workspace**: Return to the working branch (`git checkout fase/backend`).
6. **Strict Protection**: Do not modify or merge into `main`!

### B. Frontend Development Protocol
1. **Switch to the Frontend Workspace**: Check out the `fase/frontend` branch (`git checkout fase/frontend`).
2. **Implementation & Fixes**: Implement the code according to the relevant frontend architecture phase.
3. **Commit & Push to the Phase Branch**:
   - Stage and commit the changes with a clear commit message.
   - Push directly to the `fase/frontend` branch:
     ```bash
     git push origin fase/frontend
     ```
4. **Merge into the Development Branch**:
   - Switch to the `development` branch (`git checkout development`).
   - Merge the changes from `fase/frontend`:
     ```bash
     git merge fase/frontend
     git push origin development
     ```
5. **Return to the Workspace**: Return to the working branch (`git checkout fase/frontend`).
6. **Strict Protection**: Do not modify or merge into `main`!

---

## 3. BUSINESS RULES THAT IMPLEMENTATION MUST PRESERVE

Use the detailed rules in the PRD rather than duplicating or approximating their formulas here.

### Authentication and authorization

- Use first-party Sanctum session authentication with an HttpOnly session cookie, CSRF handling, session regeneration at login, and invalidation at logout. The idle session lifetime is 60 minutes as specified by the PRD.
- Configure the frontend/API under one HTTPS origin by default, with the documented reverse-proxy routes. Any subdomain variant needs explicit cookie/CORS configuration.
- No bearer-token storage, token interceptor, automatic token refresh, or refresh-token endpoint is required for this web client. Use session-aware requests, CSRF initialization, and handled 401/419 responses instead.
- Protect every privileged backend operation with permissions and ownership checks. A Next.js route guard controls navigation but is not the authorization authority.
- Use Spatie role assignments as the single permission authority; do not create a second independently maintained `users.role` system.
- Keep at least one active owner. Managers manage cashier accounts only and cannot promote themselves or alter owner/manager roles.
- Return approval is an authenticated owner/manager action bound to request contents, not a shared supervisor PIN. Cashier API responses must exclude costs and margins.

### Financial and inventory integrity

- The backend controls prices, tax, discounts, totals, payment sufficiency, quantities, and permissions. Client calculations and displayed stock are estimates only.
- Use the PRD's integer-rupiah arithmetic, cost precision, discount allocation, rounding, and original line snapshots. Do not use binary floating-point as the monetary authority.
- Quotes reserve no stock. Revalidate commercial conditions and current stock during checkout; reject stale or changed conditions instead of silently accepting a different total.
- Serialize affected stock and shift operations using the PRD's lock order or an equivalent proven design. Stock cannot become negative. Test races on MySQL/InnoDB.
- Commit each sale, payment, stock effect, authoritative audit event, and idempotency outcome together. Apply equivalent atomicity to receiving, adjustments, stocktake, and completed returns/refunds.
- Scope durable idempotency by user, operation, and key, with a payload hash. Identical retries return the original result; a changed payload with the same key is a conflict.
- Recover an unknown outcome using the same key/payload before another posting or external payment/refund. Never automatically request payment again because a response timed out.
- Enforce one original payment per sale. Non-cash payments/refunds are manually confirmed and reference external results; the application does not claim provider verification.
- Change inventory only through posted stock services and immutable movement records. Normal product CRUD cannot directly edit stock or average cost.
- Use moving weighted-average cost and historical sale cost snapshots. Apply the stocktake inventory guard to every inventory-writing pathway.
- Validate remaining returnable quantities at completion, apply the PRD's cumulative refund allocation, restock only eligible quantities, and attribute the refund to its current handling shift.
- Keep posted sales, payments, receivings, returns, stock/cash movements, and closed shift figures immutable. Corrections use the PRD's linked and audited mechanisms.
- Require an open owned shift for sales and refund completion. Include cash in/out and cash refunds in expected cash; exclude non-cash amounts. Lock closure against concurrent postings.
- Use original receipt and price/tax/identity snapshots for reprints and historical reports. Print failure cannot create a second sale.

### Frontend, outputs, and optional infrastructure

- Final posting requires the backend connection. Persist only user/terminal-scoped, non-sensitive cart and recovery data; clear it at explicit logout and after confirmed successful recovery.
- No tokens, card data, or customer personal information belong in local cart storage. Prevent another user from restoring the previous user's state.
- Preserve the session context for server-rendered requests; private data must not enter shared public caches.
- Support scanner/keyboard input, accessible dialogs, reachable totals/payment controls, and Indonesian cashier language. Product screens should explain operator actions without framework details.
- Use browser print layouts and reprints from committed records. Do not implement a server print queue or direct thermal integration as a Version 1 requirement.
- Generate exports with current permission checks, safe spreadsheet cells, and the PRD's expiry rules. Queue large exports using the initial database queue setup.
- Redis caching and broadcast notifications are optional measured enhancements. Cached stock cannot approve a sale; critical stock/audit records cannot depend on an optional notification succeeding.

---

## 4. ALIGNED DEVELOPMENT ROADMAP — FIVE PHASES

The previous separate generic Laravel/Next.js checklists are replaced by the PRD Section 12 delivery phases. Authentication and contracts must be available early enough to test each complete feature. Deliver working vertical slices, not a collection of disconnected screens.

| Phase | Backend (`fase/backend`) | Frontend (`fase/frontend`) | Exit gate |
|---|---|---|---|
| 1 — Foundation and data | Verify runtime/package compatibility; implement required schemas, constraints, models, fixtures, and secure owner setup. | Configure App Router, runtime/environment, accessible shell, session-aware API access, and domain types. | Fresh installation works; required entity relationships and constraints match the PRD; reproducible test data and controlled credentials exist. |
| 2 — Core architecture | Implement authentication/permissions and numeric, stock, shift, and idempotency services; publish contracts for upcoming slices. | Implement session handling, centralized API client, cart state, and server quote interaction. | Critical service rules, authorization, and relevant calculation tests pass; API payloads/errors are documented. |
| 3 — First complete sale | Deliver own shift opening, server quote, payment record, atomic checkout, receipt, recovery, and shift closure. | Deliver the cashier flow, manual non-cash confirmation, receipt/reprint, recovery, and shift screens. | Complete sales and drawer reconciliation work; stock race, retry, stale quote, rollback, and session-expiry recovery cases pass. |
| 4 — Operational completeness | Deliver receiving, adjustments/revaluation, stocktake, approved returns/refunds, audit, reports, and export jobs. | Deliver master-data, inventory, supervisor return, cash movement, manager report, and export interfaces. | All mandatory Version 1 features are covered; role checks and historical totals pass; scanner/receipt hardware pilot is recorded. |
| 5 — Release verification | Verify deployment, queue worker, health/logging, performance, backup/restore, and API/operations documentation. | Verify responsive/accessibility behavior, explicit lint/type/build checks, and critical end-to-end regression. | PRD Section 15 release conditions and owner UAT evidence are satisfied; no false production-ready claim. |

### Backend implementation guidance

- Use thin controllers, Form Requests, Policies, API Resources, and domain services. Repositories are used where they clarify responsibility, not as mandatory wrappers around every model call.
- Required services cover checkout/quotes, operation recovery, stock postings/costing, return/refund completion, cash/shift reconciliation, and report/export queries.
- Required endpoint families and response/error conventions follow PRD Section 10. Provide an OpenAPI contract before integrating each slice.
- Media processing and audit storage must meet their requirements. Do not expand mandatory uploads to profile avatars, payment proof, or receipt documents unless explicitly requested; product images are the required upload capability.
- Non-critical notifications and export file work happen after commit. Persist authoritative domain audit/ledger records with the business operation.

### Frontend implementation guidance

- Create strict domain types for sales, payments, returns, shifts, inventory documents, quote/recovery results, and safe role-specific responses. Monetary/cost values follow the PRD's JSON string representation.
- Centralize session/CSRF requests and domain error handling. Implement quote acceptance and unknown-outcome recovery before describing checkout as complete.
- Use server components for suitable initial reads and client components for cart/scanner/payment interactions, while keeping private reads session-safe.
- Preserve keyboard/scanner focus and prevent accidental payment submission. Keep immediate local cart estimates distinct from accepted server totals.
- Forms, hooks, and feature services follow the actual delivered API. Do not invent endpoints, simulated payment success, or client-authoritative balances to fill missing backend behavior.

---

## 5. VALIDATION AND RELEASE RULES

### Change-based verification

| Change area | Required evidence |
|---|---|
| Authentication or permissions | Session/CSRF/login/logout/expiry behavior, account activation/reset behavior, role and ownership checks, and sensitive response filtering. |
| Checkout, quote, payment, or money calculations | Relevant arithmetic/limit cases plus integration tests for stale quotes, underpayment, duplicate keys, rollback, and changed payloads. |
| Stock, shift, refund, or stocktake posting | Actual MySQL concurrency/failure-injection tests for the affected locks, atomicity, nonnegative balances, and closure/pause/return races. |
| Historical reports, costing, receipts, or exports | Snapshot/date/refund correctness, totals across outputs, access control, and spreadsheet/download safety where relevant. |
| Frontend behavior | Type checking, separate lint, production build, and affected interaction/E2E cases; hardware printing/scanner checks when those behaviors change. |
| Documentation-only changes | Source/requirement consistency, valid references, preserved protected rules, and synchronized instruction files; application tests are not required solely for prose edits. |
| Operational release | PRD performance fixture results, documented actual runtime/hardware, owner UAT, functioning worker/health/logging, and a successful backup restore. |

Map implementation and evidence to the PRD's FR/NFR IDs and AC cases. The current PRD contains 37 functional requirements and 40 acceptance cases; if the PRD is intentionally revised, follow its current definitions rather than treating these counts as fixed forever.

SQLite or UI tests alone do not prove MySQL locking behavior. Run lint separately from `npm run build`; a successful build does not demonstrate authorization, transaction correctness, hardware compatibility, or operational readiness. Do not add meaningless tests that only repeat implementation details.

Use the PRD's proposed performance workload and limits. Report measurements honestly, including environment, sample size, p95, and failures. Missing or failing required evidence means the corresponding gate remains open.

Developer completion and operational release are distinct. Deployment/publication follows the user's authorization and applicable repository policy; ordinary phase pushes and merges do not automatically authorize a production deployment or changes to `main`.

---

## 6. COMPLETION REPORT

After a task, report:

1. The requested behavior delivered and the relevant PRD requirement/acceptance IDs.
2. The files changed and any deliberate scope/dependency decision.
3. Checks actually run, their outcomes, and any required check still unavailable or failing.
4. Current branch, relevant commit/push/integration results, and remaining work when applicable. Report only Git actions that actually occurred.
5. Material implementation limitations, such as manual non-cash confirmation or hardware testing still outstanding, when relevant to the task.

Keep claims proportionate to evidence. Do not state that a legacy security issue is verified without source evidence, that payment is gateway-verified when manually recorded, or that the project is production-ready because screens render or a build passes.
