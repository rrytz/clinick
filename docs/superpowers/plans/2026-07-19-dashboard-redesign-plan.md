# CLINICK Dashboard Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the dashboard styles in `dashboard.css` and update all dashboard PHP and Next.js chatbot widgets to remove inline styles and implement a premium, clinical healthcare theme.

**Architecture:** We will rewrite `dashboard.css` to build a clean CSS-only design system using variables, media queries, print overrides, and dark mode triggers. We will then update the dashboard files sequentially to replace style attributes with appropriate layout and component classes.

**Tech Stack:** PHP, CSS, TypeScript (Next.js/React).

## Global Constraints
* Do NOT change any PHP backend logic, SQL queries, or JS event handling.
* Maintain data-theme="dark" compatibility.
* Use Space Grotesk for headings and numeric values, Plus Jakarta Sans for body.

---

### Task 1: Complete dashboard.css Design System

**Files:**
* Modify: `c:\xampp\htdocs\CLINICK\dashboard.css`

**Interfaces:**
* Produces classes like `.stats-grid`, `.stats-card`, `.treatment-plan-wrapper`, `.medication-grid`, `.notification-bell-container`, `.chat-widget-card`, `.calendar-*` for all PHP dashboards and components.

- [ ] **Step 1: Replace entire contents of dashboard.css with the complete visual design system**
  We will replace the entire file to avoid syntax errors and clean up the duplicated media queries. The design includes tokens, typography rules, buttons, grids, list items, badges, tables, modals, chatbot components, system health grids, dark mode overrides, print styles, and responsive queries.
- [ ] **Step 2: Verify the file parses successfully with no syntax errors**
  Run: `powershell -Command "Get-Content c:\xampp\htdocs\CLINICK\dashboard.css -Head 20"`
  Expected: Correctly lists CSS variables.

---

### Task 2: Update admin_dashboard.php Styles

**Files:**
* Modify: `c:\xampp\htdocs\CLINICK\admin_dashboard.php`

**Interfaces:**
* Consumes: CSS classes defined in Task 1.

- [ ] **Step 1: Remove inline styling from admin top-nav, stats card elements, and action panels**
  Replace instances of `style="..."` in `admin_dashboard.php` with classes. For example, replace `<span style="font-size:0.85rem; color:var(--text-muted);">` with `<span class="health-card-label">`.
- [ ] **Step 2: Re-style administration overview charts and table layouts**
  Replace inline max-height and template styles with new dashboard.css classes.
- [ ] **Step 3: Run dashboard validation to check logic integrity**
  Run: `php -l c:\xampp\htdocs\CLINICK\admin_dashboard.php`
  Expected: No syntax errors.

---

### Task 3: Update staff_dashboard.php Styles

**Files:**
* Modify: `c:\xampp\htdocs\CLINICK\staff_dashboard.php`

**Interfaces:**
* Consumes: CSS classes defined in Task 1.

- [ ] **Step 1: Replace inline layouts with new CSS grid and card components**
  Modify card elements, buttons, and alert indicators to use the design system.
- [ ] **Step 2: Validate PHP compilation**
  Run: `php -l c:\xampp\htdocs\CLINICK\staff_dashboard.php`
  Expected: No syntax errors.

---

### Task 4: Update doctor_dashboard.php Styles

**Files:**
* Modify: `c:\xampp\htdocs\CLINICK\doctor_dashboard.php`

**Interfaces:**
* Consumes: CSS classes defined in Task 1.

- [ ] **Step 1: Clean inline styling in prescription forms, grids, and appointment lists**
- [ ] **Step 2: Validate PHP compilation**
  Run: `php -l c:\xampp\htdocs\CLINICK\doctor_dashboard.php`
  Expected: No syntax errors.

---

### Task 5: Update patient_dashboard.php Styles

**Files:**
* Modify: `c:\xampp\htdocs\CLINICK\patient_dashboard.php`

**Interfaces:**
* Consumes: CSS classes defined in Task 1.

- [ ] **Step 1: Re-style user welcome section, action buttons, symptom checker, and medical logs**
- [ ] **Step 2: Validate PHP compilation**
  Run: `php -l c:\xampp\htdocs\CLINICK\patient_dashboard.php`
  Expected: No syntax errors.

---

### Task 6: Update Next.js FloatingChatWidget.tsx Styles

**Files:**
* Modify: `c:\xampp\htdocs\CLINICK\mediconnect-chatbot-module\components\FloatingChatWidget.tsx`

**Interfaces:**
* Consumes: CSS class triggers defined in Task 1.

- [ ] **Step 1: Replace Inline Styles object with CSS class names**
  Replace the nested `style={{ ... }}` properties on container, trigger, header, input, and list items with class names (e.g. `chat-widget-card`, `chat-widget-header`, etc.) and ensure font family and colors align with tokens.

---

### Task 7: Update chatbot-widget.php Fallback Styles

**Files:**
* Modify: `c:\xampp\htdocs\CLINICK\chatbot-widget.php`

**Interfaces:**
* Consumes: CSS variables like `var(--primary)` and `var(--primary-light)`.

- [ ] **Step 1: Replace hard-coded hex colors in the fallback Javascript widget styling with CSS variables**
