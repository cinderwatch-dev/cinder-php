# Changelog

All notable changes to `cinderwatch/cinder-php` are documented here.

## [1.0.0] - 2026-11-27

First stable release.

### Added

- `CinderClient` for reporting errors, logs and traces to Cinder's `/api/v1` API, authenticated with `Authorization: Bearer <key>`.
- Error reports with stack frames, source excerpts and the `exception.cause` chain, plus fingerprints, `reportOnlyWhen()`, `beforeSend()` and report enrichers.
- `DeployedRevision`, which reads the deployed commit from the `.git` folder (or common platform variables) for the `vcsRevision`, `vcsBranch` and `vcsMessage` config options, so every error, trace and log is tagged with it.
- Tracing with sampling, nested spans and OpenTelemetry semantic-convention attributes.
- A log buffer linked to the active trace, with sensitive keys redacted.
- Health probes, the `Inspector`, cluster snapshots and the `/cinder/v1/health` wire format, with disk usage, PHP runtime, URL reachability and Composer advisory probes.
- `ScheduleClient` to sync scheduled tasks and send run heartbeats.
- Buffered, synchronous and in-memory transports.
