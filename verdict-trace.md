## Inspiration

Product recalls are expensive, slow, and almost always reactive. The real challenge isn't knowing something is wrong — it's proving it defensibly enough to act. Safety and quality teams are drowning in support tickets, repair logs, and return notes with no systematic way to ask: *"Is this a one-off complaint, or a pattern that puts people at risk?"*

We were inspired by high-stakes industries — automotive, medical devices, consumer electronics — where a missed hazard signal isn't a KPI problem, it's a human safety problem. Yet the tools available are either crude dashboards that cry wolf constantly, or manual spreadsheet reviews that take weeks and still miss things.

We wanted to build something in between: an agent that does the investigative legwork — retrieving, clustering, scoring, and explaining signals — but never removes the human from the final judgment. Elasticsearch's hybrid search, aggregation pipeline, and anomaly detection made it the natural engine for this kind of investigation-grade work. And vanilla PHP made it accessible, lightweight, and deployable without a heavy framework standing between the logic and the server.

---

## What It Does

VerdictTrace is an audit-first safety investigation agent built on Elasticsearch. It continuously ingests support chats, emails, return notes, and repair records, then does four things:

**1. Hazard Clustering** — Uses Elasticsearch hybrid retrieval (BM25 + dense vector search) combined with time, geo, and product aggregations to surface statistically significant complaint clusters that would be invisible in raw ticket volume.

**2. Evidence Pack Generation** — For every flagged cluster, VerdictTrace auto-generates a structured Evidence Pack containing: the exact DSL queries that surfaced the signal, trend charts, representative exemplar cases, extracted entities (product SKUs, failure modes, locations, date ranges), and a confidence gate score explaining exactly why the threshold was met.

**3. Severity Tiering** — Clusters are scored across four tiers — Monitor → Investigate → Escalate → Critical — based on complaint velocity, geographic spread, injury mention rate, and product scope.

**4. Human-Gated Investigation Workflow** — VerdictTrace does NOT auto-trigger recalls or regulator outreach. Every flagged signal enters a structured investigation queue requiring human sign-off at each tier transition. Every approval, escalation, and dismissal is logged for full auditability.

---

## How We Built It

VerdictTrace is built with **vanilla PHP** on the backend and **Elasticsearch** as the core intelligence layer — not just a search index, but the actual computation engine driving retrieval, clustering, anomaly scoring, and case management.

**Data Ingestion Pipeline**
Support tickets, emails, return logs, and repair notes are normalized via a PHP ingestion script and indexed into Elasticsearch with carefully designed mappings. Each document carries structured fields for product identifiers, complaint categories, extracted failure mode entities, geographic coordinates, timestamps, and severity keywords. An LLM API call handles entity extraction and generates semantic embeddings before each document is indexed.

**Hybrid Retrieval Engine**
Each ingestion cycle triggers hybrid Elasticsearch queries combining BM25 keyword scoring — catching explicit terms like "overheating" or "battery swelling" — with `knn` dense vector search for semantically related complaints that don't share the same vocabulary. PHP's `curl` handles all Elasticsearch REST API communication directly, keeping the stack lean with zero external SDK dependencies.

**Aggregation-Driven Clustering**
Elasticsearch aggregation pipelines group complaints by `product_sku × failure_mode × time_window × geo_region` simultaneously. Elasticsearch ML anomaly detection jobs monitor rolling complaint baselines and flag clusters deviating beyond configurable z-score thresholds. The PHP agent polls these job results and applies additional confidence gate logic before promoting a cluster to an Evidence Pack.

**Evidence Pack Builder**
When a cluster crosses a confidence gate, a PHP orchestration script replays the exact DSL queries, pulls representative exemplar documents, formats aggregation results into chart-ready JSON, and calls an LLM API to generate a plain-language narrative summary. Every element in the Evidence Pack is traceable to its source Elasticsearch query — nothing is inferred from LLM memory alone.

**Elasticsearch Agent Builder Integration**
We integrated Elasticsearch Agent Builder to provide a conversational investigation interface. Five custom ES|QL tools were registered via the Kibana API — covering complaint search, cluster summaries, active cases, injury analysis, and geographic distribution. A custom VerdictTrace agent was created with investigation-focused system instructions, connecting all tools into a single conversational experience. The PHP backend communicates with Agent Builder via the Kibana REST API, with a local fallback that uses direct Elasticsearch queries and LLM interpretation when Agent Builder is not configured.

**Investigation Workflow & Case Index**
Flagged cases are written into a dedicated `verdictrace_cases` Elasticsearch index serving as the case management store. A Bootstrap 5 + jQuery web UI — styled with Google Fonts (Outfit) and Font Awesome icons — presents the Evidence Pack to investigators with a mobile-first, app-like interface. Investigators can approve tier escalation, request additional evidence, or dismiss with documented reasoning. Every decision is written back to the case document as an immutable audit log entry. Escalation actions trigger transactional email alerts via the Brevo API (not SMTP) to notify the safety team.

**MySQL for Operational Data**
While Elasticsearch handles all complaint data, case management, and search operations, MySQL serves as the operational store for user accounts, ingestion logs, notification tracking, and configurable application settings. This dual-database design keeps each system doing what it does best — Elasticsearch for search and analytics, MySQL for transactional operations.

