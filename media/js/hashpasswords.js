(function () {

    function runHashPasswords() {
        // Config en labels hier uitlezen, zodat ze zeker beschikbaar zijn
        const config = window.SimpleloginHashConfig || {};
        const labels = window.SimpleloginHashLabels || {
            confirm:    'Are you sure you want to reset all frontend passwords?',
            processing: 'Processing...',
            warning:    'Warning: ',
            error:      'Error: ',
            invalid:    'Invalid response'
        };

        if (!confirm(labels.confirm)) return;

        const resultDiv = document.getElementById('hash-result');
        resultDiv.innerHTML = '<div class="alert alert-info shadow-sm">' + labels.processing + '</div>';

        const params = new URLSearchParams();
        params.append('method', 'HashPasswords');
        params.append(config.token, '1');

        fetch(config.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(function (r) {
            if (!r.ok) throw new Error(labels.error + r.status);
            return r.json();
        })
        .then(function (data) {
            if (data.success && data.data && data.data[0]) {
                const result = data.data[0];
                if (result.success) {
                    resultDiv.innerHTML = '<div class="alert alert-success shadow-sm">' + result.message + '</div>';
                } else {
                    resultDiv.innerHTML = '<div class="alert alert-warning shadow-sm">' + labels.warning + result.message + '</div>';
                }
            } else {
                resultDiv.innerHTML = '<div class="alert alert-danger shadow-sm">' + labels.error + (data.message || labels.invalid) + '</div>';
            }
        })
        .catch(function (err) {
            resultDiv.innerHTML = '<div class="alert alert-danger shadow-sm">' + labels.error + err.message + '</div>';
            console.error(err);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const btn = document.querySelector('.simplelogin-hash-btn');
        if (btn) {
            btn.addEventListener('click', runHashPasswords);
        }
    });

})();