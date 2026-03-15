/**
 * Updated Kaspa Checkout JavaScript - Polling Only (No WebSocket)
 * Simplified version that works with direct wallet payments using AJAX polling
 */

(function () {
    'use strict';

    // Global variables
    let priceUpdateInterval;
    let countdownInterval;
    let paymentCheckInterval;
    let secondsRemaining = 15;
    let paymentCheckActive = false;
    let kaswarePaymentInProgress = false;
    let kaswareTxid = null;

    // Get data from WordPress
    const ajaxUrl = window.kaspaCheckoutData ? window.kaspaCheckoutData.ajaxUrl : '';
    const orderId = window.kaspaCheckoutData ? window.kaspaCheckoutData.orderId : 0;
    const expectedAmount = window.kaspaCheckoutData ? window.kaspaCheckoutData.expectedAmount : 0;
    const paymentNonce = window.kaspaCheckoutData ? window.kaspaCheckoutData.paymentNonce : '';
    const kaswareNonce = window.kaspaCheckoutData ? window.kaspaCheckoutData.kaswareNonce : '';
    const paymentAddress = window.kaspaCheckoutData ? window.kaspaCheckoutData.paymentAddress : '';
    const myAccountUrl = window.kaspaCheckoutData ? window.kaspaCheckoutData.myAccountUrl : '';
    const thankYouUrl = window.kaspaCheckoutData ? window.kaspaCheckoutData.thankYouUrl : '';

    // No WebSocket functions needed - using AJAX polling only

    /**
     * KasWare Browser Wallet Integration
     */
    function detectKasWare() {
        if (typeof window.kasware !== 'undefined') {
            showKasWareButton();
            return;
        }

        // KasWare may inject after DOMContentLoaded — retry a few times
        let attempts = 0;
        const maxAttempts = 10;
        const interval = setInterval(function () {
            attempts++;
            if (typeof window.kasware !== 'undefined') {
                clearInterval(interval);
                showKasWareButton();
            } else if (attempts >= maxAttempts) {
                clearInterval(interval);
            }
        }, 300);
    }

    function showKasWareButton() {
        const section = document.getElementById('kaspa-kasware-section');
        const divider = document.getElementById('kaspa-kasware-divider');
        if (section) {
            section.classList.add('detected');
        }
        if (divider) {
            divider.style.display = 'flex';
        }

        // Attach click handler
        const btn = document.getElementById('kaspa-kasware-btn');
        if (btn) {
            btn.addEventListener('click', handleKasWarePay);

            // Disable button until address is ready
            if (!getPaymentAddress()) {
                btn.disabled = true;
                btn.textContent = 'Waiting for address...';
                const addressPoll = setInterval(function () {
                    if (getPaymentAddress()) {
                        clearInterval(addressPoll);
                        btn.disabled = false;
                        btn.textContent = 'Pay ' + expectedAmount + ' KAS with KasWare';
                    }
                }, 500);
            }
        }
    }

    function getPaymentAddress() {
        // First try the address display on the page (most up-to-date, set by address generation JS)
        const addressEl = document.getElementById('kaspa-address-display');
        if (addressEl) {
            const text = (addressEl.textContent || '').trim();
            if (text.startsWith('kaspa:')) {
                return text;
            }
        }
        // Fall back to the address passed from PHP
        if (paymentAddress && paymentAddress.startsWith('kaspa:')) {
            return paymentAddress;
        }
        return null;
    }

    function kasToSompi(kasAmount) {
        // 1 KAS = 100,000,000 sompi
        // Use string math to avoid floating point issues
        var parts = String(kasAmount).split('.');
        var whole = parts[0] || '0';
        var frac = parts[1] || '';
        // Pad or truncate to 8 decimal places
        frac = (frac + '00000000').substring(0, 8);
        var sompiStr = whole + frac;
        // Remove leading zeros
        sompiStr = sompiStr.replace(/^0+/, '') || '0';
        return parseInt(sompiStr, 10);
    }

    async function handleKasWarePay() {
        if (kaswarePaymentInProgress) return;

        var btn = document.getElementById('kaspa-kasware-btn');
        var statusEl = document.getElementById('kaspa-kasware-status');

        // Check address is ready
        var address = getPaymentAddress();
        if (!address) {
            if (statusEl) statusEl.textContent = 'Payment address is still generating. Please wait a moment and try again.';
            return;
        }

        kaswarePaymentInProgress = true;
        btn.disabled = true;
        btn.className = 'kaspa-kasware-btn connecting';
        btn.textContent = 'Connecting to KasWare...';
        if (statusEl) statusEl.textContent = '';

        try {
            // Step 1: Connect — request account access
            var accounts = await window.kasware.requestAccounts();
            if (!accounts || accounts.length === 0) {
                throw new Error('No accounts returned from KasWare');
            }

            // Step 2: Send payment
            var sompiAmount = kasToSompi(expectedAmount);
            btn.textContent = 'Confirm in KasWare...';
            if (statusEl) statusEl.textContent = 'Please confirm the transaction in the KasWare popup.';

            var txResult = await window.kasware.sendKaspa(address, sompiAmount, { priorityFee: 0 });

            // KasWare may return a JSON string of the full tx, an object, or just the txid
            var txid;
            if (typeof txResult === 'string') {
                try {
                    var parsed = JSON.parse(txResult);
                    txid = parsed.id || txResult;
                } catch (e) {
                    txid = txResult;
                }
            } else if (txResult && typeof txResult === 'object' && txResult.id) {
                txid = txResult.id;
            }

            if (!txid || typeof txid !== 'string' || !/^[a-f0-9]{64}$/i.test(txid)) {
                throw new Error('Invalid transaction ID returned from KasWare');
            }

            // Step 3: Payment sent — start fast verification
            kaswareTxid = txid;
            btn.className = 'kaspa-kasware-btn success';
            btn.textContent = 'Payment sent!';
            if (statusEl) {
                statusEl.className = 'kaspa-kasware-status verifying';
                statusEl.innerHTML = '<span class="kaspa-kasware-spinner"></span> Verifying on blockchain...';
            }

            // Stop any existing polling
            stopPaymentChecking();
            updatePaymentStatus('Payment sent! Confirming on blockchain...', 'checking');

            // Notify server of txid (fire-and-forget, don't wait)
            notifyServerTxid(txid);

            // Start fast 1s polling immediately — don't wait for server response
            startFastPaymentPolling();

        } catch (err) {
            kaswarePaymentInProgress = false;
            btn.disabled = false;
            btn.className = 'kaspa-kasware-btn';
            btn.textContent = 'Pay ' + expectedAmount + ' KAS with KasWare';

            var errorMsg = err.message || String(err);
            // User rejected the request
            if (errorMsg.toLowerCase().includes('reject') || errorMsg.toLowerCase().includes('cancel') || errorMsg.toLowerCase().includes('denied')) {
                if (statusEl) statusEl.textContent = 'Transaction cancelled. Click to try again.';
            } else if (errorMsg.toLowerCase().includes('insufficient')) {
                if (statusEl) statusEl.textContent = 'Insufficient funds in your KasWare wallet.';
            } else if (errorMsg.toLowerCase().includes('storage mass')) {
                if (statusEl) statusEl.textContent = 'Wallet has too many small UTXOs. Send your full balance to yourself in KasWare to consolidate, then try again.';
            } else {
                if (statusEl) statusEl.textContent = 'Error: ' + errorMsg;
            }
        }
    }

    // Track whether the server has confirmed the order yet
    var serverVerified = false;

    /**
     * Notify server of the KasWare txid. If the Kaspa API hasn't indexed the tx
     * yet, it retries up to 5 times at 1s intervals until verification succeeds.
     */
    function notifyServerTxid(txid, attempt) {
        attempt = attempt || 1;
        var maxAttempts = 5;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success && response.data.status === 'completed') {
                            serverVerified = true;
                            handlePaymentConfirmed({ txid: txid, status: 'completed' });
                            return;
                        }
                    } catch (e) {}
                }
                // Not verified yet — retry after 1s
                if (attempt < maxAttempts && !serverVerified) {
                    setTimeout(function () {
                        notifyServerTxid(txid, attempt + 1);
                    }, 1000);
                }
            }
        };
        var data = 'action=kasppaga_kasware_confirm&order_id=' + orderId + '&txid=' + encodeURIComponent(txid) + '&nonce=' + kaswareNonce;
        xhr.send(data);
    }

    /**
     * Fast polling after KasWare payment — 1s lightweight DB checks for 15s.
     * Runs in parallel with notify retries. Whichever confirms first wins.
     */
    function startFastPaymentPolling() {
        var fastPollCount = 0;
        var maxFastPolls = 15;

        paymentCheckActive = true;
        fastCheckTxid();

        paymentCheckInterval = setInterval(function () {
            if (serverVerified) {
                clearInterval(paymentCheckInterval);
                paymentCheckInterval = null;
                paymentCheckActive = false;
                return;
            }
            fastPollCount++;
            if (fastPollCount >= maxFastPolls) {
                clearInterval(paymentCheckInterval);
                paymentCheckInterval = null;
                paymentCheckActive = false;
                startPaymentMonitoring();
                return;
            }
            fastCheckTxid();
        }, 1000);
    }

    /**
     * Lightweight order status check — just reads DB, no Kaspa API call.
     */
    function fastCheckTxid() {
        if (serverVerified) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success && response.data.status === 'completed') {
                        serverVerified = true;
                        handlePaymentConfirmed({ txid: kaswareTxid || '', status: 'completed' });
                    }
                } catch (e) {}
            }
        };
        var data = 'action=kasppaga_order_status&order_id=' + orderId + '&nonce=' + kaswareNonce;
        xhr.send(data);
    }

    /**
     * Initialize the checkout system
     */
    function initializeCheckout() {
        // Detect KasWare browser extension
        detectKasWare();

        // Start price updates if price widget exists
        if (document.getElementById('kaspa-current-price')) {
            startPriceUpdates();
        }

        // Show initial status message
        updatePaymentStatus('⏳ Setting up payment address...', 'checking');

        // Delay payment monitoring to allow address generation (15 seconds)
        setTimeout(function () {
            startPaymentMonitoring();
        }, 15000); // Wait 15 seconds before first check

        // Set up cleanup on page unload
        window.addEventListener('beforeunload', cleanup);
    }

    /**
     * Start monitoring for payment (AJAX polling)
     */
    function startPaymentMonitoring() {
        if (paymentCheckActive || !orderId) {
            return;
        }

        paymentCheckActive = true;

        // Update status message
        updatePaymentStatus('🔄 Monitoring for payment...', 'checking');

        // Check immediately (after the 15 second delay), then every 5 seconds
        checkPaymentStatus();
        paymentCheckInterval = setInterval(checkPaymentStatus, 5000);

        // Stop after 30 minutes
        setTimeout(function () {
            stopPaymentChecking();
            updatePaymentStatus('⏰ Payment monitoring timeout. Please contact support if you sent payment.', 'timeout');
        }, 1800000); // 30 minutes
    }

    /**
     * Check payment status via AJAX polling
     */
    function checkPaymentStatus() {
        if (!paymentCheckActive || !ajaxUrl) {
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            handlePaymentResponse(response.data);
                        } else {
                            // Don't show error if address is still being generated
                            const errorMsg = response.data || '';
                            if (errorMsg.includes('Missing payment information') || errorMsg.includes('Generating')) {
                                updatePaymentStatus('⏳ Setting up payment address...', 'checking');
                            } else {
                                console.error('Payment check failed:', response.data);
                                updatePaymentStatus('❌ Payment check failed: ' + response.data, 'error');
                            }
                        }
                    } catch (e) {
                        console.error('Payment check parse error:', e);
                        updatePaymentStatus('❌ Error checking payment status', 'error');
                    }
                } else {
                    console.error('Payment check HTTP error:', xhr.status);
                    updatePaymentStatus('❌ Network error checking payment', 'error');
                }
            }
        };

        const data = `action=kasppaga_check_payment&order_id=${orderId}&nonce=${paymentNonce}`;
        xhr.send(data);
    }

    /**
     * Handle payment response from AJAX polling
     */
    function handlePaymentResponse(data) {
        if (data.status === 'completed') {
            handlePaymentConfirmed(data);
        } else if (data.status === 'pending') {
            updatePaymentStatus(data.message || '⏳ Waiting for payment...', 'checking');
        } else {
            updatePaymentStatus(data.message || '❓ Unknown payment status', 'error');
        }
    }

    /**
     * Handle payment confirmation from AJAX polling
     */
    function handlePaymentConfirmed(data) {
        stopPaymentChecking();
        updatePaymentStatus('✅ Payment confirmed! Your order is being processed.', 'success');
        showNotification('🎉 Payment received and confirmed!', 'success');

        // Show success details if available
        if (data.txid) {
            const statusEl = document.getElementById('kaspa-payment-status');
            if (statusEl) {
                statusEl.innerHTML += `<br><small>Transaction: ${data.txid}</small>`;
            }
        }

        // Redirect to thank you page after a few seconds
        setTimeout(function () {
            if (thankYouUrl) {
                // Redirect to WooCommerce thank you page (order received page)
                window.location.href = thankYouUrl;
            } else if (myAccountUrl) {
                // Fallback to account page if thank you URL not available
                window.location.href = myAccountUrl;
            } else {
                // Last resort: reload the page
                window.location.reload();
            }
        }, 3000);
    }

    /**
     * Update payment status display
     */
    function updatePaymentStatus(message, status) {
        const statusEl = document.getElementById('kaspa-payment-status');
        if (statusEl) {
            statusEl.textContent = message;
            statusEl.className = 'kaspa-payment-status ' + status;
        }
    }

    /**
     * Stop payment checking
     */
    function stopPaymentChecking() {
        paymentCheckActive = false;
        if (paymentCheckInterval) {
            clearInterval(paymentCheckInterval);
            paymentCheckInterval = null;
        }
    }

    /**
     * Live pricing functionality
     */
    function startPriceUpdates() {
        if (!ajaxUrl) return;

        priceUpdateInterval = setInterval(updateKaspaPrice, 15000);
        startCountdown();
    }

    function startCountdown() {
        secondsRemaining = 15;
        updateCountdownDisplay();

        countdownInterval = setInterval(function () {
            secondsRemaining--;
            updateCountdownDisplay();

            if (secondsRemaining <= 0) {
                clearInterval(countdownInterval);
            }
        }, 1000);
    }

    function updateCountdownDisplay() {
        const countdownEl = document.getElementById('kaspa-countdown');
        const progressEl = document.getElementById('kaspa-progress');

        if (countdownEl) {
            countdownEl.textContent = secondsRemaining > 0 ? secondsRemaining + 's' : 'Updating...';
        }

        if (progressEl) {
            const progressPercent = ((15 - secondsRemaining) / 15) * 100;
            progressEl.style.width = progressPercent + '%';
        }
    }

    function updateKaspaPrice() {
        if (!ajaxUrl) return;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success && response.data) {
                        const priceEl = document.getElementById('kaspa-current-price');
                        if (priceEl) {
                            priceEl.textContent = '$' + parseFloat(response.data.rate).toFixed(5);
                            priceEl.classList.add('price-updating');
                            setTimeout(() => priceEl.classList.remove('price-updating'), 300);
                        }

                        const timeEl = document.getElementById('kaspa-last-update');
                        if (timeEl && response.data.time_formatted) {
                            timeEl.textContent = 'Updated: ' + response.data.time_formatted;
                        }

                        startCountdown();
                    } else {
                        console.error('Price update failed:', response.data);
                        setTimeout(startCountdown, 5000);
                    }
                } catch (e) {
                    console.error('Price update parse error:', e);
                    setTimeout(startCountdown, 5000);
                }
            } else if (xhr.readyState === 4) {
                console.error('Price update HTTP error:', xhr.status);
                setTimeout(startCountdown, 5000);
            }
        };

        xhr.send('action=get_kasppaga_price');
    }

    /**
     * Copy to clipboard functionality
     */
    window.copyToClipboard = function (text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showNotification('✅ Copied to clipboard!', 'success');
            }).catch(function () {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    };

    function fallbackCopy(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showNotification('✅ Copied to clipboard!', 'success');
            } else {
                showNotification('❌ Copy failed - please select manually', 'error');
            }
        } catch (err) {
            console.error('Copy failed:', err);
            showNotification('❌ Copy failed - please select manually', 'error');
        }

        document.body.removeChild(textArea);
    }

    /**
     * Manual payment check (for button click)
     */
    window.checkPaymentStatus = function () {
        const button = document.getElementById('kaspa-check-button');
        if (button) {
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Checking...';

            checkPaymentStatus();

            setTimeout(() => {
                button.disabled = false;
                button.textContent = originalText;
            }, 3000);
        } else {
            checkPaymentStatus();
        }
    };

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.kaspa-notification');
        existingNotifications.forEach(n => n.remove());

        const notification = document.createElement('div');
        notification.className = `kaspa-notification kaspa-notification-${type}`;
        notification.textContent = message;

        notification.style.cssText = `
            position: fixed; 
            top: 20px; 
            right: 20px; 
            background: ${getNotificationColor(type)}; 
            color: white; 
            padding: 12px 20px; 
            border-radius: 6px; 
            z-index: 9999; 
            font-weight: bold; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        `;

        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.style.transform = 'translateX(400px)';
            setTimeout(() => {
                if (document.body.contains(notification)) {
                    document.body.removeChild(notification);
                }
            }, 300);
        }, 5000);
    }

    function getNotificationColor(type) {
        switch (type) {
            case 'success': return '#28a745';
            case 'error': return '#dc3545';
            case 'warning': return '#ffc107';
            case 'info':
            default: return '#17a2b8';
        }
    }

    /**
     * Cleanup function
     */
    function cleanup() {
        if (priceUpdateInterval) clearInterval(priceUpdateInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        if (paymentCheckInterval) clearInterval(paymentCheckInterval);
    }

    /**
     * Enhanced error handling
     */
    window.addEventListener('error', function (e) {
        console.error('Kaspa Checkout Error:', e.error);
    });

    /**
     * Handle page visibility changes
     */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (priceUpdateInterval) clearInterval(priceUpdateInterval);
            if (countdownInterval) clearInterval(countdownInterval);
        } else {
            if (document.getElementById('kaspa-current-price')) {
                startPriceUpdates();
            }
        }
    });

    /**
     * Mobile-specific enhancements
     */
    function setupMobileEnhancements() {
        // Add touch feedback for copy buttons
        const copyFields = document.querySelectorAll('.kaspa-copy-field');
        copyFields.forEach(field => {
            field.addEventListener('touchstart', function () {
                this.style.background = '#e3f2fd';
            });

            field.addEventListener('touchend', function () {
                setTimeout(() => {
                    this.style.background = '';
                }, 150);
            });
        });
    }

    /**
     * Accessibility enhancements
     */
    function setupAccessibility() {
        // Add ARIA labels to interactive elements
        const copyFields = document.querySelectorAll('.kaspa-copy-field');
        copyFields.forEach((field, index) => {
            field.setAttribute('role', 'button');
            field.setAttribute('tabindex', '0');
            field.setAttribute('aria-label', `Copy ${field.querySelector('.kaspa-copy-text')?.textContent || 'text'} to clipboard`);

            // Add keyboard support
            field.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    field.click();
                }
            });
        });

        // Add live region for payment status updates
        const statusEl = document.getElementById('kaspa-payment-status');
        if (statusEl) {
            statusEl.setAttribute('aria-live', 'polite');
            statusEl.setAttribute('aria-atomic', 'true');
        }
    }

    // No WebSocket monitoring needed

    /**
     * Apply Pro UI customizations (accent color, button text, instructions, fee label).
     * Runs after DOM is ready so elements exist.
     */
    function applyProCustomizations() {
        const d = window.kaspaCheckoutData || {};

        // Accent color override — inject a style tag
        if (d.proAccentColor) {
            const style = document.createElement('style');
            style.textContent =
                '.kaspa-kasware-btn { background: ' + d.proAccentColor + ' !important; color: #0a0e1a !important; }' +
                '.kaspa-payment-header-compact h2 { color: ' + d.proAccentColor + ' !important; }' +
                '.kaspa-copy-button-compact { color: ' + d.proAccentColor + ' !important; }';
            document.head.appendChild(style);
        }

        // Custom KasWare button text
        if (d.proButtonText) {
            const btn = document.getElementById('kaspa-kasware-btn');
            if (btn && !btn.disabled) {
                btn.textContent = d.proButtonText + ' — ' + (d.expectedAmount || '') + ' KAS';
            }
        }

        // Custom instructions (replaces the instructions block content)
        if (d.proInstructions) {
            const instrEl = document.querySelector('.kaspa-instructions-compact');
            if (instrEl) {
                instrEl.innerHTML = '<p>' + d.proInstructions + '</p>';
            }
        }

        // Fee label — appended below the instructions block
        if (d.feeLabel) {
            const instrEl = document.querySelector('.kaspa-instructions-compact');
            if (instrEl) {
                const feeLine = document.createElement('p');
                feeLine.style.cssText = 'font-size:12px;color:#888;margin:6px 0 0;';
                feeLine.textContent = d.feeLabel;
                instrEl.appendChild(feeLine);
            }
        }
    }

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initializeCheckout();
            setupMobileEnhancements();
            setupAccessibility();
            applyProCustomizations();
        });
    } else {
        initializeCheckout();
        setupMobileEnhancements();
        setupAccessibility();
        applyProCustomizations();
    }

})();