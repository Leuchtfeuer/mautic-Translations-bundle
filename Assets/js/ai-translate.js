/* global mauticAjaxCsrf */
;(function (win, doc) {
    'use strict';

    // Namespace
    const NS = (win.LFTranslations = win.LFTranslations || {});

    // --- helpers that read from window each time (no stale snapshots) ---
    function getI18N() {
        return (win.LFTranslations && win.LFTranslations.I18N) || {};
    }
    function getLangs() {
        const arr = (win.LFTranslations && win.LFTranslations.DEEPL_LANGS) || [];
        return Array.isArray(arr) ? arr : [];
    }
    function waitForLangs(maxMs) {
        const deadline = Date.now() + (typeof maxMs === 'number' ? maxMs : 700);
        return new Promise((resolve) => {
            (function tick() {
                const langs = getLangs();
                if (langs.length > 0 || Date.now() >= deadline) return resolve(langs);
                setTimeout(tick, 25);
            })();
        });
    }

    // very small last-ditch list so the select is never empty
    const FALLBACK_LANGS = [
        { code: 'EN', name: 'English' },
        { code: 'DE', name: 'German' },
        { code: 'FR', name: 'French' },
        { code: 'ES', name: 'Spanish' },
    ];

    async function openLanguagePicker(defaultCode) {
        const $ = win.mQuery || win.jQuery;

        // Create modal once
        let $modal = $('#lf-translations-modal');
        if ($modal.length === 0) {
            const I18N = getI18N();
            const html =
                '<div class="modal fade" id="lf-translations-modal" tabindex="-1" role="dialog" aria-labelledby="lf-translations-title" aria-hidden="true">' +
                '  <div class="modal-dialog" role="document">' +
                '    <div class="modal-content">' +
                '      <div class="modal-header">' +
                `        <h4 class="modal-title" id="lf-translations-title">${I18N.choose_target_language || 'Choose target language'}</h4>` +
                '        <button type="button" class="close" data-dismiss="modal" aria-label="Close">' +
                '          <span aria-hidden="true">&times;</span>' +
                '        </button>' +
                '      </div>' +
                '      <div class="modal-body">' +
                '        <select id="lf-translations-select" class="form-control"></select>' +
                '      </div>' +
                '      <div class="modal-footer">' +
                `        <button type="button" class="btn btn-secondary" data-dismiss="modal">${I18N.cancel || 'Cancel'}</button>` +
                `        <button type="button" class="btn btn-primary" id="lf-translations-ok">${I18N.translate || 'Translate'}</button>` +
                '      </div>' +
                '    </div>' +
                '  </div>' +
                '</div>';
            $('body').append(html);
            $modal = $('#lf-translations-modal');
        }

        // Wait briefly for languages (or fall back)
        let langs = await waitForLangs(400);
        if (!langs.length) langs = FALLBACK_LANGS;

        // Populate select fresh
        const $select = $('#lf-translations-select');
        $select.empty();
        const def = String(defaultCode || 'DE').toUpperCase();
        langs.forEach((l) => {
            const code = (l && l.code) ? String(l.code) : '';
            const name = (l && l.name) ? String(l.name) : code;
            if (!code) return;
            const $opt = $('<option>').val(code).text(name + ' (' + code + ')');
            if (code.toUpperCase() === def) $opt.prop('selected', true);
            $select.append($opt);
        });

        $modal.off('shown.bs.modal').on('shown.bs.modal', function () {
            $select.trigger('focus');
        });

        const I18N = getI18N();

        return new Promise((resolve) => {
            let selection = null;

            function onHidden() {
                $('#lf-translations-ok').off('click', onOk);
                $modal.off('hidden.bs.modal', onHidden);
                resolve(selection);
            }

            function onOk() {
                const code = String($select.val() || '').trim().toUpperCase();
                if (!code) {
                    alert(I18N.please_choose_language || 'Please choose a language.');
                    return;
                }
                selection = code;
                $modal.modal('hide');
            }

            $modal.one('hidden.bs.modal', onHidden);
            $('#lf-translations-ok').on('click', onOk);

            $modal.modal('show');
        });
    }

    NS.openDialog = function (ev) {
        const I18N = getI18N();
        if (ev) {
            ev.preventDefault();
            ev.stopPropagation();
        }

        // Extract Email ID from /s/emails/view/{id}
        const m = (win.location.pathname || '').match(/\/s\/emails\/view\/(\d+)/);
        const id = m ? m[1] : null;
        if (!id) {
            alert(I18N.missing_email_id || 'Could not determine Email ID.');
            return false;
        }

        return openLanguagePicker('DE').then(function (targetLang) {
            if (!targetLang) return false;

            try {
                if (win.Mautic && Mautic.showLoadingBar) Mautic.showLoadingBar();
            } catch (_) {}

            const form = new URLSearchParams();
            form.append('targetLang', String(targetLang || '').toUpperCase());

            const csrf = (typeof mauticAjaxCsrf !== 'undefined' && mauticAjaxCsrf) ? mauticAjaxCsrf : '';
            if (!csrf) {
                alert(I18N.missing_csrf || 'No CSRF token found. Please refresh the page and try again.');
                try { if (win.Mautic && Mautic.stopLoadingBar) Mautic.stopLoadingBar(); } catch (_) {}
                return false;
            }

            return fetch('/s/plugin/ai-translate/email/' + id + '/translate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: form.toString(),
            })
                .then((r) => r.json())
                .then((d) => {
                    const baseMsg = d && d.message ? d.message : (I18N.done || 'Done.');
                    const warn = '\n\n' + (I18N.next_step || 'Next step:\nOpen the Email Builder, review the translated content, and click Save.');
                    alert(baseMsg + warn);

                    if (d && d.clone && d.clone.urls && d.clone.urls.edit) {
                        win.location.href = d.clone.urls.edit;
                    }
                })
                .catch((err) => {
                    console.error('LeuchtfeuerTranslations error:', err);
                    alert(I18N.unexpected_error || 'Unexpected error, check console.');
                })
                .finally(() => {
                    try {
                        if (win.Mautic && Mautic.stopLoadingBar) Mautic.stopLoadingBar();
                    } catch (_) {}
                });
        });
    };

    // Delegated click hook
    doc.addEventListener('click', function (ev) {
        const el = ev.target.closest('[data-lf-translate="1"]');
        if (!el) return;
        NS.openDialog(ev);
    });
})(window, document);
