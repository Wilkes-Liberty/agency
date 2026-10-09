# Maintaining Agency

## Development and distribution

GitHub hosts development and review. Drupal.org owns module-behavior issues and the
Drupal distribution channel. Use `1.x` as the development line.

Recommended remote names match the sibling projects:

- `origin`: `git@github.com:Wilkes-Liberty/agency.git`
- `drupalcode`: `git@git.drupal.org:project/agency.git`

Inspect existing remotes before changing them. A contributor's checkout may use another
name, and linked worktrees share repository configuration.

Drupal.org's project type, display name, release metadata and security coverage are
separate from source files. Confirm them directly before distribution. A Composer
`drupal-module` declaration does not convert a Distribution project.

## Before a release

1. Confirm scope, changelog, package metadata, supported versions and dependency compatibility.
2. Confirm the Drupal.org module project and release line are ready.
3. Run the supported-core matrix, standards checks and applicable behavior tests.
4. Review contributions, security findings and the exact outgoing files and history.
5. Obtain maintainer merge and release authorization.
6. Publish the approved source and version through the project's chosen release workflow.
7. Verify package availability and a clean install of that released artifact.
8. Update issue disposition and Drupal.org contribution credit accurately.

Do not force-push the development branch or silently replace a published tag.
Do not publish a stable release or claim Drupal security coverage solely because a
build is green. Coverage requires the project's eligibility and opt-in under Drupal policy.

## Repository controls

The repository inherits organization branch protection. Changes to organization rules,
secrets, billing, visibility and release credentials are maintainer responsibilities.
CI uses read-only permissions except the established attribution workflow's narrowly
scoped branch/PR cleanup. Do not add production credentials to test jobs.

Reviewers should see the actual checks, not just a mergeable flag. When an automated
reviewer cannot run, record a manual findings review; an unavailable review is not a pass.

## Documentation

Keep the README's implemented scope distinct from the North Star. Feature documentation
should explain the user's outcome, prerequisites, failure behavior and integration boundary.
Use synthetic examples and keep private operating records out of source and issue bodies.
