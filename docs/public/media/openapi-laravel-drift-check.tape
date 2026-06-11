# openapi-laravel drift-check demo
# Renders: in-sync check (exit 0) -> spec edit -> drift detected with diff (exit 1)

Output "/Users/benjamin.eckstein/IdeaProjects/planning/openapi-laravel-launch-assets/openapi-laravel-drift-check.gif"

Set Shell "bash"
Set FontSize 20
Set Width 1920
Set Height 1080
Set Padding 28
Set Theme "Catppuccin Mocha"
Set TypingSpeed 30ms

Hide
Type "cd /tmp/openapi-laravel-drift-media && clear"
Enter
Sleep 500ms
Show

Sleep 600ms
Type "# 1. The CI drift gate: code on disk vs spec"
Enter
Sleep 300ms
Type `vendor/bin/openapi-laravel check --spec=demo/pet.yaml --output=app/Data --namespace="App\\Data"`
Enter
Sleep 1000ms
Type "echo $?"
Enter
Sleep 2000ms

Hide
Type "clear"
Enter
Show

Sleep 400ms
Type "# 2. Someone tightens a constraint in the spec"
Enter
Sleep 300ms
Type `sed -i '' 's/maxLength: 64/maxLength: 32/' demo/pet.yaml`
Enter
Sleep 600ms
Type "grep -n 'maxLength' demo/pet.yaml"
Enter
Sleep 2000ms

Hide
Type "clear"
Enter
Show

Sleep 400ms
Type "# 3. The gate catches it"
Enter
Sleep 300ms
Type `vendor/bin/openapi-laravel check --spec=demo/pet.yaml --output=app/Data --namespace="App\\Data" --diff`
Enter
Sleep 4000ms
Type "echo $?"
Enter
Sleep 1200ms
Type "# exit 1 = CI build fails. The contract is enforced."
Enter
Sleep 3500ms
