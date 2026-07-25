---
tags:
  - clinick
  - audit
  - rbac
  - staff
  - production-readiness
aliases:
  - Staff Chatbot Production Audit Report
  - Staff Tool Routing & Architectural Defect Audit
status: completed
created: 2026-07-26
---

# Senior Architectural Audit & Production Readiness Report — Staff Chatbot Subsystem

> [!caution] Audit Executive Finding
> The Staff Assistant role is **NOT PRODUCTION-READY**. While role branding (`MediBot • Frontdesk Assistant`), `StaffSecretary.php` persona, and initial UI chips exist, **100% of Staff tool execution calls fail at the `ToolRegistry` layer** due to a fundamental architectural gap in `ToolRegistry.php`.

---

## 1. End-to-End Trace of All 4 Staff Quick Actions

We traced all 4 Staff quick action buttons through `chatbot-widget.php` → `chatbot-api.php` → `AssistantFactory.php` → `SecurityGuard.php` → `ToolRegistry.php`:

| Quick Action | Intent / Trigger | Target Tool | SecurityGuard Auth | Tool Declarations | ToolRegistry Dispatch | Status |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Patient check-in** | `check-in`, `checkin` | `getDailyStats` / `getQueueStatus` | ALLOWED | DECLARED / MISSING | **FAILED** (`Tool handler not found`) | ❌ BROKEN |
| **Search patient** | `search`, `patient` | `getDailyStats` / `getAssignedPatients` | ALLOWED | DECLARED / MISSING | **FAILED** (`Tool handler not found`) | ❌ BROKEN |
| **Queue status** | `queue`, `status` | `getQueueStatus` / `getDailyStats` | ALLOWED | MISSING | **FAILED** (`Tool handler not found`) | ❌ BROKEN |
| **Walk-in guide** | `walk-in`, `guide` | `getDailyStats` / `getDoctorSchedule` | ALLOWED | DECLARED / MISSING | **FAILED** (`Tool handler not found`) | ❌ BROKEN |

---

## 2. The 3 Architectural & Routing Defects

