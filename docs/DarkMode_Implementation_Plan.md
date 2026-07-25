# CLINICK Phase 5 — Global Dark Mode Architecture Implementation Plan

This implementation plan outlines the steps to build a production-grade, WCAG AA compliant global Dark Mode architecture across the entire CLINICK platform.

## Proposed Changes

### 1. Centralized CSS Token Architecture & Dark Mode Tokens

#### [MODIFY] [dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css)
- Refactor `:root` and `[data-theme="dark"]` CSS variable blocks.
- Map all component background colors (`.card`, `.modal-content`, `.calendar-container`, `.calendar-day-cell`, `.report-param-bar`, `.data-table`, `.form-control`, `.chat-widget-card`) to CSS custom properties (`var(--card-bg)`, `var(--bg-subtle)`, `var(--bg-slate)`, `var(--text-main)`, `var(--border-color)`).
- Add high-contrast rules for dark mode form controls, select options, badges, alerts, and calendar indicator pills.

#### [MODIFY] [style.css](file:///c:/xampp/htdocs/CLINICK/style.css)
- Add `[data-theme="dark"]` rules for public authentication screens (`index.php`, `.login-container`, `.auth-card`, `.form-control`).

---

### 2. Chatbot Widget Dark Mode Synchronization

#### [MODIFY] [chatbot-widget.php](file:///c:/xampp/htdocs/CLINICK/chatbot-widget.php)
- Add comprehensive `[data-theme="dark"]` CSS selectors for `#medibot-panel`, `.medibot-msg.bot`, `.medibot-chip`, `.medibot-typing`, `#medibot-input-row`, and `#medibot-input`.
- Automatically follow the document root's `data-theme` attribute without requiring a separate toggle.

---

### 3. FOUC Prevention & Global Theme Controller Script

#### [NEW] [js/theme-controller.js](file:///c:/xampp/htdocs/CLINICK/js/theme-controller.js)
- Create a reusable inline JavaScript helper that executes synchronously in `<head>` across all pages (`index.php`, `patient_dashboard.php`, `doctor_dashboard.php`, `staff_dashboard.php`, `admin_dashboard.php`).
- Logic:
  1. Checks `localStorage.getItem('clinick-theme')`.
  2. If unset, checks `window.matchMedia('(prefers-color-scheme: dark)').matches`.
  3. Instantly sets `document.documentElement.setAttribute('data-theme', 'dark')` before DOM rendering starts.
  4. Listens for system theme change events (`change` listener on `matchMedia`).

#### [MODIFY] [patient_dashboard.php](file:///c:/xampp/htdocs/CLINICK/patient_dashboard.php), [doctor_dashboard.php](file:///c:/xampp/htdocs/CLINICK/doctor_dashboard.php), [staff_dashboard.php](file:///c:/xampp/htdocs/CLINICK/staff_dashboard.php), [admin_dashboard.php](file:///c:/xampp/htdocs/CLINICK/admin_dashboard.php), [index.php](file:///c:/xampp/htdocs/CLINICK/index.php)
- Standardize header theme toggle button rendering and inline FOUC prevention snippet.

---

## Verification Plan

### Automated Verification
- Run verification script `scratch/test_darkmode_verification.php` checking CSS variable coverage, JS theme controller presence, and execution trace across all dashboard templates.

### Manual Verification
- Hard refresh dashboards and toggle theme button:
  1. Verify zero white screen flash on page load.
  2. Verify dark background, dark cards, dark tables, dark modals, dark forms, and dark chatbot widget across Patient, Doctor, Staff, and Admin dashboards.
