# Changelog

All notable changes to Agency are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Product direction, integration principles, architecture, development and
  contributor guidance.
- GitHub supported-core functional checks, package/coding standards and shared
  attribution verification.
- Repository editor settings, review ownership and contribution templates.

### Corrected

- Security advisory coverage guidance distinguishes opted-in covered releases
  from development and uncovered releases.

### Package foundation

- Initial `agency` and `agency_staff` package structure.
- Tenant configuration schema for the agency display name and operating-policy
  keys, without tenant values or policy periods in package defaults.
- A runtime requirements error when the agency display name is missing.
- Drupal.org GitLab CI, coding-standard, and static-analysis configuration.
