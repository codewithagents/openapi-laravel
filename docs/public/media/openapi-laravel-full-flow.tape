# ============================================================================
# openapi-laravel FULL FLOW demo
# ============================================================================
#
# WHAT IT SHOWS (the README infographic arc, as a live terminal):
#   spec -> openapi:generate (no flags) -> generated Data class with rules()
#   -> hand-written concrete controller -> spec changes -> openapi:check
#   fails (exit 1) -> the diff -> regenerate -> git status proves the
#   hand-written controller is untouched -> openapi:check exits 0.
#
# Recorded against the merged #45 implementation (merge commit 0a9acc9):
#   - `php artisan openapi:generate` with NO flags emits models + rules +
#     controllers + routes (config/openapi-laravel.php defaults to enabled).
#   - `php artisan openapi:check` checks everything by default, exit 1 on
#     drift, exit 0 in sync, `--diff` prints a unified line diff.
#   - Verified summary wording: "Generated 2 classes into app/Data.",
#     "Generated 1 abstract controller into app/Http/Controllers/Api.",
#     "Generated 1 route file into routes/api.generated.php.",
#     "Drift detected in 1 file(s):", "Generated code is in sync with the
#     spec."
#
# ADAPTATIONS vs the draft tape:
#   - The concrete controller lives at app/Http/Controllers/Api/
#     PetController.php (the generated routes file imports
#     App\Http\Controllers\Api\PetController, so the concrete class must sit
#     in the Api namespace next to AbstractPetController).
#   - Beat 6 is split in two screens: `openapi:check` alone (the ERROR
#     headline plus exit code fit cleanly in 23 rows), then
#     `openapi:check --diff` on a fresh screen. The diff prints the whole
#     31-line file, so the top scrolls off; the visible tail holds the
#     -/+ changed pair, which is the payload.
#   - The Pet status enum is [available, sold]: with `pending` the generated
#     Rule::in(...) line is 81+ chars and wraps at 80 columns.
#
# SIZING (the 830px math): Width 1200, Height 720, Padding 24, FontSize 24.
#   80 columns, ~23 rows, effective font ~16.6px at GitHub's 830px column.
#
# FIXTURE (already built and committed clean):
#   /tmp/openapi-laravel-full-flow = Laravel 12 skeleton, the package
#   installed via a composer path repository pointing at a fresh clone of
#   main (/tmp/openapi-laravel-main), config/openapi-laravel.php pointing at
#   openapi/petstore.yaml with relative output paths, a clean generate plus
#   the hand-written PetController committed to git.
#   RESET BEFORE EVERY TAKE:
#     cd /tmp/openapi-laravel-full-flow && git checkout -- . && \
#       php artisan openapi:generate >/dev/null && git status --short  # empty
# ============================================================================

Output "/Users/benjamin.eckstein/IdeaProjects/planning/openapi-laravel-launch-assets/openapi-laravel-full-flow.gif"

Require bat

Set Shell "bash"
Set FontSize 24
Set Width 1200
Set Height 720
Set Padding 24
Set Theme "Catppuccin Mocha"
Set TypingSpeed 30ms

Hide
Type "cd /tmp/openapi-laravel-full-flow && clear"
Enter
Sleep 500ms
Show

# --- Beat 1: the spec ------------------------------------------------------
Sleep 800ms
Type "# 1. An OpenAPI schema with validation constraints"
Enter
Sleep 400ms
Type "bat -p -r 57:73 openapi/petstore.yaml"
Enter
Sleep 4500ms

Hide
Type "clear"
Enter
Show

# --- Beat 2: one command, everything generated ------------------------------
Sleep 500ms
Type "# 2. One command, no flags"
Enter
Sleep 400ms
Type "php artisan openapi:generate"
Enter
Sleep 4s

Hide
Type "clear"
Enter
Show

# --- Beat 3: peek at a generated Data class ---------------------------------
Sleep 500ms
Type "# 3. Typed laravel-data class, rules() derived from the spec"
Enter
Sleep 400ms
Type "bat -p -r 12:30 app/Data/PetData.php"
Enter
Sleep 5s

Hide
Type "clear"
Enter
Show

# --- Beat 4: your code, a concrete controller -------------------------------
Sleep 500ms
Type "# 4. You write only business logic, extending the abstract base"
Enter
Sleep 400ms
Type "bat -p -r 11:27 app/Http/Controllers/Api/PetController.php"
Enter
Sleep 5s

Hide
Type "clear"
Enter
Show

# --- Beat 5: the spec changes ------------------------------------------------
Sleep 400ms
Type "# 5. Someone tightens a constraint in the spec"
Enter
Sleep 300ms
Type "sed -i '' 's/maxLength: 64/maxLength: 32/' openapi/petstore.yaml"
Enter
Sleep 600ms
Type "grep -n 'maxLength' openapi/petstore.yaml"
Enter
Sleep 2500ms

Hide
Type "clear"
Enter
Show

# --- Beat 6a: the gate catches it (exit 1) -----------------------------------
Sleep 400ms
Type "# 6. CI gate: code on disk vs spec"
Enter
Sleep 300ms
Type "php artisan openapi:check"
Enter
Sleep 1500ms
Type "echo $?"
Enter
Sleep 2s

Hide
Type "clear"
Enter
Show

# --- Beat 6b: see exactly what drifted ----------------------------------------
Sleep 400ms
Type "# ...and exactly what drifted"
Enter
Sleep 300ms
Type "php artisan openapi:check --diff"
Enter
Sleep 4500ms

Hide
Type "clear"
Enter
Show

# --- Beat 7: regenerate --------------------------------------------------------
Sleep 400ms
Type "# 7. Regenerate"
Enter
Sleep 300ms
Type "php artisan openapi:generate"
Enter
Sleep 3s

Hide
Type "clear"
Enter
Show

# --- Beat 8: the payoff ---------------------------------------------------------
Sleep 400ms
Type "# 8. Only generated files changed. Your controller: untouched."
Enter
Sleep 300ms
Type "git status --short"
Enter
Sleep 2500ms
Type "php artisan openapi:check"
Enter
Sleep 1500ms
Type "echo $?"
Enter
Sleep 1500ms
Type "# Generated code owned by the spec. Your code never touched."
Enter
Sleep 3500ms
