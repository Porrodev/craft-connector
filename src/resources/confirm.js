(function () {
    if (window.porroConfirm) return;
    window.porroConfirm = true;

    // Forms carrying data-porro-confirm ask in the page's <dialog id="porro-confirm">
    // first. The dialog has two confirm buttons, data-porro-ok="danger" and
    // "safe", so each panel can draw them with its own components; the one
    // that fits is shown and named for the action.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var dialog = document.getElementById('porro-confirm');

        if (!form.dataset || !form.dataset.porroConfirm || form.dataset.porroConfirmed) return;
        if (!dialog || typeof dialog.showModal !== 'function') return;

        var ask;
        try { ask = JSON.parse(form.dataset.porroConfirm); } catch (e) { return; }

        event.preventDefault();
        event.stopPropagation();

        dialog.querySelector('[data-porro-title]').textContent = ask.title;
        dialog.querySelector('[data-porro-body]').textContent = ask.body;
        dialog.querySelectorAll('[data-porro-ok]').forEach(function (ok) {
            var show = ok.getAttribute('data-porro-ok') === (ask.danger ? 'danger' : 'safe');
            ok.hidden = !show;
            ok.style.display = show ? '' : 'none';
            if (show) ok.textContent = ask.button;
        });

        dialog.returnValue = '';
        dialog.onclose = function () {
            if (dialog.returnValue !== 'ok') return;
            form.dataset.porroConfirmed = '1';
            if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
        };
        dialog.showModal();
        dialog.querySelector('[data-porro-cancel]').focus();
    }, true);

    // Buttons carrying data-porro-copy put that text on the clipboard.
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-porro-copy]');
        if (!button || !navigator.clipboard) return;
        navigator.clipboard.writeText(button.getAttribute('data-porro-copy')).then(function () {
            var label = button.textContent;
            button.textContent = '✓';
            setTimeout(function () { button.textContent = label; }, 1500);
        });
    });
})();
