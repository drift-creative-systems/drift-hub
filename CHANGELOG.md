# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [1.0.0] - 2026-10-08

First public release.

### Added
- **The hub:** labels, managers and artists log in and manage every artist's gigs, releases, tracks, band, news, photos, videos, press, merch, inbox, mailing list and site settings in one place. Roster view with upcoming gigs, new enquiries and "Unpublished changes" flags.
- **Schema in code** (`schemas/surface.php`): 12 tables, every field type and option. Adding a field reaches every artist at once; `'was'` renames carry old values over. Records stored as JSON in one custom table, so no database migrations.
- **Website API** (`/wp-json/drift-hub/v0/`) for Encore Website 2.1+: schema, paged lists with field selection and sorting, single records, and creates for website forms. Per-artist base ID and token (stored hashed). Structured errors, including 422 for unknown field names.
- **Publish website** button: stamps Last Published and tells the artist's site to sync. The website's publish secret is stored encrypted (`DRIFT_HUB_KEY`).
- **Access:** administrators manage artists and labels in wp-admin. Managers never see wp-admin; they see only their label's (or assigned) artists, and only their own uploads in the media library.
- **Hub Avatar** (Site settings → Images): the artist's picture on the roster and in the sidebar, cropped to fill the square. Falls back to the Logo, then initials. It's a `hub_only` field: left out of the website API, and changing it doesn't flag "Unpublished changes".
- **Your account:** managers change their name, email and password from the hub. Email and password changes need the current password; a new password needs 10+ characters and signs out every other session.
- **Hub at the site root** (Settings → "Make the hub the whole site", or `DRIFT_HUB_AT_ROOT`): the hub opens at `/`, `/hub/` 301s there and every other front-end URL redirects to it.
- **Updates from GitHub releases:** WordPress offers new versions under Dashboard → Updates (Plugin Update Checker 5.7, bundled in `lib/`). `DRIFT_HUB_GITHUB_TOKEN` supported for a private repo.
- `tools/build-zip.py` builds the release zip.

Works with the companion **Drift: Surface Hub theme** ([drift-hub-theme](https://github.com/drift-creative-systems/drift-hub-theme)).
