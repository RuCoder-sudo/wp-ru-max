/* WP Ru-max PRO admin v1.0.58. */
jQuery(function ($) {
  var liveChatPane = $('.wprmp-pane[data-wprmp-pane="livechat"]');
  if (liveChatPane.length && !liveChatPane.find('.wprmp-livechat-conversations').length) liveChatPane.append('<div class="wprmp-livechat-conversations"></div>');
  function updateLiveChatCount(count) {
    if (typeof count !== 'number') return;
    $('#wprmp-livechat-count').text(count + ' сообщений');
    $('.wprmp-subtab[data-wprmp-tab="livechat"] .wprmp-online-pill').text(count + ' сообщений');
  }
  $('.wprmp-pending article').each(function () {
    var article = $(this);
    if (article.find('.wprmp-message-meta small').text().indexOf('Живой чат') !== -1) article.appendTo('.wprmp-livechat-conversations');
  });
  updateLiveChatCount($('.wprmp-livechat-conversations .wprmp-thread-message.from-visitor').length);
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
  $(document).on('click', '[data-message-filter]', function () { var filter = $(this).data('message-filter'); $('[data-message-filter]').removeClass('is-active'); $(this).addClass('is-active'); $('.wprmp-pending article').each(function () { var status = $(this).data('conversation-status') || 'open'; $(this).toggle(filter === 'all' || filter === status); }); });
  $(document).on('click', '#wprmp-save', function (event) { event.preventDefault(); event.stopImmediatePropagation(); var b = $(this), original = b.text(); b.prop('disabled', true).text('Сохранение…'); $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_save', nonce: wpRuMaxPro.nonce, settings: collect() }).done(function (r) { window.alert(r && r.success ? (r.data || 'Настройки сохранены.') : ((r && r.data) || 'Ошибка сохранения.')); }).fail(function () { window.alert('Ошибка сети при сохранении.'); }).always(function () { b.prop('disabled', false).text(original || 'Сохранить настройки'); }); });
  $(document).on('click', '.wprmp-reply', function () { var b = $(this), box = b.closest('.wprmp-reply-box'), text = box.find('.wprmp-reply-text').val(), status = box.find('.wprmp-reply-status'); if (!text) return; b.prop('disabled', true); status.text('Отправляем…'); $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_reply', nonce: wpRuMaxPro.nonce, message: text, message_id: b.data('message-id') }).done(function (r) { status.text(r.success ? 'Ответ отправлен — посетитель увидит его в чате.' : (r.data || 'Ошибка')); if (r.success) box.find('.wprmp-reply-text').val(''); }).always(function () { b.prop('disabled', false); }); });
  $('.wprmp-reply-box').each(function () { var box = $(this); if (box.find('.wprmp-close-chat').length) return; $('<button type="button" class="button wprmp-close-chat">Завершить чат</button>').insertAfter(box.find('.wprmp-reply')); });
  $(document).on('click', '.wprmp-close-chat', function () { var b = $(this), id = b.siblings('.wprmp-reply').data('message-id'), status = b.siblings('.wprmp-reply-status'); if (!id || !window.confirm('Завершить этот чат?')) return; b.prop('disabled', true); $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_close', nonce: wpRuMaxPro.nonce, message_id: id }).done(function (r) { status.text(r.success ? 'Чат завершён.' : (r.data || 'Ошибка')); if (r.success) b.closest('article').addClass('is-closed'); }).always(function () { b.prop('disabled', false); }); });
  setInterval(function () { if (!window.wpRuMaxPro || !$('.wprmp-pane[data-wprmp-pane="messages"]').length) return; $.post(wpRuMaxPro.ajaxUrl, { action: 'wp_ru_max_pro_messages', nonce: wpRuMaxPro.nonce }).done(function (r) { if (r.success && r.data && $('.wprmp-message-count').text() !== r.data.count + ' новых') { $('.wprmp-message-count').text(r.data.count + ' новых'); $('.wprmp-subtab[data-wprmp-tab="messages"] b').text(r.data.count); } if (r.success && r.data) updateLiveChatCount(parseInt(r.data.live_count, 10) || 0); }); }, 15000);
  preview();
});
