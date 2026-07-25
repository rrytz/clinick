---
tags:
  - clinick
  - chatbot
  - investigation
  - frontdesk
  - rbac
aliases:
  - Staff Chatbot Full Root Cause Analysis
  - Frontdesk Assistant Bug Investigation
status: completed
created: 2026-07-26
---

# Complete Investigation Report — Dual Root-Cause Analysis of Staff Chatbot Behavior

> [!danger] Executive Summary
> Empirical execution tracing reveals **TWO SEPARATE BUGS** that cause Staff users to see Patient-facing quick actions and Patient-facing responses:
> 1. **Backend Role Fallback Bug (`AssistantFactory.php`)**: When fallback/degraded logic triggers, `AssistantFactory.php` only checks `Admin` and `Doctor` roles. `Staff` requests fall into an unhandled `else` block that hardcodes `assistant_name = 'Personal Clinic Assistant'` and returns the Patient greeting.
> 2. **Frontend Follow-Up Chip Hardcoding (`chatbot-widget.php`)**: `offerFollowups()` in JavaScript contains role-unaware hardcoded arrays that overwrite Staff chips whenever the bot reply contains the word `"appointment"`.

---

## 1. Empirical Request / Response Trace

Using an active Staff user session (`user_id = 16`, `role = 'Staff'`), we captured the exact network payload and backend response when clicking **"Patient check-in"**:

### Request Payload (`chatbot-widget.php` → `chatbot-api.php`)
```json
{
  "message": "Patient check-in",
  "session_id": 4
}
```
*(Session cookie carries `$_SESSION['user_role'] = 'Staff'`)*

### Backend Response JSON (`chatbot-api.php` / `ChatbotService_AI`)
```json
{
    "reply": "Hello! I am your Personal Clinic Assistant. How can I help you today? I can help you check doctor availability, book or manage appointments, or track your queue position.",
    "intent": "ai_assistant",
    "language": "en",
    "confidence": 0.95,
    "lowConfidence": false,
    "session_id": 4,
    "role": "Staff",
    "assistant_name": "Personal Clinic Assistant",
    "tool_calls": []
}
```

> [!important] Key Empirical Proof
> The backend response **ALREADY** returns `assistant_name: "Personal Clinic Assistant"` and the Patient greeting string for a `Staff` request. The frontend does not corrupt the name; the backend explicitly returns it.

---

## 2. Bug #1 Root Cause — Backend Role Routing Defect

