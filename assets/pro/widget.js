/* WP Ru-max PRO frontend v1.0.51. */
(function () {
  var menu = document.getElementById('wp-ru-max-pro-menu');
  var icon = document.getElementById('wp-ru-max-icon');
  if (!menu || !icon) return;
  var panel = menu.querySelector('.wprmp-panel');
  var channels = menu.querySelector('.wprmp-channels');
  var close = menu.querySelector('.wprmp-close');
  function open() {
    menu.classList.add('is-open');
    var balloon = document.getElementById('wp-ru-max-balloon');
    if (balloon) { balloon.style.display = 'none'; balloon.dataset.closed = '1'; }
    if (panel) { panel.classList.remove('is-open'); panel.setAttribute('aria-hidden', 'true'); }
    if (channels) { channels.classList.add('is-open'); channels.setAttribute('aria-hidden', 'false'); }
    icon.setAttribute('aria-expanded', 'true');
  }
  function showPanel(mode) {
    mode = mode || 'live_chat';
    if (panel) {
      panel.dataset.mode = mode;
      var heading = panel.querySelector('.wprmp-panel-brand h3');
      var status = panel.querySelector('.wprmp-panel-brand small');
      if (heading) heading.textContent = mode === 'contact_form' ? 'Форма обратной связи' : 'Живой чат';
      if (status) status.textContent = mode === 'contact_form' ? 'Обычная заявка без переписки' : (window.wpRuMaxProFront && wpRuMaxProFront.managerOnline ? 'Менеджер онлайн' : 'Сообщение попадёт менеджеру');
      panel.classList.add('is-open'); panel.setAttribute('aria-hidden', 'false');
      var channel = panel.querySelector('input[name="channel"]'); if (channel) channel.value = mode;
      var consents = panel.querySelector('.wprmp-consents');
      if (consents) {
        var existing = ''; try { existing = sessionStorage.getItem('wpRuMaxProConversation') || ''; } catch (e) {}
        var needsRegistration = mode === 'contact_form' || !existing;
        consents.hidden = !needsRegistration; consents.style.display = needsRegistration ? '' : 'none';
        consents.querySelectorAll('input').forEach(function (field) { field.required = needsRegistration && field.name === 'consent'; });
      }
    }
    if (channels) { channels.classList.remove('is-open'); channels.setAttribute('aria-hidden', 'true'); }
  }
  function shut() {
    menu.classList.remove('is-open');
    if (panel) { panel.classList.remove('is-open'); panel.setAttribute('aria-hidden', 'true'); }
    if (channels) { channels.classList.remove('is-open'); channels.setAttribute('aria-hidden', 'true'); }
    icon.setAttribute('aria-expanded', 'false');
  }
  icon.addEventListener('click', function (event) { event.preventDefault(); event.stopImmediatePropagation(); menu.classList.contains('is-open') ? shut() : open(); }, true);
  icon.classList.add('wp-ru-max-pro-trigger');
  if (close) close.addEventListener('click', shut);
  /*
   * Keep channel navigation isolated from the launcher and the outside-click
   * handler. The default action is deliberately preserved so regular links,
   * tel: and mailto: links work in every theme.
   */
  menu.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a.wprmp-channel') : null;
    if (link && link.getAttribute('href') && link.getAttribute('href') !== '#') {
      event.stopPropagation();
    }
  });
  menu.querySelectorAll('.wprmp-open-chat,.wprmp-open-live-chat').forEach(function (button) { button.addEventListener('click', function () { showPanel(button.dataset.mode || 'contact_form'); }); });
  menu.querySelectorAll('.wprmp-faq-item').forEach(function (button) { button.addEventListener('click', function () { button.classList.toggle('is-expanded'); }); });
  menu.querySelectorAll('.wprmp-quick-buttons button').forEach(function (button) { button.addEventListener('click', function () { var textarea = menu.querySelector('textarea[name="message"]'); if (textarea) { textarea.value = button.getAttribute('data-message') || ''; textarea.focus(); } }); });
  document.addEventListener('click', function (event) { if (!menu.classList.contains('is-open') || menu.contains(event.target) || event.target === icon) return; shut(); });
  var form = menu.querySelector('.wprmp-form');
  if (form) form.addEventListener('submit', function (event) {
    event.preventDefault();
    var status = form.querySelector('.wprmp-form-status'), data = new FormData(form), conversationId = '';
    var contact = data.get('channel') === 'contact_form';
    if (!contact) { try { conversationId = sessionStorage.getItem('wpRuMaxProConversation') || ''; } catch (e) {} }
    if (conversationId) data.append('conversation_id', conversationId);
    data.append('action', 'wp_ru_max_pro_message'); data.append('nonce', wpRuMaxProFront.nonce); status.textContent = 'Отправляем…';
    fetch(wpRuMaxProFront.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (result) {
      if (result.success && result.data) {
        status.textContent = result.data.message || 'Сообщение отправлено.';
        if (result.data.conversation_id && !contact) { conversationId = result.data.conversation_id; try { sessionStorage.setItem('wpRuMaxProConversation', conversationId); } catch (e) {} }
        form.querySelector('.wprmp-identity').style.display = 'none'; form.querySelector('.wprmp-consents').style.display = 'none';
        form.querySelectorAll('.wprmp-identity input').forEach(function (field) { field.disabled = true; field.required = false; });
        form.reset(); renderThread(result.data.messages || []);
      } else status.textContent = (result && result.data) || 'Не удалось отправить сообщение.';
    }).catch(function () { status.textContent = 'Не удалось отправить сообщение.'; });
  });
  function renderThread(messages) {
    var thread = menu.querySelector('.wprmp-thread-live'); if (!thread) return; thread.innerHTML = '';
    messages.forEach(function (item) { var bubble = document.createElement('div'); bubble.className = 'wprmp-live-bubble ' + (item.role || 'bot'); bubble.innerHTML = '<b>' + (item.role === 'manager' ? 'Менеджер' : (item.name || (item.role === 'bot' ? 'Помощник' : 'Вы'))) + '</b><span></span>'; bubble.querySelector('span').textContent = item.text || ''; thread.appendChild(bubble); });
    thread.scrollTop = thread.scrollHeight;
  }
  function pollThread() {
    var id = ''; try { id = sessionStorage.getItem('wpRuMaxProConversation') || ''; } catch (e) {}
    if (!id || !window.wpRuMaxProFront) return;
    var data = new FormData(); data.append('action', 'wp_ru_max_pro_history'); data.append('nonce', wpRuMaxProFront.nonce); data.append('conversation_id', id);
    fetch(wpRuMaxProFront.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (r.success && r.data && r.data.messages) renderThread(r.data.messages); }).catch(function () {});
  }
  setInterval(pollThread, 4000);
})(); 
