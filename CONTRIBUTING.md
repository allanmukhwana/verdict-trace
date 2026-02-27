# Contributing to VerdictTrace

Thank you for your interest in contributing to VerdictTrace! This is an open-source project and we welcome contributions from the community.

---

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How to Contribute](#how-to-contribute)
- [Development Setup](#development-setup)
- [Project Structure](#project-structure)
- [Coding Standards](#coding-standards)
- [Submitting Changes](#submitting-changes)
- [Reporting Issues](#reporting-issues)

---

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](https://www.contributor-covenant.org/version/2/1/code_of_conduct/). By participating, you agree to uphold this standard.

---

## How to Contribute

### Types of Contributions Welcome

- **Bug fixes** — Find and fix issues in the codebase
- **Feature development** — Implement items from the roadmap or propose new features
- **Documentation** — Improve guides, add examples, fix typos
- **Testing** — Add test coverage, report edge cases
- **UI/UX improvements** — Enhance the interface, improve mobile experience
- **Elasticsearch optimizations** — Improve query performance, mapping design
- **Security improvements** — Identify and fix security vulnerabilities

### Before Starting

1. Check [existing issues](https://github.com/allanmukhwana/verdict-trace/issues) to avoid duplicate work
2. For large changes, open an issue first to discuss the approach
3. Fork the repository and create a feature branch

---

## Development Setup

### Prerequisites

- PHP 8.1+ with `curl`, `pdo_mysql`, `json` extensions
- MySQL 5.7+ or MariaDB 10.3+
- Elasticsearch 8.x (local or Elastic Cloud free trial)

### Quick Start

```bash
# 1. Fork and clone
git clone https://github.com/YOUR_USERNAME/verdict-trace.git
cd verdict-trace

# 2. Configure environment
cp .env.example .env
# Edit .env with your local credentials

# 3. Run setup
php setup.php

# 4. Start development server
php -S localhost:8080

# 5. Open http://localhost:8080
```

---

## Project Structure

VerdictTrace uses a **flat directory structure** with prefix-based file grouping. Maximum 1 level of nesting.

```
verdict-trace/
├── config.php              # Environment loader & constants
├── db.php                  # MySQL database helper
├── es.php                  # Elasticsearch REST API helper
├── llm.php                 # LLM API helper (OpenAI-compatible)
├── email.php               # Brevo email API helper
├── agent.php               # Elasticsearch Agent Builder integration
├── header.php              # Shared HTML header & navigation
├── footer.php              # Shared HTML footer & bottom nav
├── assets.css              # Custom stylesheet
├── assets.js               # Custom JavaScript (jQuery-based)
├── setup.php               # One-time setup script
├── index.php               # Dashboard
├── case_list.php           # Case listing page
├── case_view.php           # Case detail + Evidence Pack view
├── complaint_list.php      # Complaint listing page
├── complaint_view.php      # Complaint detail view
├── ingest_upload.php       # Data ingestion (CSV + manual entry)
├── evidence_list.php       # Evidence Pack listing
├── agent_chat.php          # AI Agent chat interface (full page)
├── agent_api.php           # Agent chat JSON API endpoint
├── scan.php                # Cluster detection scanner engine
├── notification_list.php   # Notification center
├── settings.php            # Application settings
├── auth_logout.php         # Session logout
├── .env.example            # Environment template
├── .gitignore              # Git ignore rules
├── uploads/                # Uploaded files directory
├── LICENSE                 # MIT License
├── README.md               # Project documentation
├── DEPLOYMENT.md           # Deployment guide
├── CONTRIBUTING.md         # This file
├── verdict-trace.md        # Hackathon writeup (detailed)
└── writeup.md              # Hackathon writeup (summary)
```

### Naming Conventions

- **Module pages**: `modulename_action.php` (e.g., `case_list.php`, `case_view.php`)
- **Helper files**: `helpername.php` (e.g., `db.php`, `es.php`, `llm.php`)
- **Shared UI**: `header.php`, `footer.php`, `assets.css`, `assets.js`

---

## Coding Standards

### PHP

- **No frameworks** — vanilla PHP only. This is a deliberate architectural choice for transparency and auditability.
- **Comment extensively** — every file must have a header docblock explaining its purpose. Functions must have PHPDoc comments.
- **Use helper functions** — all MySQL queries go through `db.php`, all Elasticsearch calls through `es.php`.
- **No raw `echo` in logic files** — separate data fetching from rendering.
- **Use prepared statements** — never concatenate user input into SQL queries.
- **Follow PSR-12** coding style where applicable.

### JavaScript

- **jQuery-based** — use jQuery for DOM manipulation and AJAX.
- **No build step** — all JS is vanilla, loaded directly.
- **Comment functions** — use JSDoc-style comments.

### CSS

- **CSS custom properties** — use `var(--vt-*)` variables defined in `assets.css`.
- **Mobile-first** — design for mobile, enhance for desktop.
- **BEM-like naming** — use `vt-` prefix for custom classes.

### Elasticsearch

- **All queries via `es.php`** — never make direct cURL calls from page files.
- **Store DSL queries** — every Evidence Pack must include the originating DSL queries for traceability.
- **Index naming** — use `verdictrace_` prefix for all indices.

---

## Submitting Changes

1. **Fork** the repository
2. **Create a branch**: `git checkout -b feature/your-feature-name`
3. **Make your changes** following the coding standards above
4. **Test thoroughly** — verify on both desktop and mobile
5. **Commit** with clear messages:
   ```
   feat: add supplier correlation for hazard clusters
   fix: correct confidence score calculation for single-region clusters
   docs: update deployment guide for Docker setup
   ```
6. **Push** to your fork: `git push origin feature/your-feature-name`
7. **Open a Pull Request** against the `main` branch

### PR Requirements

- Clear description of what changed and why
- Screenshots for UI changes (desktop + mobile)
- No breaking changes to existing functionality without discussion
- All existing demo data and setup scripts must still work

---

## Reporting Issues

When reporting bugs, please include:

1. **Steps to reproduce**
2. **Expected behavior** vs **actual behavior**
3. **Environment details** (PHP version, Elasticsearch version, browser, OS)
4. **Error messages** (from browser console, PHP error log, or Elasticsearch response)
5. **Screenshots** if it's a UI issue

---

## Roadmap

See the "What's Next" section in `verdict-trace.md` for planned features:

- Regulatory report drafting (CPSC, NHTSA, FDA formats)
- Supplier & batch correlation
- Multi-language ingestion
- Closed-loop learning
- Enterprise connectors (Zendesk, Salesforce, SAP QM)

---

## License

By contributing to VerdictTrace, you agree that your contributions will be licensed under the [MIT License](LICENSE).
