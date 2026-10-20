# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working in this repository.

## Project architecture

- Laravel 12 is the source of truth for authentication, authorization, validation, data, and lifecycle. Public pages are Blade-rendered through `routes/web.php`; the admin is a Vue 3 + Vuetify SPA mounted at `/admin`, calling APIs in `routes/api.php`.
- Admin APIs use Sanctum bearer tokens and Spatie permissions. Enforce access in Laravel, not in frontend visibility. Return API JSON through `App\Http\Responses\BaseResponse`; frontend services unwrap the envelope.
- Backend request flow is route/middleware → FormRequest → controller orchestration → Action/Service/Repository/Model → Resource/`BaseResponse`. Controllers should not own multi-step business rules or transaction logic.
- The Vue entry is `resources/js/main.js`; `vite.config.js` discovers pages in `resources/js/pages` and auto-imports Vue/VueUse/Pinia APIs. Pages compose feature views, composables, stores, and API services. Frontend domain code is JavaScript, Composition API, and `<script setup>`; the project has not adopted TypeScript for application modules.
- Resource Template is a separate asynchronous lifecycle: private ZIP/artifact processing and validation, version readiness/promote/archive, stateless preview, and public catalog/download/share. Read `docs/api/RESOURCE_TEMPLATE_API.md` and `docs/plans/RESOURCE_TEMPLATE.md` before changing it.
- Media assets are owned through `MediaAsset` and usage relations; upload validation, archive scanning, and asset attachment are backend boundaries. Blog `Post` and Marketing `SocialPost` are separate domains.
- AI Content runs through `/api/admin/ai-agent/*`: session creation stores an `AiImport` and queues `ProcessAiImportJob`; `ArticleImportService` extracts/sanitizes source and selects outputs; Post body generation uses `ArticleGenerationPipeline` (Analyze/Plan → Write → Edit). `AiContentReviewService` handles edit/review/approve/reject, and approved Post source snapshots go through `AiArticleArchiveService`. `generation_meta.content_origin` is required; regeneration from a parent without it is rejected. `requested_outputs` is required for session creation, but source preview only extracts and does not require outputs. Social AI content creates a Social Post draft via `SocialPostLifecycleService`, never a blog Post.

## Common commands

Prerequisites: PHP 8.2+, Composer, Node.js/npm 10+, SQLite by default (or another Laravel-compatible database). Local setup from the README:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm ci
```

If using SQLite and `database/database.sqlite` does not exist, create it before migrating. In PowerShell, use `Copy-Item .env.example .env` instead of `cp`.

Run the app in separate terminals, or use the Composer concurrent dev script:

```bash
php artisan serve
npm run dev
composer dev
```

Checks and focused test commands:

```bash
php artisan test
php artisan test tests/Feature/AiArticleQualityEvaluationTest.php
php artisan test --filter='AiArticleQualityEvaluationTest::test_quality_gate_blocks_approval_until_ready_eligible_evaluation'
npm run test:run
npx vitest run tests/frontend/aiAgentService.test.js
npm run test:e2e
npm run build
npx eslint resources/js/services/aiAgent.js -c .eslintrc.cjs
```

`npm run lint` runs ESLint with `--fix` and modifies files. `composer test` clears Laravel config and runs PHPUnit. E2E uses a temporary SQLite database and its own server; see `tests/e2e/README.md`. `VITE_ENABLE_MSW=true` is for template/demo mock endpoints only; use `false` when developing against Laravel, and never enable MSW in production.

## Repository-specific constraints

- Read `docs/architecture/DEVELOPMENT_GUIDELINES.md` before editing PHP/Vue feature code. PHP files/classes/methods require Vietnamese structured comments documenting purpose, inputs/outputs, side effects, and transaction/exception behavior. Every new or edited `.vue` file requires the Vietnamese header block listing purpose, important functions/computed/watchers, and component inputs/outputs; update it when behavior changes.
- Follow the required `AppDialogLayout` pattern for application dialogs: fixed header/footer, only the body scrolls, and preserve state until `after-leave`. Add/Edit admin pages should follow the canonical Vuexy Product Add layout documented in `DEVELOPMENT_GUIDELINES.md`.
- For new backend behavior, keep auth, permission, validation, and lifecycle on the server. AI approval uses `/ai-agent/candidates/{id}/approve` with both `expected_version` and `expected_review_version`; it creates a new Post draft, not an update to an existing Post. The existing Post form can still submit AI provenance (`ai_run_id`/`ai_fields` or `ai_runs`) through normal Post creation.
- Queue work is asynchronous. Local defaults are SQLite and the database queue; provider calls happen in jobs, not request handlers. Do not infer that a ready candidate has passed quality evaluation—the backend gate decides eligibility.
- Check `docs/plans/PLAN.md` for current scope/deferred work, `docs/architecture/PROJECT_STRUCTURE.md` for architectural detail, `docs/architecture/PROJECT_INVENTORY.md` for module/API lookup, and `docs/operations/ENVIRONMENT.md` for queue/storage/provider configuration.
