---
tags:
  - clinick
  - architecture
  - audit
  - tool-registry
  - rbac
  - production-readiness
aliases:
  - ToolRegistry Architecture & Production Readiness Audit
  - Master AI Tool Routing Audit
status: completed
created: 2026-07-26
---

# Senior Architecture & AI Systems Audit — `ToolRegistry.php` & Staff Subsystem

> [!caution] Production Readiness Audit Result
> The Staff Assistant subsystem receives a Production Readiness Score of **15 / 100 (CRITICAL ARCHITECTURAL FAILURE)**. While UI branding, role-aware greetings, and persona prompts exist, **100% of Staff tool execution calls fail at the `ToolRegistry` layer**, triggering cascading patient fallbacks and chip corruption.

---

## 1. Executive Summary & Audit Matrix

A complete empirical execution trace of all system tools across all 4 roles revealed **three core architectural disconnects** in `ToolRegistry.php`:

1. **Declarations Mismatch**: `ToolRegistry::getDeclarationsForRole('Staff')` sends `AdminTools::getDeclarations()` to Gemini. It exposes 6 executive tools that `SecurityGuard` will immediately deny, while omitting 4 operational tools Staff is actually authorized to use.
2. **Dispatch Execution Gap**: `ToolRegistry::executeToolCall()` checks `$role === 'Patient'`, `$role === 'Admin'`, `$role === 'Doctor'`. It has **no `$role === 'Staff'` dispatch branch**, causing **100% of Staff tool executions to fail with `"Tool handler implementation for '$toolName' not found."`**
3. **Missing Frontdesk Operational Tooling**: Staff lacks dedicated operational tools (`checkInPatient`, `searchPatientByName`, `registerWalkInPatient`, `getClinicQueueOverview`). Staff is forced to share patient tools like `PatientTools::getQueueStatus()`, which treats the Staff `$userId` as a patient.

---

## 2. 9-Point Detailed Architectural Review

### 1. Staff Tool Permissions (`SecurityGuard.php`)
- **Location**: [SecurityGuard.php L152–159](file:///c:/xampp/htdocs/CLINICK/classes/ai/SecurityGuard.php#L152-L159)
- **Authorized Tools for Staff**:
  - `getAvailableDoctors` (PatientTools)
  - `getDoctorSchedule` (PatientTools)
  - `getAppointmentStatus` (PatientTools)
  - `getQueueStatus` (PatientTools)
  - `getDailyStats` (AdminTools)
  - `getPendingApprovals` (AdminTools)

---

### 2. Staff Declarations (`ToolRegistry::getDeclarationsForRole('Staff')`)
- **Location**: [ToolRegistry.php L33–39](file:///c:/xampp/htdocs/CLINICK/classes/ai/Tools/ToolRegistry.php#L33-L39)
- **Code**:
  ```php
  return match ($role) {
      'Patient' => PatientTools::getDeclarations(),
      'Admin'   => AdminTools::getDeclarations(),
      'Doctor'  => DoctorTools::getDeclarations(),
      'Staff'   => AdminTools::getDeclarations(), // ❌ EXPOSES ADMIN TOOLS TO STAFF
      default   => PatientTools::getDeclarations(),
  };
  ```
- **Discrepancy**: Gemini is shown 6 executive tools (`getWeeklyStats`, `getMonthlyReport`, `getDoctorWorkload`, `getNoShowRate`, `getHighRiskPatients`, `generateAnalyticsReport`) that Staff cannot execute. Simultaneously, Gemini is **NOT shown** `getAvailableDoctors`, `getDoctorSchedule`, `getAppointmentStatus`, or `getQueueStatus`.

---

### 3. Staff Dispatch Paths (`ToolRegistry::executeToolCall`)
- **Location**: [ToolRegistry.php L61–69](file:///c:/xampp/htdocs/CLINICK/classes/ai/Tools/ToolRegistry.php#L61-L69)
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
- **Defect**: Lacks an `elseif ($role === 'Staff')` branch.  
  - If Staff calls `getDailyStats` (in `AdminTools`), `$role === 'Admin'` is false → **FAILS**.
  - If Staff calls `getQueueStatus` (in `PatientTools`), `$role === 'Patient'` is false → **FAILS**.

---

### 4. Missing Handlers & Unimplemented Staff Tools
Staff currently has **zero dedicated frontdesk tools**. The following frontdesk workflows do NOT exist in code:
- `searchPatientByName(name_or_email)` — Needed for frontdesk lookup.
- `checkInPatient(appointment_id)` — Needed for arriving patients.
- `registerWalkInPatient(name, doctor_id, reason)` — Needed for walk-in arrivals.
- `getClinicQueueOverview()` — Needed for clinic-wide queue monitoring across all doctors.

---

### 5. Dead Tools & Role Leakage Risks
Because Gemini is fed `AdminTools::getDeclarations()` for Staff, Gemini frequently attempts to call `getWeeklyStats` or `generateAnalyticsReport`.  
When called, `SecurityGuard` blocks the execution with `Permission Denied`. Gemini receives an error payload and hallucinates or falls back to generic text.

---

### 6. AI Hallucination & Fallback Cascading Risks
When tool calls fail in `ToolRegistry` for Staff:
1. `AssistantFactory` enters fallback mode.
2. In fallback mode, queue queries hit line 325:  
   *"You currently have no active queue ticket for today. Would you like me to help you book an appointment?"*
3. The reply contains `"appointment"`.
4. `offerFollowups()` in `chatbot-widget.php` matches `lower.includes('appointment')` and renders Patient chips (`Check my appointments`, `See available doctors`, `How to book`).

---

### 7. Security Implications
- **Data Privacy**: Safe. `SecurityGuard` successfully blocks Staff from reading executive analytics or unauthorized patient records.
- **System Stability**: Vulnerable to fallback degradation and user confusion due to broken tool calls.

---

### 8. Empirical Test Execution Results Table

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

---

### 9. Subsystem Production Readiness Scores

| Subsystem / Role | Score | Status | Primary Notes |
| :--- | :---: | :---: | :--- |
| **Patient Assistant** | **85 / 100** | Stable | `getMyRecords` & RBAC hardened. |
| **Doctor Assistant** | **85 / 100** | Stable | Assigned patient checks enforced. |
| **Admin Assistant** | **90 / 100** | Stable | Full executive tool suite working. |
| **Staff Assistant** | **15 / 100** | **CRITICAL** | 0/12 tools execute; missing dispatch logic. |

---

## 3. Recommended Remediation Architecture (For Next Sprint)

1. **Create `StaffTools.php`**:
   - Create `classes/ai/Tools/StaffTools.php`.
   - Implement `StaffTools::getDeclarations()` with exact declarations for Staff-authorized tools.
   - Implement handlers: `getAvailableDoctors`, `getDoctorSchedule`, `getAppointmentStatus`, `getClinicQueueOverview`, `getDailyStats`, `getPendingApprovals`, `searchPatientByName`.

2. **Update `ToolRegistry.php` Dispatcher**:
   - Instantiate `$this->staffTools = new StaffTools($db);`
   - Update `getDeclarationsForRole('Staff')` to return `StaffTools::getDeclarations()`.
   - Add `elseif ($role === 'Staff' && method_exists($this->staffTools, $toolName))` in `executeToolCall()`.

3. **Update `SecurityGuard.php`**:
   - Align `Staff` allowed tools to include new frontdesk tools (`searchPatientByName`, `getClinicQueueOverview`).
