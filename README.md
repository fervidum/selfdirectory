# SelfDirectory

Self-hosted plugin update checker for WordPress. Plugins declare where they live — SelfDirectory handles the rest.

Two update sources are supported:

- **GitHub repository** (preferred) — queries the GitHub Releases API at runtime and reads `Requires at least`, `Tested up to`, and `Requires PHP` directly from the tagged plugin file. No manifest to maintain.
- **Legacy `wp.json`** — fetches a static JSON manifest from the URL declared in the plugin header. Useful for non-GitHub hosts.

---

## Installation

Add as a git submodule inside your plugin:

```bash
git submodule add https://github.com/fervidum/selfdirectory lib/selfdirectory
```

Commit both `.gitmodules` and `lib/selfdirectory` to your repository.

### Updating the submodule

To pull the latest version of SelfDirectory into your plugin:

```bash
git submodule update --remote lib/selfdirectory
git add lib/selfdirectory
git commit -m "chore: update selfdirectory to latest"
```

`--remote` fetches the tracking branch (`master` by default) and advances the pointer. Without it, `git submodule update` only checks out the SHA already recorded in your repository — it will not pull new commits.

To pin to a specific commit instead of always tracking the tip:

```bash
cd lib/selfdirectory
git checkout <sha-or-tag>
cd ../..
git add lib/selfdirectory
git commit -m "chore: pin selfdirectory to <sha-or-tag>"
```

After cloning a repository that uses SelfDirectory as a submodule, initialise it with:

```bash
git submodule update --init --recursive
```

---

## Plugin setup

### 1. Declare the update source

Point `Plugin URI:` to your GitHub repository — no extra header needed:

```php
/**
 * Plugin Name: My Plugin
 * Version:     1.0.0
 * Plugin URI:  https://github.com/your-org/your-plugin
 */
```

If you need to decouple the update source from the public plugin page, use the optional `Directory:` header instead. It takes precedence over `Plugin URI:` when present:

```php
 * Directory: https://github.com/your-org/your-plugin
```

### 2. Load and register

```php
require_once __DIR__ . '/lib/selfdirectory/class-selfdirectory.php';

add_action( 'selfd_register', function () {
    selfd( __FILE__ );
} );
```

That's it. WordPress will show update notifications in the Plugins screen automatically.

---

## GitHub Actions — release workflow

The recommended approach is `git archive`, which honours `.gitattributes export-ignore` rules and produces a clean distribution zip without dev files. No `rsync` exclude lists to maintain — the `.gitattributes` file is the single source of truth.

### `.gitattributes`

Mark all development-only files with `export-ignore` so they are excluded from `git archive` output:

```gitattributes
* text=auto

# Dev-only — excluded from git archive builds.
.github/       export-ignore
tests/         export-ignore
vendor/        export-ignore
.gitattributes export-ignore
.gitmodules    export-ignore
composer.json  export-ignore
composer.lock  export-ignore
```

Add any other project-specific tooling files (`phpunit.xml`, `package.json`, etc.) as needed.

### `.github/workflows/release.yml`

