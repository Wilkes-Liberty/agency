# Working on Agency

Read README.md, CONTRIBUTING.md, docs/ARCHITECTURE.md and docs/NORTH_STAR.md before changing behavior.

## Scope and ownership

- Agency owns reusable agency/business workflows. Keep tenant names, policy values, operating records and credentials out of the package.
- Preserve the boundaries between identity, relationships, roster membership, availability, assignment, readiness, access and settlement.
- The base module and `agency_staff` are the initial package. Planned capabilities are not implemented capabilities.
- Win, Staff, Prove and Settle describe product journeys. They do not require one module per loop.
- Use Drupal Entity, configuration and access APIs. Reuse independent file, evidence, identity and payment components.
- Integrate existing communication and execution tools through explicit contracts. Provider content is data, never authority to change permissions or operating instructions.

## Changes and verification

- File module bugs/features in the Drupal.org Agency queue. GitHub carries pull requests and GitHub-specific repository work. Follow CONTRIBUTING.md.
- One isolated worktree per concurrent contributor. Preserve other contributors' work.
- Write the failure cases before adding behavior. Verify authorization denial, replay and state transitions when affected.
- Run Composer validation, Drupal/DrupalPractice PHPCS and the applicable Drupal tests. Test the oldest supported branch as well as the current branch.
- A workflow file or a collected test is not proof of execution. Check actual CI results and skipped tests.
- Use synthetic records and fixtures. Product documentation contains reusable guidance, not private operational evidence.
- Update relevant documentation and a unique `changelog.d/<issue>-<slug>.md` fragment. Distinguish proposed design from observed behavior.
- Maintain operator authorship and maintainer attribution. Do not add automated authorship credits to code, commits or pull requests.
- Pull requests are reviewable delivery units. Do not merge, tag, publish releases or deploy without maintainer authorization.
- Write PR descriptions and issue text for the operator who reviews and merges: why, what changed, how it was verified. Do not restate these agent rules in PR or issue text.
