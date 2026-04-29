(function ($) {
    'use strict';

    if (typeof wp === 'undefined' || !wp.plugins) {
        return;
    }

    var el               = wp.element.createElement;
    var useState         = wp.element.useState;
    var useEffect        = wp.element.useEffect;
    var useSelect        = wp.data.useSelect;
    var registerPlugin   = wp.plugins.registerPlugin;
    var Button           = wp.components.Button;
    var Spinner          = wp.components.Spinner;
    var ToggleControl    = wp.components.ToggleControl;

    var PluginDocumentSettingPanel =
        (wp.editor && wp.editor.PluginDocumentSettingPanel) ||
        (wp.editPost && wp.editPost.PluginDocumentSettingPanel);

    if (!PluginDocumentSettingPanel) {
        return;
    }

    function MaxIcon() {
        return el('img', {
            src: wpRuMaxGutenberg.iconUrl,
            width: 18,
            height: 18,
            alt: 'MAX',
            style: { verticalAlign: 'middle', display: 'inline-block' }
        });
    }

    // Универсальный POST в свой REST-эндпоинт.
    // Сначала пробуем REST (через apiFetch если доступен, иначе jQuery),
    // если падает — fallback на admin-ajax.php.
    function saveSkipState(postId, isOn, onDone) {
        var done = false;
        function finish(ok, stored) {
            if (done) { return; }
            done = true;
            if (typeof onDone === 'function') { onDone(ok, stored); }
        }

        var url = wpRuMaxGutenberg.restUrl + postId;
        $.ajax({
            url: url,
            method: 'POST',
            data: { on: isOn ? 1 : 0 },
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpRuMaxGutenberg.restNonce);
            }
        }).done(function (res) {
            finish(true, res && res.stored);
        }).fail(function () {
            // Fallback: admin-ajax
            $.post(wpRuMaxGutenberg.ajaxUrl, {
                action: 'wp_ru_max_set_skip',
                nonce:  wpRuMaxGutenberg.nonce,
                post_id: postId,
                on:      isOn ? 1 : 0
            }, function (r) {
                if (r && r.success) {
                    finish(true, r.data && r.data.stored);
                } else {
                    finish(false, null);
                }
            }).fail(function () {
                finish(false, null);
            });
        });
    }

    function loadSkipState(postId, onDone) {
        var url = wpRuMaxGutenberg.restUrl + postId;
        $.ajax({
            url: url,
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpRuMaxGutenberg.restNonce);
            }
        }).done(function (res) {
            onDone(true, !!(res && res.on));
        }).fail(function () {
            $.post(wpRuMaxGutenberg.ajaxUrl, {
                action: 'wp_ru_max_get_skip',
                nonce:  wpRuMaxGutenberg.nonce,
                post_id: postId
            }, function (r) {
                if (r && r.success) {
                    onDone(true, !!(r.data && r.data.on));
                } else {
                    onDone(false, false);
                }
            }).fail(function () {
                onDone(false, false);
            });
        });
    }

    function WpRuMaxPanel() {
        var stateArr = useState({ sending: false, sent: false, error: null });
        var state    = stateArr[0];
        var setState = stateArr[1];

        // Локальное состояние тумблера: true = ВКЛ (автоотправка включена).
        // По умолчанию — false (ВЫКЛ). Загружаем настоящее значение из БД
        // через свой REST-эндпоинт сразу после монтирования.
        var onArr   = useState(false);
        var isOn    = onArr[0];
        var setIsOn = onArr[1];

        var savingArr = useState(false);
        var saving    = savingArr[0];
        var setSaving = savingArr[1];

        var errArr = useState(null);
        var saveErr = errArr[0];
        var setSaveErr = errArr[1];

        var loadedArr = useState(false);
        var loaded    = loadedArr[0];
        var setLoaded = loadedArr[1];

        var postId = useSelect(function (select) {
            var editor = select('core/editor');
            return editor ? editor.getCurrentPostId() : 0;
        });

        // Подгружаем текущее состояние из БД при появлении postId.
        useEffect(function () {
            if (!postId) { return; }
            loadSkipState(postId, function (ok, on) {
                setIsOn(!!on);
                setLoaded(true);
            });
        }, [postId]);

        function handleToggle(newVal) {
            // Оптимистично обновляем UI.
            var prev = isOn;
            setIsOn(!!newVal);
            setSaving(true);
            setSaveErr(null);

            if (!postId) {
                setSaving(false);
                setSaveErr('Сначала сохраните статью как черновик.');
                setIsOn(prev);
                return;
            }

            saveSkipState(postId, !!newVal, function (ok, stored) {
                setSaving(false);
                if (!ok) {
                    setSaveErr('Не удалось сохранить состояние тумблера.');
                    setIsOn(prev);
                    return;
                }
                // Синхронизируем с тем, что РЕАЛЬНО лежит в БД.
                setIsOn(stored === '0');
            });
        }

        function sendToMax() {
            if (state.sending) { return; }
            setState({ sending: true, sent: false, error: null });

            $.post(
                wpRuMaxGutenberg.ajaxUrl,
                {
                    action:  'wp_ru_max_send_post_now',
                    nonce:   wpRuMaxGutenberg.nonce,
                    post_id: postId
                },
                function (res) {
                    if (res && res.success) {
                        setState({ sending: false, sent: true, error: null });
                        setTimeout(function () {
                            setState({ sending: false, sent: false, error: null });
                        }, 4000);
                    } else {
                        var msg = (res && res.data) ? res.data : 'Ошибка отправки';
                        setState({ sending: false, sent: false, error: msg });
                    }
                }
            ).fail(function () {
                setState({ sending: false, sent: false, error: 'Ошибка соединения с сервером' });
            });
        }

        var panelContent = [];

        var helpText;
        if (!loaded) {
            helpText = 'Загрузка состояния…';
        } else if (saving) {
            helpText = 'Сохранение…';
        } else if (saveErr) {
            helpText = saveErr;
        } else if (isOn) {
            helpText = 'Эта статья будет автоматически отправлена в MAX при публикации.';
        } else {
            helpText = 'Эта статья НЕ будет отправлена в MAX автоматически.';
        }

        panelContent.push(
            el(ToggleControl, {
                key:      'toggle',
                label:    isOn ? 'Автоотправка в MAX: ВКЛ' : 'Автоотправка в MAX: ВЫКЛ',
                checked:  isOn,
                disabled: !loaded || saving,
                onChange: handleToggle,
                help:     helpText
            })
        );

        panelContent.push(
            el('hr', {
                key: 'sep',
                style: { margin: '8px 0', borderColor: '#ddd' }
            })
        );

        if (state.sent) {
            panelContent.push(
                el('div', {
                    key: 'ok',
                    style: {
                        background: '#d1fae5',
                        border: '1px solid #6ee7b7',
                        borderRadius: '4px',
                        padding: '8px 12px',
                        marginBottom: '10px',
                        fontSize: '13px',
                        color: '#065f46'
                    }
                }, 'Запись отправлена в MAX!')
            );
        }

        if (state.error) {
            panelContent.push(
                el('div', {
                    key: 'err',
                    style: {
                        background: '#fee2e2',
                        border: '1px solid #fca5a5',
                        borderRadius: '4px',
                        padding: '8px 12px',
                        marginBottom: '10px',
                        fontSize: '13px',
                        color: '#991b1b'
                    }
                }, state.error)
            );
        }

        panelContent.push(
            el(Button, {
                key:      'btn',
                variant:  'primary',
                isBusy:   state.sending,
                disabled: state.sending,
                onClick:  sendToMax,
                style:    { width: '100%', justifyContent: 'center', marginTop: '4px' }
            },
            state.sending
                ? el(wp.element.Fragment, null, el(Spinner, { key: 'sp' }), ' Отправка...')
                : 'Отправить в MAX вручную'
            )
        );

        return el(
            PluginDocumentSettingPanel,
            {
                name:  'wp-ru-max-send-panel',
                title: 'Отправить в MAX',
                icon:  el(MaxIcon, { key: 'ico' })
            },
            panelContent
        );
    }

    registerPlugin('wp-ru-max-gutenberg', {
        render: WpRuMaxPanel,
        icon:   el(MaxIcon, {})
    });

}(jQuery));
