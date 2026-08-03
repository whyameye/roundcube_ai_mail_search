<?php

/**
 * AI Mail Search
 *
 * Adds a natural-language email search panel to Roundcube, backed by the
 * Claude Messages API. Claude extracts structured IMAP search parameters
 * (sender, subject, dates, ...) from the typed query, PHP runs the actual
 * search against the user's own authenticated IMAP session, and a second
 * Claude pass reviews the candidate results (headers, plus a truncated body
 * excerpt of each when the request needs actual content matching — e.g.
 * "the email where X talks about Y") to pick the final order/subset.
 *
 * @license GNU GPL v3 or later
 */
class ai_mail_search extends rcube_plugin
{
    public $task = 'mail';

    /** @var rcmail */
    private $rc;

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-sonnet-5';
    private const DEFAULT_MAX_RESULTS = 25;
    private const PER_FOLDER_CAP = 200;
    private const CONTENT_REVIEW_FETCH_CAP = 20;
    private const EXCERPT_MAX_CHARS = 1500;

    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->register_action('plugin.ai_mail_search.search', [$this, 'action_search']);

        if ($this->rc->action == '' || $this->rc->action == 'show') {
            $this->load_config();
            $this->add_texts('localization', true);
            $this->include_script('ai_mail_search.js');
            $this->include_stylesheet($this->local_skin_path() . '/ai_mail_search.css');

            $this->add_button([
                'command'    => 'plugin.ai_mail_search.toggle',
                'type'       => 'link',
                'class'      => 'button icon toolbar-button ai-mail-search',
                'classact'   => 'button icon toolbar-button ai-mail-search active',
                'title'      => 'ai_mail_search.buttontitle',
                'innerclass' => 'inner',
                'label'      => 'ai_mail_search.buttonlabel',
            ], 'toolbar');
        }
    }

    /**
     * AJAX handler: plugin.ai_mail_search.search
     */
    public function action_search()
    {
        $this->load_config();
        $this->add_texts('localization');

        $query = trim(rcube_utils::get_input_string('q', rcube_utils::INPUT_POST));

        if ($query === '') {
            $this->send_error($this->gettext('emptyquery'));
            return;
        }

        $api_key = (string) $this->rc->config->get('ai_mail_search_api_key', '');
        if ($api_key === '') {
            $this->send_error($this->gettext('nokeyconfigured'));
            return;
        }

        $model = (string) $this->rc->config->get('ai_mail_search_model', self::DEFAULT_MODEL);
        $max_results = (int) $this->rc->config->get('ai_mail_search_max_results', self::DEFAULT_MAX_RESULTS);

        // this action can make up to two sequential Claude API calls plus, for
        // content-matching requests, a round of per-message IMAP body fetches;
        // give it more room than the default page-load budget
        set_time_limit(90);

        try {
            $params = $this->extract_search_params($query, $api_key, $model);
            $storage = $this->rc->get_storage();
            $criteria = $this->build_criteria($params);
            $folders = $this->resolve_folders($storage, $params);
            $sort = ($params['sort'] ?? '') === 'oldest_first' ? 'oldest_first' : 'newest_first';
            $items = $this->run_search($storage, $criteria, $folders, $max_results, $sort);
        }
        catch (Exception $e) {
            $this->send_error($e->getMessage());
            return;
        }

        // content-matching requests ("the one where X talks about Y") need
        // actual message text, not just headers — fetch a capped, truncated
        // excerpt per candidate before handing them to the review pass
        if (!empty($params['needs_content_review']) && $items) {
            $items = array_slice($items, 0, self::CONTENT_REVIEW_FETCH_CAP);
            $items = $this->fetch_excerpts($items);
        }

        // second pass: let Claude actually look at the candidates (and their
        // content excerpt, if fetched above) and apply anything from the
        // original request that isn't a raw IMAP filter — ordering, "just the
        // last 3", "the one that talks about the website", etc. Falls back to
        // the mechanically-sorted list if this call fails, so a hiccup here
        // degrades gracefully instead of breaking search.
        try {
            $items = $this->review_results($query, $items, $api_key, $model);
        }
        catch (Exception $e) {
            // ignore — keep the mechanical result
        }

        foreach ($items as &$item) {
            unset($item['timestamp'], $item['excerpt']);
        }
        unset($item);

        $this->rc->output->command('plugin.ai_mail_search.results', [
            'query' => $query,
            'count' => count($items),
            'items' => $items,
        ]);
        $this->rc->output->send();
    }

    private function send_error($message)
    {
        $this->rc->output->command('plugin.ai_mail_search.error', $message);
        $this->rc->output->send();
    }

    /**
     * Ask Claude to turn the natural-language query into structured IMAP
     * search parameters via a forced tool call. No email content is sent.
     */
    private function extract_search_params($query, $api_key, $model)
    {
        $tools = [[
            'name' => 'search_emails',
            'description' => 'Search the user\'s mailbox using IMAP search criteria extracted from a natural-language request.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sender' => [
                        'type' => 'string',
                        'description' => 'Filter by sender name or email address. Only use this for a sender-only request (e.g. "emails from Tyler"). Do not also set "to" to the same person to mean "to or from" — use the "participant" field for that instead, since setting both ANDs them together and will match almost nothing.',
                    ],
                    'to' => [
                        'type' => 'string',
                        'description' => 'Filter by recipient name or email address. Only use this for a recipient-only request (e.g. "emails I sent to Tyler"). See "participant" for "to or from" requests.',
                    ],
                    'participant' => [
                        'type' => 'string',
                        'description' => 'Name or email address of someone who is the sender, a recipient, or cc\'d. Use this for phrasing like "to or from X", "involving X", "with X", "between me and X" — anything where the direction isn\'t specified. Do not also set sender/to to the same person.',
                    ],
                    'subject' => [
                        'type' => 'string',
                        'description' => 'Keyword(s) expected in the subject line',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'An exact word/phrase to match in the message body via IMAP\'s literal substring search. Only set this when the user gave an exact word to search for. For topical/semantic requests like "the one about X" or "where they talk about Y", leave this empty and set needs_content_review instead — a strict substring match would likely miss the right message.',
                    ],
                    'needs_content_review' => [
                        'type' => 'boolean',
                        'description' => 'True if satisfying the request requires actually reading message content, not just structural filters — e.g. "the email where Tyler talks about the website", "which one mentions the deadline", "find the one about the lease". False for purely structural requests (sender/subject/date/read status).',
                    ],
                    'since' => [
                        'type' => 'string',
                        'description' => 'Only messages on or after this date, format YYYY-MM-DD',
                    ],
                    'before' => [
                        'type' => 'string',
                        'description' => 'Only messages before this date, format YYYY-MM-DD',
                    ],
                    'unread' => [
                        'type' => 'boolean',
                        'description' => 'Only unread messages',
                    ],
                    'flagged' => [
                        'type' => 'boolean',
                        'description' => 'Only flagged/starred messages',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Restrict to one folder, only if the user explicitly names one (e.g. "Sent", "Drafts", "Inbox"). Omit to search all folders.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => ['newest_first', 'oldest_first'],
                        'description' => 'Result order. Use "oldest_first" for phrasing like "chronological order", "in order", "oldest first". Default to "newest_first" (most recent first) otherwise.',
                    ],
                ],
                'required' => [],
            ],
        ]];

        $system = 'Today\'s date is ' . gmdate('Y-m-d') . " (UTC). Extract IMAP search "
            . 'parameters from the user\'s natural-language email search request. Resolve '
            . 'relative dates ("last week", "this month", "yesterday") against today\'s date. '
            . 'Always call the search_emails tool exactly once with your best-guess parameters '
            . '— never ask a clarifying question, and omit any field you have no basis for.';

        $payload = [
            'model' => $model,
            'max_tokens' => 1024,
            'system' => $system,
            'tools' => $tools,
            'tool_choice' => ['type' => 'tool', 'name' => 'search_emails'],
            'messages' => [
                ['role' => 'user', 'content' => $query],
            ],
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Exception($this->gettext('apiconnectionerror') . ' (' . $error . ')');
        }

        $data = json_decode($raw, true);

        if ($http_code != 200) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $http_code);
            throw new Exception($this->gettext('apierror') . ' ' . $msg);
        }

        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'search_emails') {
                return (array) $block['input'];
            }
        }

        throw new Exception($this->gettext('apinotool'));
    }

    /**
     * Build an RFC 3501 IMAP SEARCH criteria string from extracted params.
     */
    private function build_criteria(array $params)
    {
        $parts = [];

        $string_fields = [
            'sender' => 'FROM',
            'to' => 'TO',
            'subject' => 'SUBJECT',
            'body' => 'BODY',
        ];

        foreach ($string_fields as $field => $keyword) {
            if (!empty($params[$field]) && is_string($params[$field])) {
                $parts[] = $keyword . ' "' . $this->imap_escape($params[$field]) . '"';
            }
        }

        if (!empty($params['participant']) && is_string($params['participant'])) {
            $who = $this->imap_escape($params['participant']);
            $parts[] = 'OR OR FROM "' . $who . '" TO "' . $who . '" CC "' . $who . '"';
        }

        foreach (['since' => 'SINCE', 'before' => 'BEFORE'] as $field => $keyword) {
            if (!empty($params[$field]) && is_string($params[$field])) {
                $ts = strtotime($params[$field]);
                if ($ts !== false) {
                    $parts[] = $keyword . ' "' . gmdate('d-M-Y', $ts) . '"';
                }
            }
        }

        if (!empty($params['unread'])) {
            $parts[] = 'UNSEEN';
        }
        if (!empty($params['flagged'])) {
            $parts[] = 'FLAGGED';
        }

        return $parts ? '(' . implode(' ', $parts) . ')' : 'ALL';
    }

    private function imap_escape($value)
    {
        return addcslashes($value, '\\"');
    }

    /**
     * Determine which folder(s) to search. Honors an explicit folder name
     * from the extracted params (matched against real + special folders);
     * otherwise searches every folder except Trash and Junk.
     */
    private function resolve_folders($storage, array $params)
    {
        $all_folders = $storage->list_folders();

        if (!empty($params['folder']) && is_string($params['folder'])) {
            $wanted = strtolower(trim($params['folder']));

            $special = [
                'inbox' => 'INBOX',
                'sent' => $this->rc->config->get('sent_mbox', 'Sent'),
                'drafts' => $this->rc->config->get('drafts_mbox', 'Drafts'),
                'trash' => $this->rc->config->get('trash_mbox', 'Trash'),
                'deleted' => $this->rc->config->get('trash_mbox', 'Trash'),
                'junk' => $this->rc->config->get('junk_mbox', 'Junk'),
                'spam' => $this->rc->config->get('junk_mbox', 'Junk'),
            ];

            if (isset($special[$wanted])) {
                return [$special[$wanted]];
            }

            foreach ($all_folders as $folder) {
                if (strtolower($folder) === $wanted) {
                    return [$folder];
                }
            }
            // fall through to a full search if the named folder doesn't match
        }

        $trash = $this->rc->config->get('trash_mbox');
        $junk = $this->rc->config->get('junk_mbox');

        return array_values(array_filter($all_folders, function ($folder) use ($trash, $junk) {
            return $folder !== $trash && $folder !== $junk;
        }));
    }

    private function run_search($storage, $criteria, array $folders, $max_results, $sort = 'newest_first')
    {
        $all = [];

        foreach ($folders as $folder) {
            $index = $storage->search($folder, $criteria);
            if (!$index || $index->is_error() || !$index->count()) {
                continue;
            }

            $uids = $index->get();
            if (count($uids) > self::PER_FOLDER_CAP) {
                $uids = array_slice($uids, -self::PER_FOLDER_CAP);
            }

            $headers = $storage->fetch_headers($folder, $uids, false);

            foreach ($headers as $header) {
                $timestamp = rcube_utils::strtotime($header->date);
                $all[] = [
                    'folder' => $folder,
                    'uid' => (string) $header->uid,
                    'subject' => $header->subject !== '' ? $header->subject : $this->gettext('nosubject'),
                    'from' => $header->from,
                    'date' => $this->rc->format_date($header->date),
                    'timestamp' => $timestamp ?: 0,
                ];
            }
        }

        // always select the most recent $max_results matches first, so a
        // "chronological order" request re-orders that set rather than
        // surfacing unrelated old mail that happens to match the filters
        usort($all, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        $limited = array_slice($all, 0, $max_results);

        if ($sort === 'oldest_first') {
            $limited = array_reverse($limited);
        }

        return $limited;
    }

    /**
     * Fetch a plain-text excerpt of each candidate's body, for requests that
     * need actual content matching. Uses Roundcube's own message parser
     * (handles multipart/HTML-to-text), truncated so token cost stays small.
     */
    private function fetch_excerpts(array $items)
    {
        foreach ($items as &$item) {
            try {
                $message = new rcube_message($item['uid'], $item['folder']);
                $text = $message->first_text_part() ?? '';
            }
            catch (Exception $e) {
                $text = '';
            }

            $text = trim(preg_replace('/\s+/', ' ', $text));
            if (strlen($text) > self::EXCERPT_MAX_CHARS) {
                $text = substr($text, 0, self::EXCERPT_MAX_CHARS) . '…';
            }

            $item['excerpt'] = $text;
        }
        unset($item);

        return $items;
    }

    /**
     * Second Claude pass: hand back the candidates (headers, plus a body
     * excerpt of each when fetch_excerpts() was run) plus the user's original
     * wording, and let Claude pick the final order/subset. Handles anything
     * that isn't a raw IMAP filter — "chronological order", "just the last
     * 3", "the one that talks about the website", etc. An empty selection is
     * a valid, meaningful answer ("checked, none match") and is respected as
     * such — only an actual call failure falls back to the mechanical list,
     * and that fallback happens one level up in action_search().
     */
    private function review_results($query, array $items, $api_key, $model)
    {
        if (!$items) {
            return $items;
        }

        $has_excerpts = isset($items[0]['excerpt']);

        $candidates = array_map(function ($item) use ($has_excerpts) {
            $candidate = [
                'uid' => $item['uid'],
                'folder' => $item['folder'],
                'subject' => $item['subject'],
                'from' => $item['from'],
                'date' => gmdate('Y-m-d H:i', $item['timestamp']) . ' UTC',
            ];
            if ($has_excerpts) {
                $candidate['body_excerpt'] = $item['excerpt'];
            }
            return $candidate;
        }, $items);

        $tools = [[
            'name' => 'select_results',
            'description' => 'Choose and order the final set of matching messages to show the user, from the given candidates.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'selected' => [
                        'type' => 'array',
                        'description' => 'Candidate messages to show, in final display order. Only include uid/folder pairs that appear in the candidate list — never invent one.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'uid' => ['type' => 'string'],
                                'folder' => ['type' => 'string'],
                            ],
                            'required' => ['uid', 'folder'],
                        ],
                    ],
                ],
                'required' => ['selected'],
            ],
        ]];

        $system = 'Today\'s date is ' . gmdate('Y-m-d') . ' (UTC). An IMAP search already ran for '
            . 'the user\'s request below; the candidates are what matched, currently sorted '
            . 'most-recent-first. Apply anything in the original request that affects which '
            . 'candidates to show or what order to show them in — sorting (e.g. "chronological '
            . 'order" means oldest first), a specific count ("just the last 3"), or filtering by '
            . 'sender/subject/date already visible. If nothing like that applies, keep the given '
            . 'order and return every candidate unchanged. Only return uid/folder pairs copied '
            . 'exactly from the candidate list — never invent one.'
            . ($has_excerpts
                ? ' Each candidate includes a body_excerpt (truncated plain text of the message). '
                    . 'Use it to judge which candidates actually satisfy the request — e.g. for "the '
                    . 'email where X talks about Y", only select messages whose excerpt supports that. '
                    . 'If none of the candidates genuinely match, return an empty selected list rather '
                    . 'than guessing — that is a valid, useful answer.'
                : '');

        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $system,
            'tools' => $tools,
            'tool_choice' => ['type' => 'tool', 'name' => 'select_results'],
            'messages' => [
                ['role' => 'user', 'content' => "Original request: " . $query . "\n\nCandidates (JSON):\n" . json_encode($candidates)],
            ],
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 25,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $http_code != 200) {
            throw new Exception('review call failed');
        }

        $data = json_decode($raw, true);
        $selected = null;

        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'select_results') {
                $selected = $block['input']['selected'] ?? null;
                break;
            }
        }

        if (!is_array($selected)) {
            throw new Exception('review call returned no selection');
        }

        $index = [];
        foreach ($items as $item) {
            $index[$item['folder'] . "\x00" . $item['uid']] = $item;
        }

        $ordered = [];
        foreach ($selected as $sel) {
            $key = ($sel['folder'] ?? '') . "\x00" . ($sel['uid'] ?? '');
            if (isset($index[$key])) {
                $ordered[] = $index[$key];
            }
        }

        // trust the selection as-is, including an intentionally empty one —
        // that means Claude checked and nothing genuinely matched, which is
        // a real answer, not a failure to fall back from
        return $ordered;
    }
}
