import { createAuth0Client } from '@auth0/auth0-spa-js';

function initProfile() {
    const container = document.getElementById('profile-container');

    if (!container) {
        return;
    }

    const AUTH0_DOMAIN = container.getAttribute('data-auth0-domain');
    const AUTH0_CLIENT_ID = container.getAttribute('data-auth0-client-id');
    const API_URL = container.getAttribute('data-api-url');

    const loadingEl = document.getElementById('profile-loading');
    const loginEl = document.getElementById('profile-login');
    const contentEl = document.getElementById('profile-content');
    const errorEl = document.getElementById('profile-error');

    async function init() {
        try {
            const auth0Client = await createAuth0Client({
                domain: AUTH0_DOMAIN,
                clientId: AUTH0_CLIENT_ID,
                cacheLocation: 'localstorage',
                authorizationParams: {
                    redirect_uri: window.location.origin
                }
            });

            const isAuthenticated = await auth0Client.isAuthenticated();

            if (!isAuthenticated) {
                loadingEl.style.display = 'none';
                loginEl.style.display = 'block';

                document.getElementById('profile-login-btn')?.addEventListener('click', async function () {
                    try {
                        await auth0Client.loginWithRedirect({
                            authorizationParams: {
                                redirect_uri: window.location.origin + '/auth/callback'
                            },
                            appState: {
                                returnTo: '/profile'
                            }
                        });
                    } catch (e) {
                        console.error('Login error:', e);
                        showError('Login failed. Please try again.');
                    }
                });

                return;
            }

            const token = await auth0Client.getTokenSilently();
            const response = await fetch(API_URL, {
                headers: {
                    'Authorization': 'Bearer ' + token
                }
            });

            if (!response.ok) {
                throw new Error('Failed to load profile');
            }

            const html = await response.text();
            loadingEl.style.display = 'none';
            contentEl.innerHTML = html;
            contentEl.style.display = 'block';

            document.getElementById('profile-logout-btn')?.addEventListener('click', async function () {
                await auth0Client.logout({ logoutParams: { returnTo: window.location.origin } });
            });
        } catch (e) {
            console.error('Profile error:', e);
            showError('Failed to load profile data.');
        }
    }

    function showError(msg) {
        loadingEl.style.display = 'none';
        errorEl.style.display = 'block';
        document.getElementById('profile-error-message').textContent = msg;
    }

    init();
}

document.addEventListener('turbo:load', initProfile);
