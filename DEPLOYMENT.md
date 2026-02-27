# VerdictTrace Deployment Guide

This guide walks you through deploying VerdictTrace from scratch. The application uses **vanilla PHP**, **MySQL**, **Elasticsearch**, and optionally **Elasticsearch Agent Builder**.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Elasticsearch Setup](#elasticsearch-setup)
3. [Elasticsearch Agent Builder Setup](#elasticsearch-agent-builder-setup)
4. [MySQL Setup](#mysql-setup)
5. [PHP Application Setup](#php-application-setup)
6. [Brevo Email Configuration](#brevo-email-configuration)
7. [LLM API Configuration](#llm-api-configuration)
8. [Running the Application](#running-the-application)
9. [Cron Job (Automated Scanning)](#cron-job-automated-scanning)
10. [Production Deployment](#production-deployment)
11. [Troubleshooting](#troubleshooting)

---

## Prerequisites

| Requirement       | Minimum Version | Notes                                    |
| ----------------- | --------------- | ---------------------------------------- |
| PHP               | 8.1+            | With `curl`, `pdo_mysql`, `json` extensions |
| MySQL             | 5.7+ / 8.0+    | Or MariaDB 10.3+                          |
| Elasticsearch     | 8.x             | Cloud or self-hosted                      |
| Web Server        | Apache / Nginx  | Or PHP built-in server for development    |
| cURL              | Any             | PHP cURL extension required               |

---

## Elasticsearch Setup

### Option A: Elastic Cloud (Recommended)

1. **Sign up** for a free trial at [cloud.elastic.co](https://cloud.elastic.co/registration)
2. **Create a deployment** — select the region closest to your users
3. **Note your credentials:**
   - Elasticsearch endpoint URL (e.g., `https://my-deployment.es.us-central1.gcp.cloud.es.io`)
   - API Key: Go to **Management → API Keys → Create API Key**
   - Kibana URL (for Agent Builder): found in your deployment overview

### Option B: Self-Hosted Elasticsearch

1. Download Elasticsearch 8.x from [elastic.co/downloads](https://www.elastic.co/downloads/elasticsearch)
2. Start Elasticsearch:
   ```bash
   ./bin/elasticsearch
   ```
3. Default endpoint: `http://localhost:9200`
4. Generate an API key:
   ```bash
   curl -X POST "localhost:9200/_security/api_key" -H "Content-Type: application/json" -d '{
     "name": "verdictrace-key",
     "role_descriptors": {
       "verdictrace": {
         "cluster": ["monitor"],
         "index": [{ "names": ["verdictrace_*"], "privileges": ["all"] }]
       }
     }
   }'
   ```

---

## Elasticsearch Agent Builder Setup

Agent Builder is a feature of Elasticsearch that lets you create custom AI agents with ES|QL tools. VerdictTrace uses it for the conversational investigation interface.

### Step 1: Enable Agent Builder

- **Elastic Cloud Serverless**: Enabled by default. Find **Agents** in the navigation menu.
- **Elastic Cloud Hosted**: Navigate to your Kibana space → look for **Agents** in the sidebar.
- **Self-Hosted**: Switch to the Elasticsearch solution navigation, then find **Agents** in the sidebar.

> **Reference**: [Get Started with Agent Builder](https://www.elastic.co/docs/explore-analyze/ai-features/agent-builder/get-started)

### Step 2: Get Kibana API Key

1. In Kibana, go to **Management → API Keys**
2. Create a new API key with permissions for Agent Builder
3. Copy the encoded API key value

### Step 3: Configure in `.env`

```
KIBANA_URL=https://your-deployment.kb.us-central1.gcp.cloud.es.io
KIBANA_API_KEY=your_kibana_api_key_here
```

### Step 4: Register Tools & Agent

Run the setup script (browser or CLI):
```bash
php setup.php
```

This will automatically:
- Register 5 custom ES|QL tools (search complaints, cluster summary, active cases, injury analysis, geographic distribution)
- Create the **VerdictTrace Safety Investigator** agent with all tools assigned

### Step 5: Verify in Kibana

1. Open Kibana → **Agents**
2. You should see "VerdictTrace Safety Investigator" in the agent list
3. Test it by chatting: "Show me recent overheating complaints"

### How Agent Builder Works in VerdictTrace

```
User Question → agent_api.php → Agent Builder API → ES|QL Query → Results → LLM → Response
                    ↓ (fallback)
              Local ES Query → LLM Interpretation → Response
```

If Agent Builder is not configured, the app falls back to direct Elasticsearch queries with local intent detection and optional LLM interpretation.

---

## MySQL Setup

1. Create the database:
   ```sql
   CREATE DATABASE verdict_trace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Create a database user (optional but recommended):
   ```sql
   CREATE USER 'verdictrace'@'localhost' IDENTIFIED BY 'your_secure_password';
   GRANT ALL PRIVILEGES ON verdict_trace.* TO 'verdictrace'@'localhost';
   FLUSH PRIVILEGES;
   ```

---

## PHP Application Setup

### Step 1: Clone the Repository

```bash
git clone https://github.com/allanmukhwana/verdict-trace.git
cd verdict-trace
```

### Step 2: Create `.env` File

```bash
cp .env.example .env
```

Edit `.env` with your actual credentials:

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=verdict_trace
DB_USER=verdictrace
DB_PASS=your_secure_password

ES_HOST=https://your-deployment.es.us-central1.gcp.cloud.es.io
ES_API_KEY=your_elasticsearch_api_key

KIBANA_URL=https://your-deployment.kb.us-central1.gcp.cloud.es.io
KIBANA_API_KEY=your_kibana_api_key

LLM_API_KEY=your_openai_api_key
BREVO_API_KEY=your_brevo_api_key
BREVO_SENDER_EMAIL=alerts@yourdomain.com

APP_URL=http://localhost:8080
```

### Step 3: Run Setup

**Via browser:** Navigate to `http://localhost:8080/setup.php`

**Via CLI:**
```bash
php setup.php
```

This will:
- Create MySQL tables (`users`, `ingest_sources`, `ingest_log`, `notifications`, `settings`)
- Create Elasticsearch indices (`verdictrace_complaints`, `verdictrace_cases`)
- Register Agent Builder tools and agent (if configured)
- Seed demo data for testing

### Step 4: Verify

Open `http://localhost:8080` — you should see the Dashboard with demo data.

---

## Brevo Email Configuration

VerdictTrace uses **Brevo (formerly Sendinblue) API** for transactional emails (not SMTP).

1. Sign up at [brevo.com](https://www.brevo.com)
2. Go to **SMTP & API → API Keys**
3. Generate a new API key
4. Add to `.env`:
   ```
   BREVO_API_KEY=xkeysib-your-api-key-here
   BREVO_SENDER_NAME=VerdictTrace
   BREVO_SENDER_EMAIL=alerts@yourdomain.com
   ```
5. **Important:** Verify your sender email in Brevo's **Senders** section

---

## LLM API Configuration

Used for: entity extraction, embedding generation, Evidence Pack narratives.

1. Get an API key from [OpenAI](https://platform.openai.com/api-keys) (or any compatible API)
2. Add to `.env`:
   ```
   LLM_API_KEY=sk-your-openai-key
   LLM_MODEL=gpt-4o
   LLM_EMBEDDING_MODEL=text-embedding-3-small
   ```

> **Note:** The application works without LLM configured — entity extraction and narratives will use basic fallbacks.

---

## Running the Application

### Development (PHP Built-in Server)

```bash
php -S localhost:8080
```

Then open `http://localhost:8080` in your browser.

### Apache

Create a VirtualHost or place the files in your web root:

```apache
<VirtualHost *:80>
    ServerName verdictrace.local
    DocumentRoot /path/to/verdict-trace
    
    <Directory /path/to/verdict-trace>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx

```nginx
server {
    listen 80;
    server_name verdictrace.local;
    root /path/to/verdict-trace;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(env|git) {
        deny all;
    }
}
```

---

## Cron Job (Automated Scanning)

Set up a cron job to run the cluster scanner periodically:

```bash
# Run every hour
0 * * * * php /path/to/verdict-trace/scan.php >> /path/to/verdict-trace/scan.log 2>&1

# Run every 30 minutes
*/30 * * * * php /path/to/verdict-trace/scan.php >> /path/to/verdict-trace/scan.log 2>&1
```

---

## Production Deployment

### Security Checklist

- [ ] Set `APP_ENV=production` in `.env`
- [ ] Ensure `.env` is NOT publicly accessible (`.gitignore` handles this)
- [ ] Block access to `.env`, `.git`, and `.md` files via web server config
- [ ] Use HTTPS for all connections
- [ ] Set strong passwords for MySQL and Elasticsearch
- [ ] Restrict Elasticsearch API key permissions to `verdictrace_*` indices only
- [ ] Enable PHP OPcache for performance

### Recommended Hosting

- **Shared Hosting:** Works out of the box — upload files, create MySQL database, configure `.env`
- **VPS (DigitalOcean, Linode, etc.):** Full control with Apache/Nginx + PHP-FPM
- **Docker:** Not required but can be added easily (no framework dependencies)

---

## Troubleshooting

| Issue | Solution |
|-------|---------|
| "Missing .env file" | Copy `.env.example` to `.env` and fill in credentials |
| Elasticsearch connection error | Check `ES_HOST` and `ES_API_KEY` in `.env`. Test with `curl <ES_HOST>` |
| MySQL connection error | Verify `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` in `.env` |
| Agent Builder not working | Ensure `KIBANA_URL` and `KIBANA_API_KEY` are set. Re-run `setup.php` |
| Emails not sending | Verify Brevo API key and sender email. Check Brevo dashboard for logs |
| No clusters detected | Lower `cluster_min_docs` in Settings, or ingest more complaint data |
| LLM errors | Check `LLM_API_KEY` and ensure the API endpoint is reachable |