**Tech Stack Summary**
- Elasticsearch 8.x (hybrid search, aggregations, ML anomaly detection, case indexing)
- Elasticsearch Agent Builder (custom ES|QL tools, conversational investigation agent via Kibana API)
- Vanilla PHP 8.1+ (ingestion, agent orchestration, REST API communication via cURL, UI rendering)
- MySQL (user management, ingestion logs, notification tracking, configurable settings)
- Bootstrap 5 + jQuery 3.7 + Font Awesome 6 (mobile-first, app-like responsive UI)
- Chart.js 4 (trend visualizations rendered from aggregation JSON)
- Google Fonts — Outfit (clean, modern typography)
- LLM API — OpenAI or compatible (entity extraction, embedding generation, Evidence Pack narrative)
- Brevo API (transactional email alerts for case escalation — API-based, not SMTP)

---

## Challenges We Ran Into

**False Positive Control**
The hardest problem wasn't detection — it was calibration. Early versions flagged too aggressively, which would cause investigation fatigue in a real safety team. We iterated heavily on the confidence gate design, combining complaint velocity, injury mention weighting, and geographic spread scoring until the signal-to-noise ratio felt genuinely investigation-worthy. Getting this right in PHP meant building a scoring pipeline that assembled multiple Elasticsearch query results and applied weighted logic before any cluster was promoted.

**Elasticsearch Mapping Design for Multi-Dimensional Clustering**
Getting mappings right for simultaneous clustering across product, time, geography, and semantic similarity required significant iteration. Early flat mappings caused aggregation performance issues. The solution was a carefully structured schema using `keyword` sub-fields for aggregations alongside `dense_vector` fields for knn search on the same documents — a design that took real trial and error to stabilize.

**Evidence Pack Traceability Without an ORM**
In a framework-heavy stack, you'd reach for an ORM or query builder to track which queries produced which results. In vanilla PHP, we had to build disciplined query-replay logic from scratch — every Evidence Pack item stores its originating DSL query as part of the case document so investigators can re-run it at any point. This was tedious but produced a genuinely stronger auditability story than most agent architectures offer.

**PHP ↔ Elasticsearch Communication at Query Complexity**
Vanilla PHP with cURL works beautifully for simple Elasticsearch queries. For complex nested aggregations and knn hybrid queries, managing query construction, error handling, and response parsing without a client library required careful structuring of reusable PHP query-builder functions. It was more work upfront but kept the stack transparent and dependency-free.

---

## Accomplishments That We're Proud Of

- Built a genuinely investigation-grade pipeline — not a monitoring dashboard — where every flagged signal is explainable, traceable, and human-reviewed before any action is taken.
- Elasticsearch doing real computation: retrieval, clustering, anomaly scoring, and case management all in one system rather than stitching together five different tools.
- Integrated Elasticsearch Agent Builder with five custom ES|QL tools, enabling a conversational investigation interface where safety teams can explore complaint data in natural language.
- A confidence gate system that meaningfully controls false positives without per-category manual tuning.
- Full auditability — every human decision is logged with reasoning, making the system defensible if a recall investigation ever proceeds to regulatory review.
- A mobile-first, app-like UI using Bootstrap 5 that looks and feels like a native mobile application on phones while remaining a powerful investigation tool on desktop.
- A clean, dependency-light stack in vanilla PHP that any developer can deploy on shared hosting without a container orchestration setup — lowering the barrier for smaller safety teams to actually use it.
- Open-source under the MIT License with comprehensive documentation (README, DEPLOYMENT guide, CONTRIBUTING guidelines) to make the project accessible to the community.

---

## What We Learned

- Elasticsearch is dramatically underutilized as an agent computation layer. Treating it as retrieval-only misses half its power — aggregation pipelines and ML anomaly detection jobs are first-class agent tools.
- Hybrid search (BM25 + vector) is especially powerful for safety signal detection because hazard language is inconsistent. Users describe the same failure mode in completely different words, making pure keyword or pure semantic search individually insufficient.
- Human-in-the-loop design is an architectural decision, not a UI afterthought. Building workflow gates into the data model from day one made the auditability properties structurally stronger.
- Vanilla PHP is surprisingly capable as an agent orchestration layer. Without framework abstractions in the way, the logic between "Elasticsearch response" and "agent decision" is direct, readable, and easy to audit — which turns out to be exactly the right property for a safety-focused system.
- False positive control is the real product. An agent that flags everything is worse than useless in a safety context — calibration is where the actual value is created.

---

## What's Next for VerdictTrace

- **Regulatory Report Drafting** — At Critical tier with human sign-off, auto-generate a draft regulatory submission in CPSC, NHTSA, or FDA format using the Evidence Pack as structured source material.
- **Supplier & Batch Correlation** — Cross-reference hazard clusters with supply chain data to identify whether signals correlate with specific manufacturing batches, suppliers, or production windows.
- **Multi-Language Ingestion** — Extend entity extraction and embeddings to support non-English support data, essential for global product lines.
- **Closed-Loop Learning** — Feed confirmed recall outcomes back into anomaly detection baselines so confidence gates improve from real-world ground truth over time.
- **Enterprise Connectors** — Native PHP integrations for Zendesk, Salesforce Service Cloud, and SAP QM to eliminate manual ingestion pipelines.
