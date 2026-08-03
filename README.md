# AI Mail Search

Natural-language email search for Roundcube, backed by the Claude Messages
API. A toolbar button opens a search panel; Claude turns your typed query
into structured IMAP search parameters (sender, subject, dates, ...), and
the search itself runs locally against your already-authenticated IMAP
session. Only the typed query goes to Claude — message bodies and full
results never leave your Roundcube server.

## Deploy (this server)

This server's Roundcube is the Debian/Ubuntu package layout:
`/usr/share/roundcube` (app code, root-owned) symlinked from
`/var/lib/roundcube`, with config at `/etc/roundcube/config.inc.php`.
Custom (non-packaged) plugins live as real directories under
`/var/lib/roundcube/plugins/`, e.g. the existing `password` plugin.

1. Copy this directory into place:
   ```
   sudo cp -r ai_mail_search /var/lib/roundcube/plugins/ai_mail_search
   sudo chown -R www-data:www-data /var/lib/roundcube/plugins/ai_mail_search
   ```
2. Create the config file and add your Claude API key:
   ```
   sudo cp /var/lib/roundcube/plugins/ai_mail_search/config.inc.php.dist \
           /var/lib/roundcube/plugins/ai_mail_search/config.inc.php
   sudo -e /var/lib/roundcube/plugins/ai_mail_search/config.inc.php
   ```
3. Enable the plugin by adding it to the `plugins` array in
   `/etc/roundcube/config.inc.php`:
   ```php
   $config['plugins'] = array_merge($config['plugins'] ?? [], ['ai_mail_search']);
   ```
   (Or add `'ai_mail_search'` to the existing array literal, if there is one.)
4. Reload the page (no service restart needed — Roundcube reads plugin
   config per-request).

## Rollback

Remove `'ai_mail_search'` from the `plugins` array in
`/etc/roundcube/config.inc.php` (or delete the plugin directory). Nothing
else on the server is touched.

## Config options (`config.inc.php`)

| Key | Default | Notes |
|---|---|---|
| `ai_mail_search_api_key` | `''` | Required. From console.anthropic.com |
| `ai_mail_search_model` | `claude-sonnet-5` | Model used for parameter extraction |
| `ai_mail_search_max_results` | `25` | Cap on returned messages |

## How it works

1. Click "AI Search" in the mail toolbar.
2. Type a query, e.g. *"emails from Kaitlyn about the grant since June"*.
3. The plugin sends only that text to Claude with a forced tool call
   (`search_emails`), asking it to extract sender/subject/date/etc.
4. The PHP backend builds an IMAP `SEARCH` command from Claude's output and
   runs it against your existing Roundcube IMAP session (no separate
   credentials, respects your folder access).
5. Matching messages (folder, subject, from, date) are returned and
   rendered in the panel; click a result to open it in Roundcube.
