import { createAuth0Client } from '@auth0/auth0-spa-js';

function initPublish() {
    const container = document.getElementById('publish-container');

    if (!container) {
        return;
    }

    const AUTH0_DOMAIN = container.getAttribute('data-auth0-domain');
    const AUTH0_CLIENT_ID = container.getAttribute('data-auth0-client-id');
    const API_URL = container.getAttribute('data-api-url');
    const ASSET_URL = container.getAttribute('data-asset-url');

    if (!ASSET_URL) {
        return;
    }

    let auth0Client = null;

    const loadingEl = document.getElementById('auth-loading');
    const loginEl = document.getElementById('publish-login');
    const formEl = document.getElementById('publish-form');
    const userNameEl = document.getElementById('publish-user-name');
    const errorEl = document.getElementById('publish-error');
    const successEl = document.getElementById('publish-success');
    const submitBtn = document.getElementById('publish-submit-btn');

    async function initAuth0() {
        try {
            auth0Client = await createAuth0Client({
                domain: AUTH0_DOMAIN,
                clientId: AUTH0_CLIENT_ID,
                cacheLocation: 'localstorage',
                authorizationParams: {
                    redirect_uri: window.location.origin + '/auth/callback'
                }
            });

            await updateUI();
        } catch (e) {
            console.error('Auth0 init error:', e);
            showError('Failed to initialize authentication.');
            loadingEl.style.display = 'none';
            loginEl.style.display = 'block';
        }
    }

    async function updateUI() {
        loadingEl.style.display = 'none';

        const isAuthenticated = await auth0Client.isAuthenticated();

        if (isAuthenticated) {
            const user = await auth0Client.getUser();
            userNameEl.textContent = user.name || user.email;
            loginEl.style.display = 'none';
            formEl.style.display = 'block';
        } else {
            loginEl.style.display = 'block';
            formEl.style.display = 'none';
        }
    }

    document.getElementById('publish-login-btn').addEventListener('click', async function () {
        try {
            await auth0Client.loginWithRedirect({
                authorizationParams: {
                    redirect_uri: window.location.origin + '/auth/callback'
                },
                appState: {
                    returnTo: window.location.pathname + window.location.search
                }
            });
        } catch (e) {
            console.error('Login error:', e);
            showError('Login failed. Please try again.');
        }
    });

    document.getElementById('publish-logout-btn').addEventListener('click', async function (e) {
        e.preventDefault();
        await auth0Client.logout({ logoutParams: { returnTo: window.location.origin } });
    });

    submitBtn.addEventListener('click', async function () {
        try {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Publishing...';
            hideMessages();

            const token = await auth0Client.getTokenSilently();

            const response = await fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify({
                    asset_url: ASSET_URL
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Failed to publish package');
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

    initAuth0();
}

document.addEventListener('turbo:load', initPublish);
