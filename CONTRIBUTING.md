# Contributing

Thanks for considering a contribution. This project is small and deliberate; the bar is high
but the process is simple.

## Dev setup

```bash
git clone https://github.com/codewithagents/openapi-laravel.git
cd openapi-laravel
composer install
```

PHP 8.2+ is required.

## Quality gates

Run these before opening a PR. They are the same checks CI runs.

```bash
composer test         # Pest test suite (includes the 130-spec corpus gate)
composer stan         # PHPStan at max level
composer lint         # Laravel Pint, check only
composer pint         # Laravel Pint, fix style
composer qa           # lint + stan + test in one go
```

Extra gates for deeper changes:

```bash
composer test:type    # 100% type coverage, enforced
composer test:mutate  # Pest native mutation testing, min score 90%
composer deptrac      # architecture boundaries (Parser/Naming are leaf layers)
```

## The quality bar

- **PHPStan max** and **Pint** must pass, no baseline additions.
- **Deterministic output**: same spec in, byte-identical files out. If your change alters
  generated output, update the committed snapshots and explain the diff in the PR.
- **All 130 corpus specs** (Stripe, GitHub, OpenAI, Slack, Twilio, and friends) must still
  parse and generate valid PHP.
- New behavior needs tests. Pest is the test framework.

## Commits and PRs

- **Conventional commits with scopes**, e.g. `feat(emitter): ...`, `fix(parser): ...`,
  `docs(readme): ...`.
- Keep PRs **small and focused**: one logical change per PR. A big PR that mixes a feature,
  a refactor, and style fixes will be asked to split.

## Style rule

No em dashes anywhere: not in code comments, docs, or commit messages. Use commas, colons,
or full stops instead.
