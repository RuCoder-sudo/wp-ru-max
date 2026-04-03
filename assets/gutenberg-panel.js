(function ($) {
    'use strict';

    if (typeof wp === 'undefined' || !wp.plugins) {
        return;
    }

    var el               = wp.element.createElement;
    var useState         = wp.element.useState;
    var useSelect        = wp.data.useSelect;
    var useDispatch      = wp.data.useDispatch;
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

    function WpRuMaxPanel() {
        var stateArr = useState({ sending: false, sent: false, error: null });
        var state    = stateArr[0];
        var setState = stateArr[1];

        var postId = useSelect(function (select) {
            var editor = select('core/editor');
            return editor ? editor.getCurrentPostId() : 0;
        });

        var skipSend = useSelect(function (select) {
            var editor = select('core/editor');
            if (!editor) { return false; }
            var meta = editor.getEditedPostAttribute('meta');
            return meta && meta['_wp_ru_max_skip'] === '1';
        });

        var editPost = useDispatch('core/editor').editPost;

        function toggleSkip(value) {
            editPost({ meta: { '_wp_ru_max_skip': value ? '1' : '' } });
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

        panelContent.push(
            el(ToggleControl, {
                key:      'toggle',
                label:    skipSend ? 'Автоотправка в MAX: ВЫКЛ' : 'Автоотправка в MAX: ВКЛ',
                checked:  !skipSend,
                onChange: function (val) { toggleSkip(!val); },
                help:     skipSend
                    ? 'Эта статья НЕ будет отправлена в MAX автоматически.'
                    : 'Эта статья будет автоматически отправлена в MAX при публикации.'
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
