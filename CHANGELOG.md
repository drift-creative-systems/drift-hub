# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [1.3.0] - 2026-10-08

### Changed
- **Fonts are self-hosted.** Inter (400/500/600) and Poppins (500/600) now load from `assets/fonts/` instead of Google Fonts, so the hub makes no requests to Google: no third-party cookies or IP logging, and nothing to declare in a cookie banner. Latin and Latin Extended, woff2, with OFL licences included. The two main files are preloaded.
- The login screen now loads the fonts too (before, it named them but only showed them if installed on the visitor's computer).

## [1.2.0] - 2026-10-08

### Added
- **Footer** on the hub and under the login form: © Drift Creative Systems, plus Support, Privacy and Terms links (open in a new tab, announced to screen readers). Set them under Drift: Surface Hub → Settings; empty ones are hidden. Support defaults to support.driftcreativesystems.co.uk. Links must be https, http or mailto.

### Fixed
- Settings: the Save button now shows even when `DRIFT_HUB_AT_ROOT` is set in wp-config.php, and saving doesn't switch the locked root setting off.

## [1.1.0] - 2026-10-08

### Changed
- **Media is now per artist.** In the hub, the media picker only lists the artist you're editing: their uploads from every team member, and nothing from your other artists. Applies to admins in the hub too; wp-admin → Media still shows admins everything.
- Saving only accepts media that belongs to that artist (members). Before, a manager could pick any of their own uploads, whichever artist they were for.

### Added
- `includes/class-media.php`. Uploads from an artist's screen are tagged with that artist (`_drift_hub_artist`), and so is any media saved into their content. A shared image can belong to more than one artist.
- One-off tagging on update: media already used in each artist's content is tagged automatically. Older uploads that were never used anywhere stay untagged and only show in wp-admin.

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
