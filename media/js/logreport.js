(function () {
    var token = Joomla.getOptions('csrf.token');

    // ===========================================================================
    // Exportknop verplaatsen naar naast 'Verwijder alle logs'
    // ===========================================================================

    function moveExportButton() {
        var exportBtn  = document.getElementById('sl-export-log-btn');
        var purgeBtn   = document.getElementById('sl-log-purge-btn');
        var statusSpan = document.getElementById('sl-export-log-status');

        if (!exportBtn || !purgeBtn) return;
        if (exportBtn.dataset.slMoved) return;
        exportBtn.dataset.slMoved = '1';

        purgeBtn.insertAdjacentElement('afterend', exportBtn);

        if (statusSpan) {
            exportBtn.insertAdjacentElement('afterend', statusSpan);
        }

        var emptyGroup = document.getElementById('jform_params_log_export-lbl');
        if (emptyGroup) {
            var controlGroup = emptyGroup.closest('.control-group');
            if (controlGroup) controlGroup.style.display = 'none';
        }
    }

    // ===========================================================================
    // Log tabel: filter, purge en laden
    // ===========================================================================

    function init() {
        var select  = document.getElementById('sl-log-type-select');
        var btn     = document.getElementById('sl-log-purge-btn');
        var wrapper = document.getElementById('sl-log-table-wrapper');
        if (!select || !btn || !wrapper) return;
        if (select.dataset.slInit) return;
        select.dataset.slInit = '1';

        var labels = window.SimpleloginLogLabels || {
            deleteType:  'Delete type: ',
            deleteAll:   'Delete all logs',
            confirmType: 'Are you sure you want to delete all logs of type "%s"?',
            confirmAll:  'Are you sure you want to delete all logs?'
        };

        function updateButtonLabel(type) {
            btn.textContent = type
                ? labels.deleteType + type
                : labels.deleteAll;
        }

        function confirmMessage(type) {
            return type
                ? labels.confirmType.replace('%s', type)
                : labels.confirmAll;
        }

        function loadRows(type) {
            var url = 'index.php?option=com_ajax&plugin=simplelogin&format=json'
                    + '&method=GetLogRows&type=' + encodeURIComponent(type)
                    + '&' + token + '=1';
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (response) {
                    var result = response.data;
                    if (Array.isArray(result)) result = result[0];
                    if (result && result.success) {
                        wrapper.innerHTML = result.data;
                    }
                });
        }

        function purgeRows(type) {
            if (!confirm(confirmMessage(type))) return;
            var url = 'index.php?option=com_ajax&plugin=simplelogin&format=json'
                    + '&method=PurgeLogRows&type=' + encodeURIComponent(type)
                    + '&' + token + '=1';
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (response) {
                    var result = response.data;
                    if (Array.isArray(result)) result = result[0];
                    if (result && result.success) {
                        loadRows(type);
                    }
                });
        }

        select.addEventListener('change', function () {
            updateButtonLabel(this.value);
            loadRows(this.value);
        });

        btn.addEventListener('click', function () {
            purgeRows(select.value);
        });

        updateButtonLabel('');
    }

    // ===========================================================================
    // Exportknop: klik-handler
    // ===========================================================================

    function initExportButton() {
        var exportBtn  = document.getElementById('sl-export-log-btn');
        if (!exportBtn) return;
        if (exportBtn.dataset.slExportInit) return;
        exportBtn.dataset.slExportInit = '1';

        exportBtn.addEventListener('click', function () {
            var exportToken = exportBtn.dataset.token;
            var status      = document.getElementById('sl-export-log-status');

            exportBtn.disabled     = true;
            status.textContent     = Joomla.Text._('PLG_SYSTEM_SIMPLELOGIN_BTN_EXPORT_SENDING') || 'Bezig…';
            status.className       = 'ms-2';

            fetch('index.php?option=com_ajax&plugin=simplelogin&format=json'
                + '&method=ExportLog'
                + '&' + exportToken + '=1', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var inner = Array.isArray(data.data) ? data.data[0] : data;
                status.textContent = inner.message || '';
                status.className   = 'ms-2 text-' + (inner.success ? 'success' : 'danger');
            })
            .catch(function () {
                status.textContent = 'Fout bij versturen.';
                status.className   = 'ms-2 text-danger';
            })
            .finally(function () { exportBtn.disabled = false; });
        });
    }

    // ===========================================================================
    // DOMContentLoaded: alles opstarten + MutationObserver voor lazy-render
    // ===========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        moveExportButton();
        init();
        initExportButton();

        var observer = new MutationObserver(function () {
            moveExportButton();
            init();
            initExportButton();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });

})();