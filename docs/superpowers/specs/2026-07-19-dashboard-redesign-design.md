# CLINICK Dashboard Redesign Specification

## 1. Design System & Tokens
We will expand the existing token system defined in `style.css` using custom variables within `dashboard.css`.

### Palette
* **Primary (Deep Teal):** `#0f766e`
* **Primary Light (Light Teal):** `#14b8a6`
* **Primary Hover (Dark Teal):** `#115e59`
* **Secondary (Light Blue):** `#0284c7`
* **Secondary Hover (Dark Blue):** `#0369a1`
* **Background Gradient:** `linear-gradient(135deg, #f0fdfa 0%, #e0f2fe 100%)`
* **Card BG:** `rgba(255, 255, 255, 0.85)`
* **Dark Mode Card BG:** `#1e293b`
* **Dark Mode Sidebar BG:** `#0f172a`
* **Text Main:** `#0f172a` (Slate 900)
* **Text Muted:** `#475569` (Slate 600)
* **Text White:** `#ffffff`
* **Border Color:** `#e2e8f0`

### Typography
* **Headers & Data Values:** `Space Grotesk`, sans-serif (semibold/bold)
* **Body Text:** `Plus Jakarta Sans`, sans-serif (weights 300, 400, 500, 600, 700)

---

## 2. Component Design & Styles

### Status Badges (Option 1)
Status badges will use a clean, flat style with a subtle 10% HSL background tint and a 4px left-border accent:
* **Scheduled:** `background: rgba(15, 118, 110, 0.08); border-left: 4px solid var(--primary); color: var(--primary);`
* **Completed:** `background: rgba(16, 185, 129, 0.08); border-left: 4px solid #10b981; color: #10b981;`
* **Cancelled:** `background: rgba(239, 68, 68, 0.08); border-left: 4px solid #ef4444; color: #ef4444;`
* **Checked-in:** `background: rgba(2, 132, 199, 0.08); border-left: 4px solid var(--secondary); color: var(--secondary);`

### Sidebar & Layout
* Supports `.sidebar-collapsed` with narrow width (`64px`) and hidden labels.
* Flexbox navigation structure with `.active` highlight indicator.

### Stats & Health Cards
* **Stats Cards:** Card layout containing `.stats-label`, `.stats-number` (Space Grotesk), `.stats-icon-container`, and `.stats-trend`.
* **Health Cards:** Horizontal layouts displaying labels and dynamic system indicators.

### Diagnostic & Treatment Plan
* **Body SVG Hotspots:** Absolute-positioned pulsing buttons (`.hotspot`) mapping to standard body parts for clinical evaluations.
* **Treatment Timeline:** Left-bordered line (`.treatment-plan-wrapper`) containing `.treatment-plan-item` and `.treatment-time-marker`.
* **Medication Grid:** Cards presenting medication details with hover scales.

### Notification Bell
* `.notification-bell-container` with bell button, badge counter, and hover dropdown.

### Chatbot Widgets
* `.chat-widget-card` sliding from bottom right when active.
* Fully styled message list, scrollbar customization, and input field using clinical teal values.

---

## 3. Codebase Changes

### admin_dashboard.php, staff_dashboard.php, doctor_dashboard.php, patient_dashboard.php
* Remove inline `style="..."` attributes from elements like headers, canvas wrappers, grids, and tables.
* Re-style tables using `.table-responsive` and `.data-table`.
* Replace custom button inline-styling with standard button classes (`.btn`, `.btn-primary`, etc.).

### mediconnect-chatbot-module/components/FloatingChatWidget.tsx
* Move inline styles to class names mapping to variables in `dashboard.css`.
* Enforce clinical teal palette and Space Grotesk header layout.

### chatbot-widget.php (Vanilla JS fallback)
* Re-style trigger button and widget layout using variables (`var(--primary)`, `var(--primary-light)`, etc.).

---

## 4. Dark Mode & Responsive Layouts
* **Dark Mode overrides:** Triggered via `[data-theme="dark"]` attribute on the document element. Changes card background, text colors, and border colors.
* **Media Queries:** Add breakpoints for `1200px` (desktop), `992px` (tablet landscape), `768px` (tablet portrait), and `480px` (mobile).
* **Print Styles:** Hide navigation sidebar, top bars, buttons, and chatbot widgets. Make only `#printable-report-area` visible.
