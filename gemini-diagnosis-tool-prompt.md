Reviewed your Step 1 findings — approved. Your existing tool pattern (createAppointment) is clear, and your fallbackRoleResponse() already covers offline fallback, so we're dropping the separate fallback step from my earlier prompt — we'll just extend the existing one. Proceed with the following, in order. Show me output at each checkpoint before moving to the next.

CONTEXT: I'm attaching DiagnosisClassifier.php. This is DELIBERATELY SEPARATE from the chatbot's existing intent classifier — it does symptom-text → broad condition category classification, which is the actual thing Objective 5 requires. It also has a built-in emergency-term interceptor that must never be bypassed.

STEP 1 — Place the file
Put DiagnosisClassifier.php and its dependency NaiveBayesClassifier.php wherever your existing PHP utility/service classes live in this project (match your existing convention — show me where you put them and why).

STEP 2 — Register it as a Gemini tool, matching your exact createAppointment pattern
In PatientTools.php (and DoctorTools.php if doctors should also be able to trigger this), add:

1. A declaration following the exact same schema shape as createAppointment:
   - name: checkSymptoms
   - description: must explicitly instruct Gemini that whenever a user describes symptoms or asks what condition/illness they might have, it MUST call this tool rather than answering from its own knowledge. Be explicit about this in the description text itself, since that's what Gemini reads to decide when to call it.
   - parameters: a single required string parameter, symptom_text, description "The patient's own description of their symptoms, as close to verbatim as possible."

2. A handler method checkSymptoms($args, $userId) that:
   - Instantiates DiagnosisClassifier, calls ->classify($args['symptom_text'])
   - Returns the raw result array as-is (isEmergency, category, confidence, disclaimer) — do NOT reformat or reword it in PHP, let Gemini phrase the final reply to the user from this structured result in the next turn, the same way createAppointment's result flows back through functionResponse.

3. Register the tool in ToolRegistry.php the same way existing tools are registered, and confirm SecurityGuard::isToolAllowed() permits it for Patient (and Doctor, if added) roles.

4. IMPORTANT — update the relevant system prompt builder (PatientSecretary.php, and DoctorSecretary.php if applicable) to explicitly instruct Gemini: "When the user describes symptoms or asks what condition they might have, always call checkSymptoms rather than diagnosing from your own knowledge. If the tool result has isEmergency: true, relay the emergency disclaimer directly and urge the user to contact staff or emergency services immediately — do not soften or omit this."

STEP 3 — Extend the existing offline fallback for symptoms
In AssistantFactory::fallbackRoleResponse(), in the Patient branch (and Doctor branch if applicable), add a condition checking for symptom-related keywords (fever, pain, ache, symptom, sick, hurts, etc. — match the spirit of your existing keyword checks in that method) that calls DiagnosisClassifier directly (no Gemini needed, it's local) and returns its result in the reply, same disclaimer rules as Step 2.4 apply here too.

STEP 4 — Test and report back with real output
1. Send a normal symptom message through api/ai/chat.php, e.g. "I have a fever, sore throat, and mild cough" — paste the actual JSON response. Confirm tool_calls_executed includes checkSymptoms, and that the replied category/disclaimer text traces back to the classifier's actual output, not something Gemini invented independently.
2. Send an emergency-flagged message, e.g. "I'm having chest pain and can't breathe" — paste the response. Confirm it returned the emergency redirect, not a category guess.
3. Temporarily break the Gemini API call (bad key) and resend the fever message from step 4.1 through the same endpoint — confirm fallbackRoleResponse now handles it correctly via the local classifier, then restore the real key.
4. Confirm an unrelated existing flow still works — send an appointment booking message and paste that result too, to confirm nothing broke.

Do not tell me this is done without pasting all four real outputs from Step 4.