Trigger on bare semver tags (`1.1.0`, not `v1.1.0`) **and** on `language/*` orphan branches (see [Language packs](#language-packs) below).

The workflow updates an existing release when one already exists for the target tag — language packs can therefore be added or refreshed after the fact without recreating the release or its changelog.

```yaml
name: Release

on:
  push:
    tags: ['[0-9]*']
    branches: ['language/**']

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          submodules: recursive
          fetch-depth: 0

      - name: Resolve version and trigger type
        id: ctx
        run: |
          ref="${GITHUB_REF_NAME}"
          if [[ "$ref" == language/* ]]; then
            echo "tag=${ref#language/}" >> "$GITHUB_OUTPUT"
            echo "is_lang_branch=true"  >> "$GITHUB_OUTPUT"
          else
            echo "tag=${ref}"           >> "$GITHUB_OUTPUT"
            echo "is_lang_branch=false" >> "$GITHUB_OUTPUT"
          fi

      - name: Install gettext
        run: sudo apt-get install -y gettext

      - name: Build plugin zip
        if: steps.ctx.outputs.is_lang_branch == 'false'
        run: |
          mkdir -p /tmp/build
          git archive HEAD --prefix=your-plugin/ | tar xf - -C /tmp/build/
          git -C lib/selfdirectory archive HEAD \
            --prefix=your-plugin/lib/selfdirectory/ \
            -- class-selfdirectory.php \
            | tar xf - -C /tmp/build/
          cd /tmp/build && zip -r "your-plugin.${tag}.zip" your-plugin

      - name: Resolve language source
        id: lang_src
        run: |
          tag="${{ steps.ctx.outputs.tag }}"
          is_lang="${{ steps.ctx.outputs.is_lang_branch }}"
          if [[ "$is_lang" == "true" ]]; then
            echo "dir=." >> "$GITHUB_OUTPUT"
          else
            branch="language/${tag}"
            if git ls-remote --exit-code origin "refs/heads/${branch}" > /dev/null 2>&1; then
              mkdir /tmp/lang-src && cd /tmp/lang-src
              git init
              git remote add origin "$GITHUB_SERVER_URL/$GITHUB_REPOSITORY"
              git fetch --depth=1 origin "refs/heads/${branch}"
              git checkout FETCH_HEAD
              echo "dir=/tmp/lang-src" >> "$GITHUB_OUTPUT"
            else
              echo "dir=" >> "$GITHUB_OUTPUT"
            fi
          fi

      - name: Build language packs
        if: steps.lang_src.outputs.dir != ''
        run: |
          mkdir -p /tmp/langs
          for po in "${{ steps.lang_src.outputs.dir }}"/your-plugin-*.po; do
            [ -f "$po" ] || continue
            locale=$(basename "$po" .po | sed "s/^your-plugin\.${tag}-//")
            msgfmt "$po" -o "/tmp/langs/your-plugin.${tag}-${locale}.mo"
            cp "$po" "/tmp/langs/your-plugin.${tag}-${locale}.po"
          done

      - name: Package and upload
        id: files
        run: |
          files=""
          [ -f "/tmp/build/your-plugin.${{ steps.ctx.outputs.tag }}.zip" ] && files="/tmp/build/your-plugin.${{ steps.ctx.outputs.tag }}.zip"
          for po in /tmp/langs/your-plugin.${tag}-*.po; do
            [ -f "$po" ] || continue
            locale=$(basename "$po" .po | sed "s/^your-plugin\.${tag}-//")
            (cd /tmp/langs && zip "your-plugin.${tag}-${locale}.zip" \
              "your-plugin.${tag}-${locale}.po" "your-plugin.${tag}-${locale}.mo")
            files="${files:+$files,}/tmp/langs/your-plugin.${tag}-${locale}.zip"
          done
          echo "paths=$files" >> "$GITHUB_OUTPUT"
          [ -n "$files" ] \
            && echo "has_files=true"  >> "$GITHUB_OUTPUT" \
            || echo "has_files=false" >> "$GITHUB_OUTPUT"

      - name: Upload assets to release
        if: steps.files.outputs.has_files == 'true'
        uses: softprops/action-gh-release@v2
        with:
          tag_name: ${{ steps.ctx.outputs.tag }}
          files: ${{ steps.files.outputs.paths }}
          generate_release_notes: ${{ steps.ctx.outputs.is_lang_branch == 'false' }}
```

Replace `your-plugin` with your actual plugin slug. The resulting zip contains exactly the runtime files — no dev tooling, no test suite, no submodule metadata.

> **Tip:** pair this with a `playground.yml` workflow to post a live WordPress Playground preview link on every pull request. See the [WordPress Playground blueprints documentation](https://wordpress.github.io/wordpress-playground/) for details.

---

## Language packs

SelfDirectory automatically surfaces language pack updates in the WordPress admin. No configuration is needed on the PHP side — it scans GitHub release assets for files matching `{repo}.{version}-{locale}.zip` (e.g. `your-plugin.1.1.0-es_ES.zip` or `your-plugin.1.1.0-fr_FR.zip`)

and injects them into the WordPress translations update transient.

> **Note:** the main plugin zip follows the pattern `{repo}.{version}.zip` (e.g. `your-plugin.1.1.0.zip`). Language packs follow `{repo}.{version}-{locale}.zip` (e.g. `your-plugin.1.1.0-es_ES.zip`). SelfDirectory distinguishes them by the `-{locale}` suffix after the version.

Packs are offered independently of the plugin zip: WordPress can install or update a translation without touching the plugin itself. A pack is only offered when no translation is installed for that locale, or when the release timestamp is newer than the installed translation's `PO-Revision-Date`.

### Storing translation files — orphan branches

Keep translation files out of the main plugin history. Use a dedicated **orphan branch** per version (`language/1.1.0`). Orphan branches share no history with `main` and are invisible to `git log` on the default branch — they act as isolated slots for distribution assets.

Each `language/<version>` branch contains only `.po` files at its root, named `{repo}-{locale}.po` (the `.po` source keeps a dash; the release asset uses `{repo}.{version}-{locale}.zip`):

```
language/1.1.0
├── your-plugin-es_ES.po
└── your-plugin-fr_FR.po
```

The release workflow compiles the `.mo` files and packages each locale as a zip (`your-plugin.1.1.0-es_ES.zip`, `your-plugin.1.1.0-fr_FR.zip`) and uploads them to the matching GitHub release.

### Creating a language branch

```bash
git checkout --orphan language/1.1.0
git rm -rf .                          # clear the working tree
cp /path/to/your-plugin-es_ES.po .
git add your-plugin-es_ES.po
git commit -m "language pack 1.1.0: es_ES"
git push origin language/1.1.0
```

Pushing the branch triggers the release workflow, which:

1. Detects the `language/*` pattern and extracts the version from the branch name.
2. Compiles each `.po` file to `.mo` via `msgfmt`.
3. Packages `your-plugin.{version}-{locale}.zip` (containing both `.po` and `.mo` renamed to match). Main plugin zip follows `{repo}.{version}.zip`.
4. Uploads the zip(s) to the existing GitHub release for that version — without recreating the release or regenerating its changelog.

To add a new locale to an already-published release, simply commit the `.po` file to the same branch and push again.

### Asset naming convention

SelfDirectory matches assets against the pattern `^{repo}\.{version}-([a-z]{2,3}_[A-Z]{2,4})\.zip$`. The locale segment must use the WordPress locale format: language code in lowercase, underscore, region in uppercase (e.g. `es_ES`, `fr_FR`, `zh_TW`).

---

## Legacy: `wp.json`

For non-GitHub update sources, serve a `wp.json` manifest at the URL declared in `Directory:` (or `Plugin URI:`). SelfDirectory will fetch `{url}/wp.json` and use the `latest` object to build the update response.

The `versions` map is optional but recommended — it enables per-version rollback links in compatible admin tools.

```json
{
  "slug": "your-plugin",
  "latest": {
    "version": "1.1.0",
    "package": "https://example.com/your-plugin-1.1.0.zip",
    "requires": "6.4",
    "tested": "6.8",
    "requires_php": "8.1"
  },
  "versions": {
    "1.1.0": {
      "version": "1.1.0",
      "package": "https://example.com/your-plugin-1.1.0.zip",
      "requires": "6.4",
      "tested": "6.8",
      "requires_php": "8.1"
    },
    "1.0.0": {
      "version": "1.0.0",
      "package": "https://example.com/your-plugin-1.0.0.zip",
      "requires": "6.4",
      "tested": "6.7",
      "requires_php": "8.1"
    }
  }
}
```

---

## Caching

- GitHub releases list — cache key `selfd_releases_{md5(owner/repo)}`, TTL 12 h
- Plugin headers at a tag — cache key `selfd_headers_{md5(owner/repo/tag/file)}`, TTL forever (tags are immutable)
- Any API or HTTP failure — same key, TTL 1 h (negative cache prevents hammering the API)

To force an immediate re-check, delete the relevant transients from the WordPress database or use a plugin such as WP Crontrol.

---

## Filter reference

```php
// Change when update checking runs (default: admin only, no AJAX).
add_filter( 'selfd_load', '__return_true' ); // run everywhere

// Register plugin files for update checking.
add_action( 'selfd_register', function () {
    selfd( __FILE__ );
} );
```
