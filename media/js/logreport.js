(function () {
    var token = Joomla.getOptions('csrf.token');

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

    document.addEventListener('DOMContentLoaded', function () {
        var observer = new MutationObserver(function () {
            init();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        init();
    });

})();	 