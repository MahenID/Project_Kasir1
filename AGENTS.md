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

# PROJECT DEVELOPMENT RULES & WORKFLOW GUIDELINES

A modern Point of Sale (POS) project with separate backend (Laravel) and frontend (Next.js) architectures.

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

## 3. LARAVEL BACKEND DEVELOPMENT WORKFLOW (5 PHASES)
*From Migrations to Production Readiness*

### PHASE 1 – DATABASE
- **01. Migrations**: Design a structured database schema, key indexes, and relational data integrity constraints.
- **02. Models & Relations**: Create Eloquent models and define casts, timestamps, and relationships between tables (HasMany, BelongsTo, BelongsToMany, etc.).
- **03. Factories**: Provide model factories for mock data and automated test data generation.
- **04. Seeders**: Populate initial system data (predefined roles, administrator accounts, and default product categories).

### PHASE 2 – CORE APPLICATION
- **05. Repository Layer**: Abstract the data layer to separate database queries from business logic.
- **06. Service Layer**: Centralize application business logic (transaction calculations, stock inventory management, and promotions/discounts).
- **07. Form Requests**: Isolate request validation (validation rules, custom error messages, and request authorization).
- **08. Policies & Permissions**: Define access permissions for data operations (RBAC - Role-Based Access Control) through Laravel gates and policies.

### PHASE 3 – REST API
- **09. Controllers**: Use thin controllers to connect HTTP requests to the Service Layer.
- **10. API Resources**: Transform JSON data responses consistently, securely, and efficiently.
- **11. Routes & Middleware**: Define API routes (`routes/api.php`), API versioning, rate limiting, and the middleware pipeline.
- **12. Authentication**: Implement secure token authentication with Laravel Sanctum.

### PHASE 4 – INTEGRATION
- **13. Spatie Media Library**: Handle uploaded files (product images, receipt documents, and profile media).
- **14. Spatie Activitylog**: Record transaction audit trails, important data changes, and user activity logs.
- **15. Redis & Queue**: Implement high-performance caching and asynchronous background jobs (print queues and report summaries).
- **16. Events & Notifications**: Use an event-driven architecture to broadcast real-time transaction data and send notifications.

### PHASE 5 – QUALITY & RELEASE
- **17. Tests & API Docs**: Implement automated feature and unit tests, and provide complete API endpoint documentation.
- **18. Deployment & Monitoring**: Configure production servers, optimize Laravel caching, and monitor logs and errors.
- **-> PRODUCTION READY**

---

## 4. NEXT.JS FRONTEND DEVELOPMENT WORKFLOW (5 PHASES)
*From Project Setup to Production Readiness*

### PHASE 1 – FOUNDATION
- **01. Create Next.js App**: Establish the Next.js architecture using the App Router.
- **02. Project Structure**: Organize folders clearly (`app/`, `components/`, `lib/`, `hooks/`, `types/`, `services/`).
- **03. Environment Config**: Configure environment variables securely (`NEXT_PUBLIC_API_URL`, etc.).
- **04. Styles & UI**: Set up the visual theme, utility styling, modern typography, and the POS interface design system.

### PHASE 2 – CORE ARCHITECTURE
- **05. Types & Utils**: Define strict TypeScript interfaces and types for all data domains (Products, Transactions, Users), along with utility functions.
- **06. API Client**: Create a centralized HTTP client (Fetch/Axios wrapper) with a token interceptor and centralized error handling.
- **07. Auth Service**: Manage login sessions, secure access token storage, automatic token refresh, and logout.
- **08. Proxy & Route Guard**: Protect private pages through middleware and route users according to their roles (Admin vs. Cashier).

### PHASE 3 – UI DEVELOPMENT
- **09. Root Layout**: Define the application's root layout (POS sidebar, cashier header, and cashier status bar).
- **10. Shared Components**: Build a collection of reusable UI components (Button, Input, Modal, Badge, Dropdown, Table).
- **11. Pages & Server Components**: Create server-rendered pages for efficient initial data loading.
- **12. Client Components**: Build interactive transaction components (instant product catalog, payment/change calculator, and shopping cart).

### PHASE 4 – FEATURE INTEGRATION
- **13. Forms & Validation**: Build checkout and new product forms with client-side schema validation.
- **14. Feature Services**: Abstract API calls for each POS feature module.
- **15. Hooks & State**: Create custom React hooks to manage the cashier's shopping cart and active transaction state.
- **16. Role & Permission UI**: Adapt the interface to each user's permissions.

### PHASE 5 – QUALITY & RELEASE
- **17. Media Upload**: Build a product image upload interface with instant visual previews.
- **18. Loading, Error & Cache**: Handle loading states with skeletons, error boundaries, and client-side caching strategies.
- **19. Tests & Production Build**: Test component functionality and verify a clean production build (`npm run build`).
- **20. Deployment & Monitoring**: Configure deployment, optimize bundles, and monitor Web Vitals performance.
- **-> PRODUCTION READY**
