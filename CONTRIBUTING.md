# Contributing to Certinel

This project aims to stay lightweight and easy to self-host. Contributions are welcome, but the bar for maintainability is strict.

## Workflow

1. Fork the repository
2. Create a feature branch:
   ```bash
   git checkout -b feature/<short-name>
   ```
3. Commit your changes (small commits preferred):
   ```bash
   git commit -m "Concise summary of the change"
   ```
4. Push your branch:
   ```bash
   git push origin feature/<short-name>
   ```
5. Open a Pull Request (PR)

## Contribution Guidelines

- **Follow the Code of Conduct** (`CODE_OF_CONDUCT.md`).
- **Keep scope tight.** A PR should do one thing; avoid drive-by refactors.
- **No breaking changes without discussion.** If you must break behavior, justify it and document the migration path.
- **Security-sensitive changes** (auth, roles, CSRF, input handling) must include:
  - threat reasoning (what is prevented)
  - tests or reproducible validation steps
- **Documentation required** for any user-facing change (README and/or `docs/`).

## Coding standards (pragmatic)

- Prefer clarity over cleverness.
- Keep functions small and explicit.
- Validate and sanitize all external input.
- Use prepared statements for all SQL (PDO).
- Avoid introducing external dependencies unless the value is clear and the operational burden is justified.

## What to include in a PR

- Problem statement (what this fixes / adds)
- Approach (what you changed)
- Validation steps (how you tested it)
- Any backward-compatibility notes or migration SQL (if applicable)

## Reporting security issues

Please do **not** open a public issue for security vulnerabilities. Email the contact listed in `README.md`.
