# Drift: Surface Hub

One place to run every artist's website. Labels, managers and artists log in to Drift: Surface Hub and manage gigs, releases, tracks, photos, videos, press, merch and site settings for their whole roster. Each artist's website (Encore Website plugin 2.1+) pulls its content from the hub, and a **Publish website** button sends changes live.

- **Requires:** WordPress 6.2+, PHP 8.0+, OpenSSL, pretty permalinks, HTTPS.
- **Install it on its own WordPress site** (e.g. `surface.driftcreativesystems.co.uk`), not on an artist's website.

## What it does

- **One login for a whole roster.** A label sees all of its artists; a manager sees only the artists they're given.
- **One schema for every artist.** Add a field in `schemas/surface.php` and every artist has it straight away.
- **No limits** on artists, records or API calls.
- **Publishing built in.** Edits save in the hub, and **Publish website** updates the artist's site, usually within a minute.
- **Members never see wp-admin.** They log in, land on the hub and see their artists and nothing else.

## Setting up

1. Install and activate **Drift: Surface Hub** on the hub site. Use pretty permalinks (Settings → Permalinks → Post name).
2. **Drift: Surface Hub → Labels:** add each label.
3. **Drift: Surface Hub → Add artist:** enter the name, tick its label and publish. The **Website connection** box then shows a **Data source**, a **Base ID** and **Generate token**. The token is shown once, so copy it.
4. **On the artist's website** (Encore Website → Connection):
   - **Data source:** the address from step 3.
   - **Base ID** and **Token:** from step 3.
   - Save, then run **Check connection** (everything should be present) and **Sync now**.
5. **Back on the hub artist**, paste the website's address and its **Publish secret** (from Encore Website → Connection) into the Website connection box, then update. The hub's Publish button now updates that site.
6. **Users → Add New** for each label person, with role **Manager**. On their profile, under **Drift: Surface Hub access**, tick their label(s) and/or individual artists. Managers can change their own name, email and password from **Your account** in the hub (click their name, top right).

**Artist pictures:** the roster card shows the artist's **Site settings → Images → Hub Avatar**. It's only used in the hub, so it shows straight away with no need to publish. With no avatar, the card falls back to the **Logo**, then to the artist's initials.

## How labels use it

- **Roster:** every artist with upcoming gigs, new enquiries and an "Unpublished changes" flag.
- **Artist screens:** Gigs, Music (releases), Tracks, Band, News, Photos, Videos, Press, Merch, Inbox (website enquiries), Mailing list, and Site settings.
- **Edit, then Publish website:** edits save in the hub; Publish tells the website to pull them in, usually within a minute.
- **Show on Site / Published:** untick to hide an item from the website without deleting it.
- **Media per artist:** the media picker only shows the artist you're working on, including uploads from everyone on their team. Nothing from other artists appears. Admins see the full library in wp-admin → Media.

## Changing the schema

All fields live in `schemas/surface.php`. Field names and types must match the Encore Website map (`maps/encore.php`) for anything the website uses.

- **Add a field:** add a line; every artist has it straight away. If the website should use it, add it to the plugin's map too.
- **Rename a field:** rename it and add `'was' => 'Old Name'`, so existing values carry over.
- **Remove a field:** delete the line. The stored values stay in the database, unused.
- **Hub-only field:** add `'hub_only' => true` (like **Hub Avatar**). It shows in the hub but is left out of the website API, and editing it doesn't flag unpublished changes.

There are no database migrations: records are stored as JSON, one row per record (`{prefix}drift_hub_records`).

## API (for reference)

Artist websites read from `/wp-json/drift-hub/v0/`, authenticated with `Authorization: Bearer hub_…`:

- `GET meta/bases/{base}/tables`: the schema (used by **Check connection**).
- `GET {base}/{table}`: supports `pageSize`, `offset`, `fields[]`, `sort[n][field|direction]` and `maxRecords`.
- `GET {base}/{table}/{record}`
- `POST {base}/{table}`: Inbox and Mailing list only (website forms).

Errors come back as `{ "error": { "type", "message" } }` with a matching status code.

The hub screens use `/wp-json/drift-hub/app/v1/` (logged-in, nonce).

## Updates

The plugin updates itself from [GitHub releases](https://github.com/drift-creative-systems/drift-hub/releases). WordPress checks every 6 hours and shows new versions under **Dashboard → Updates** and on the Plugins screen, like any other plugin. Click **Update now** (or turn on auto-updates).

If the repo is ever made private, add a fine-grained, read-only GitHub token to the hub's `wp-config.php`:

```php
define( 'DRIFT_HUB_GITHUB_TOKEN', 'github_pat_…' );
```

### Releasing a new version

1. Bump `Version:` and `DRIFT_HUB_VERSION` in `drift-hub.php` (SemVer), and add a `CHANGELOG.md` entry.
2. Commit and push to `main`.
3. Build the zip: `python tools/build-zip.py` → `dist/drift-hub.zip` (leaves out `CLAUDE.md`, `tools/` and `dist/`).
4. Publish a GitHub release tagged `vX.Y.Z` with `drift-hub.zip` attached:
   ```
   gh release create vX.Y.Z dist/drift-hub.zip --title "Drift: Surface Hub X.Y.Z" --notes-file notes.md
   ```

Sites pick it up at their next check. Always attach the zip: without it WordPress would install GitHub's source archive, which unpacks into the wrong folder name.

## Hosting notes

- **Authorization header.** Some Apache/CGI hosts strip it, so websites get "Authentication required". Add this to the hub's `.htaccess`, above the WordPress block:
  ```
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  ```
- **Image downloads.** Artist websites download images from the hub. On normal HTTPS hosting this just works; WordPress only allows ports 80, 443 and 8080.
- **Encryption key.** Website publish secrets are stored encrypted. Define `DRIFT_HUB_KEY` in `wp-config.php` (any long random string) so they survive a migration.
- **Backups.** Back up the database and `wp-content/uploads`. That's every artist's content.
- **No third-party requests.** Fonts (Inter, Poppins) are self-hosted in `assets/fonts/`, so the hub and login screen don't contact Google or set third-party cookies.

## Running the hub on its own subdomain

For `https://surface.driftcreativesystems.co.uk/`:

1. Create the subdomain on the host, point it at its own WordPress install and turn on SSL. If the hub already runs elsewhere, move it (e.g. Migrate Guru or WP-CLI `search-replace`) so **Settings → General** shows the subdomain for both addresses.
2. Install and activate the **Drift: Surface Hub theme**, then delete the default themes.
3. **Drift: Surface Hub → Settings** → tick **Make the hub the whole site** → Save.
4. On each artist website, set **Encore Website → Connection → Data source** to the URL shown on that settings page (`https://surface.driftcreativesystems.co.uk/wp-json/drift-hub/v0/`) and press **Check connection**. Tokens don't change.

Managers log in at the same address; `/wp-login.php` still handles the sign-in screen.

## Footer links

**Drift: Surface Hub → Settings** sets the Support, Privacy notice and Terms links shown in the hub footer and under the login form. Leave one empty to hide it. Support defaults to `https://support.driftcreativesystems.co.uk/`. The hub has no public pages, so host Privacy and Terms on the main driftcreativesystems.co.uk site and link to them here.
