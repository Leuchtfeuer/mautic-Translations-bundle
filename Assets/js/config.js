/* global window, document, mauticAjaxCsrf */
;(function (win) {
    'use strict';

    // Namespace for shared config between bundle scripts
    const NS = (win.LFTranslations = win.LFTranslations || {});

    // ---- I18N defaults (non-destructive: keep pre-seeded keys if any) ----
    NS.I18N = Object.assign(
        {
            choose_target_language: 'Choose target language',
            cancel: 'Cancel',
            translate: 'Translate',
            missing_csrf: 'No CSRF token found. Please refresh the page and try again.',
            missing_email_id: 'Could not determine Email ID.',
            done: 'Done.',
            next_step:
                'Next step:\nOpen the Email Builder, review the translated content, and click Save.',
            please_choose_language: 'Please choose a language.',
            unexpected_error: 'Unexpected error, check console.',
            test_api_connection: 'Test API Connection',
        },
        NS.I18N || {}
    );

    // ---- DeepL target languages (only set if not already provided) ----
    NS.DEEPL_LANGS =
        NS.DEEPL_LANGS ||
        [
            { code: 'AR', name: 'Arabic' },
            { code: 'BG', name: 'Bulgarian' },
            { code: 'CS', name: 'Czech' },
            { code: 'DA', name: 'Danish' },
            { code: 'DE', name: 'German' },
            { code: 'EL', name: 'Greek' },

            { code: 'EN', name: 'English' },
            { code: 'EN-GB', name: 'English (UK)' },
            { code: 'EN-US', name: 'English (US)' },

            { code: 'ES', name: 'Spanish' },
            { code: 'ES-419', name: 'Spanish (Latin American)' },

            { code: 'ET', name: 'Estonian' },
            { code: 'FI', name: 'Finnish' },
            { code: 'FR', name: 'French' },
            { code: 'HE', name: 'Hebrew' },
            { code: 'HU', name: 'Hungarian' },
            { code: 'ID', name: 'Indonesian' },
            { code: 'IT', name: 'Italian' },
            { code: 'JA', name: 'Japanese' },
            { code: 'KO', name: 'Korean' },
            { code: 'LT', name: 'Lithuanian' },
            { code: 'LV', name: 'Latvian' },
            { code: 'NB', name: 'Norwegian (Bokmål)' },
            { code: 'NL', name: 'Dutch' },
            { code: 'PL', name: 'Polish' },

            { code: 'PT', name: 'Portuguese' },
            { code: 'PT-BR', name: 'Portuguese (Brazil)' },
            { code: 'PT-PT', name: 'Portuguese (Portugal)' },

            { code: 'RO', name: 'Romanian' },
            { code: 'RU', name: 'Russian' },
            { code: 'SK', name: 'Slovak' },
            { code: 'SL', name: 'Slovenian' },
            { code: 'SV', name: 'Swedish' },

            { code: 'TH', name: 'Thai' },
            { code: 'TR', name: 'Turkish' },
            { code: 'UK', name: 'Ukrainian' },
            { code: 'VI', name: 'Vietnamese' },

            { code: 'ZH', name: 'Chinese' },
            { code: 'ZH-HANS', name: 'Chinese (Simplified)' },
            { code: 'ZH-HANT', name: 'Chinese (Traditional)' },
        ];

    // ---- Test button injection for IntegrationsBundle config modal ----
    function lfInjectTestButton() {
        // Detect our integration's config form by the deepl_api_key field
        var apiKeyInput = document.querySelector('input[name*="deepl_api_key"]');
        if (!apiKeyInput) return;

        // Don't inject twice
        if (document.getElementById('lf-test-deepl-btn')) return;

        var formGroup = apiKeyInput.closest('.form-group') || apiKeyInput.parentNode;

        var inputCol = apiKeyInput.closest('[class*="col-"]') || formGroup;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'lf-test-deepl-btn';
        btn.className = 'btn btn-default mt-xs';
        btn.textContent = NS.I18N.test_api_connection || 'Test API Connection';

        var resultSpan = document.createElement('span');
        resultSpan.id = 'lf-test-deepl-result';
        resultSpan.className = 'help-block';

        inputCol.appendChild(btn);
        inputCol.appendChild(resultSpan);

        document.getElementById('lf-test-deepl-btn').addEventListener('click', function () {
            var btn = this;
            var result = document.getElementById('lf-test-deepl-result');
            btn.disabled = true;
            result.className = 'help-block';
            result.textContent = '…';

            var csrf = (typeof mauticAjaxCsrf !== 'undefined' && mauticAjaxCsrf) || '';
            fetch('/s/plugin/ai-translate/test-api', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrf,
                },
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    result.className = 'help-block ' + (d.success ? 'text-success' : 'text-danger');
                    result.textContent = d.message || (d.success ? 'Success' : 'Failed');
                })
                .catch(function () {
                    result.className = 'help-block text-danger';
                    result.textContent = 'Request failed.';
                })
                .finally(function () { btn.disabled = false; });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        lfInjectTestButton();

        // Also run after every Mautic AJAX load (modal/panel)
        var $ = win.mQuery || win.jQuery;
        if ($) {
            $(document).on('ajaxComplete', function () { lfInjectTestButton(); });
        }
    });
})(window);
