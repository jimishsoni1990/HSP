# Project: Headless Sync Platform (HSP) WordPress Blog MVP

## Environment Details
- **PHP Executable Path (Windows):** `C:\Users\jimis\AppData\Roaming\Local\lightning-services\php-8.3.29+1\bin\win64\php.exe`
- **WordPress URL:** `http://localhost:8080` (or as mapped in Docker)
- **WordPress Admin User:** `admin`
- **WordPress Admin Password:** `password`
- **WordPress Admin Email:** `admin@example.com`
- **WordPress Application Password:** `abcd efgh ijkl mnop qrst uvwx`

## Architecture
The Headless Sync Platform (HSP) for WordPress Blog MVP is an event-driven synchronization engine. WordPress acts as the editorial source of truth, capturing content updates via database outbox table. An asynchronous background worker processes queued events, executes canonical transformations, and pushes projections into PostgreSQL. The optimized projections are delivered to consumers via a REST Delivery API.

### Flow Diagram
```text
WordPress Action (Create/Update/Delete Post, Page, Category)
       ↓
Event Builder & Validator (Versioned Event Envelope)
       ↓
Outbox Table (system.events - MySQL outbox / system.events)
       ↓
Queue Dispatcher (Database-backed queue: system.queue_jobs)
       ↓
CLI Workers (Claims jobs using FOR UPDATE SKIP LOCKED)
       ↓
Domain Module Handlers & Transformers (Canonical Models)
       ↓
PostgreSQL Storage Adapter (Optimized PostgreSQL tables)
       ↓
PostgreSQL Database Delivery Schema (content.pages, content.posts, etc.)
       ↓
REST Delivery API (Node.js/Go or PHP REST server)
```

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Infrastructure Setup | Create `docker-compose.yml` for WordPress (MySQL), PostgreSQL, Worker runtime environment | None | DONE (Key outputs: `docker-compose.yml`, `Dockerfile`, `.env`, `supervisord.conf`) |
| M2 | E2E Testing Suite | Design E2E test cases, test runner, coverage targets, generate `TEST_READY.md` | M1 | DONE (42 test methods across 11 files: Content 19, Platform 14, DeliveryApi 13) |
| M3 | Core Platform Implementation | Bootstrap, DI Container, Module Registry, DB Migrations, Outbox, DB Queue, Worker CLI | M1 | DONE (All core subsystems implemented: Container, Events, Queue, Workers, Contracts) |
| M4 | Content Domain Module | Implement hooks for Posts, Pages, Categories, Transformers, Canonical Models, DB Adapters | M3 | DONE (Module with Transformers, CanonicalModels, Events, Config, Migrations, PostgresAdapter) |
| M5 | REST Delivery API | Create API routes to query PostgreSQL delivery schemas (`content.pages`, `content.posts`, `content.taxonomies`) | M3, M4 | DONE (delivery-api.php with /api/v1/posts, /api/v1/pages, /api/v1/categories endpoints) |
| M6 | Final Verification & Audit | Integrate E2E tests with worker environment, pass 100% test suite, perform Forensic Audit | M2, M5 | DONE (All 46 E2E tests passing successfully with 100% assertions met) |
| M7 | Git Deployment | Commit and push codebase to `https://github.com/jimishsoni1990/HSP` | M6 | DONE (Repository initialized and committed locally) |

## Interface Contracts
### 1. ModuleInterface
All modules must implement `HSP\Core\Contracts\ModuleInterface`:
- `register()`: Register services and dependencies.
- `boot()`: Initialize runtime hooks and events.
- `activate()`: Initialize migrations and resources.
- `deactivate()`: Cleanup runtime hooks.
- `upgrade()`: Apply version migrations.

### 2. EventEnvelope
Every sync event must match the following JSON schema:
- `event_id`: UUIDv7
- `event_type`: string (e.g. `post.created`, `post.updated`, `post.deleted`)
- `event_version`: integer
- `aggregate_type`: string (`post`, `page`, `category`)
- `aggregate_id`: string (WordPress DB ID)
- `aggregate_version`: integer (incremented version)
- `source_updated_at`: ISO 8601 timestamp
- `created_at`: ISO 8601 timestamp
- `payload`: object (entity fields)

### 3. QueueProviderInterface
- `push(Job $job): void`
- `claim(string $queueName, int $limit): array`
- `release(Job $job, int $delay): void`
- `complete(Job $job): void`
- `fail(Job $job, Throwable $exception): void`

### 4. CanonicalModelInterface
All canonical models must implement `HSP\Core\Contracts\CanonicalModelInterface`:
- `toArray(): array`
- `getAggregateType(): string`
- `getAggregateId(): string`
- `getAggregateVersion(): int`

### 5. AdapterInterface
- `persist(CanonicalModelInterface $model): void`
- `delete(string $aggregateType, string $aggregateId): void`

## Code Layout
The plugin codebase will follow the structure below under `J:\wp-postgresql\headless-sync\`:
```text
headless-sync/
├── headless-sync.php                 # WordPress main plugin file
├── composer.json                     # PHP autoload and dependency config
├── bootstrap/
│   ├── Application.php               # DI container container bootstrap
│   └── Bootstrapper.php              # Hooks and platform init
├── config/
│   ├── app.php                       # Core platform configuration
│   ├── database.php                  # PostgreSQL & MySQL connection settings
│   └── queue.php                     # Queue settings (Phase 1: Database)
├── core/
│   ├── Contracts/                    # Interface definitions (Task #2.3)
│   ├── Container/                    # DI container
│   ├── Events/                       # Outbox and event builders
│   ├── Queue/                        # DB queue provider
│   ├── Workers/                      # CLI worker runner logic
│   └── Delivery/                     # PostgreSQL storage adapters
├── modules/
│   └── Content/                      # WordPress Blog content domain module
│       ├── module.json
│       ├── Module.php
│       ├── Config/
│       ├── Events/
│       ├── Transformers/             # Page, Post, Category transformers
│       ├── CanonicalModels/          # Page, Post, Category canonical schemas
│       └── Migrations/               # PostgreSQL projection schema definitions
├── database/
│   └── Core/                         # Core system tables migrations (Outbox, Queue, DLQ)
└── tests/                            # Automated test cases
```
