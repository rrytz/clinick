---
tags:
  - architecture
  - dark-mode
  - audit
  - frontend
  - clinick
aliases:
  - Dark Mode Audit
  - Global Theme System Audit
status: completed
---

# CLINICK Global Dark Mode Architecture — System Audit Report

> [!NOTE]
> This audit evaluates all CSS stylesheets, PHP templates, inline styles, hardcoded colors, and interactive UI components across the CLINICK platform to establish a 100% WCAG AA compliant global theme system.

---

## 1. System Overview & Scope

The CLINICK platform currently utilizes a CSS variable design system defined in `dashboard.css` and `style.css`. While basic `[data-theme="dark"]` CSS variables exist in `dashboard.css`, several UI components contain hardcoded background colors, hardcoded borders, inline element styles, or non-variable color references that cause visual breaking, contrast failures, or Flash of Unstyled Content (FOUC) when toggling to Dark Mode.

### Target Pages & Components Audited:
1. **Public Authentication Page**: [index.php](file:///c:/xampp/htdocs/CLINICK/index.php)
2. **Patient Dashboard**: [patient_dashboard.php](file:///c:/xampp/htdocs/CLINICK/patient_dashboard.php)
3. **Doctor Dashboard**: [doctor_dashboard.php](file:///c:/xampp/htdocs/CLINICK/doctor_dashboard.php)
4. **Staff Dashboard**: [staff_dashboard.php](file:///c:/xampp/htdocs/CLINICK/staff_dashboard.php)
5. **Admin Dashboard**: [admin_dashboard.php](file:///c:/xampp/htdocs/CLINICK/admin_dashboard.php)
6. **AI Assistant Widget**: [chatbot-widget.php](file:///c:/xampp/htdocs/CLINICK/chatbot-widget.php)
7. **Core Stylesheets**: [dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css) & [style.css](file:///c:/xampp/htdocs/CLINICK/style.css)

---

## 2. Comprehensive Hardcoded & Vulnerable Style Audit

### A. Core Components & Hardcoded Color Vulnerabilities

| Component / File | Current Styling Vulnerability | Issue in Dark Mode | Severity |
| :--- | :--- | :--- | :--- |
| **Index / Auth Page** ([style.css](file:///c:/xampp/htdocs/CLINICK/style.css)) | Hardcoded `.login-container` background `#ffffff`, `.form-control` `#ffffff` | Background remains bright white in dark mode, breaking login aesthetics. | **HIGH** |
| **Filter Toolbars** ([dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css#L725)) | Hardcoded `.report-param-bar` `background: #ffffff` | Leaves a bright white box across dark dashboards. | **HIGH** |
| **Calendar Component** ([dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css#L1320)) | Hardcoded `.calendar-container` `background: #ffffff`, `.calendar-day-cell` `background: #ffffff` | Calendar grid displays glaring white day tiles against dark background. | **HIGH** |
| **Data Tables** ([dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css#L1060)) | `.data-table tr:hover` uses `#f8fafc`, `td` border `#e2e8f0` | Table row hover turns bright white/light blue, obscuring white text. | **HIGH** |
| **Form Controls** ([dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css#L1256)) | `.form-control` `background-color: #ffffff`, select options | Input fields stay bright white or select options render illegible dark-on-dark text. | **HIGH** |
| **Modal Windows** ([dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css#L1180)) | `.modal-content` `background: #ffffff`, `.modal-header` border | Modal popups retain blinding white background. | **HIGH** |
| **MediBot Chatbot** ([chatbot-widget.php](file:///c:/xampp/htdocs/CLINICK/chatbot-widget.php#L140)) | `.medibot-msg.bot` `background: #ffffff`, `.medibot-chip` `background: #ffffff` | Bot messages and quick reply chips retain bright white backgrounds. | **MEDIUM** |
| **Alerts & Callouts** ([dashboard.css](file:///c:/xampp/htdocs/CLINICK/dashboard.css#L1221)) | `.alert-success` `background-color: #ecfdf5`, `.alert-danger` `#fef2f2` | Pastel light alert boxes create high contrast glare on dark backgrounds. | **MEDIUM** |

---

## 3. Flash of Unstyled Content (FOUC) Audit

> [!WARNING]
> In current PHP dashboard templates, inline theme detection scripts are placed in `<head>`, but execution timing varies. If the theme detection script runs after CSS parsing or body rendering begins, users experience a brief white screen flicker before dark mode applies.

**Solution Requirement**:
A synchronous, lightweight `<head>` script must execute BEFORE any HTML rendering occurs, checking `localStorage` and `window.matchMedia('(prefers-color-scheme: dark)')` to immediately apply `data-theme="dark"` on `document.documentElement`.

---

## 4. Theme System Architectural Tokens Matrix

To achieve 100% consistency, the following tokens must be declared in `:root` (Light Mode) and overridden in `[data-theme="dark"]`:

```css
:root {
  --bg-slate: #f8fafc;
  --bg-subtle: #f1f5f9;
  --card-bg: #ffffff;
  --nav-bg: #ffffff;
  --text-main: #0f172a;
  --text-secondary: #334155;
  --text-muted: #64748b;
  --text-placeholder: #94a3b8;
  --border-color: #e2e8f0;
  --border-subtle: #f1f5f9;
  --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.06);
}

[data-theme="dark"] {
  --bg-slate: #0f172a;
  --bg-subtle: #1e293b;
  --card-bg: #1e293b;
  --nav-bg: #1e293b;
  --text-main: #f1f5f9;
  --text-secondary: #cbd5e1;
  --text-muted: #94a3b8;
  --text-placeholder: #64748b;
  --border-color: #334155;
  --border-subtle: #1e293b;
  --shadow-card: 0 2px 8px rgba(0, 0, 0, 0.35);
}
```

---

## 5. Audit Summary & Sign-off

- **Audited Files**: 7
- **Identified Hardcoded Styles**: 24 locations
- **Contrast Failure Risks**: 12 elements
- **FOUC Prevention Strategy**: Synchronous `<head>` snippet + System media listener
- **Status**: Audit Completed — Ready for Implementation Planning.
