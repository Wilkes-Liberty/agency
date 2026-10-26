# Contributing to Agency

Agency is developed through reviewed changes. Begin with the [North Star](docs/NORTH_STAR.md)
and [architecture](docs/ARCHITECTURE.md), then keep each contribution independently verifiable.

## Where work belongs

- Module bugs and feature proposals: [Drupal.org issue queue](https://www.drupal.org/project/issues/agency).
- Code review: GitHub pull requests; reference the canonical Drupal.org issue when applicable.
- GitHub-specific workflows and repository documentation: GitHub issues.
- Vulnerability reports: [SECURITY.md](SECURITY.md); use a private reporting path.

Do not duplicate a module-behavior issue across trackers. Keep Drupal.org discussions
self-contained, with Drupal.org issue, release and DrupalCode references. Internal work
tracking belongs outside public project history.

## Development workflow

1. Start from the development branch `1.x`, or an explicitly named dependency branch for a stacked change.
2. Use an isolated worktree and a focused branch, such as `feature/ISSUE-topic` or `chore/ISSUE-topic`.
3. Describe the user outcome and failure cases before implementation.
4. Run the [development checks](docs/DEVELOPMENT.md).
5. Update the relevant documentation and a unique `changelog.d/<issue>-<slug>.md` fragment.
6. Open a pull request with its canonical issue, validation and remaining limitations.
7. Resolve review findings and failing checks. A maintainer decides when to merge and release.

Preserve a stacked pull request's dependency until the foundation is merged. Rebase or
retarget deliberately and rerun checks; never force-push another contributor's branch.

## Standards and compatibility

Use Drupal and DrupalPractice coding standards. Keep PHP syntax compatible with the
declared minimum and verify the supported core minors independently. A constraint such
as `^11.3` can resolve a later minor; a floor job must pin `~11.3.0`.

Tests should demonstrate a meaningful failure or integration boundary. Prefer an installed
Drupal host for module discovery, enablement, configuration and access behavior. Do not
add tests that only repeat implementation details.

## Product boundaries

Do not embed a customer's identity, personnel data, rates, policy periods or integration
credentials. Keep fixtures synthetic and documents appropriate for public distribution.

Changes to invitation, access, private documents, financial state or evidence persistence
must explain authorization, concurrency, retries, failure behavior and recovery. Future
integrations should reduce adoption effort through the tools users already have; never
claim provider support before the integration is implemented and verified.

## Maintainers

Jeremy Michael Cerda and Wilkes & Liberty, LLC maintain Agency.

## Changelog fragments

Follow [the changelog workflow](.github/CHANGELOG.md) for independent PR notes
and lossless release compilation. Existing opt-in entry policy is unchanged.