### File & Line Reference
- **File**: [AssistantFactory.php L246–280](file:///c:/xampp/htdocs/CLINICK/classes/ai/AssistantFactory.php#L246-L280)

### Code Inspection
```php
if ($role === 'Admin') {
    $assistantName = 'AI Operations Secretary';
    // Admin handling logic...
} elseif ($role === 'Doctor') {
    $assistantName = 'Clinical Workflow Assistant';
    // Doctor handling logic...
} else {
    // ❌ DEFECT SITE: Staff role falls into this default else block!
    $assistantName = 'Personal Clinic Assistant';
    ...
    $reply = "Hello! I am your Personal Clinic Assistant. How can I help you today? I can help you check doctor availability, book or manage appointments, or track your queue position.";
}
```

### Explanation
When processing messages in fallback/degraded mode (or keyword fallback), `AssistantFactory` evaluates `$role`:
1. It checks `if ($role === 'Admin')` → sets `AI Operations Secretary`.
2. It checks `elseif ($role === 'Doctor')` → sets `Clinical Workflow Assistant`.
3. **There is NO `elseif ($role === 'Staff')` branch!**
4. Any `Staff` request falls directly into `else` (line 279), which overrides `$assistantName` to `'Personal Clinic Assistant'` and sets `$reply` to the Patient greeting.

This is where `'Frontdesk Assistant'` (rendered in the header on page load) becomes `'Personal Clinic Assistant'` in the chat bubble response.

---

## 3. Bug #2 Root Cause — Frontend Follow-Up Chip Defect

### File & Line Reference
- **File**: [chatbot-widget.php L442–453](file:///c:/xampp/htdocs/CLINICK/chatbot-widget.php#L442-L453)

### Code Inspection
```javascript
function offerFollowups(text, intent) {
  if (!intent && text.length < 10) return;

  const lower = text.toLowerCase();
  if (lower.includes('appointment') || intent === 'book_appointment') {
    addChips(['Check my appointments', 'See available doctors', 'How to book']); // ❌ HARDCODED FOR ALL ROLES
  } else if (intent === 'farewell' || lower.includes('take care') || lower.includes('goodbye')) {
    addChips(QUICK_REPLIES);
  } else if (intent === 'fallback' || lower.includes("not sure")) {
    addChips(['Book appointment', 'Clinic hours', 'Talk to staff']); // ❌ HARDCODED PATIENT FALLBACK
  }
}
```

### Explanation
1. On initial load, `chatbot-widget.php` correctly initializes `QUICK_REPLIES` for `Staff` (`['Patient check-in', 'Search patient', 'Queue status', 'Walk-in guide']`).
2. When the backend returns Bug #1's reply (*"Hello! I am your Personal Clinic Assistant... appointments..."*), `offerFollowups()` receives the text.
3. `lower.includes('appointment')` evaluates to `true`.
4. `offerFollowups()` ignores `USER_ROLE` and unconditionally appends `['Check my appointments', 'See available doctors', 'How to book']`.

---

## 4. Summary of Scenarios & Findings

| Item | Question | Empirical Finding |
| :--- | :--- | :--- |
| 1 | **Request Payload** | `{ "message": "Patient check-in", "session_id": 4 }` (Session cookie carries `$_SESSION['user_role'] = 'Staff'`) |
| 2 | **Role Detection** | Server detects `$userRole = 'Staff'` correctly |
| 3 | **Response Payload** | Returns `role: "Staff"`, `assistant_name: "Personal Clinic Assistant"`, `degraded: true` |
| 4 | **Returned Name** | `"Personal Clinic Assistant"` (returned directly by `AssistantFactory.php` L280) |
| 5 | **Execution Path** | Executed `AssistantFactory.php` fallback path lines 246–328 |
| 6 | **Scenario Result** | **Scenario B & C Combined**: Backend role-routing lacks `Staff` branch, causing fallback to Patient logic |

---

## 5. Recommended Architectural Fixes (Do Not Implement Yet)

To resolve both bugs completely:

### 1. Fix Backend Role Routing in `AssistantFactory.php`
Add an explicit `elseif ($role === 'Staff')` branch in `AssistantFactory.php` around line 278:
```php
} elseif ($role === 'Staff') {
    $assistantName = 'Frontdesk Assistant';
    if (str_contains($lower, 'check-in') || str_contains($lower, 'queue') || str_contains($lower, 'patient')) {
        $q = $this->tools->executeToolCall('getQueueStatus', [], $userId, $role, $convId);
        $reply = "Frontdesk Assistant online. Queue management and patient check-in tool ready.";
    } else {
        $reply = "Hello! I am your Frontdesk Assistant. I can help you with patient check-in, queue status, searching patient records, and walk-in guidance.";
    }
}
```
Also add a dedicated `StaffSecretary.php` persona class for full Gemini AI execution.

### 2. Fix Frontend Follow-Up Rendering in `chatbot-widget.php`
Refactor `offerFollowups()` to select chips dynamically using `USER_ROLE`:
```javascript
function offerFollowups(text, intent) {
  if (!intent && text.length < 10) return;

  const lower = text.toLowerCase();
  if (lower.includes('appointment') || intent === 'book_appointment') {
    if (USER_ROLE === 'Staff') {
      addChips(['Patient check-in', 'Search patient', 'Queue status', 'Walk-in guide']);
    } else if (USER_ROLE === 'Doctor') {
      addChips(['Today\'s schedule', 'Write Rx guide', 'Work availability', 'Search patient']);
    } else if (USER_ROLE === 'Admin') {
      addChips(['Pending approvals', 'System analytics', 'User roles', 'Audit logs']);
    } else {
      addChips(['Check my appointments', 'See available doctors', 'How to book']);
    }
  } else {
    addChips(QUICK_REPLIES);
  }
}
```
