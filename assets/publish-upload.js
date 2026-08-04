// Mirrors PackageZipUploader::MAX_BYTES. The API rejects anything bigger anyway; this saves
// the popup from streaming a huge archive across first.
const MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;

function initPublishUpload() {
    const container = document.getElementById('publish-upload-container');

    if (!container) {
        return;
    }

    const waitingEl = document.getElementById('publish-upload-waiting');
    const confirmEl = document.getElementById('publish-upload-confirm');
    const confirmBtn = document.getElementById('publish-upload-confirm-btn');
    const filenameEl = document.getElementById('publish-upload-filename');
    const sizeEl = document.getElementById('publish-upload-size');
    const errorEl = document.getElementById('publish-upload-error');
    const successEl = document.getElementById('publish-upload-success');

    // State held in the popup until the user confirms upload. We store the trusted
    // origin from the postMessage event (browser-set, can't be spoofed by page JS).
    let pendingArchive = null;
    let pendingFilename = null;
    let pendingOrigin = null;

    const GENERIC_ERROR = "Something went wrong while publishing. Please try again, or contact support if it keeps happening.";

    function errorMessageForResponse(response, data) {
        if (data && data.error) {
            return data.error;
        }
        switch (response.status) {
            case 413:
                return 'The campaign archive is too large to upload. The maximum allowed size is 50 MB — try removing large images or assets from the campaign and publish again.';
            case 401:
            case 403:
                return 'Your marketplace session has expired or you are not allowed to publish. Please log in again and retry.';
            case 502:
            case 503:
            case 504:
                return 'The marketplace server is temporarily unavailable. Please try again in a few minutes.';
            default:
                return GENERIC_ERROR + ' (HTTP ' + response.status + ')';
        }
    }

    // Notify the Mautic opener that this popup is ready to receive the archive.
    // We use '*' for the targetOrigin because we don't know which Mautic host the
    // user is publishing from — the popup is the trust boundary, not the message.
    if (window.opener && !window.opener.closed) {
        try {
            window.opener.postMessage({ type: 'marketplace:ready' }, '*');
        } catch (e) {
            // Opener may be on an origin that blocks postMessage; not fatal.
        }
    }

    window.addEventListener('message', function (event) {
        if (!event.data || event.data.type !== 'mautic:upload') {
            return;
        }

        const { archive, filename } = event.data;
        if (!(archive instanceof Blob) || archive.size === 0) {
            showError("Mautic didn't send a valid campaign archive. Close this window and try again from Mautic.");
            return;
        }

        if (archive.size > MAX_ARCHIVE_BYTES) {
            showError('This campaign archive is ' + formatBytes(archive.size) + ', which is over the '
                + formatBytes(MAX_ARCHIVE_BYTES) + ' limit. Remove some assets in Mautic and share it again.');
            return;
        }

        pendingArchive = archive;
        pendingFilename = typeof filename === 'string' && filename ? filename : 'campaign.zip';
        pendingOrigin = event.origin;

        filenameEl.textContent = pendingFilename;
        sizeEl.textContent = formatBytes(pendingArchive.size);

        waitingEl.style.display = 'none';
        confirmEl.style.display = 'block';
    });

    confirmBtn.addEventListener('click', async function () {
        if (!pendingArchive) {
            showError("We haven't received the campaign archive yet. Close this window and try again from Mautic.");
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Publishing...';
        hideMessages();

        try {
            const formData = new FormData();
            formData.append('package', pendingArchive, pendingFilename);

            const response = await fetch('/api/package/upload', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: formData,
            });

            let data = null;
            try { data = await response.json(); } catch (e) { /* non-JSON */ }

            if (!response.ok) {
                throw new Error(errorMessageForResponse(response, data));
            }

            if (!data) {
                throw new Error(GENERIC_ERROR);
            }

            // Notify Mautic so it can clear its transient archive and close its dialog.
            if (window.opener && !window.opener.closed) {
                try {
                    window.opener.postMessage({
                        type: 'marketplace:done',
                        package_name: data.package_name,
                        version: data.version,
                        created: data.created,
                    }, pendingOrigin);
                } catch (e) { /* ignore */ }
            }

            const action = data.created ? 'published' : 'updated';
            showSuccess('Campaign ' + action + ' successfully as "' + data.package_name + '" (version ' + data.version + '). Redirecting...');

            setTimeout(function () {
                // Package names are vendor/package — keep the slash literal so the
                // route matches without depending on nginx/Apache decoding %2F.
                var pathSegments = data.package_name.split('/').map(encodeURIComponent).join('/');
                window.location.href = '/package/' + pathSegments;
            }, 2000);
        } catch (err) {
            console.error('Upload error:', err);
            showError(err.message || GENERIC_ERROR);
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm and publish';
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

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }
}

document.addEventListener('turbo:load', initPublishUpload);
