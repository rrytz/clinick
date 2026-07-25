# CLINICK Chatbot — PHP Port

Ported from the original Next.js/TypeScript module to plain PHP so it runs
natively inside your Laravel/XAMPP hospital system (CLINICK) instead of as
a separate Node app.

## Files
- `NaiveBayesClassifier.php` — the classifier, framework-agnostic
- `chatbot-data.php` — training phrases + responses (EN/FIL/CEB)
- `ChatbotService.php` — wires language detection + intent classification
- `ChatbotController.php` — **use this if CLINICK uses Laravel routing**
- `chatbot-api.php` — **use this if CLINICK is plain PHP / not using Laravel's router**
- `chatbot-widget.php` — the floating chat widget, works via `include()` or Blade `@include`

## Step 0 — figure out which setup you actually have
Before wiring anything, check your project:
1. Do you have a `routes/web.php` or `routes/api.php` file? → You have Laravel routing. Use `ChatbotController.php`.
2. Do your pages (`admin_dashboard.php`, `patient_dashboard.php`, etc.) start with plain `<?php` and no `namespace App\...` line, and there's no `routes/` folder? → Plain PHP. Use `chatbot-api.php` instead.
3. Not sure? Run `php artisan route:list` in your project root. If that command works, you have Laravel routing — use the Controller path.

## Path A — Laravel routing
1. Move `NaiveBayesClassifier.php`, `ChatbotService.php`, `chatbot-data.php` into `app/Services/`.
2. Move `ChatbotController.php` into `app/Http/Controllers/`.
3. Add to `routes/api.php`:
   ```php
   use App\Http\Controllers\ChatbotController;
   Route::post('/chatbot', [ChatbotController::class, 'respond']);
   ```
4. In `chatbot-widget.php`, set `$chatbotEndpoint = '/api/chatbot';`
5. Rename `chatbot-widget.php` to `chatbot-widget.blade.php`, put it in `resources/views/partials/`.
6. In your main layout file (probably `resources/views/layouts/app.blade.php`), add before `</body>`:
   ```blade
   @include('partials.chatbot-widget')
   ```

## Path B — plain PHP, no Laravel routing
1. Put `NaiveBayesClassifier.php`, `ChatbotService.php`, `chatbot-data.php`, and `chatbot-api.php` in the same folder as your existing dashboard files.
2. Leave `$chatbotEndpoint = '/chatbot-api.php';` as-is in `chatbot-widget.php` (adjust the path if you put it in a subfolder).
3. In each dashboard file that should show the widget (`admin_dashboard.php`, `doctor_dashboard.php`, `patient_dashboard.php`, etc.), add right before `</body>`:
   ```php
   <?php include 'chatbot-widget.php'; ?>
   ```

## Verify it works before trusting it
I could not run PHP in my own environment to test this port live — it's a
direct line-for-line translation of the TypeScript version I already tested
and confirmed working, but you should verify it yourself. Quick test:

```php
<?php
require_once 'ChatbotService.php';
$bot = new ChatbotService();
print_r($bot->respond("cancel my appointment"));
print_r($bot->respond("gusto ko pong mag-book ng appointment"));
print_r($bot->respond("unsa oras mo mo-abre"));
```
Expected: each call returns an array with `intent`, `language` matching the
input language (en/fil/ceb), `confidence`, `reply`, and `lowConfidence`.
Run this with `php test.php` in your terminal (or drop it as a page in
XAMPP and view it in browser) before wiring the widget in.

## Known limitations (same as the original)
- Small training set (~15-25 phrases/intent) — expect some misclassification, especially Cebuano.
- Words shared across languages (e.g. "salamat") can cause language misdetection on short messages.
- `check_symptoms` intent currently just hands off with a disclaimer — wire it to your actual Naive Bayes diagnosis module once that's built; `NaiveBayesClassifier.php` is reusable for that with a different training set.
