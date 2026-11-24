(function ($) {
    'use strict';

    if (typeof wp === 'undefined' || !wp.plugins) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var useSelect = wp.data.useSelect;
    var registerPlugin = wp.plugins.registerPlugin;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var ToggleControl = wp.components.ToggleControl;
    var TextControl = wp.components.TextControl;
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

    function ajax(action, data, done) {
        $.post(wpRuMaxGutenberg.ajaxUrl, $.extend({
            action: action,
            nonce: wpRuMaxGutenberg.nonce
        }, data || {}), done).fail(function () {
            done({ success: false, data: 'Ошибка соединения с сервером.' });
        });
    }

    function configuredNetworkNames() {
        return Object.keys(wpRuMaxGutenberg.networks || {});
    }

    function onlyConfiguredNetworks(networks) {
        var available = configuredNetworkNames();
        return (Array.isArray(networks) ? networks : []).filter(function (network, index, values) {
            return available.indexOf(network) !== -1 && values.indexOf(network) === index;
        });
    }

    function loadSkipState(postId, onDone) {
        $.ajax({
            url: wpRuMaxGutenberg.restUrl + postId,
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpRuMaxGutenberg.restNonce);
            }
        }).done(function (res) {
            var ok = res && typeof res.on === 'boolean';
            onDone(ok ? res : {}, !!ok);
        }).fail(function () {
            ajax('wp_ru_max_get_skip', { post_id: postId }, function (res) {
                var state = res && res.success ? res.data : null;
                var ok = state && typeof state.on === 'boolean';
                onDone(ok ? state : {}, !!ok);
            });
        });
    }

    var skipSaveQueue = [];
    var skipSaveRunning = false;

    function runNextSkipSave() {
        if (skipSaveRunning || !skipSaveQueue.length) {
            return;
        }
        skipSaveRunning = true;
        var task = skipSaveQueue.shift();
        task.send(function (ok) {
            try {
                task.onDone(ok);
            } finally {
                skipSaveRunning = false;
                runNextSkipSave();
            }
        });
    }

    function saveSkipState(postId, changes, onDone) {
        var fallbackData = $.extend({ post_id: postId }, changes);
        skipSaveQueue.push({
            onDone: onDone,
            send: function (finish) {
                $.ajax({
                    url: wpRuMaxGutenberg.restUrl + postId,
                    method: 'POST',
                    data: changes,
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', wpRuMaxGutenberg.restNonce);
                    }
                }).done(function () {
                    finish(true);
                }).fail(function () {
                    ajax('wp_ru_max_set_skip', fallbackData, function (res) {
                        finish(!!(res && res.success));
                    });
                });
            }
        });
        runNextSkipSave();
    }

    function WpRuMaxPanel() {
        var postId = useSelect(function (select) {
            var editor = select('core/editor');
            return editor ? editor.getCurrentPostId() : 0;
        });
        var preferencesArr = useState({
            on: !!wpRuMaxGutenberg.autoSendDefault,
            excluded: []
        });
        var preferences = preferencesArr[0];
        var setPreferences = preferencesArr[1];
        var skipLoadedArr = useState(false);
        var skipLoaded = skipLoadedArr[0];
        var setSkipLoaded = skipLoadedArr[1];
        var skipLoadToken = useRef(0);
        var autoArr = useState({ networks: [], datetime: '', status: {} });
        var auto = autoArr[0];
        var setAuto = autoArr[1];
        var loadedArr = useState(false);
        var loaded = loadedArr[0];
        var setLoaded = loadedArr[1];
        var savingArr = useState(false);
        var saving = savingArr[0];
        var setSaving = savingArr[1];
        var noticeArr = useState('');
        var notice = noticeArr[0];
        var setNotice = noticeArr[1];

        useEffect(function () {
            var requestToken = ++skipLoadToken.current;
            if (!postId) {
                setSkipLoaded(false);
                return;
            }
            setLoaded(false);
            setSkipLoaded(false);
            ajax('wp_ru_max_autopost_get_meta', { post_id: postId }, function (res) {
                if (res && res.success) {
                    var loadedConfig = res.data || { networks: [], datetime: '', status: {} };
                    loadedConfig.networks = onlyConfiguredNetworks(loadedConfig.networks);
                    setAuto(loadedConfig);
                }
                setLoaded(true);
            });
            loadSkipState(postId, function (state, ok) {
                if (requestToken !== skipLoadToken.current) {
                    return;
                }
                if (!ok) {
                    setNotice('Не удалось загрузить состояние автоотправки. Обновите страницу перед изменением настройки.');
                    return;
                }
                setPreferences({
                    on: !!state.on,
                    excluded: onlyConfiguredNetworks(state.excluded || [])
                });
                setSkipLoaded(true);
            });
        }, [postId]);

        function toggleNetwork(network) {
            var networks = (auto.networks || []).slice(0);
            var index = networks.indexOf(network);
            if (index === -1) {
                networks.push(network);
            } else {
                networks.splice(index, 1);
            }
            setAuto($.extend({}, auto, { networks: networks }));
        }

        function saveAuto() {
            if (!postId || saving) {
                return;
            }
            setSaving(true);
            ajax('wp_ru_max_autopost_save_meta', {
                post_id: postId,
                networks: onlyConfiguredNetworks(auto.networks),
                datetime: (auto.datetime || '').replace('T', ' ')
            }, function (res) {
                setSaving(false);
                if (res && res.success) {
                    setAuto(res.data);
                    setNotice('Настройки автопостинга сохранены.');
                } else {
                    setNotice((res && res.data) || 'Не удалось сохранить настройки.');
                }
            });
        }

        function clearAuto() {
            setAuto({ networks: [], datetime: '', status: {} });
            setSaving(true);
            ajax('wp_ru_max_autopost_save_meta', {
                post_id: postId,
                networks: [],
                datetime: ''
            }, function () {
                setSaving(false);
                setNotice('Автопостинг для записи отключён.');
            });
        }

        function toggleExcluded(network) {
            var excluded = (preferences.excluded || []).slice(0);
            var index = excluded.indexOf(network);
            if (index === -1) {
                excluded.push(network);
            } else {
                excluded.splice(index, 1);
            }
            setPreferences($.extend({}, preferences, { excluded: excluded }));
            if (postId) {
                saveSkipState(postId, { excluded_json: JSON.stringify(excluded) }, function (ok) {
                    if (!ok) {
                        setNotice('Не удалось сохранить исключения сетей.');
                    }
                });
            }
        }

        var networkControls = [];
        var excludedControls = [];
        var seenNetworkLabels = {};
        $.each(wpRuMaxGutenberg.networks || {}, function (network, label) {
            var normalizedLabel = String(label || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (seenNetworkLabels[normalizedLabel]) {
                return;
            }
            seenNetworkLabels[normalizedLabel] = true;
            networkControls.push(el('label', {
                key: network,
                style: { display: 'block', margin: '5px 0', cursor: 'pointer' }
            }, el('input', {
                type: 'checkbox',
                checked: (auto.networks || []).indexOf(network) !== -1,
                onChange: function () { toggleNetwork(network); }
            }), ' ', label));
            excludedControls.push(el('label', {
                key: 'exclude-' + network,
                style: { display: 'block', margin: '5px 0', cursor: 'pointer' }
            }, el('input', {
                type: 'checkbox',
                checked: (preferences.excluded || []).indexOf(network) !== -1,
                disabled: !skipLoaded,
                onChange: function () { toggleExcluded(network); }
            }), ' ', label));
        });

        var content = [];
        content.push(el(ToggleControl, {
            key: 'auto-toggle',
            label: preferences.on ? 'Автоотправка: ВКЛ' : 'Автоотправка: ВЫКЛ',
            checked: preferences.on,
            disabled: !skipLoaded,
            help: skipLoaded
                ? 'Общий переключатель применяется ко всем подключённым социальным сетям.'
                : 'Загрузка состояния автоотправки…',
            onChange: function (value) {
                setPreferences($.extend({}, preferences, { on: value }));
                if (postId) {
                    saveSkipState(postId, { on: value ? 1 : 0 }, function (ok) {
                        if (!ok) {
                            setNotice('Не удалось сохранить состояние автоотправки.');
                        }
                    });
                }
            }
        }));
        content.push(el('div', {
            key: 'excluded',
            style: { marginTop: '8px' }
        }, el('strong', null, 'Не отправлять автоматически в:'),
        excludedControls.length ? excludedControls : el('p', {
            style: { fontSize: '12px', color: '#b32d2e', margin: '5px 0' }
        }, 'Подключите социальную сеть в настройках плагина.')));
        content.push(el('div', {
            key: 'autopost',
            className: 'wp-ru-max-gutenberg-autopost',
            style: { borderTop: '1px solid #ddd', marginTop: '12px', paddingTop: '12px' }
        }, el('strong', null, 'Автопостинг в социальные сети'),
        el('p', { style: { fontSize: '12px', color: '#757575', margin: '5px 0' } },
            'Выберите сети и дату публикации. Время — по часовому поясу сайта.'),
        el('div', { style: { marginBottom: '10px' } }, networkControls),
        networkControls.length ? null : el('p', {
            style: { fontSize: '12px', color: '#b32d2e', margin: '5px 0 10px' }
        }, 'Подключите социальную сеть в настройках плагина, чтобы создать задание.'),
        el(TextControl, {
            label: 'Дата и время',
            type: 'datetime-local',
            value: (auto.datetime || '').replace(' ', 'T'),
            onChange: function (value) { setAuto($.extend({}, auto, { datetime: value })); },
            help: loaded ? 'Оставьте пустым, чтобы снять задание.' : 'Загрузка…',
        }),
        el(Button, {
            variant: 'primary',
            isBusy: saving,
            disabled: !loaded || saving,
            onClick: saveAuto,
            style: { marginRight: '6px' }
        }, saving ? el(wp.element.Fragment, null, el(Spinner), ' Сохранение…') : 'Сохранить автопостинг'),
        el(Button, {
            variant: 'link',
            disabled: saving || !loaded,
            onClick: clearAuto
        }, 'Снять задание')));

        if (notice) {
            content.push(el('p', {
                key: 'notice',
                style: { fontSize: '12px', color: notice.indexOf('не') === 0 || notice.indexOf('Ошибка') === 0 ? '#d63638' : '#008a20' }
            }, notice));
        }

        return el(PluginDocumentSettingPanel, {
            name: 'wp-ru-max-send-panel',
            title: 'Автопостинг',
            icon: el(MaxIcon)
        }, content);
    }

    registerPlugin('wp-ru-max-gutenberg', {
        render: WpRuMaxPanel,
        icon: el(MaxIcon)
    });
}(jQuery));
