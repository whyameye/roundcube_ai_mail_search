<?php

/**
 * AI Mail Search
 *
 * Adds a natural-language email search panel to Roundcube, backed by the
 * Claude Messages API. Claude runs as a small agent: it gets a search_emails
 * tool it can call multiple times against the user's own authenticated IMAP
 * session — seeing real result counts and headers (and a body excerpt, on
 * request, for topical matches) each time — and only finishes by calling
 * present_results. That loop is what lets it recover from its own mistakes
 * (e.g. a too-literal filter that returns nothing) by trying again, rather
 * than a single one-shot guess with no way back.
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
    private const PREVIEW_FETCH_CAP = 15;
    private const EXCERPT_MAX_CHARS = 1500;
    private const MAX_SEARCH_ROUNDS = 3;
    private const CLAUDE_CALL_TIMEOUT = 15;

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

        // the agent can make several sequential Claude calls (search, look at
        // results, search again, ...) plus IMAP work each round; nginx's own
        // proxy timeout to php-fpm here is 60s by default, so stay under that
        set_time_limit(55);

        try {
            $storage = $this->rc->get_storage();
            $items = $this->run_agentic_search($query, $api_key, $model, $storage, $max_results);
        }
        catch (Exception $e) {
            $this->send_error($e->getMessage());
            return;
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
     * The agent loop. Round 1 is forced to search_emails (never let Claude
     * "answer" before it has looked at anything real). After that it can
     * search again or call present_results, up to MAX_SEARCH_ROUNDS searches,
     * then it's forced to present_results with whatever it has seen so far.
     * present_results is validated against every uid/folder actually
     * returned by a real search_emails call in this session — Claude can't
     * invent a result that was never seen.
     */
    private function run_agentic_search($query, $api_key, $model, $storage, $max_results)
    {
        $tools = $this->agent_tools();
        $system = $this->agent_system_prompt();
        $messages = [['role' => 'user', 'content' => $query]];

        $seen = [];       // "folder\x00uid" => full item, accumulated across every search_emails call
        $last_found = []; // most recent search_emails result, as a last-resort fallback

        for ($round = 1; $round <= self::MAX_SEARCH_ROUNDS; $round++) {
            $tool_choice = $round === 1
                ? ['type' => 'tool', 'name' => 'search_emails']
                : ['type' => 'any', 'disable_parallel_tool_use' => true];

            $data = $this->call_claude($api_key, $model, $system, $tools, $tool_choice, $messages);
            $content = $data['content'] ?? [];
            $tool_use = $this->first_tool_use($content);

            if (!$tool_use) {
                if (!$seen) {
                    throw new Exception($this->gettext('apinotool'));
                }
                break;
            }

            if ($tool_use['name'] === 'present_results') {
                return $this->resolve_selection($tool_use['input']['selected'] ?? [], $seen);
            }

            $found = $this->execute_search_emails($storage, (array) $tool_use['input'], $max_results);
            $last_found = $found;
            foreach ($found as $item) {
                $seen[$item['folder'] . "\x00" . $item['uid']] = $item;
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = ['role' => 'user', 'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => $tool_use['id'],
                'content' => json_encode([
                    'count' => count($found),
                    'items' => $this->compact_items($found),
                ]),
            ]]];
        }

        // out of search rounds without a present_results call — ask once
        // more, forced, so it synthesizes across everything it has seen
        if ($seen) {
            try {
                $messages[] = [
                    'role' => 'user',
                    'content' => 'You are out of searches. Call present_results now with your final answer from what you have already seen.',
                ];
                $data = $this->call_claude($api_key, $model, $system, $tools, ['type' => 'tool', 'name' => 'present_results'], $messages);
                $tool_use = $this->first_tool_use($data['content'] ?? []);
                if ($tool_use && $tool_use['name'] === 'present_results') {
                    return $this->resolve_selection($tool_use['input']['selected'] ?? [], $seen);
                }
            }
            catch (Exception $e) {
                // fall through to the mechanical fallback below
            }
        }

        return $last_found;
    }

    private function first_tool_use(array $content)
    {
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                return $block;
            }
        }
        return null;
    }

    private function resolve_selection(array $selected, array $seen)
    {
        $ordered = [];
        foreach ($selected as $sel) {
            $key = ($sel['folder'] ?? '') . "\x00" . ($sel['uid'] ?? '');
            if (isset($seen[$key])) {
                $ordered[] = $seen[$key];
            }
        }
        return $ordered;
    }

    /**
     * Tool definitions for the agent loop. Field-level guidance still steers
     * Claude away from over-constraining a search up front, but that's now a
     * quality nudge, not the only line of defense — the loop itself (see a
     * bad result, try again) is what actually makes this resilient.
     */
    private function agent_tools()
    {
        return [
            [
                'name' => 'search_emails',
                'description' => 'Search the user\'s mailbox. You can call this more than once (up to '
                    . self::MAX_SEARCH_ROUNDS . ' times) — if a search returns nothing useful, or '
                    . 'too much, adjust the parameters and search again rather than giving up.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sender' => [
                            'type' => 'string',
                            'description' => 'Filter by sender name or email address. Only for a sender-only request. Do not also set "to" to the same person to mean "to or from" — use "participant" instead.',
                        ],
                        'to' => [
                            'type' => 'string',
                            'description' => 'Filter by recipient name or email address. Only for a recipient-only request. See "participant" for "to or from" requests.',
                        ],
                        'participant' => [
                            'type' => 'string',
                            'description' => 'Name or email of someone who is sender, recipient, or cc\'d. Use for "to or from X", "involving X", "with X" — direction unspecified.',
                        ],
                        'subject' => [
                            'type' => 'string',
                            'description' => 'An exact word/phrase you\'re confident literally appears in the subject line — this is a strict substring match. If you\'re guessing at the wording (a paraphrase, category, or topic — e.g. "artist submissions" when the real subject might be "New submission from OSL Artists"), leave it empty instead: you\'ll see if the search comes back empty and can retry without it, or with preview:true to judge by content instead.',
                        ],
                        'body' => [
                            'type' => 'string',
                            'description' => 'An exact word/phrase to literal-match in the message body. Same caveat as subject — only for wording you\'re confident is exact; otherwise leave empty and consider preview:true. Warning: this matches anywhere in the FULL raw message — quoted replies, forwarded chains, other people\'s signatures — not just content the sender themselves wrote, and not necessarily the topic at hand. A hit is not evidence of relevance by itself; only body_excerpt (truncated to the start of the message) is what you can actually see, so never include a result on the strength of a body/subject filter match alone if that excerpt doesn\'t itself show why it matches.',
                        ],
                        'since' => ['type' => 'string', 'description' => 'Only messages on or after this date, format YYYY-MM-DD'],
                        'before' => ['type' => 'string', 'description' => 'Only messages before this date, format YYYY-MM-DD'],
                        'unread' => ['type' => 'boolean', 'description' => 'Only unread messages'],
                        'flagged' => ['type' => 'boolean', 'description' => 'Only flagged/starred messages'],
                        'folder' => ['type' => 'string', 'description' => 'Restrict to one folder, only if the user named one explicitly. Omit to search all folders.'],
                        'sort' => [
                            'type' => 'string',
                            'enum' => ['newest_first', 'oldest_first'],
                            'description' => 'Order of returned results. Default newest_first.',
                        ],
                        'preview' => [
                            'type' => 'boolean',
                            'description' => 'Set true to also fetch a truncated plain-text body excerpt of each result (up to '
                                . self::PREVIEW_FETCH_CAP . ' messages), when you need to judge actual content or topic rather '
                                . 'than just headers — e.g. "the one that talks about X". Costs more time; leave false for '
                                . 'purely structural searches.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max messages to return, default ' . self::DEFAULT_MAX_RESULTS . '.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'present_results',
                'description' => 'Call this when you have enough information, from real search_emails results, to give the user their final answer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'selected' => [
                            'type' => 'array',
                            'description' => 'The final messages to show, in display order. Only uid/folder pairs actually returned by a previous search_emails call in this conversation — never invent one. For a content/topic request, only include a message if its own body_excerpt actually shows the relevant content — a body/subject filter match is not by itself evidence, since it can hit quoted text or someone else\'s signature elsewhere in the raw message that you never see. An empty list is a valid, useful answer if nothing you can actually confirm matches the request — prefer that over a guess.',
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
            ],
        ];
    }

    private function agent_system_prompt()
    {
        return 'Today\'s date is ' . gmdate('Y-m-d') . ' (UTC). Find the email(s) matching the '
            . 'user\'s request by calling search_emails (up to ' . self::MAX_SEARCH_ROUNDS . ' times) '
            . 'and, once you have looked at real results and are satisfied, present_results. Resolve '
            . 'relative dates ("last week", "yesterday") against today\'s date. If a search returns '
            . 'nothing, or clearly the wrong thing, don\'t give up — broaden or change the parameters '
            . '(e.g. drop a literal subject/body guess, add preview:true to judge by content) and '
            . 'search again rather than presenting an empty or wrong answer on the first try. Apply '
            . 'anything in the request that affects ordering or count (e.g. "chronological order" '
            . 'means oldest first, "just the last 3") when you present_results. For a request about '
            . 'what someone said or a topic discussed, a body/subject filter matching is a hint to '
            . 'investigate, not proof — it matches anywhere in the full raw message, including quoted '
            . 'replies and other people\'s signatures, which can coincidentally contain your search '
            . 'term with nothing to do with the actual request. Only present a message for a content '
            . 'request if its body_excerpt itself visibly supports it; if you can\'t confirm that after '
            . 'your searches, present_results with an empty list rather than guessing from a filter '
            . 'match you can\'t actually verify.';
    }

    /**
     * Execute one search_emails tool call against real IMAP: build criteria,
     * resolve folders, run the search, and (if requested) fetch excerpts.
     */
    private function execute_search_emails($storage, array $params, $default_max_results)
    {
        $criteria = $this->build_criteria($params);
        $folders = $this->resolve_folders($storage, $params);
        $sort = ($params['sort'] ?? '') === 'oldest_first' ? 'oldest_first' : 'newest_first';

        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : $default_max_results;
        $limit = max(1, min($limit, $default_max_results));

        $items = $this->run_search($storage, $criteria, $folders, $limit, $sort);

        if (!empty($params['preview']) && $items) {
            $items = array_slice($items, 0, self::PREVIEW_FETCH_CAP);
            $items = $this->fetch_excerpts($items);
        }

        return $items;
    }

    /**
     * Strip internal-only fields before sending a search result back to
     * Claude as a tool_result.
     */
    private function compact_items(array $items)
    {
        return array_map(function ($item) {
            $compact = [
                'uid' => $item['uid'],
                'folder' => $item['folder'],
                'subject' => $item['subject'],
                'from' => $item['from'],
                'date' => $item['date'],
            ];
            if (isset($item['excerpt'])) {
                $compact['body_excerpt'] = $item['excerpt'];
            }
            return $compact;
        }, $items);
    }

    /**
     * Shared Claude Messages API caller used by the agent loop.
     */
    private function call_claude($api_key, $model, $system, array $tools, array $tool_choice, array $messages)
    {
        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $system,
            'tools' => $tools,
            'tool_choice' => $tool_choice,
            'messages' => $messages,
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
            CURLOPT_TIMEOUT => self::CLAUDE_CALL_TIMEOUT,
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

        return $data;
    }

    /**
     * Build an RFC 3501 IMAP SEARCH criteria string from search_emails params.
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
     * from the params (matched against real + special folders); otherwise
     * searches every folder except Trash and Junk.
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

        // always select the most recent $max_results matches first, so
        // oldest_first re-orders that set rather than surfacing unrelated
        // old mail that happens to match the filters
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
     * Fetch a plain-text excerpt of each candidate's body, for search_emails
     * calls made with preview:true. Uses Roundcube's own message parser
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
}
