# VerdictTrace

**Turn messy support noise into defensible safety investigations.**

VerdictTrace is an audit-first safety investigation agent built on Elasticsearch. It continuously ingests support chats, emails, return notes, and repair records — then clusters hazard signals, builds fully traceable Evidence Packs, and routes flagged cases to a structured human review workflow. **No auto-recalls. Ever.**

> *The agent builds the case. The verdict belongs to humans.*

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4.svg)](https://php.net)
[![Elasticsearch](https://img.shields.io/badge/Elasticsearch-8.x-005571.svg)](https://elastic.co)

---

## What It Does

| Feature | Description |
|---------|-------------|
| **Hazard Clustering** | Elasticsearch hybrid retrieval (BM25 + knn dense vector) combined with multi-dimensional aggregations to surface statistically significant complaint clusters |
| **Evidence Pack Generation** | Auto-generates traceable evidence: DSL queries, trend charts, exemplar cases, extracted entities, confidence scores, and plain-language narratives |
| **Severity Tiering** | Four-tier system (Monitor → Investigate → Escalate → Critical) scored on complaint velocity, geographic spread, injury rate, and product scope |
| **Human-Gated Workflow** | Every signal enters an investigation queue requiring human sign-off. Every approval, escalation, and dismissal is logged as an immutable audit trail |
| **AI Agent Chat** | Conversational interface powered by Elasticsearch Agent Builder for natural language data exploration |
| **Email Alerts** | Automated escalation notifications via Brevo API |

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Vanilla PHP 8.1+ (no frameworks) |
| **Frontend** | Bootstrap 5, jQuery 3.7, Font Awesome 6, Chart.js 4 |
| **Database** | Elasticsearch 8.x (search, clustering, ML, case management) + MySQL (users, settings, audit) |
| **AI Agent** | Elasticsearch Agent Builder (ES|QL tools + custom agents) |
| **LLM** | OpenAI-compatible API (entity extraction, embeddings, narratives) |
| **Email** | Brevo API (transactional, not SMTP) |
| **Font** | Google Fonts — Outfit |

---

## Quick Start

### 1. Clone & Configure

```bash
git clone https://github.com/allanmukhwana/verdict-trace.git
cd verdict-trace
cp .env.example .env
# Edit .env with your credentials
```

### 2. Run Setup

```bash
# Creates MySQL tables, Elasticsearch indices, registers Agent Builder tools, seeds demo data
php setup.php
```

### 3. Start the Server

```bash
php -S localhost:8080
```

Open **http://localhost:8080** — you'll see the dashboard with demo data.

---

## Project Structure

Flat directory structure with prefix-based file grouping. Maximum 1 level of nesting.

```
verdict-trace/
├── config.php              # Environment loader & constants
├── db.php                  # MySQL database helper
├── es.php                  # Elasticsearch REST API helper (cURL, zero SDK)
├── llm.php                 # LLM API helper (entity extraction, embeddings)
├── email.php               # Brevo email API helper
├── agent.php               # Elasticsearch Agent Builder integration
├── header.php              # Shared HTML header & navigation
├── footer.php              # Shared HTML footer & mobile bottom nav
├── assets.css              # Custom stylesheet (Outfit font, mobile-first)
├── assets.js               # jQuery-based client JS (charts, chat, dropzone)
├── setup.php               # One-time setup (MySQL + ES + Agent Builder + demo data)
├── index.php               # Dashboard (KPIs, trends, recent cases)
├── case_list.php           # Investigation case listing with filters
├── case_view.php           # Case detail: Evidence Pack, actions, audit log
├── complaint_list.php      # Complaint browser with search & filters
├── complaint_view.php      # Single complaint detail view
├── ingest_upload.php       # Data ingestion: CSV upload + manual entry
├── evidence_list.php       # Evidence Pack gallery
├── agent_chat.php          # Full-page AI Agent conversational interface
├── agent_api.php           # Agent chat JSON API endpoint
├── scan.php                # Cluster detection scanner (confidence gate engine)
├── notification_list.php   # In-app notification center
├── settings.php            # Scanner config, system status, user management
├── auth_logout.php         # Session logout
├── uploads/                # Uploaded files
├── .env.example            # Environment variable template
├── .gitignore              # Git ignore rules
├── LICENSE                 # MIT License
├── DEPLOYMENT.md           # Full deployment guide
├── CONTRIBUTING.md         # Contribution guidelines
├── verdict-trace.md        # Detailed project writeup
└── writeup.md              # Summary writeup
```

---

## Elasticsearch Agent Builder

VerdictTrace integrates with [Elasticsearch Agent Builder](https://www.elastic.co/elasticsearch/agent-builder) to provide a conversational investigation interface.

### Registered Tools

| Tool ID | Description |
|---------|-------------|
| `verdictrace_search_complaints` | Search complaints by keyword, product, or failure mode |
| `verdictrace_cluster_summary` | Complaint clusters grouped by product SKU and failure mode |
| `verdictrace_active_cases` | List active investigation cases with severity tiers |
| `verdictrace_injury_analysis` | Analyze complaints mentioning injuries |
| `verdictrace_geo_distribution` | Geographic distribution of complaints |

### How It Works

```
User Question → agent_api.php → Agent Builder API → ES|QL → Results → LLM → Response
                     ↓ (fallback if Agent Builder not configured)
               Direct ES Query → Intent Detection → LLM Interpretation → Response
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for setup instructions.

---

## Confidence Gate Scoring

The scanner (`scan.php`) scores clusters using a weighted formula:

| Factor | Weight | Description |
|--------|--------|-------------|
| Volume | 30% | Complaint count (log-scaled) |
| Injury Rate | 35% | Proportion of complaints mentioning injury |
| Geographic Spread | 20% | Number of distinct regions affected |
| Velocity | 15% | Rate of complaint increase over time |

Clusters scoring above the configurable threshold (default: 0.7) generate Evidence Packs and enter the investigation queue.

---

## Color Palette

| Role | Color | Hex |
|------|-------|-----|
| Primary | Blue | `#003c8a` |
| Secondary | Dark Navy | `#001d42` |
| Background | White | `#ffffff` |

---

## Deployment

See **[DEPLOYMENT.md](DEPLOYMENT.md)** for the complete deployment guide covering:

- Elasticsearch (Cloud + self-hosted)
- Elasticsearch Agent Builder setup
- MySQL configuration
- Brevo email setup
- Apache / Nginx / PHP built-in server
- Cron job for automated scanning
- Production security checklist

---

## Contributing

See **[CONTRIBUTING.md](CONTRIBUTING.md)** for guidelines on:

- Development setup
- Coding standards
- Submitting pull requests
- Reporting issues

---

## Roadmap

- **Regulatory Report Drafting** — Auto-generate CPSC, NHTSA, or FDA format drafts from Evidence Packs
- **Supplier & Batch Correlation** — Cross-reference hazard clusters with supply chain data
- **Multi-Language Ingestion** — Support non-English complaint data
- **Closed-Loop Learning** — Feed confirmed outcomes back into anomaly baselines
- **Enterprise Connectors** — Zendesk, Salesforce Service Cloud, SAP QM integrations

---

## License

[MIT License](LICENSE) — Copyright (c) 2026 allanmukhwana

---

**VerdictTrace** — *The agent builds the case. The verdict belongs to humans.*
