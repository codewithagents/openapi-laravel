# openapi-laravel launch demo
# Renders: spec snippet -> generator run -> generated Data class

Output "/Users/benjamin.eckstein/IdeaProjects/planning/openapi-laravel-launch-assets/openapi-laravel-demo.gif"

Require bat

Set Shell "bash"
Set FontSize 20
Set Width 1920
Set Height 1080
Set Padding 28
Set Theme "Catppuccin Mocha"
Set TypingSpeed 30ms

Hide
Type "cd /tmp/openapi-laravel-media && clear"
Enter
Sleep 500ms
Show

Sleep 800ms
Type "# 1. An OpenAPI schema with validation constraints"
Enter
Sleep 400ms
Type "bat --style=plain demo/pet.yaml"
Enter
Sleep 7s

Hide
Type "clear"
Enter
Show

Sleep 500ms
Type "# 2. One command"
Enter
Sleep 400ms
Type `vendor/bin/openapi-laravel --spec=demo/pet.yaml --output=app/Data --namespace="App\\Data"`
Enter
Sleep 3s

Hide
Type "clear"
Enter
Show

Sleep 500ms
Type "# 3. Typed laravel-data classes, validation rules derived from the spec"
Enter
Sleep 400ms
Type "bat --style=plain app/Data/PetData.php"
Enter
Sleep 10s
