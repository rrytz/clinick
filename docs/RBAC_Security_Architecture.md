---
tags:
  - clinick
  - rbac
  - security
  - architecture
aliases:
  - RBAC Security Architecture
  - CLINICK Access Control
status: active
created: 2026-07-26
---

# CLINICK RBAC & Security Architecture

> [!important] Core Security Invariant
> AI layer permission enforcement MUST equal Web Dashboard permission enforcement, which MUST equal Database level queries (`AI == Web == DB`). No interface may present a more permissive access boundary than another.

## 1. Role-Based Access Overview

CLINICK implements strict 4-tier Role-Based Access Control (RBAC) across both the Web UI and the AI Tool Execution Layer:

| Role | Scope | Authorized Tooling | Scoping Rule |
| :--- | :--- | :--- | :--- |
| **Patient** | Self-data only | `getAvailableDoctors`, `getDoctorSchedule`, `getAppointmentStatus`, `getQueueStatus`, `createAppointment`, `rescheduleAppointment`, `cancelAppointment`, `check_symptoms_naive_bayes`, `getMyRecords` | Hardcoded `$userId` from authenticated session. Cannot request or target other patient IDs. |
| **Doctor** | Assigned patients & schedule | `getAssignedPatients`, `getConsultationHistory`, `getUpcomingAppointments` | `doctor_id` matched against session `$userId`. Consultation history restricted to assigned patients with existing appointments. |
| **Staff** | Operational support | Subset of Admin & Doctor tools | Administrative support scope; subject to role validation. |
| **Admin** | System-wide clinic operations | `getDailyStats`, `getWeeklyStats`, `getMonthlyReport`, `getDoctorWorkload`, `getNoShowRate`, `getPendingApprovals`, `getHighRiskPatients`, `generateAnalyticsReport` | Full clinic-wide operational analytics. No clinical patient record modification. |

---

## 2. Hardened Access Control Components

### A. AI Tool Execution Engine (`ToolRegistry.php` & `SecurityGuard.php`)

All tool executions called by Gemini pass through [[SecurityGuard]]:
1. **Role Pre-Filter**: `SecurityGuard::isToolAllowed($toolName, $role)` verifies role entitlement.
2. **Explicit Assignment Verification**:
   - `DoctorTools::getConsultationHistory`: Enforces active/past appointment relationship between doctor and patient.
   - `PatientTools::cancelAppointment`: Pre-checks appointment ownership and active status.
   - `PatientTools::getMyRecords`: Uses ONLY session `$userId`, ignoring untrusted input parameters.

### B. Web Dashboard Alignment (`doctor_dashboard.php` & `patient_dashboard.php`)

> [!check] Alignment Status
> The web dashboard endpoints match `SecurityGuard` standards:
> - Status changes (`complete`, `cancel`) require `AND doctor_id = :doctor_id`.
> - Rescheduling requires `AND doctor_id = :doctor_id`.
> - Prescriptions require explicit appointment relationship pre-checks.
> - Patient dropdown lists only assigned patients.

---

## 3. Related Links & Visuals

- Visual Canvas Diagram: [[RBAC_Permission_Flow.canvas]]
- Verification Suite: `scratch/test_phase3_rbac_verification.php`
- Core Engine: `classes/ai/SecurityGuard.php`
