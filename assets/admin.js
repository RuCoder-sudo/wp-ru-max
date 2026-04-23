/* WP Ru-max Admin JavaScript */
(function ($) {
    'use strict';

    var currentPage = 1;
    var perPage = 50;
    var historyType = typeof wpRuMaxHistoryType !== 'undefined' ? wpRuMaxHistoryType : '';

    /* -- Helper: AJAX -- */
    function doAjax(action, data, callback) {
        data = $.extend({ action: action, nonce: wpRuMax.nonce }, data);
        $.post(wpRuMax.ajaxUrl, data, function (res) {
            callback(res);
        });
    }

    /* -- Helper: Show Notice -- */
    function showNotice($el, type, message) {
        $el.removeClass('success error info').addClass(type).html(message).fadeIn(200);
    }

    /* -- Token visibility -- */
    $('#toggle_token_visibility').on('click', function () {
        var $input = $('#bot_token');
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $(this).text('Скрыть');
        } else {
            $input.attr('type', 'password');
            $(this).text('Показать');
        }
    });

    /* -- Main Tab -- */
    $('#save_main_settings').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        doAjax('wp_ru_max_save_settings', {
            bot_token: $('#bot_token').val(),
            bot_name:  $('#bot_name').val(),
        }, function (res) {
            $btn.prop('disabled', false).text('Сохранить настройки');
            showNotice($('#connection_result'), res.success ? 'success' : 'error', res.success ? res.data : res.data);
        });
    });

    $('#test_connection').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Проверяем...');
        doAjax('wp_ru_max_test_connection', { token: $('#bot_token').val() }, function (res) {
            $btn.prop('disabled', false).text('Проверить подключение');
            if (res.success) {
                showNotice($('#connection_result'), 'success', res.data.message);
                var $status = $('#bot_status');
                if ($status.length) {
                    $status.html('<span class="wp-ru-max-status-indicator status-success">●</span> ' + res.data.message);
                }
            } else {
                showNotice($('#connection_result'), 'error', res.data ? res.data.message || res.data : 'Ошибка подключения');
            }
        });
    });

    /* -- Post Sender Tab -- */
    $('#post_sender_enabled').on('change', function () {
        $('#post_sender_settings').toggle(this.checked);
        doAjax('wp_ru_max_save_settings', { field: 'post_sender_enabled', value: this.checked ? '1' : '0' }, function () {});
    });

    /* Collect post buttons as JSON — also flushes any pending button from the input fields */
    function collectPostButtons() {
        var pendingText = $('#new_post_btn_text').val().trim();
        var pendingUrl  = $('#new_post_btn_url').val().trim();
        if (pendingText && pendingUrl) {
            var row = '<div class="wp-ru-max-button-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">' +
                '<input type="text" name="post_btn_text[]" value="' + escAttr(pendingText) + '" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />' +
                '<input type="text" name="post_btn_url[]" value="' + escAttr(pendingUrl) + '" class="regular-text" placeholder="https://..." style="flex:1;" />' +
                '<button type="button" class="button wp-ru-max-remove-btn">Удалить</button>' +
                '</div>';
            $('#post_buttons_list').append(row);
            $('#new_post_btn_text').val('');
            $('#new_post_btn_url').val('');
        }
        var buttons = [];
        $('#post_buttons_list .wp-ru-max-button-row').each(function () {
            var t = $(this).find('input[name="post_btn_text[]"]').val().trim();
            var u = $(this).find('input[name="post_btn_url[]"]').val().trim();
            if (t && u) { buttons.push({ text: t, url: u }); }
        });
        return { post_buttons_json: JSON.stringify(buttons) };
    }

    $('#save_post_sender').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        var channels = [];
        $('input[name="channels[]"]').each(function () {
            var v = $(this).val().trim();
            if (v) channels.push(v);
        });
        var postTypes = [];
        $('input[name="post_types[]"]:checked').each(function () { postTypes.push($(this).val()); });

        var data = $.extend({
            post_sender_enabled:   $('#post_sender_enabled').is(':checked') ? '1' : '0',
            send_new_post:         $('input[name="send_new_post"]').is(':checked') ? '1' : '0',
            send_updated_post:     $('input[name="send_updated_post"]').is(':checked') ? '1' : '0',
            show_read_more:        $('input[name="show_read_more"]').is(':checked') ? '1' : '0',
            show_action_label:     $('input[name="show_action_label"]').is(':checked') ? '1' : '0',
            show_author_date:      $('input[name="show_author_date"]').is(':checked') ? '1' : '0',
            excerpt_max_chars:     parseInt($('#excerpt_max_chars').val(), 10) || 0,
            post_message_template: $('#post_message_template').val(),
            'channels[]':          channels,
            'post_types[]':        postTypes,
        }, collectPostButtons());

        doAjax('wp_ru_max_save_settings', data, function (res) {
            $btn.prop('disabled', false).text('Сохранить');
            showNotice($('#post_sender_result'), res.success ? 'success' : 'error', res.success ? 'Сохранено!' : res.data);
        });
    });

    $('#test_post_sender').on('click', function () {
        $('#test_chat_id_row').toggle();
    });

    $('#send_test_post').on('click', function () {
        var chatId = $('#test_chat_id').val().trim();
        if (!chatId) { alert('Введите ID чата!'); return; }
        var $btn = $(this).prop('disabled', true).text('Отправка...');
        doAjax('wp_ru_max_send_test_message', { chat_id: chatId, type: 'general' }, function (res) {
            $btn.prop('disabled', false).text('Отправить тест');
            showNotice($('#post_sender_result'), res.success ? 'success' : 'error', res.success ? res.data : res.data);
        });
    });

    /* -- Post Buttons management -- */
    $('#add_post_button').on('click', function () {
        var text = $('#new_post_btn_text').val().trim();
        var url  = $('#new_post_btn_url').val().trim();
        if (!text || !url) { alert('Введите название и URL кнопки.'); return; }
        var row = '<div class="wp-ru-max-button-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">' +
            '<input type="text" name="post_btn_text[]" value="' + escAttr(text) + '" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />' +
            '<input type="text" name="post_btn_url[]" value="' + escAttr(url) + '" class="regular-text" placeholder="https://..." style="flex:1;" />' +
            '<button type="button" class="button wp-ru-max-remove-btn">Удалить</button>' +
            '</div>';
        $('#post_buttons_list').append(row);
        $('#new_post_btn_text').val('');
        $('#new_post_btn_url').val('');
    });

    /* -- Channel / Chat ID management -- */
    $(document).on('click', '.wp-ru-max-remove-channel', function () {
        var $row = $(this).closest('.wp-ru-max-channel-row');
        if ($row.siblings('.wp-ru-max-channel-row').length === 0) {
            $row.find('input').val('');
        } else {
            $row.remove();
        }
    });

    /* -- Button row remove -- */
    $(document).on('click', '.wp-ru-max-remove-btn', function () {
        $(this).closest('.wp-ru-max-button-row').remove();
    });

    $('#add_channel').on('click', function () {
        $('#channels_list').append('<div class="wp-ru-max-channel-row"><input type="text" name="channels[]" class="regular-text" placeholder="@channel_name или -100123456789" /><button type="button" class="button wp-ru-max-remove-channel">X</button></div>');
    });

    $('#add_notify_channel').on('click', function () {
        $('#notify_chat_ids_list').append('<div class="wp-ru-max-channel-row"><input type="text" name="notify_chat_ids[]" class="regular-text" placeholder="987654321 | My Personal ID" /><button type="button" class="button wp-ru-max-remove-channel">X</button></div>');
    });

    /* -- Notifications Tab -- */
    $('#notifications_enabled').on('change', function () {
        $('#notifications_settings').toggle(this.checked);
        doAjax('wp_ru_max_save_settings', { field: 'notifications_enabled', value: this.checked ? '1' : '0' }, function () {});
    });

    $('#test_notification').on('click', function () {
        var chatId = $('#notify_test_chat').val().trim();
        if (!chatId) {
            chatId = prompt('Введите ID чата для теста:');
            if (!chatId) return;
        }
        var $btn = $(this).prop('disabled', true).text('Отправка...');
        doAjax('wp_ru_max_send_test_message', { chat_id: chatId, type: 'notification' }, function (res) {
            $btn.prop('disabled', false).text('Тестировать');
            showNotice($('#notify_test_result'), res.success ? 'success' : 'error', res.success ? res.data : res.data);
        });
    });

    /* Collect notification buttons as JSON — also flushes any pending button from the input fields */
    function collectNotifyButtons() {
        var pendingText = $('#new_notify_btn_text').val().trim();
        var pendingUrl  = $('#new_notify_btn_url').val().trim();
        if (pendingText && pendingUrl) {
            var row = '<div class="wp-ru-max-button-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">' +
                '<input type="text" name="notify_btn_text[]" value="' + escAttr(pendingText) + '" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />' +
                '<input type="text" name="notify_btn_url[]" value="' + escAttr(pendingUrl) + '" class="regular-text" placeholder="https://..." style="flex:1;" />' +
                '<button type="button" class="button wp-ru-max-remove-btn">Удалить</button>' +
                '</div>';
            $('#notify_buttons_list').append(row);
            $('#new_notify_btn_text').val('');
            $('#new_notify_btn_url').val('');
        }
        var buttons = [];
        $('#notify_buttons_list .wp-ru-max-button-row').each(function () {
            var t = $(this).find('input[name="notify_btn_text[]"]').val().trim();
            var u = $(this).find('input[name="notify_btn_url[]"]').val().trim();
            if (t && u) { buttons.push({ text: t, url: u }); }
        });
        return { notify_buttons_json: JSON.stringify(buttons) };
    }

    $('#save_notifications').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        var chatIds = [];
        $('input[name="notify_chat_ids[]"]').each(function () {
            var v = $(this).val().trim();
            if (v) chatIds.push(v);
        });

        var data = $.extend({
            notifications_enabled: $('#notifications_enabled').is(':checked') ? '1' : '0',
            notify_from_email:     $('#notify_from_email').val(),
            'notify_chat_ids[]':   chatIds,
            notify_template:       $('#notify_template').val(),
            notify_format:         $('input[name="notify_format"]:checked').val(),
        }, collectNotifyButtons());

        doAjax('wp_ru_max_save_settings', data, function (res) {
            $btn.prop('disabled', false).text('Сохранить');
            showNotice($('#notifications_result'), res.success ? 'success' : 'error', res.success ? 'Сохранено!' : res.data);
        });
    });

    /* -- Notify Button management -- */
    $('#add_notify_button').on('click', function () {
        var text = $('#new_notify_btn_text').val().trim();
        var url  = $('#new_notify_btn_url').val().trim();
        if (!text || !url) { alert('Введите название и URL кнопки.'); return; }
        var row = '<div class="wp-ru-max-button-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">' +
            '<input type="text" name="notify_btn_text[]" value="' + escAttr(text) + '" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />' +
            '<input type="text" name="notify_btn_url[]" value="' + escAttr(url) + '" class="regular-text" placeholder="https://..." style="flex:1;" />' +
            '<button type="button" class="button wp-ru-max-remove-btn">Удалить</button>' +
            '</div>';
        $('#notify_buttons_list').append(row);
        $('#new_notify_btn_text').val('');
        $('#new_notify_btn_url').val('');
    });

    /* -- Advanced Tab -- */
    $('#save_advanced').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        doAjax('wp_ru_max_save_settings', {
            send_post_image:        $('#send_post_image').is(':checked') ? '1' : '0',
            send_files_by_url:      $('#send_files_by_url').is(':checked') ? '1' : '0',
            enable_bot_api_log:     $('#enable_bot_api_log').is(':checked') ? '1' : '0',
            enable_post_sender_log: $('#enable_post_sender_log').is(':checked') ? '1' : '0',
            delete_on_uninstall:    $('#delete_on_uninstall').is(':checked') ? '1' : '0',
        }, function (res) {
            $btn.prop('disabled', false).text('Сохранить');
            showNotice($('#advanced_result'), res.success ? 'success' : 'error', res.success ? 'Сохранено!' : res.data);
        });
    });

    /* -- Chat Widget Tab -- */
    $('#chat_widget_enabled').on('change', function () {
        $('#chat_widget_settings').toggle(this.checked);
        doAjax('wp_ru_max_save_settings', { field: 'chat_widget_enabled', value: this.checked ? '1' : '0' }, function () {});
    });

    $('input[name="chat_widget_size"]').on('change', function () {
        var sizes = { small: 32, medium: 64, large: 80 };
        var px = sizes[$(this).val()] || 64;
        $('#preview_icon_img').attr('width', px).attr('height', px);
        $('label.wp-ru-max-size-option').removeClass('selected');
        $(this).closest('label').addClass('selected');
    });

    $('#chat_widget_message').on('input', function () {
        $('#preview_message').text($(this).val());
    });

    $('input[name="chat_widget_animation"]').on('change', function () {
        var $icon = $('.wp-ru-max-preview-widget .preview-icon');
        $icon.removeClass('wp-ru-max-anim-pulse wp-ru-max-anim-ripple wp-ru-max-anim-bounce wp-ru-max-anim-shake wp-ru-max-anim-glow wp-ru-max-anim-rotate');
        var val = $(this).val();
        if (val && val !== 'none') {
            $icon.addClass('wp-ru-max-anim-' + val);
        }
        $(this).closest('div').find('label').each(function () {
            var $lbl = $(this);
            var checked = $lbl.find('input[type="radio"]').is(':checked');
            $lbl.css({
                background: checked ? '#e8f0fe' : '#f8f9fa',
                'border-color': checked ? '#4a90d9' : '#ddd'
            });
        });
    });

    $('input[name="chat_widget_position"]').on('change', function () {
        var $widget = $('.wp-ru-max-preview-widget');
        if ($(this).val() === 'left') {
            $widget.css({ right: 'auto', left: '20px', 'align-items': 'flex-start' });
        } else {
            $widget.css({ left: 'auto', right: '20px', 'align-items': 'flex-end' });
        }
    });

    $('#save_chat_widget').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        var chatUrl = $('#chat_widget_url').val().trim();
        doAjax('wp_ru_max_save_settings', {
            chat_widget_enabled:       $('#chat_widget_enabled').is(':checked') ? '1' : '0',
            chat_widget_size:          $('input[name="chat_widget_size"]:checked').val() || 'medium',
            chat_widget_url:           chatUrl,
            chat_widget_message:       $('#chat_widget_message').val(),
            chat_widget_position:      $('input[name="chat_widget_position"]:checked').val() || 'right',
            chat_widget_bottom_offset: parseInt($('#chat_widget_bottom_offset').val(), 10) || 20,
            chat_widget_show_delay:    parseInt($('input[name="chat_widget_show_delay"]:checked').val(), 10) || 0,
            chat_widget_hide_delay:    parseInt($('input[name="chat_widget_hide_delay"]:checked').val(), 10) || 0,
            chat_widget_repeat_delay:  parseInt($('input[name="chat_widget_repeat_delay"]:checked').val(), 10) || 0,
            chat_widget_sound:         $('input[name="chat_widget_sound"]:checked').val() || 'none',
            chat_widget_sound_delay:   parseInt($('input[name="chat_widget_sound_delay"]:checked').val(), 10) || 3,
            chat_widget_sound_pages:   $('input[name="chat_widget_sound_pages"]:checked').val() || 'all',
            chat_widget_sound_specific_pages: $('#chat_widget_sound_specific_pages').val() || '',
            chat_widget_sound_once_per_session: $('#chat_widget_sound_once_per_session').is(':checked') ? '1' : '0',
            chat_widget_animation:     $('input[name="chat_widget_animation"]:checked').val() || 'none',
            chat_widget_retention_enabled:        $('#chat_widget_retention_enabled').is(':checked') ? '1' : '0',
            chat_widget_retention_title:          $('#chat_widget_retention_title').val() || '',
            chat_widget_retention_message:        $('#chat_widget_retention_message').val() || '',
            chat_widget_retention_text_align:     $('input[name="chat_widget_retention_text_align"]:checked').val() || 'left',
            chat_widget_retention_buttons_align:  $('input[name="chat_widget_retention_buttons_align"]:checked').val() || 'right',
            chat_widget_retention_btn_radius:     parseInt($('#chat_widget_retention_btn_radius').val(), 10) || 0,
            chat_widget_retention_stay_text:      $('#chat_widget_retention_stay_text').val() || 'Остаться',
            chat_widget_retention_leave_text:     $('#chat_widget_retention_leave_text').val() || 'Все равно уйти',
            chat_widget_retention_stay_bg:        $('input[name="chat_widget_retention_stay_bg"]').val() || '#4a90d9',
            chat_widget_retention_stay_color:     $('input[name="chat_widget_retention_stay_color"]').val() || '#ffffff',
            chat_widget_retention_leave_bg:       $('input[name="chat_widget_retention_leave_bg"]').val() || '#f0f0f0',
            chat_widget_retention_leave_color:    $('input[name="chat_widget_retention_leave_color"]').val() || '#555555',
        }, function (res) {
            $btn.prop('disabled', false).text('Сохранить');
            if (res.success) {
                var notice = 'Сохранено! Обновите страницу сайта, чтобы увидеть иконку.';
                if (!chatUrl) {
                    notice += ' Ссылка не заполнена — иконка покажется, но нажатие никуда не ведёт. Введите ссылку на ваш MAX чат.';
                }
                showNotice($('#chat_widget_result'), 'success', notice);
            } else {
                showNotice($('#chat_widget_result'), 'error', res.data);
            }
        });
    });

    /* -- Sound preview buttons (admin panel) -- */
    function adminPlaySound(type) {
        try {
            var AC  = window.AudioContext || window.webkitAudioContext;
            if (!AC) { alert('Ваш браузер не поддерживает Web Audio API'); return; }
            var ctx = new AC();

            function tone(freq, startOff, vol, dur) {
                var osc  = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.connect(gain);
                gain.connect(ctx.destination);
                var t = ctx.currentTime + startOff;
                osc.frequency.setValueAtTime(freq, t);
                gain.gain.setValueAtTime(0, t);
                gain.gain.linearRampToValueAtTime(vol, t + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, t + dur);
                osc.start(t);
                osc.stop(t + dur + 0.05);
            }

            if (type === 'sound1') {
                tone(880, 0, 0.18, 0.35);
                tone(1100, 0.22, 0.12, 0.28);
            } else if (type === 'sound2') {
                var osc  = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.connect(gain);
                gain.connect(ctx.destination);
                var t = ctx.currentTime;
                osc.frequency.setValueAtTime(200, t);
                osc.frequency.exponentialRampToValueAtTime(900, t + 0.12);
                osc.frequency.exponentialRampToValueAtTime(600, t + 0.28);
                gain.gain.setValueAtTime(0, t);
                gain.gain.linearRampToValueAtTime(0.38, t + 0.05);
                gain.gain.exponentialRampToValueAtTime(0.001, t + 0.45);
                osc.start(t);
                osc.stop(t + 0.5);
            } else if (type === 'sound3') {
                tone(523.25, 0, 0.12, 0.55);
            }
        } catch(e) { console.warn('WP Ru-max audio error:', e); }
    }

    $(document).on('click', '.wp-ru-max-preview-sound', function (e) {
        e.preventDefault();
        adminPlaySound($(this).data('sound'));
    });

    /* -- History Tab -- */
    function loadLogs(page) {
        currentPage = page || 1;
        var offset = (currentPage - 1) * perPage;
        $('#history_table_wrap').html('<div class="wp-ru-max-loading">Загрузка...</div>');

        doAjax('wp_ru_max_get_logs', { limit: perPage, offset: offset, type: historyType }, function (res) {
            if (!res.success) {
                $('#history_table_wrap').html('<p>Ошибка загрузки логов.</p>');
                return;
            }
            var logs = res.data.logs;
            var total = res.data.total;
            var totalPages = Math.ceil(total / perPage);

            if (!logs || logs.length === 0) {
                $('#history_table_wrap').html('<p style="padding:20px;color:#94a3b8;">Записей не найдено.</p>');
            } else {
                var typeLabels = {
                    api: 'API',
                    post_sender: 'Публикации',
                    notifications: 'Уведомления',
                    test: 'Тест',
                    settings: 'Настройки',
                };
                var html = '<table><thead><tr><th>ID</th><th>Время</th><th>Тип</th><th>Статус</th><th>Событие</th><th>Детали</th></tr></thead><tbody>';
                $.each(logs, function (i, log) {
                    var typeLabel = typeLabels[log.event_type] || log.event_type;
                    var statusClass = 'log-status-' + log.status;
                    var typeClass   = 'type-' + log.event_type;
                    var hasDetails  = log.details && log.details.trim();
                    html += '<tr>';
                    html += '<td>' + log.id + '</td>';
                    html += '<td style="white-space:nowrap;font-size:12px;">' + log.event_time + '</td>';
                    html += '<td><span class="log-type-badge ' + typeClass + '">' + typeLabel + '</span></td>';
                    html += '<td class="' + statusClass + '">' + log.status + '</td>';
                    html += '<td>' + $('<div>').text(log.event_data).html() + '</td>';
                    html += '<td>';
                    if (hasDetails) {
                        html += '<span class="toggle-details" data-id="' + log.id + '">показать ▼</span>';
                        html += '<pre class="log-details" id="details-' + log.id + '">' + $('<div>').text(log.details).html() + '</pre>';
                    } else {
                        html += '—';
                    }
                    html += '</td></tr>';
                });
                html += '</tbody></table>';
                $('#history_table_wrap').html(html);
            }

            $('#page_info').text('Страница ' + currentPage + ' из ' + Math.max(1, totalPages) + ' (всего: ' + total + ')');
            $('#prev_page').prop('disabled', currentPage <= 1);
            $('#next_page').prop('disabled', currentPage >= totalPages);
        });
    }

    $(document).on('click', '.toggle-details', function () {
        var id  = $(this).data('id');
        var $pre = $('#details-' + id);
        if ($pre.is(':visible')) {
            $pre.hide();
            $(this).text('показать ▼');
        } else {
            $pre.show();
            $(this).text('скрыть ▲');
        }
    });

    $('#prev_page').on('click', function () { loadLogs(currentPage - 1); });
    $('#next_page').on('click', function () { loadLogs(currentPage + 1); });
    $('#refresh_logs').on('click', function () { loadLogs(currentPage); });

    $('#clear_logs').on('click', function () {
        if (!confirm('Вы уверены, что хотите очистить логи?')) return;
        doAjax('wp_ru_max_clear_logs', { type: historyType }, function (res) {
            if (res.success) loadLogs(1);
        });
    });

    /* -- Global Test from History -- */
    $('#send_global_test').on('click', function () {
        var chatId = $('#global_test_chat').val().trim();
        if (!chatId) {
            chatId = prompt('Введите ID чата или канала для тестового сообщения:');
            if (!chatId) return;
        }
        var $btn = $(this).prop('disabled', true).text('Отправка...');
        doAjax('wp_ru_max_send_test_message', { chat_id: chatId, type: 'general' }, function (res) {
            $btn.prop('disabled', false).text('Тест подключения');
            showNotice($('#history_test_result'), res.success ? 'success' : 'error', res.success ? res.data : res.data);
            loadLogs(1);
        });
    });

    /* Auto-load logs on history tab */
    if ($('#history_table_wrap').length) {
        loadLogs(1);
    }

    /* -- Utility: escape attribute value -- */
    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})(jQuery);
