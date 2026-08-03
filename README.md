# AI Mail Search

Natural-language email search for Roundcube, backed by the Claude Messages
API. A toolbar button opens a search panel; Claude runs as a small agent
with a `search_emails` tool it can call more than once against your
already-authenticated IMAP session — seeing real result counts and headers
each time, so it can broaden or adjust a search that came back empty or
wrong instead of guessing once and giving up — and finishes by calling
`present_results`. By default only structural details (sender, subject,
dates, folder) are involved; if a request needs actual content matching
(e.g. "the email where X talks about Y"), Claude can ask for a truncated
plain-text excerpt of candidate messages, which does leave your server as
part of that request.

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
3. Claude calls `search_emails` with structured parameters (sender, subject,
   dates, folder, ...); the PHP backend builds an IMAP `SEARCH` command from
   them and runs it against your existing Roundcube IMAP session (no
   separate credentials, respects your folder access) — headers only,
   unless Claude also set `preview: true` because the request needs actual
   content matching, in which case a truncated plain-text excerpt of each
   candidate is fetched too.
4. Claude sees the real result count and headers (and excerpts, if
   requested) back as a tool result. If it's empty or clearly wrong, it can
   call `search_emails` again with different parameters — up to 3 times —
   rather than settling for a bad first guess.
5. Once satisfied, Claude calls `present_results` with the final ordered
   list. Only messages that were actually returned by a real search in this
   session can be included — the results panel validates this and would
   never render something Claude merely claimed.
6. Matching messages (folder, subject, from, date) are rendered in the
   panel; click a result to open it in Roundcube's own reading pane.
