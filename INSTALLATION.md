# Headless Sync Platform (HSP) — Installation & Verification Guide

This guide describes how to install, configure, run, and verify the Headless Sync Platform (HSP) plugin on your test WordPress website.

---

## 📋 Prerequisites

Before installing the plugin, ensure your test environment meets the following requirements:

1. **WordPress**: A working WordPress installation (v6.0+ recommended).
2. **PHP Version**: PHP 8.1 or higher (plugin developed and verified on PHP 8.3).
3. **PHP Extensions**: Ensure both MySQL and PostgreSQL PDO drivers are enabled in your `php.ini`:
   ```ini
   extension=pdo_mysql
   extension=pdo_pgsql
   ```
4. **PostgreSQL Database**: A running PostgreSQL database instance (accessible from the WordPress server) to store read-optimized projections.
5. **Composer**: Installed on the server to retrieve dependency packages.
6. **WP-CLI (Optional but Recommended)**: Installed on the server to execute the background worker.

---

## 🚀 Installation Steps

### Step 1: Deploy Plugin Files
Copy the `headless-sync` plugin directory into your WordPress installation's plugins folder:
```bash
# Path target
wp-content/plugins/headless-sync/
```

### Step 2: Install Composer Dependencies
Navigate to the plugin directory and install the production dependencies:
```bash
cd wp-content/plugins/headless-sync/
composer install --no-dev --optimize-autoloader
```

### Step 3: Configure the Database Connection
HSP connects to PostgreSQL to write projections. You can configure this connection by setting environment variables on your server, or by editing the configuration file.

#### Option A: Set Environment Variables (Recommended)
Define these variables in your web server config, Docker container environment, or system environment:
* `HSP_DB_HOST`: PostgreSQL hostname (e.g. `127.0.0.1` or `postgres`)
* `HSP_DB_PORT`: PostgreSQL port (default: `5432` or `5433`)
* `HSP_DB_NAME`: PostgreSQL database name
* `HSP_DB_USER`: PostgreSQL username
* `HSP_DB_PASSWORD`: PostgreSQL password

#### Option B: Edit Configuration File
If you cannot use environment variables, edit the database configuration directly in:
`wp-content/plugins/headless-sync/config/database.php`

---

## 🔌 Activation

1. Log in to your WordPress Admin Dashboard.
2. Navigate to **Plugins** > **Installed Plugins**.
3. Locate **Headless Sync Platform (HSP)** and click **Activate**.
   * *Alternatively, activate via WP-CLI:*
     ```bash
     wp plugin activate headless-sync --allow-root
     ```

> [!NOTE]
> Upon activation, the plugin automatically executes its internal migrations. It will connect to your PostgreSQL database and create the `system` and `content` schemas along with all tracking and projection tables.

---

## ⚙️ Running the Services

The sync architecture relies on two separate background services running alongside WordPress:

### 1. The Background Worker
The worker polls the PostgreSQL outbox queue (`system.queue_jobs`) and performs the sync transformations.

* **To run locally/manually:**
  ```bash
  wp headless-sync worker run --queue=content --allow-root
  ```
* **For Production:**
  Use a process supervisor (like **Supervisor** or a **systemd service**) to keep the worker running continuously in the background and restart it if it exits.
  
  Example supervisor config block:
  ```ini
  [program:hsp-worker]
  command=wp headless-sync worker run --queue=content --allow-root
  directory=/var/www/html
  autostart=true
  autorestart=true
  user=www-data
  ```

### 2. The REST Delivery API
This light-weight PHP server serves the read-optimized projection data from PostgreSQL to frontend consumers.

* **To run locally/manually:**
  Navigate to the `headless-sync` plugin folder and start the built-in PHP server:
  ```bash
  cd wp-content/plugins/headless-sync/
  php -S localhost:9000 delivery-api.php
  ```

---

## 🔍 Verification: Check if it's Working

Follow these steps to verify that the sync engine is working correctly end-to-end:

### Step 1: Create a Post
1. In the WordPress Admin, go to **Posts** > **Add New**.
2. Title it `Verification Test Post`, add some content, and click **Publish**.

### Step 2: Verify Outbox Generation
Connect to your PostgreSQL database and query the outbox tables. You should see the generated event:
```sql
-- Check that the outbox event was captured
SELECT event_type, aggregate_type, aggregate_id, aggregate_version 
FROM system.events 
ORDER BY created_at DESC LIMIT 1;
```
*Expected Output:* `content.post.created` event with your post ID.

### Step 3: Verify the Job in Queue
```sql
-- Check that a job is waiting in the queue
SELECT job_id, queue_name, status, attempts 
FROM system.queue_jobs;
```
*Expected Output:* Status should be `'queued'`.

### Step 4: Run the Worker
Execute the worker CLI once to process the queue:
```bash
wp headless-sync worker run --queue=content --stop-when-empty --allow-root
```
*Expected Output:*
```text
Starting background worker for queue: content
Success: Worker execution completed successfully.
```

### Step 5: Check the Projection Database
Verify that the post was successfully transformed and written to the `content` schema:
```sql
SELECT title, slug, status, source_post_id 
FROM content.posts;
```
*Expected Output:* A row corresponding to `Verification Test Post` with status `'publish'`.

### Step 6: Query the REST Delivery API
Send a request to the Delivery API server to verify that the frontend consumer endpoint is working:
```bash
curl http://localhost:9000/api/v1/posts?slug=verification-test-post
```
*Expected Output:*
```json
{
  "id": "...",
  "source_post_id": "...",
  "slug": "verification-test-post",
  "title": "Verification Test Post",
  "excerpt": "...",
  "content": "...",
  "status": "publish",
  "created_at": "...",
  "updated_at": "...",
  "categories": []
}
```

---

## 🛠️ Troubleshooting

* **PDO Exception (pgsql not found)**: Make sure you restart your web server/PHP-FPM service after enabling `extension=pdo_pgsql` in your `php.ini`.
* **Events not appearing in `system.events`**: Verify that your WordPress database user has network access to the PostgreSQL server. Check your web server error logs for database connection warnings.
* **REST API returns 404 for posts**: Ensure the worker processed the job and the record exists in the `content.posts` table.
