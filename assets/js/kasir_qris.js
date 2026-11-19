(function () {
    var paymentPage = document.querySelector('[data-qris-page]');
    if (!paymentPage) {
        return;
    }

    var status = paymentPage.dataset.qrisStatus;
    var returnUrl = paymentPage.dataset.returnUrl;
    var receiptPattern = /[?&]id=\d+/;
    if (status === 'paid') {
        if (receiptPattern.test(returnUrl || '')) {
            window.setTimeout(function () {
                window.location.replace(returnUrl);
            }, 1000);
        }
        return;
    }

    if (status !== 'pending') {
        return;
    }

    var statusUrl = paymentPage.dataset.statusUrl;
    var statusMessage = document.getElementById('qris-poll-message');

    function checkPaymentStatus() {
        fetch(statusUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            if (response.redirected) {
                window.location.assign(response.url);
                return null;
            }
            if (!response.ok) {
                throw new Error('Status pembayaran tidak dapat diperiksa.');
            }
            return response.json();
        }).then(function (result) {
            if (result === null) {
                return;
            }
            if (result.status === 'paid') {
                statusMessage.textContent = 'Pembayaran diterima. Membuka struk...';
                var receiptUrl = result.receipt_url;
                window.setTimeout(function () {
                    if (receiptUrl && receiptPattern.test(receiptUrl)) {
                        window.location.replace(receiptUrl);
                    } else {
                        // Halaman akan diarahkan server ke struk setelah id transaksi tersedia.
                        window.location.replace(statusUrl.replace('&status_json=1', ''));
                    }
                }, 1000);
                return;
            }
            if (result.status !== 'pending') {
                window.location.reload();
                return;
            }
            window.setTimeout(checkPaymentStatus, 4000);
        }).catch(function () {
            statusMessage.textContent = 'Belum dapat memeriksa status. Sistem akan mencoba kembali.';
            window.setTimeout(checkPaymentStatus, 6000);
        });
    }

    window.setTimeout(checkPaymentStatus, 3000);
})();
