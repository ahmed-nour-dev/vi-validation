# Contributing to vi/validation

First off, thank you for considering contributing to vi/validation! It's people like you who make the open-source community such an amazing place.

## Code of Conduct

By participating in this project, you are expected to uphold our Code of Conduct. (Currently following standard professional ethics).

## How Can I Contribute?

### Reporting Bugs
- Use a clear and descriptive title.
- Describe the exact steps which reproduce the problem.
- Provide a code snippet that demonstrates the issue.
- Explain which behavior you expected to see instead and why.

### Suggesting Enhancements
- Use a clear and descriptive title.
- Provide a step-by-step description of the suggested enhancement.
- Explain why this enhancement would be useful to most vi/validation users.

### Pull Requests
- Fill in the pull request template.
- Ensure the test suite passes (`./vendor/bin/phpunit`).
- Ensure PHPStan passes at level 8 (`./vendor/bin/phpstan analyse`).
- Maintain existing coding standards (PSR-12).
- Document any change in behavior.
- Add tests for new features or bug fixes (aim for high coverage).


## Development Setup

1. Clone the repository.
2. Install dependencies: `composer install`.
3. Run tests: `./vendor/bin/phpunit`.
4. Run static analysis: `./vendor/bin/phpstan analyse`.
5. Changing anything on a hot path (rules, `ValidatorEngine`, `NativeCompiler`)? Run
   `composer bench:quick` (or the full `composer bench`) and check it against
   `benchmark-results/latest.json` — see the README's [Benchmarks](README.md#-benchmarks)
   section for methodology.


## Attribution
Our contributing guidelines are inspired by plenty of successful open-source projects.
