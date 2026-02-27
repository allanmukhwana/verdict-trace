# VerdictTrace: Investigation-Grade Safety Signal Detection on Elasticsearch

Every product company has a recall waiting to happen somewhere in their support data. The problem isn't that the signal doesn't exist — it's buried in thousands of inconsistently worded tickets, repair logs, and return notes with no systematic way to surface it before it becomes a crisis.

VerdictTrace is an audit-first safety investigation agent that turns unstructured support noise into defensible, human-approved safety investigations. Built with **Elasticsearch** as the core computation engine, **Elasticsearch Agent Builder** for conversational data exploration, and **vanilla PHP** as the orchestration layer, VerdictTrace ingests support data continuously, clusters hazard signals statistically, generates fully traceable Evidence Packs, and routes flagged cases to a structured human review workflow — without ever auto-triggering recalls.

The agent uses **Elasticsearch hybrid retrieval** — combining BM25 keyword scoring with `knn` dense vector search — to catch both explicit hazard language and semantically related complaints. Aggregation pipelines cluster signals across product SKU, failure mode, geography, and time simultaneously, while **ML anomaly detection jobs** flag clusters deviating from rolling baselines. A PHP confidence gate layer applies weighted scoring (complaint velocity 30%, injury rate 35%, geographic spread 20%, velocity 15%) before promoting clusters to investigations.

When a cluster crosses the threshold, VerdictTrace generates an **Evidence Pack**: the exact DSL queries that surfaced the signal, trend visualizations via Chart.js, representative exemplar cases, extracted entities, and a plain-language narrative. Every element is traceable to its source document — making the system defensible to both internal review and regulators.

**Elasticsearch Agent Builder** was a key integration. We registered five custom ES|QL tools via the Kibana API and created a VerdictTrace investigation agent for conversational data exploration. Safety teams can ask *"Show me recent overheating complaints"* and get data-grounded answers. The PHP backend routes queries to Agent Builder when configured, with a local fallback using direct Elasticsearch queries and LLM interpretation.

The UI is built with **Bootstrap 5**, **jQuery**, and **Font Awesome** — styled with **Google Fonts (Outfit)** — delivering a mobile-first interface that looks like a native mobile app. **MySQL** handles operational data (users, logs, notifications, settings), while **Brevo API** powers transactional email alerts.

The biggest challenge was **false positive control**. Calibrating the confidence gate transparently in PHP without hiding logic inside a framework was the hardest and most important work. Hybrid search and the aggregation-as-clustering pattern were standout features — Elasticsearch became the computation layer, not just the data store.

VerdictTrace is open-source under the MIT License, deployable on shared hosting without container orchestration.

*The agent builds the case. The verdict belongs to humans.*