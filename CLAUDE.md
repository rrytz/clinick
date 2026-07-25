# Agent Instructions

> This file is mirrored across CLAUDE.md, AGENTS.md, and GEMINI.md so the same instructions load in any AI environment.

You operate within a 3-layer architecture that separates concerns to maximize reliability. LLMs are probabilistic, whereas most business logic is deterministic and requires consistency. This system fixes that mismatch.

## The 3-Layer Architecture

**Layer 1: Directive (What to do)**
- Basically just SOPs written in Markdown, live in `directives/`
- Define the goals, inputs, tools/scripts to use, outputs, and edge cases
- Natural language instructions, like you'd give a mid-level employee

**Layer 2: Orchestration (Decision making)**
- This is you. Your job: intelligent routing.
- Read directives, call execution tools in the right order, handle errors, ask for clarification, update directives with learnings
- You're the glue between intent and execution. E.g you don't try scraping websites yourself—you read `directives/scrape_website.md` and come up with inputs/outputs and then run `execution/scrape_single_site.py`

**Layer 3: Execution (Doing the work)**
- Deterministic Python scripts in `execution/`
- Environment variables, api tokens, etc are stored in `.env`
- Handle API calls, data processing, file operations, database interactions
- Reliable, testable, fast. Use scripts instead of manual work. Commented well.

**Why this works:** if you do everything yourself, errors compound. 90% accuracy per step = 59% success over 5 steps. The solution is push complexity into deterministic code. That way you just focus on decision-making.

## Operating Principles

**1. Check for tools first**
Before writing a script, check `execution/` per your directive. Only create new scripts if none exist.

**2. Self-anneal when things break**
- Read error message and stack trace
- Fix the script and test it again (unless it uses paid tokens/credits/etc—in which case you check w user first)
- Update the directive with what you learned (API limits, timing, edge cases)
- Example: you hit an API rate limit → you then look into API → find a batch endpoint that would fix → rewrite script to accommodate → test → update directive.

**3. Update directives as you learn**
Directives are living documents. When you discover API constraints, better approaches, common errors, or timing expectations—update the directive. But don't create or overwrite directives without asking unless explicitly told to. Directives are your instruction set and must be preserved (and improved upon over time, not extemporaneously used and then discarded).

## Self-annealing loop

Errors are learning opportunities. When something breaks:
1. Fix it
2. Update the tool
3. Test tool, make sure it works
4. Update directive to include new flow
5. System is now stronger

## File Organization

**Deliverables vs Intermediates:**
- **Deliverables**: Google Sheets, Google Slides, or other cloud-based outputs that the user can access
- **Intermediates**: Temporary files needed during processing

**Directory structure:**
- `.tmp/` - All intermediate files (dossiers, scraped data, temp exports). Never commit, always regenerated.
- `execution/` - Python scripts (the deterministic tools)
- `directives/` - SOPs in Markdown (the instruction set)
- `.env` - Environment variables and API keys
- `credentials.json`, `token.json` - Google OAuth credentials (required files, in `.gitignore`)

**Key principle:** Local files are only for processing. Deliverables live in cloud services (Google Sheets, Slides, etc.) where the user can access them. Everything in `.tmp/` can be deleted and regenerated.

## Summary

You sit between human intent (directives) and deterministic execution (Python scripts). Read instructions, make decisions, call tools, handle errors, continuously improve the system.

Be pragmatic. Be reliable. Self-anneal.

---

# Mandatory Skill Utilization Policy

You have access to multiple installed skills. Do not merely acknowledge that the skills exist. For every user request, you must first determine which skill(s) are applicable and actively use them when they provide value.

## Core Requirement

Before beginning any task:

1. Analyze the request.
2. Identify all relevant skills.
3. Invoke the appropriate skill(s).
4. Explain which skill(s) are being used and why.
5. Do not skip a relevant skill if it materially improves the outcome.

---

## Required Skill Selection Rules

### Development Tasks

Use:
* `using-superpowers`
* `writing-plans`
* `executing-plans`
* `verification-before-completion`

For:
* feature implementation
* architecture changes
* production readiness work
* refactoring
* deployment preparation

---

### Bug Fixing

Use:
* `systematic-debugging`
* `test-driven-development`
* `verification-before-completion`

Before proposing or implementing fixes.
Never jump directly to code changes without root-cause analysis.

---

### Large Features

Use:
* `subagent-driven-development`
* `dispatching-parallel-agents`

When a task can be split into independent investigations, audits, testing, implementation, or documentation streams.

---

### Code Review

Use:
* `requesting-code-review`
* `receiving-code-review`

For:
* security reviews
* production-readiness audits
* architecture audits
* pull request reviews

---

### Documentation & Knowledge Management

When working on CLINICK documentation, architecture documentation, RBAC documentation, audit reports, or project notes:

Use Obsidian skills instead of plain text whenever appropriate.

#### Use `obsidian-markdown`
For:
* architecture notes
* RBAC documentation
* implementation reports
* audit findings
* feature documentation
* project knowledge base creation

Generate proper frontmatter properties (`tags`, `aliases`, `status`), `[[Wikilinks]]`, `> [!callouts]`, and Obsidian-native formatting.

#### Use `json-canvas`
For:
* system architecture diagrams
* RBAC flowcharts
* AI tool routing maps
* appointment workflow diagrams
* database relationship visualizations

Generate `.canvas` structures instead of text-only descriptions whenever a visual model would help.

#### Use `obsidian-bases`
For:
* requirements tracking
* audit matrices
* security findings
* production readiness checklists
* feature inventories
* test result dashboards

Generate `.base` database views when structured data is involved.

#### Use `obsidian-cli`
When notes need to be:
* created
* updated
* searched
* linked
* organized inside the vault

Do not recreate information manually if it belongs in the vault.

---

### External Documentation Research

Use:
* `defuddle`

When extracting information from:
* framework documentation
* API documentation
* medical references
* standards documentation
* online technical resources

Convert cluttered web pages into clean markdown before analysis.

---

## CLINICK-Specific Rules

For CLINICK tasks:

### Architecture Reviews
Always use:
* `writing-plans`
* `requesting-code-review`
* `obsidian-markdown`

### Security Audits
Always use:
* `systematic-debugging`
* `requesting-code-review`
* `obsidian-bases`

### Production Readiness Reviews
Always use:
* `verification-before-completion`
* `receiving-code-review`
* `obsidian-bases`

### RBAC Documentation
Always use:
* `obsidian-markdown`
* `json-canvas`

Create both:
* written documentation
* visual permission-flow diagrams

---

## Anti-Waste Rule

Do not leave Obsidian skills unused simply because plain text is easier.
If the output would reasonably belong in a knowledge base, architecture repository, audit repository, or project documentation system, use the appropriate Obsidian skill.
Treat Obsidian as the primary documentation platform, not an optional extra.

For every future task, explicitly state:

**Selected Skills:**
* Skill A → reason
* Skill B → reason
* Skill C → reason

before starting work.

