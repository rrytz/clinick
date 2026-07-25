---
tags:
  - clinick
  - chatbot
  - investigation
  - queue
  - staff
aliases:
  - Staff Queue Status Trace Report
  - Causal Chain Analysis of Queue Status
status: completed
created: 2026-07-26
---

# Complete Investigation Report — Causal Chain Analysis of Staff "Queue Status" Execution

> [!warning] Issue Summary
> When a Staff user clicks **"Queue status"**, the chatbot replies:
> *"You currently have no active queue ticket for today. Would you like me to help you book an appointment?"*
> Followed by Patient quick-action chips (`Check my appointments`, `See available doctors`, `How to book`).

---

## 1. Complete Step-by-Step Execution Trace

```text
[Staff Dashboard] User clicks "Queue status" chip
       │
       ▼
[chatbot-widget.php] POSTs payload { message: "Queue status", session_id: 4 }
       │
       ▼
[chatbot-api.php] Normalizes session role -> $userRole = 'Staff'
       │
       ▼
[ChatbotService_AI] Calls AssistantFactory->handleMessage("Queue status", userId, "Staff")
       │
       ▼
[AssistantFactory.php] Selects tool declarations for 'Staff' via ToolRegistry
       │
       ▼
[SecurityGuard.php] isToolAllowed('getQueueStatus', 'Staff') returns TRUE
       │
       ▼
[ToolRegistry.php] executeToolCall('getQueueStatus', [], userId, 'Staff')
   ❌ DISCOVERY 1: ToolRegistry ONLY has branches for 'Patient', 'Admin', 'Doctor'.
   It has NO 'Staff' branch! Returns: {"error": "Tool handler implementation for 'getQueueStatus' not found."}
       │
       ▼
[AssistantFactory.php Fallback] Triggers fallback execution block for queue queries (L325)
   ❌ DISCOVERY 2: Line 325 fallback template hardcodes Patient-facing text:
   "You currently have no active queue ticket for today. Would you like me to help you book an appointment?"
       │
       ▼
[chatbot-widget.php offerFollowups] Receives reply containing word "appointment"
   ❌ DISCOVERY 3: lower.includes('appointment') evaluates to TRUE and renders:
   ['Check my appointments', 'See available doctors', 'How to book']
```

---

## 2. Answers to Investigation Questions

### 1. Which quick-action button handler fires?
`chatbot-widget.php` line 343 (`sendMessage(label)`) fires when the user clicks `"Queue status"`.

### 2. Which intent is detected?
Message sent is `"Queue status"`. Gemini AI attempts function call `getQueueStatus`, or fallback queue keyword matching triggers `str_contains($lower, 'queue')`.

### 3. Which tool is executed?
`ToolRegistry::executeToolCall('getQueueStatus', [], $userId, 'Staff')` is invoked.  
`SecurityGuard::isToolAllowed('getQueueStatus', 'Staff')` returns `TRUE`, but **`ToolRegistry.php` has no `$role === 'Staff'` dispatch branch** (only `Patient`, `Admin`, `Doctor`). `ToolRegistry` returns: `Tool handler implementation for 'getQueueStatus' not found.`

### 4. Which role is passed into `AssistantFactory`?
`'Staff'` (retrieved from `$_SESSION['user_role']`).

### 5. Which response template generates "You currently have no active queue ticket"?
[AssistantFactory.php L325](file:///c:/xampp/htdocs/CLINICK/classes/ai/AssistantFactory.php#L325):
```php
$reply = "You currently have no active queue ticket for today. Would you like me to help you book an appointment?";
```

### 6. Why is a Staff user receiving Patient queue logic?
1. `ToolRegistry.php` lacks a `Staff` handler, preventing `Staff` from executing operational queue tools (like `getDailyStats` or a staff queue monitor).
2. The fallback queue template in `AssistantFactory.php` L325 hardcodes patient queue wording (*"no active queue ticket..."*).

### 7. Why does the response contain appointment-booking language?
Line 325 of `AssistantFactory.php` explicitly appends: `"Would you like me to help you book an appointment?"`.

### 8. Why are Patient follow-up chips being generated after a Staff workflow?
The response string returned by `AssistantFactory.php` contains the word `"appointment"`. When `chatbot-widget.php` passes the response to `offerFollowups()`, `lower.includes('appointment')` matches and appends Patient chips (`Check my appointments`, `See available doctors`, `How to book`).

---

## 3. Recommended Architectural Fixes (Do Not Implement Yet)

1. **Add Staff Dispatch Branch in `ToolRegistry.php`**:
   Allow `Staff` role to execute appropriate operational tools (`AdminTools` or shared tools).

2. **Implement Staff Queue Monitor Tool (`getStaffQueueOverview`)**:
   Add a Staff-scoped tool that queries total active queue tickets for today across all doctors instead of filtering by `$userId` as a patient.

3. **Make Fallback Wording Role-Aware in `AssistantFactory.php`**:
   Update queue fallback logic to check `$role`:
   - **Staff**: *"Queue Monitor: There are currently X patients in today's clinic queue."*
   - **Patient**: *"Your queue ticket for today..."*
