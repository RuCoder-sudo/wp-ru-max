/* WP Ru-max PRO admin v1.0.71 — separate form recipient settings. */
jQuery(function ($) {
  var liveChatPane = $('.wprmp-pane[data-wprmp-pane="livechat"]');
  var messagesPane = $('.wprmp-pane[data-wprmp-pane="messages"]');
  var pendingList = messagesPane.find('.wprmp-pending').first();
  var pendingHash = pendingList.attr('data-pending-hash') || '';
  if (liveChatPane.length && !liveChatPane.find('.wprmp-livechat-conversations').length) liveChatPane.append('<div class="wprmp-livechat-conversations"></div>');
  function updateLiveChatCount(count) {
    if (typeof count !== 'number') return;
    $('#wprmp-livechat-count').text(count + ' сообщений');
    $('.wprmp-subtab[data-wprmp-tab="livechat"] .wprmp-online-pill').text(count + ' сообщений');
  }
  function updateClosedLiveChatCount(count) {
    if (typeof count !== 'number') return;
    var button = $('#wprmp-clear-closed-livechat');
    button.attr('data-count', count).prop('disabled', count < 1).text('Очистить завершённые (' + count + ')');
    liveChatPane.find('[data-message-filter="closed"]').text('Завершённые (' + count + ')');
  }
  function applyMessageFilter(pane, filter) {
    if (!pane || !pane.length) return;
    pane.find('[data-message-filter]').removeClass('is-active').filter('[data-message-filter="' + filter + '"]').addClass('is-active');
    var visible = 0;
    pane.find('article[data-conversation-status]').each(function () {
      var article = $(this), status = article.attr('data-conversation-status') || 'open';
      var show = filter === 'all' || filter === status;
      article.toggle(show);
      if (show) visible++;
    });
    if (pane.attr('data-wprmp-pane') === 'livechat') {
      var empty = pane.find('.wprmp-livechat-empty');
      if (empty.length) {
        empty.find('strong').text(filter === 'closed' ? 'Завершённых чатов нет' : 'Нет открытых чатов');
        empty.prop('hidden', visible > 0);
      }
    }
  }
  $('.wprmp-pending article').each(function () {
    var article = $(this);
    if (article.attr('data-conversation-channel') === 'live_chat') article.appendTo('.wprmp-livechat-conversations');
  });
  updateLiveChatCount($('.wprmp-livechat-conversations article[data-conversation-status="open"] .wprmp-thread-message.from-visitor').length);
  updateClosedLiveChatCount(liveChatPane.find('article[data-conversation-status="closed"]').length);
  applyMessageFilter(liveChatPane, 'open');
  function collect() {
    var out = { enabled: true, channels: {}, custom_channels: [], style: {}, chat: {}, channel_order: [] };
    $('.wprmp-channel-order [data-channel-item]').each(function () {
      var card = $(this), key = String(card.attr('data-channel-item') || '');
      if (!key) return;
      out.channel_order.push(key);
      if (card.attr('data-custom-channel') === '1') {
        out.custom_channels.push({ id: key, label: card.find('[data-custom-field="label"]').val() || '', url: card.find('[data-custom-field="url"]').val() || '', icon_url: card.find('[data-custom-field="icon_url"]').val() || '', enabled: card.find('.wprmp-enabled').prop('checked') ? '1' : '0', desktop: card.find('[data-device="desktop"]').prop('checked') ? '1' : '0', mobile: card.find('[data-device="mobile"]').prop('checked') ? '1' : '0' });
      } else {
        out.channels[key] = { enabled: card.find('.wprmp-enabled').prop('checked') ? '1' : '0', value: card.find('[data-value="' + key + '"]').val(), icon: key === 'phone' ? 'phone-svg.svg' : key === 'telegram' ? 'telegram.svg' : key === 'vkontakte' ? 'vkontakte.svg' : key === 'email' ? 'email.svg' : 'contact.svg', desktop: card.find('[data-device="desktop"]').prop('checked') ? '1' : '0', mobile: card.find('[data-device="mobile"]').prop('checked') ? '1' : '0' };
      }
    });
    $('[data-style]').each(function () { var v = $(this).is(':checkbox') ? ($(this).prop('checked') ? '1' : '0') : $(this).val(); out.style[$(this).data('style')] = v; });
    $('[data-chat]').each(function () { out.chat[$(this).data('chat')] = $(this).is(':checkbox') ? ($(this).prop('checked') ? '1' : '0') : $(this).val(); });
    ['live_recipients', 'form_recipients'].forEach(function (listKey) {
      out.chat[listKey] = [];
      $('.wprmp-recipient-list[data-recipient-list="' + listKey + '"]').find('[data-recipient-row]').each(function () {
        var row = $(this), id = String(row.find('[data-recipient-id]').val() || '').trim();
        if (!id) return;
        out.chat[listKey].push({
          id: id,
          type: row.find('[data-recipient-type]').val() === 'user' ? 'user' : 'chat'
        });
      });
    });
    out.chat.form_fields = {};
    $('[data-form-field][data-form-property]').each(function () {
      var field = String($(this).data('form-field')), property = String($(this).data('form-property'));
      if (!out.chat.form_fields[field]) out.chat.form_fields[field] = {};
      out.chat.form_fields[field][property] = $(this).is(':checkbox') ? ($(this).prop('checked') ? '1' : '0') : $(this).val();
    });
    out.chat.schedule_days = [];
    $('[data-schedule-day]:checked').each(function () { out.chat.schedule_days.push(parseInt($(this).data('schedule-day'), 10)); });
    out.chat.faq = [];
    $('.wprmp-faq-row').each(function () { var row = $(this), q = row.find('[data-faq="question"]').val(), a = row.find('[data-faq="answer"]').val(); if (q && a) out.chat.faq.push({ question: q, answer: a }); });
    return out;
  }
  var customChannelIndex = 0;
  function customChannelCard(id) {
    var icon = (window.wpRuMaxPro && wpRuMaxPro.assetsUrl ? wpRuMaxPro.assetsUrl : '') + 'contact.svg';
    return $('<div class="wprmp-channel wprmp-channel-card wprmp-custom-channel-card" draggable="false" data-channel-item="' + id + '" data-custom-channel="1"><div class="wprmp-channel-top"><label><span class="wprmp-drag-handle" draggable="true">⋮⋮</span><img class="wprmp-channel-icon" src="' + icon + '" alt=""><input type="checkbox" class="wprmp-enabled" checked> <b class="wprmp-channel-name">Произвольная ссылка</b></label><span class="wprmp-channel-status">Произвольный</span></div><div class="wprmp-custom-fields"><label>Название <input type="text" data-custom-field="label" value="Произвольная ссылка"></label><label>Ссылка <input type="url" data-custom-field="url" value="" placeholder="https://example.com"></label><label>URL иконки <input type="url" data-custom-field="icon_url" value="' + icon + '"></label></div><button type="button" class="button wprmp-remove-custom">Удалить канал</button><div class="wprmp-channel-devices"><label><input type="checkbox" data-device="desktop" checked> ПК</label><label><input type="checkbox" data-device="mobile" checked> Мобильные</label></div></div>');
  }
  function preview() {
    var s = collect(), st = s.style, w = $('#wprmp-preview-widget');
    if (!w.length) return;
    var blurEnabled = st.backdrop_blur === true || st.backdrop_blur === '1';
    w.css({ '--preview-bg': st.icon_background || '#4f46e5', '--preview-bg-glass': glassColor(st.icon_background || '#4f46e5'), '--preview-color': st.icon_color || '#fff', '--preview-size': (st.size || 60) + 'px', right: st.position === 'left' ? 'auto' : '20px', left: st.position === 'left' ? '20px' : 'auto' }).attr({ 'data-preview-layout': st.layout || 'circle', 'data-preview-position': st.position || 'right' });
    w.toggleClass('wprmp-preview-blur', blurEnabled).removeClass('wprmp-preview-attention-pulse wprmp-preview-attention-bounce').addClass('wprmp-preview-attention-' + (st.attention || 'none'));
    w.find('.wprmp-preview-trigger').css('background', blurEnabled ? glassColor(st.icon_background || '#4f46e5') : (st.icon_background || '#4f46e5')).css('color', st.icon_color || '#fff');
    w.find('.wprmp-preview-cta').text(st.cta || 'Написать нам').css('background', st.cta_background || '#4f46e5').css('color', st.cta_text_color || '#fff').toggleClass('is-always', st.cta_behavior === 'always');
    w.closest('.wprmp-browser').find('.wprmp-demo-page > span').text(st.page_title || 'Ваш сайт');
    w.find('.wprmp-preview-chat strong').text(s.chat.title || 'Живой чат');
    w.find('.wprmp-preview-chat small').text(s.chat.welcome || 'Здравствуйте! Чем можем помочь?');
    w.find('.wprmp-preview-chat').toggleClass('is-visible', st.mode === 'chat' && w.hasClass('is-open-chat'));
    var channelPreview = w.find('.wprmp-preview-channels');
    if (!channelPreview.length) { channelPreview = $('<div class="wprmp-preview-channels" aria-hidden="true"></div>'); w.append(channelPreview); }
    channelPreview.empty();
    var assetsUrl = window.wpRuMaxPro && wpRuMaxPro.assetsUrl ? wpRuMaxPro.assetsUrl : '';
    if (s.chat.live_chat_enabled !== false && s.chat.live_chat_enabled !== '0') channelPreview.append($('<button type="button" class="wprmp-preview-channel" data-preview-mode="chat" title="Открыть живой чат"></button>').append($('<img>').attr('src', assetsUrl + 'roboform.svg').attr('alt', '')).append($('<b>').text('Живой чат')));
    channelPreview.append($('<button type="button" class="wprmp-preview-channel" data-preview-mode="external" title="MAX"></button>').append($('<img>').attr('src', assetsUrl + 'MAX.svg').attr('alt', '')).append($('<b>').text('MAX')));
    $('.wprmp-channel-order [data-channel-item]').each(function () {
      var card = $(this);
      if (card.attr('data-custom-channel') === '1') { var label = card.find('[data-custom-field="label"]').val() || 'Произвольная ссылка', customIcon = card.find('[data-custom-field="icon_url"]').val() || ''; card.find('.wprmp-channel-name').text(label); card.find('.wprmp-channel-icon').attr('src', customIcon); }
      if (!card.find('.wprmp-enabled').prop('checked')) return;
      channelPreview.append($('<button type="button" class="wprmp-preview-channel" data-preview-mode="external"></button>').append($('<img>').attr('src', card.find('.wprmp-channel-icon').attr('src') || '').attr('alt', '')).append($('<b>').text(card.find('.wprmp-channel-top b').text())));
    });
    channelPreview.removeClass('is-empty');
    $('.wprmp-channel-card').each(function () { var card = $(this), enabled = card.find('.wprmp-enabled').prop('checked'); card.toggleClass('is-disabled', !enabled); card.find('[data-device="desktop"]').closest('label').toggleClass('is-off', !card.find('[data-device="desktop"]').prop('checked')); card.find('[data-device="mobile"]').closest('label').toggleClass('is-off', !card.find('[data-device="mobile"]').prop('checked')); });
  }
  function glassColor(hex) {
    var value = String(hex || '#4f46e5').replace('#', '');
    if (value.length === 3) value = value.split('').map(function (part) { return part + part; }).join('');
    var number = parseInt(value, 16);
    if (isNaN(number)) return 'rgba(79,70,229,.22)';
    return 'rgba(' + ((number >> 16) & 255) + ',' + ((number >> 8) & 255) + ',' + (number & 255) + ',.22)';
  }
  $(document).on('click', '.wprmp-preview-trigger', function (event) {
    event.preventDefault();
    var button = $(this), widget = button.closest('#wprmp-preview-widget'), open = !widget.hasClass('is-open');
    widget.toggleClass('is-open', open).attr('data-preview-open', open ? '1' : '0');
    button.attr('aria-expanded', open ? 'true' : 'false');
    widget.find('.wprmp-preview-channels').attr('aria-hidden', open ? 'false' : 'true');
    if (!open) widget.removeClass('is-open-chat').find('.wprmp-preview-chat').removeClass('is-visible');
  });
  $(document).on('click', '.wprmp-preview-channel', function () {
    var channel = $(this), widget = channel.closest('#wprmp-preview-widget'), isChat = channel.attr('data-preview-mode') === 'chat';
    widget.addClass('is-open').attr('data-preview-open', '1').find('.wprmp-preview-channels').attr('aria-hidden', 'false');
    widget.toggleClass('is-open-chat', isChat).find('.wprmp-preview-chat').toggleClass('is-visible', isChat && $('[data-style="mode"]').val() === 'chat');
  });
  $(document).on('input change', '.wprmp-admin input,.wprmp-admin select,.wprmp-admin textarea', preview);
  $(document).on('change', '[data-chat="contact_form_enabled"]', function () {
    var visible = $(this).prop('checked');
    ['email', 'phone'].forEach(function (field) {
      $('[data-form-field="' + field + '"][data-form-property="visible"]').prop('checked', visible);
    });
    preview();
  });
  $(document).on('click', '.wprmp-enabled', function (event) {
    // Let the browser perform the native checkbox toggle. Do not let the
    // card/label click handlers cancel it or toggle it a second time.
    event.stopImmediatePropagation();
  });
  $(document).on('click', '.wprmp-channel-top', function (event) {
    var target = $(event.target), checkbox = $(this).find('.wprmp-enabled').first();
    if (!checkbox.length || target.closest('label, input, button, a, .wprmp-drag-handle').length) return;
    checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
  });
  $(document).on('click', '.wprmp-add-custom-channel', function () { customChannelIndex += 1; $('.wprmp-channel-order').append(customChannelCard('custom_' + Date.now() + '_' + customChannelIndex)); preview(); });
  $(document).on('click', '.wprmp-remove-custom', function () { $(this).closest('[data-custom-channel="1"]').remove(); preview(); });
  var draggedChannel = null;
  $(document).on('dragstart', '.wprmp-drag-handle', function (event) { draggedChannel = $(this).closest('[data-channel-item]')[0]; if (!draggedChannel) return; event.originalEvent.dataTransfer.effectAllowed = 'move'; event.originalEvent.dataTransfer.setData('text/plain', draggedChannel.getAttribute('data-channel-item')); $(draggedChannel).addClass('is-dragging'); });
  $(document).on('dragover', '.wprmp-channel-order [data-channel-item]', function (event) { event.preventDefault(); if (draggedChannel && draggedChannel !== this) { var box = this.getBoundingClientRect(); $(this)[event.originalEvent.clientY < box.top + box.height / 2 ? 'before' : 'after'](draggedChannel); } });
  $(document).on('dragend', '.wprmp-drag-handle', function () { if (draggedChannel) $(draggedChannel).removeClass('is-dragging'); draggedChannel = null; });
  $(document).on('click', '.wprmp-subtab', function () { var tab = $(this).data('wprmp-tab'); $('.wprmp-subtab').removeClass('is-active'); $(this).addClass('is-active'); $('.wprmp-pane').removeClass('is-active'); $('.wprmp-pane[data-wprmp-pane="' + tab + '"]').addClass('is-active'); });
  $(document).on('click', '[data-message-filter]', function () { var button = $(this), filter = button.data('message-filter'), pane = button.closest('.wprmp-pane'); applyMessageFilter(pane, filter); });
  $(document).on('click', '[data-recipient-add]', function () {
    var listKey = String($(this).attr('data-recipient-add') || '');
    $('.wprmp-recipient-list[data-recipient-list="' + listKey + '"]').append(
      '<div class="wprmp-recipient-row" data-recipient-row><label>Тип получателя<select data-recipient-type><option value="chat">Группа или канал (chat_id)</option><option value="user">Личный чат (user_id)</option></select></label><label>ID получателя<input type="text" data-recipient-id placeholder="chat_id или user_id"></label><div class="wprmp-recipient-actions"><button type="button" class="button wprmp-test-recipient">Проверить отправку</button><button type="button" class="button wprmp-remove-recipient">Удалить</button></div><small class="wprmp-recipient-status" role="status" aria-live="polite"></small></div>'
    );
  });
  $(document).on('click', '.wprmp-remove-recipient', function () { $(this).closest('[data-recipient-row]').remove(); });
  $(document).on('click', '.wprmp-test-recipient', function () {
    var button = $(this), row = button.closest('[data-recipient-row]'), status = row.find('.wprmp-recipient-status');
    var id = String(row.find('[data-recipient-id]').val() || '').trim();
    if (!id) { status.text('Сначала укажите ID получателя.').removeClass('is-success'); return; }
    var original = button.text();
    button.prop('disabled', true).text('Проверяем…');
    status.text('Отправляем тестовое сообщение…').removeClass('is-success');
    $.post(wpRuMaxPro.ajaxUrl, {
      action: 'wp_ru_max_pro_test_recipient',
      nonce: wpRuMaxPro.nonce,
      recipient_id: id,
      recipient_type: row.find('[data-recipient-type]').val() || 'chat'
    }).done(function (r) {
      status.text(r && r.data ? r.data : (r && r.success ? 'MAX принял сообщение.' : 'MAX не подтвердил отправку.'));
      status.toggleClass('is-success', !!(r && r.success));
    }).fail(function (xhr) {
      var response = xhr.responseJSON || {};
      status.text(response.data || 'Не удалось проверить отправку. Проверьте соединение и журнал ошибок.');
    }).always(function () {
      button.prop('disabled', false).text(original || 'Проверить отправку');
    });
  });
  $(document).on('click', '#wprmp-save', function (event) { event.preventDefault(); event.stopImmediatePropagation(); var b = $(this), original = b.text(); b.prop('disabled', true).text('Сохранение…'); $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_save', nonce: wpRuMaxPro.nonce, settings: collect() }).done(function (r) { window.alert(r && r.success ? (r.data || 'Настройки сохранены.') : ((r && r.data) || 'Ошибка сохранения.')); }).fail(function () { window.alert('Ошибка сети при сохранении.'); }).always(function () { b.prop('disabled', false).text(original || 'Сохранить настройки'); }); });
  $(document).on('click', '.wprmp-reply', function () { var b = $(this), box = b.closest('.wprmp-reply-box'), text = box.find('.wprmp-reply-text').val(), status = box.find('.wprmp-reply-status'); if (!text) return; b.prop('disabled', true); status.text('Отправляем…'); $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_reply', nonce: wpRuMaxPro.nonce, message: text, message_id: b.data('message-id') }).done(function (r) { status.text(r.success ? 'Ответ отправлен — посетитель увидит его в чате.' : (r.data || 'Ошибка')); if (r.success) box.find('.wprmp-reply-text').val(''); }).always(function () { b.prop('disabled', false); }); });
  $('.wprmp-reply-box').each(function () { var box = $(this); if (box.find('.wprmp-close-chat').length) return; $('<button type="button" class="button wprmp-close-chat">Завершить чат</button>').insertAfter(box.find('.wprmp-reply')); });
  $(document).on('click', '.wprmp-close-chat', function () {
    var b = $(this), id = b.siblings('.wprmp-reply').data('message-id'), status = b.siblings('.wprmp-reply-status'), article = b.closest('article');
    if (!id || !window.confirm('Завершить этот чат?')) return;
    b.prop('disabled', true);
    $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_close', nonce: wpRuMaxPro.nonce, message_id: id }).done(function (r) {
      status.text(r.success ? 'Чат завершён. Его можно удалить вместе с другими завершёнными.' : (r.data || 'Ошибка'));
      if (!r.success) return;
      article.attr('data-conversation-status', 'closed').addClass('is-closed');
      if (article.attr('data-conversation-channel') === 'live_chat') {
        updateLiveChatCount(liveChatPane.find('article[data-conversation-status="open"] .wprmp-thread-message.from-visitor').length);
        updateClosedLiveChatCount(liveChatPane.find('article[data-conversation-status="closed"]').length);
        applyMessageFilter(liveChatPane, liveChatPane.find('[data-message-filter].is-active').attr('data-message-filter') || 'open');
      } else {
        var pane = article.closest('.wprmp-pane');
        applyMessageFilter(pane, pane.find('[data-message-filter].is-active').attr('data-message-filter') || 'all');
      }
    }).always(function () { b.prop('disabled', false); });
  });
  $(document).on('click', '#wprmp-clear-closed-livechat', function () {
    var button = $(this), count = parseInt(button.attr('data-count'), 10) || 0;
    if (!count || !window.confirm('Удалить все завершённые диалоги живого чата? Это действие нельзя отменить.')) return;
    button.prop('disabled', true).text('Очищаем…');
    $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_clear_closed', nonce: wpRuMaxPro.nonce }).done(function (r) {
      if (!r || !r.success) {
        window.alert((r && r.data) || 'Не удалось очистить завершённые диалоги.');
        updateClosedLiveChatCount(count);
        return;
      }
      liveChatPane.find('article[data-conversation-channel="live_chat"][data-conversation-status="closed"]').remove();
      updateClosedLiveChatCount(0);
      applyMessageFilter(liveChatPane, 'open');
      window.alert('Удалено завершённых диалогов: ' + (parseInt(r.data.deleted, 10) || 0) + '.');
    }).fail(function () {
      window.alert('Ошибка сети при очистке завершённых диалогов.');
      updateClosedLiveChatCount(count);
    }).always(function () { button.prop('disabled', (parseInt(button.attr('data-count'), 10) || 0) < 1); });
  });
  setInterval(function () {
    if (!window.wpRuMaxPro || !messagesPane.length) return;
    $.post(wpRuMaxPro.ajaxUrl, {
      action: 'wp_ru_max_pro_messages',
      nonce: wpRuMaxPro.nonce,
      pending_hash: pendingHash
    }).done(function (r) {
      if (!r || !r.success || !r.data) return;
      var count = parseInt(r.data.count, 10) || 0;
      $('.wprmp-message-count').text(count + ' новых');
      $('.wprmp-subtab[data-wprmp-tab="messages"] b').text(count);
      updateLiveChatCount(parseInt(r.data.live_count, 10) || 0);
      updateClosedLiveChatCount(parseInt(r.data.closed_live_count, 10) || 0);
      if (r.data.pending_html && r.data.pending_hash !== pendingHash) {
        var messageFilter = messagesPane.find('[data-message-filter].is-active').attr('data-message-filter') || 'all';
        var liveFilter = liveChatPane.find('[data-message-filter].is-active').attr('data-message-filter') || 'open';
        var replyDrafts = {};
        $('.wprmp-reply-box').each(function () {
          var box = $(this), id = String(box.find('.wprmp-reply').data('message-id') || '');
          if (id) replyDrafts[id] = box.find('.wprmp-reply-text').val() || '';
        });
        pendingList.html(r.data.pending_html).attr('data-pending-hash', r.data.pending_hash);
        pendingHash = r.data.pending_hash;
        pendingList.find('article[data-conversation-channel="live_chat"]').appendTo(liveChatPane.find('.wprmp-livechat-conversations'));
        $('.wprmp-reply-box').each(function () {
          var box = $(this), id = String(box.find('.wprmp-reply').data('message-id') || '');
          if (id && Object.prototype.hasOwnProperty.call(replyDrafts, id)) box.find('.wprmp-reply-text').val(replyDrafts[id]);
        });
        applyMessageFilter(messagesPane, messageFilter);
        applyMessageFilter(liveChatPane, liveFilter);
      } else if (r.data.pending_hash) {
        pendingHash = r.data.pending_hash;
      }
    });
  }, 15000);
  preview();
});
