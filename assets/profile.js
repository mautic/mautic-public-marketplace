import { createAuth0Client } from '@auth0/auth0-spa-js';

function initProfile() {
    const container = document.getElementById('profile-container');

    if (!container) {
        return;
    }

    const AUTH0_DOMAIN = container.getAttribute('data-auth0-domain');
    const AUTH0_CLIENT_ID = container.getAttribute('data-auth0-client-id');
    const API_URL = container.getAttribute('data-api-url');

    let auth0Client = null;

    const loadingEl = document.getElementById('profile-loading');
    const loginEl = document.getElementById('profile-login');
    const contentEl = document.getElementById('profile-content');
    const errorEl = document.getElementById('profile-error');

    async function initAuth0() {
        try {
            auth0Client = await createAuth0Client({
                domain: AUTH0_DOMAIN,
                clientId: AUTH0_CLIENT_ID,
                cacheLocation: 'localstorage',
                authorizationParams: {
                    redirect_uri: window.location.origin
                }
            });

            const isAuthenticated = await auth0Client.isAuthenticated();

            if (isAuthenticated) {
                await loadProfile();
            } else {
                loadingEl.style.display = 'none';
                loginEl.style.display = 'block';
            }
        } catch (e) {
            console.error('Auth0 init error:', e);
            showError('Failed to initialize authentication.');
        }
    }

    async function loadProfile() {
        try {
            const token = await auth0Client.getTokenSilently();

            const response = await fetch(API_URL, {
                headers: {
                    'Authorization': 'Bearer ' + token
                }
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || 'Failed to load profile');
            }

            const data = await response.json();
            renderProfile(data);
        } catch (e) {
            console.error('Profile load error:', e);
            showError('Failed to load profile data.');
        }
    }

    function renderProfile(data) {
        loadingEl.style.display = 'none';
        contentEl.style.display = 'block';

        const user = data.user;

        if (user.picture) {
            const avatarEl = document.getElementById('profile-avatar');
            avatarEl.src = user.picture;
            avatarEl.style.display = 'block';
        }

        document.getElementById('profile-name').textContent = user.name || 'Anonymous';
        document.getElementById('profile-email').textContent = user.email || '';

        renderReviews(data.reviews);
        renderPackages(data.uploaded_packages);
    }

    function renderReviews(reviews) {
        const listEl = document.getElementById('profile-reviews-list');

        if (!reviews || reviews.length === 0) {
            listEl.innerHTML = '<p>No reviews yet.</p>';
            return;
        }

        listEl.innerHTML = reviews.map(function (review) {
            const date = new Date(review.created_at).toLocaleDateString();
            const stars = '\u2605'.repeat(review.rating) + '\u2606'.repeat(5 - review.rating);

            return '<div class="profile-review-item mb-16">' +
                '<div><strong>' + escapeHtml(review.objectId) + '</strong> <span>' + stars + '</span></div>' +
                '<p>' + escapeHtml(review.review) + '</p>' +
                '<small>' + date + '</small>' +
                '</div>';
        }).join('');
    }

    function renderPackages(packages) {
        const listEl = document.getElementById('profile-packages-list');

        if (!packages || packages.length === 0) {
            listEl.innerHTML = '<p>No uploaded packages yet.</p>';
            return;
        }

        listEl.innerHTML = packages.map(function (pkg) {
            return '<div class="profile-package-item mb-16">' +
                '<strong>' + escapeHtml(pkg.name) + '</strong>' +
                (pkg.description ? '<p>' + escapeHtml(pkg.description) + '</p>' : '') +
                '</div>';
        }).join('');
    }

    function escapeHtml(text) {
        if (!text) {
            return '';
        }

        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function showError(msg) {
        loadingEl.style.display = 'none';
        errorEl.style.display = 'block';
        document.getElementById('profile-error-message').textContent = msg;
    }

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

    initAuth0();
}

document.addEventListener('turbo:load', initProfile);
