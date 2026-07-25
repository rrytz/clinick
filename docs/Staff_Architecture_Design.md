---
tags:
  - clinick
  - architecture
  - design
  - rbac
  - staff
aliases:
  - Staff Architecture Design Document
  - CLINICK Phase 4 Staff Remediation Architecture
status: design
created: 2026-07-26
---

# CLINICK Phase 4 — Staff Architecture & Tool Remediation Design Document

> [!important] Architectural Goal
> Eliminate 100% of Staff tool execution failures, fix declaration leaks, and provide frontdesk staff with dedicated, privacy-compliant operational tools without duplicating existing codebase logic.

---

## 1. Inventory of Existing System Capabilities

Below is the complete capability matrix of all 20 AI tools across the CLINICK system:

| Tool Name | Class Source | Declared Roles | SecurityGuard Authorized Roles | Operational Description |
| :--- | :--- | :--- | :--- | :--- |
| **`getAvailableDoctors`** | `PatientTools` | Patient | Patient, Staff | Lists available doctors, specializations, and open dates |
| **`getDoctorSchedule`** | `PatientTools` | Patient | Patient, Staff | Returns open time slots for a doctor on a specified date |
| **`getAppointmentStatus`**| `PatientTools` | Patient | Patient, Staff | Fetches details and status of patient appointments |
| **`getQueueStatus`** | `PatientTools` | Patient | Patient | Returns patient's own queue number and wait time |
| **`createAppointment`** | `PatientTools` | Patient | Patient | Books a new appointment slot for the patient |
| **`rescheduleAppointment`**| `PatientTools` | Patient | Patient | Reschedules an existing appointment to a new slot |
| **`cancelAppointment`** | `PatientTools` | Patient | Patient | Cancels a patient's own scheduled appointment |
| **`check_symptoms_naive_bayes`**| `PatientTools` | Patient | Patient | Classifies physical symptoms via Naive Bayes |
| **`getMyRecords`** | `PatientTools` | Patient | Patient | Fetches authenticated patient's own medical records |
| **`getAssignedPatients`**| `DoctorTools` | Doctor | Doctor | Returns doctor's assigned patient consultations for today |
| **`getConsultationHistory`**| `DoctorTools`| Doctor | Doctor | Fetches medical records for assigned patients only |
| **`getUpcomingAppointments`**| `DoctorTools`| Doctor | Doctor | Returns doctor's remaining scheduled appointments |
| **`getDailyStats`** | `AdminTools` | Admin | Admin, Staff | Daily operational summary (appointments, pending, high-risk) |
| **`getWeeklyStats`** | `AdminTools` | Admin | Admin | Weekly consultation volume and completion analytics |
| **`getMonthlyReport`** | `AdminTools` | Admin | Admin | Executive monthly operational report |
| **`getDoctorWorkload`** | `AdminTools` | Admin | Admin | Distribution of scheduled patients across doctors |
| **`getNoShowRate`** | `AdminTools` | Admin | Admin | Missed consultation percentage and recurring trends |
| **`getPendingApprovals`** | `AdminTools` | Admin | Admin, Staff | Account registrations awaiting approval |
| **`getHighRiskPatients`** | `AdminTools` | Admin | Admin | High-risk patient flags requiring administrative review |
| **`generateAnalyticsReport`**| `AdminTools`| Admin | Admin | Executive analytical report compilation |

---

## 2. Staff Frontdesk Workflow & Tool Classification

Frontdesk Staff operate in an administrative capacity (patient arrival, queue tracking, walk-in inquiries).

### A. Reusable Tools (DRY Principle)
- `getAvailableDoctors`: Staff looking up doctor availability for walk-ins.
- `getDoctorSchedule`: Staff checking open consultation slots.
- `getDailyStats`: Staff monitoring total appointments for today.
- `getPendingApprovals`: Staff checking pending account registrations.

### B. Missing Frontdesk Operational Tools (To Be Added)
- `getClinicQueueOverview`: Returns clinic-wide queue status across all doctors for today (replaces `getQueueStatus` patient ticket check).
- `searchPatientByName`: Searches registered patients by name or email for frontdesk lookup.
- `checkInPatient`: Marks an arriving patient's appointment as checked-in / in-progress.

### C. Dangerous Tools (Staff Strict Exclusion)
- `getMyRecords` / `getConsultationHistory`: Clinical medical records — Staff MUST NOT view medical notes or diagnoses.
- `getWeeklyStats` / `getMonthlyReport` / `generateAnalyticsReport`: Executive financial analytics.
- `check_symptoms_naive_bayes`: Diagnostic clinical tool.

---

## 3. Architecture Decision — Hybrid Architecture (Option C)

We evaluated three architectural approaches:

- **Option A (Standalone `StaffTools.php`)**: High code duplication for doctor schedule and daily stats logic.
- **Option B (Shared Handlers Only)**: Incapable of providing clinic-wide queue monitoring (`getQueueStatus` remains patient-scoped).
- **Option C (Hybrid Architecture — RECOMMENDED)**:
  1. Create lightweight `StaffTools.php` containing frontdesk operational methods (`getClinicQueueOverview`, `searchPatientByName`, `checkInPatient`).
  2. Implement `StaffTools::getDeclarations()` that exports exact schemas for both shared tools and new frontdesk tools.
  3. Update `ToolRegistry.php` with an explicit `elseif ($role === 'Staff')` dispatch block routing shared tools to `PatientTools`/`AdminTools` and frontdesk tools to `StaffTools`.

```
                        ┌───────────────────────────────┐
                        │    ToolRegistry Dispatcher    │
                        └───────────────┬───────────────┘
                                        │
             ┌──────────────────────────┼──────────────────────────┐
             ▼                          ▼                          ▼
     if ($role === 'Patient')    if ($role === 'Doctor')   if ($role === 'Staff')
             │                          │                          │
      PatientTools.php           DoctorTools.php            Hybrid Dispatch:
                                                            ├── Shared -> PatientTools/AdminTools
                                                            └── Operational -> StaffTools.php
```

---

## 4. Security & Privacy Impact Review

1. **PHI Privacy Barrier**: `searchPatientByName` returns `id`, `name`, `email`, `created_at`. No clinical diagnoses, doctor notes, or treatments are exposed to Staff.
2. **Privilege Escalation Prevention**: `SecurityGuard` permission arrays strictly enforce that `Staff` cannot execute Doctor or Admin tools.
3. **Audit Logging**: Every Staff tool invocation is logged in `ai_tool_execution_logs` with `role = 'Staff'`.

---

## 5. Phase 4 Implementation Plan

### A. New & Modified Files
1. **Create**: `classes/ai/Tools/StaffTools.php` (Staff declarations & operational handlers)
2. **Modify**: `classes/ai/Tools/ToolRegistry.php` (Staff declarations & hybrid dispatching)
3. **Modify**: `classes/ai/SecurityGuard.php` (Register `getClinicQueueOverview`, `searchPatientByName`, `checkInPatient` under `Staff`)
4. **Create**: `scratch/test_staff_hybrid_architecture.php` (Empirical verification script)

### B. Verification Strategy
- Test 1: Verify `ToolRegistry::getDeclarationsForRole('Staff')` returns ONLY Staff-authorized tools.
- Test 2: Verify `getClinicQueueOverview` returns clinic-wide queue data.
- Test 3: Verify `searchPatientByName` returns basic demographic info without PHI leakage.
- Test 4: Verify 100% of Staff tool execution calls succeed without `Tool handler not found` errors.

### C. Rollback Strategy
If any issue arises, reverting `ToolRegistry.php` and `SecurityGuard.php` restores previous state.
