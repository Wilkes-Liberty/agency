# Maintaining Agency

## Development and distribution

GitHub hosts development and review. Drupal.org owns module-behavior issues and the
Drupal distribution channel. Use `1.x` as the development line.

Recommended remote names match the sibling projects:

- `origin`: `git@github.com:Wilkes-Liberty/agency.git`
- `drupalcode`: `git@git.drupal.org:project/agency.git`

The existing DrupalCode repository is
[project/agency](https://git.drupalcode.org/project/agency). Do not create a
replacement. HTTPS (`https://git.drupalcode.org/project/agency.git`) can be used
for public fetches, with the SSH URL above configured as its push URL.

Inspect existing remotes before changing them. A contributor's checkout may use another
name, and linked worktrees share repository configuration.

When `origin` already names DrupalCode in a shared checkout, retain it until
contributors coordinate a rename. Add `github` for the GitHub URL and use explicit
remote names in every fetch and push. Do not change another worktree's branch or
upstream. The examples below use `github` and `drupalcode`.

## Synchronization workflow

Synchronization is a manual, additive push from reviewed GitHub development to
DrupalCode. There is no automatic push mirror or reverse synchronization job.
GitHub and GitLab CI validate source; neither publishes it to the other service.

| Source | Destination | Authorization |
| --- | --- | --- |
| Reviewed commit on GitHub `1.x` | DrupalCode `refs/heads/1.x` | Explicit source-publication approval |
| Approved release tag, for example `1.0.0` | Identical `refs/tags/1.0.0` | Separate tag/release approval |
| Feature branches and other refs | None by default | Review and explicit approval per ref |

Use semantic version tags without a `v` prefix. Preserve historical branches and
tags. Never use `--mirror`, `--all`, `--tags`, pruning, force-push options, or a
refspec beginning with `+`. Do not resolve divergent history by resetting a
remote branch; bring the differences through review first.

Authenticate through the maintainer's existing SSH agent/keychain and registered
GitHub/Drupal.org keys. Keep host-key verification enabled. Keys, tokens and
private SSH configuration do not belong in the repository, command output or
CI artifacts. This workflow needs no GitHub Actions publication secret.

### Review before publication

From an isolated worktree, fetch both remote histories without pruning:

```sh
git fetch github
git fetch drupalcode
git remote get-url --push drupalcode
git rev-parse github/1.x drupalcode/1.x
git log --reverse --format=fuller drupalcode/1.x..github/1.x
git diff --stat drupalcode/1.x github/1.x
git diff drupalcode/1.x github/1.x
git merge-base --is-ancestor drupalcode/1.x github/1.x
```

Stop if the ancestry check fails or either ref is missing. Review every outgoing
commit's files and message, including material later removed, for credentials,
private operational information and unintended changes. Confirm the destination
is the existing public Agency repository and check the exact candidate's CI and
review results. Record the full candidate SHA and expected destination SHA for
approval. A moving branch name alone is not a publication candidate.

After approval, use the approved full commit SHA as the source of a single-ref
push. In the following template, replace `APPROVED_FULL_COMMIT_SHA` with that
reviewed SHA. Confirm it is still reachable from GitHub `1.x`, refetch DrupalCode,
and stop for renewed review if either the approved candidate or expected
destination has changed.

```sh
git push --dry-run drupalcode APPROVED_FULL_COMMIT_SHA:refs/heads/1.x
git push drupalcode APPROVED_FULL_COMMIT_SHA:refs/heads/1.x
git ls-remote drupalcode refs/heads/1.x
```

The returned remote SHA must equal the approved SHA. A dry-run checks transport
and the proposed ref update; it does not prove every server-side receive hook
will accept the real push. A rejected push is a stop condition, not permission
to force it. No automated return path exists to create synchronization loops.

### Tags and development packages

For a separately approved release, push only its explicit tag ref to each
remote, such as `refs/tags/1.0.0:refs/tags/1.0.0`. Compare the tag object and peeled
commit on both remotes. Never overwrite an existing tag. A source push, a tag,
a Drupal.org release node and a downloadable Composer package are distinct steps.

The intended development line is `1.x`; its Drupal.org development package is
`1.x-dev`. Verify the DrupalCode/project default branch is `1.x` when preparing
that package. Changing a default branch or creating/showing a development
release requires its own authorized step. Do not create a development release
as a side effect of configuring remotes. Verify any approved pointer change on
the saved Drupal.org release record and public download table.

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
