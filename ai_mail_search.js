/* AI Mail Search - client UI */

function ai_mail_search_ui() {
  var $panel, $input, $status, $results;

  function build() {
    if ($panel) {
      return;
    }

    $panel = $('<div>', { id: 'ai-mail-search-panel', class: 'ai-mail-search-panel' }).appendTo(document.body);

    var $header = $('<div>', { class: 'ai-mail-search-header' }).appendTo($panel);
    $('<span>', { class: 'ai-mail-search-title', text: rcmail.gettext('ai_mail_search.paneltitle') }).appendTo($header);
    $('<a>', { href: '#', class: 'ai-mail-search-close', text: '×' })
      .on('click', function (e) { e.preventDefault(); hide(); })
      .appendTo($header);

    var $form = $('<div>', { class: 'ai-mail-search-form' }).appendTo($panel);
    $input = $('<input>', {
      type: 'text',
      class: 'ai-mail-search-input',
      placeholder: rcmail.gettext('ai_mail_search.placeholder'),
    }).appendTo($form);
    var $btn = $('<button>', { type: 'button', text: rcmail.gettext('ai_mail_search.searchbutton') }).appendTo($form);

    $input.on('keydown', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        runSearch();
      }
      else if (e.which === 27) {
        hide();
      }
    });
    $btn.on('click', runSearch);

    $status = $('<div>', { class: 'ai-mail-search-status' }).appendTo($panel);
    $results = $('<ul>', { class: 'ai-mail-search-results' }).appendTo($panel);
  }

  function show() {
    build();
    $panel.addClass('open');
    $input.trigger('focus');
  }

  function hide() {
    if ($panel) {
      $panel.removeClass('open');
    }
  }

  function toggle() {
    build();
    if ($panel.hasClass('open')) {
      hide();
    }
    else {
      show();
    }
  }

  function runSearch() {
    var query = $.trim($input.val());
    if (!query) {
      return;
    }

    $results.empty();
    $status.text(rcmail.gettext('ai_mail_search.searching')).removeClass('ai-mail-search-error');

    var lock = rcmail.set_busy(true, 'ai_mail_search.searching');
    rcmail.http_post('plugin.ai_mail_search.search', { q: query }, lock);
  }

  function renderResults(data) {
    $results.empty();

    var items = data.items || [];

    if (!items.length) {
      $status.text(rcmail.gettext('ai_mail_search.noresults')).removeClass('ai-mail-search-error');
      return;
    }

    $status.text(rcmail.gettext('ai_mail_search.foundcount').replace('$count', items.length))
      .removeClass('ai-mail-search-error');

    $.each(items, function (i, item) {
      // fallback href so ctrl/cmd/middle-click still opens in a new tab natively
      var url = rcmail.env.comm_path
        + '&_mbox=' + encodeURIComponent(item.folder)
        + '&_uid=' + encodeURIComponent(item.uid)
        + '&_action=show';

      var $li = $('<li>', { class: 'ai-mail-search-result' }).appendTo($results);
      $('<a>', { href: url }).appendTo($li).append(
        $('<span>', { class: 'ai-mail-search-subject', text: item.subject }),
        $('<span>', { class: 'ai-mail-search-from', text: item.from }),
        $('<span>', { class: 'ai-mail-search-meta', text: item.folder + ' · ' + item.date })
      ).on('click', function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.which === 2) {
          return; // let the browser handle it natively (new tab/window)
        }
        e.preventDefault();
        // switch the message list to the message's folder and ask Roundcube
        // to auto-select/scroll to this uid once that list finishes loading
        // (env.list_uid is the same mechanism Roundcube's own "back to list"
        // links use — see the 'list' response handler in app.js). Selecting
        // the row natively triggers the reading-pane preview too, so we don't
        // need a separate show_message() call.
        rcmail.env.list_uid = item.uid;
        rcmail.command('list', item.folder);
        hide();
      });
    });
  }

  function renderError(message) {
    $results.empty();
    $status.text(message).addClass('ai-mail-search-error');
  }

  return { show: show, hide: hide, toggle: toggle, renderResults: renderResults, renderError: renderError };
}

var ai_mail_search = ai_mail_search_ui();

rcmail.addEventListener('init', function () {
  rcmail.register_command('plugin.ai_mail_search.toggle', function () {
    ai_mail_search.toggle();
  }, true);

  rcmail.addEventListener('plugin.ai_mail_search.results', function (data) {
    ai_mail_search.renderResults(data);
  });

  rcmail.addEventListener('plugin.ai_mail_search.error', function (message) {
    ai_mail_search.renderError(message);
  });
});
