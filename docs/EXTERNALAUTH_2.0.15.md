# Externalauth 2.0.15 binding-writer review

**Observed review**, then an operator pin move. Writer bytes on 2.0.14 and
2.0.15 match the hashes already bound on 2.0.13. On 2026-11-13 the operator
moved the reviewed reference to Externalauth **2.0.15** and requalified.
This record does not approve a later unreviewed 2.0.x, a host Composer
constraint change outside this package, or production qualification.

Source review: [agency#30](https://github.com/Wilkes-Liberty/agency/issues/30).
Host dependency qualification is separate.

## What the fingerprint binds

`BindingHistory::fingerprint()` fails closed unless:

- `Composer\InstalledVersions::getReference('drupal/externalauth')` is exactly
  `2.0.15` (OpenID Connect remains `3.0.0-alpha9`)
- SHA-256 of the installed writer files matches `REVIEWED_WRITERS`
  (`src/Authmap.php` and `src/Form/AuthmapDeleteForm.php`)
- Externalauth schema is at least `8104` when the module is enabled
- The digest still includes that reviewed reference string, the writer-adjacent
  schema tuple, covered mappings, provider settings, and HMAC
  `chronicle-agency-history-key-v1`

The digest therefore changes if the reviewed reference string changes, even
when the writer bytes are identical. Moving the pin from 2.0.13 to 2.0.15
changes the digest and requires fresh qualification of already-qualified
coverage. That is the 2026-11-13 operator decision recorded below.

## Method

Compared git tags `2.0.13` (`b705c6c2f6b0e6b8e4b9f3949db8eaebdc6fac85`) through
`2.0.15` (`0c3d303227d66440f1b85da9aeb263b832bdb093`) from
[git.drupalcode.org/project/externalauth](https://git.drupalcode.org/project/externalauth).
Hashed the two fingerprinted writers on `2.0.13`, `2.0.14` and `2.0.15`. Read
the 2.0.14 and 2.0.15 release notes and the surrounding authentication,
deletion, service and migrate paths that can reach `authmap`.

## Observed 2.0.13 → 2.0.15 delta

| Release | Upstream change | Binding-writer effect |
| --- | --- | --- |
| 2.0.14 | README.txt → README.md ([#3315932](https://www.drupal.org/project/externalauth/issues/3315932)) | None. Not a writer. |
| 2.0.14 | Remove the D6/D7 migrate source plugin and templates; deprecate the migrate destination for 2.2.0 ([#3618043](https://www.drupal.org/project/externalauth/issues/3618043)) | Destination still calls `AuthmapInterface::save()`. Agency's tracked decorator remains the public `externalauth.authmap` writer. No `authmap` schema or update hook change. |
| 2.0.15 | Remove the global `Psr\Log\LoggerInterface` alias and wire `logger.channel.externalauth` only into `ExternalAuth` ([#3618046](https://www.drupal.org/project/externalauth/issues/3618046)) | `Authmap` still takes only the database connection. Agency decorates `externalauth.authmap`, not `ExternalAuth`. |

`src/Authmap.php`, `src/Form/AuthmapDeleteForm.php`, `src/AuthmapInterface.php`,
`src/ExternalAuth.php`, `externalauth.install` and `externalauth.post_update.php`
are byte-identical across 2.0.13, 2.0.14 and 2.0.15. No hook_update_N was
added. Schema floor `8104` is unchanged.

### Writer hashes (SHA-256)

These match `BindingHistory::REVIEWED_WRITERS` on every listed 2.0.x tag.
Reconfirmed after the pin move: the hashes did not change.

| File | SHA-256 |
| --- | --- |
| `src/Authmap.php` | `e6c98dd790128410e07f05ad0ae54aa4b8e05850fc1fa8bad8a9491d9126c93a` |
| `src/Form/AuthmapDeleteForm.php` | `aebd9185d105c9f1445baf532a33c6419459d28914d5da6f808921ccf1543500` |

## Surrounding paths

- **Tracked writer / decorator.** `TrackedAuthmap` still wraps
  `AuthmapInterface::{save,delete,deleteProvider}`. Those methods did not
  change. Covered mutations stay inside `BindingHistory::mutate()`.
- **Admin deletion.** `AuthmapDeleteForm::submitForm()` still deletes the
  `{authmap}` row directly. `TrackedAuthmapDeleteForm` continues to route
  confirmation through the decorated public `delete()`.
- **Direct-write drift.** Untracked `{authmap}` SQL is still outside the
  reviewed writers. Existing kernel cases keep refusing that path.
- **Logger alias.** 2.0.15 only stops advertising Externalauth's logger as
  every autowired `Psr\Log\LoggerInterface`. It does not change authmap
  writes.

## Decision recorded here

**2026-10 review.** Writer bytes on 2.0.14 and 2.0.15 match the hashes bound
to 2.0.13. The reviewed reference stayed 2.0.13 because the version string is
part of the digest. Installing 2.0.15 then failed closed with
`Binding writer dependency requires review.` That was the intended
unreviewed-release refusal, not 2.0.15 compatibility.

**2026-11-13 operator decision.** Move the reviewed reference from 2.0.13 to
2.0.15 and requalify. `REVIEWED_WRITERS` hashes stay. CI pin is
`drupal/externalauth:2.0.15`. This package has no Composer constraint that
pins Externalauth; `fingerprint()` is the authority. OpenID Connect remains
`3.0.0-alpha9`. Already-qualified 2.0.13 coverage must be requalified against
the new digest.

`BindingHistoryFingerprintTest` pins the reviewed source string `2.0.15` and
installed writer bytes (that hash assertion is not skippable).
`BindingFingerprintTest` proves a live 2.0.15 digest.
`BindingHistoryFingerprintDenyTest` executes the fail-closed throws:
unreviewed `InstalledVersions::getReference()` (including `2.0.16` and the
previous `2.0.13` pin), a writer file that does not match `REVIEWED_WRITERS`,
and enabled Externalauth below schema `8104`. Removing the
unreviewed-reference throw while leaving `'drupal/externalauth' => '2.0.15'`
in the reviewed array makes that deny test fail. Mutating the reviewed string
to `2.0.13` fails the pin test.

Fingerprint hashing stays PHP `hash()` / `hash_hmac()` / `hash_file()`
SHA-256, not the OpenSSL FIPS provider. That is pre-existing; this pin move
changes the digest only because the reviewed reference string changed. Host
dependency upgrades still require separate acceptance.
