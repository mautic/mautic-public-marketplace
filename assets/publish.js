function initPublish() {
    const container = document.getElementById('publish-container');

    if (!container) {
        return;
    }

    const ASSET_URL = container.getAttribute('data-asset-url');
    const submitBtn = document.getElementById('publish-submit-btn');

    if (!ASSET_URL || !submitBtn) {
        return;
    }

    const errorEl = document.getElementById('publish-error');
    const successEl = document.getElementById('publish-success');

    submitBtn.addEventListener('click', async function () {
        try {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Publishing...';
            hideMessages();

            const response = await fetch('/api/package/submit', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    asset_url: ASSET_URL,
                }),
            });

            let data = null;
            try {
                data = await response.json();
            } catch (e) {
                // Non-JSON response (e.g. HTML error page). Fall through with a generic error below.
            }

            if (!response.ok) {
                const message = (data && data.error)
                    ? data.error
                    : 'Failed to publish package (HTTP ' + response.status + '). Please try again or check that the asset URL is reachable.';
                throw new Error(message);
            }

            if (!data) {
                throw new Error('Unexpected response from the server. Please try again.');
            }

            const action = data.created ? 'published' : 'updated';
            showSuccess('Campaign ' + action + ' successfully as "' + data.package_name + '" (version ' + data.version + '). Redirecting...');

            setTimeout(function () {
                window.location.href = '/package/' + encodeURIComponent(data.package_name);
            }, 2000);
        } catch (err) {
            console.error('Submit error:', err);
            showError(err.message || 'Failed to publish. Please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Publish to Marketplace';
        }
    });

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.style.display = 'block';
        successEl.style.display = 'none';
    }

    function showSuccess(msg) {
        successEl.textContent = msg;
        successEl.style.display = 'block';
        errorEl.style.display = 'none';
    }

    function hideMessages() {
        errorEl.style.display = 'none';
        successEl.style.display = 'none';
    }
}

document.addEventListener('turbo:load', initPublish);
