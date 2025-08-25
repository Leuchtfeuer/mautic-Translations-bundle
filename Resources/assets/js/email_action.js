// plugins/AiTranslateBundle/Resources/assets/js/email_action.js

document.addEventListener('DOMContentLoaded', () => {
    const translateButton = document.querySelector('.btn-clone-translate');
    if (!translateButton) return;

    translateButton.addEventListener('click', (event) => {
        event.preventDefault();

        // 1) Prompt for target language
        const targetLang = prompt('Please enter the target language code (e.g., DE, FR, ES):', 'DE');
        if (!targetLang || targetLang.trim() === '') return;

        // 2) Loading feedback
        try { if (window.Mautic && Mautic.showLoadingBar) Mautic.showLoadingBar(); } catch (_) {}
        const originalButtonHtml = translateButton.innerHTML;
        translateButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Translating...';
        translateButton.disabled = true;

        const url = translateButton.getAttribute('href');

        // 3) CSRF token from Mautic global
        const csrf = (typeof mauticAjaxCsrf !== 'undefined' && mauticAjaxCsrf) || '';
        if (!csrf) {
            alert('No CSRF token found. Please refresh the page and try again.');
            try { if (window.Mautic && Mautic.stopLoadingBar) Mautic.stopLoadingBar(); } catch (_) {}
            translateButton.innerHTML = originalButtonHtml;
            translateButton.disabled = false;
            return;
        }

        // 4) Build form body
        const form = new URLSearchParams();
        form.append('targetLang', targetLang.trim().toUpperCase());

        // 5) Make the secure AJAX call
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: form.toString()
        })
            .then(response => response.json())
            .then(data => {
                alert(data.message || 'Done.');
            })
            .catch(error => {
                console.error('Translation Error:', error);
                alert('An unexpected error occurred. Check the console for details.');
            })
            .finally(() => {
                try { if (window.Mautic && Mautic.stopLoadingBar) Mautic.stopLoadingBar(); } catch (_) {}
                translateButton.innerHTML = originalButtonHtml;
                translateButton.disabled = false;
            });
    });
});