### Defect 1 — Missing Staff Dispatch Branch in `ToolRegistry.php`
- **File**: [ToolRegistry.php L61–69](file:///c:/xampp/htdocs/CLINICK/classes/ai/Tools/ToolRegistry.php#L61-L69)
- **Code**:
  ```php
  if ($role === 'Patient' && method_exists($this->patientTools, $toolName)) {
      $result = $this->patientTools->$toolName($args, $userId);
  } elseif ($role === 'Admin' && method_exists($this->adminTools, $toolName)) {
      $result = $this->adminTools->$toolName($args, $userId);
  } elseif ($role === 'Doctor' && method_exists($this->doctorTools, $toolName)) {
      $result = $this->doctorTools->$toolName($args, $userId);
  } else {
      $result = ['error' => "Tool handler implementation for '$toolName' not found."];
  }
  ```
- **Architectural Impact**: `ToolRegistry` has dispatch handlers for `Patient`, `Admin`, and `Doctor`, but **no `elseif ($role === 'Staff')` branch**. Every tool execution call made for a `Staff` user fails with `"Tool handler implementation for '$toolName' not found."`

### Defect 2 — Declaration Set Mismatch in `ToolRegistry.php`
- **File**: [ToolRegistry.php L33–39](file:///c:/xampp/htdocs/CLINICK/classes/ai/Tools/ToolRegistry.php#L33-L39)
- **Code**:
  ```php
  return match ($role) {
      'Patient' => PatientTools::getDeclarations(),
      'Admin'   => AdminTools::getDeclarations(),
      'Doctor'  => DoctorTools::getDeclarations(),
      'Staff'   => AdminTools::getDeclarations(), // ❌ EXPOSES ADMIN ANALYTICS DECLARATIONS TO STAFF
      default   => PatientTools::getDeclarations(),
  };
  ```
- **Architectural Impact**: `getDeclarationsForRole('Staff')` sends `AdminTools` declarations (`getWeeklyStats`, `getMonthlyReport`, `getDoctorWorkload`, `getNoShowRate`, `generateAnalyticsReport`) to Gemini. Gemini sees these executive analytics tools in its payload, but if Gemini attempts to call them, `SecurityGuard` blocks them with `Permission Denied` because Staff is not entitled to executive reports.

### Defect 3 — Missing Operational Tools in `SecurityGuard` & Tool Handlers
- **File**: [SecurityGuard.php L152–159](file:///c:/xampp/htdocs/CLINICK/classes/ai/SecurityGuard.php#L152-L159)
- **Code**:
  ```php
  'Staff' => [
      'getAvailableDoctors',
      'getDoctorSchedule',
      'getAppointmentStatus',
      'getQueueStatus',
      'getDailyStats',
      'getPendingApprovals',
  ],
  ```
- **Architectural Impact**: Staff lacks operational tools for frontdesk tasks (e.g. `searchPatientByName`, `registerWalkIn`, `getClinicQueueOverview`). Instead, Staff shares `PatientTools::getQueueStatus()`, which queries `appointments WHERE patient_id = :userId` (treating the Staff user as a patient).

---

## 3. Comprehensive Tool Support & Execution Audit for Staff

We tested all 12 tools in the system with `$role = 'Staff'`:

```text
Tool Name                  | SecurityGuard | Declarations | ToolRegistry Dispatch
--------------------------------------------------------------------------------
getAvailableDoctors        | ALLOWED      | MISSING      | FAILED (Tool handler not found)
getDoctorSchedule          | ALLOWED      | MISSING      | FAILED (Tool handler not found)
getAppointmentStatus       | ALLOWED      | MISSING      | FAILED (Tool handler not found)
getQueueStatus             | ALLOWED      | MISSING      | FAILED (Tool handler not found)
getDailyStats              | ALLOWED      | DECLARED     | FAILED (Tool handler not found)
getPendingApprovals        | ALLOWED      | DECLARED     | FAILED (Tool handler not found)
getWeeklyStats             | DENIED       | DECLARED     | FAILED (Unauthorized tool call)
getMonthlyReport           | DENIED       | DECLARED     | FAILED (Unauthorized tool call)
getDoctorWorkload          | DENIED       | DECLARED     | FAILED (Unauthorized tool call)
getNoShowRate              | DENIED       | DECLARED     | FAILED (Unauthorized tool call)
getHighRiskPatients        | DENIED       | DECLARED     | FAILED (Unauthorized tool call)
generateAnalyticsReport    | DENIED       | DECLARED     | FAILED (Unauthorized tool call)
```

> [!danger] Final Verdict
> **0 out of 12 tools can execute successfully for the Staff role.**  
> The Staff Assistant role is **PARTIALLY WIRED AT THE UI & PERSONA LAYER, BUT UNWIRED AT THE TOOL EXECUTION & DISPATCH LAYER.**

---

## 4. Required Production Remediation Plan (Do Not Implement Yet)

To make the Staff role 100% production-ready:

1. **Add Staff Execution Branch in `ToolRegistry.php`**:
   Add `elseif ($role === 'Staff')` in `ToolRegistry.php` to dispatch allowed tools across `AdminTools` (`getDailyStats`, `getPendingApprovals`) and `PatientTools` (`getAvailableDoctors`, `getDoctorSchedule`, `getAppointmentStatus`).

2. **Create Staff Tool Declarations**:
   Create a dedicated declaration method `StaffTools::getDeclarations()` or filter allowed declarations in `ToolRegistry::getDeclarationsForRole('Staff')` so Gemini only sees tools Staff can actually execute.

3. **Build Frontdesk Queue Monitor Tool (`getClinicQueueOverview`)**:
   Add an operational queue monitor tool for Staff that returns clinic-wide queue counts for today instead of filtering by `$userId` as a patient.
