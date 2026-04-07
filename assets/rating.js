import { createAuth0Client } from '@auth0/auth0-spa-js';

function initRating() {
    const container = document.getElementById('marketplace-review-container');

    if (!container) {
        return;
    }

    const AUTH0_DOMAIN = container.getAttribute('data-auth0-domain');
    const AUTH0_CLIENT_ID = container.getAttribute('data-auth0-client-id');
    const API_URL = container.getAttribute('data-api-url');
    const PACKAGE_NAME = container.getAttribute('data-package-name');

    let auth0Client = null;

    const loadingEl = document.getElementById('auth-loading');
    const loginEl = document.getElementById('auth-login');
    const formEl = document.getElementById('auth-form');
    const loginBtn = document.getElementById('auth0-login-btn');
    const logoutBtn = document.getElementById('auth0-logout-btn');
    const userNameEl = document.getElementById('auth-user-name');
    const errorEl = document.getElementById('review-error');
    const successEl = document.getElementById('review-success');
    const submitBtn = document.getElementById('submit-btn');

    function setDisplay(element, value) {
        if (element) {
            element.style.display = value;
        }
    }

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

            if (window.location.search.includes('code=')) {
                await auth0Client.handleRedirectCallback();
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            await updateUI();
        } catch (e) {
            console.error('Auth0 init error:', e);
            showError('Failed to initialize authentication.');
            setDisplay(loadingEl, 'none');
            setDisplay(loginEl, 'block');
        }
    }

    async function updateUI() {
        setDisplay(loadingEl, 'none');

        const isAuthenticated = await auth0Client.isAuthenticated();

        if (isAuthenticated) {
            const user = await auth0Client.getUser();
            if (userNameEl) {
                userNameEl.textContent = user.name || user.email;
            }
            setDisplay(loginEl, 'none');
            setDisplay(formEl, 'block');
        } else {
            setDisplay(loginEl, 'block');
            setDisplay(formEl, 'none');
        }
    }

    loginBtn?.addEventListener('click', async function () {
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

    logoutBtn?.addEventListener('click', async function (e) {
        e.preventDefault();
        await auth0Client.logout({ logoutParams: { returnTo: window.location.origin } });
    });

    const stars = document.querySelectorAll('#rating-stars [data-rating-option]');
    const ratingInput = document.getElementById('rating');

    function setRating(nextRating) {
        ratingInput.value = String(nextRating);
        updateStars(nextRating);
        ratingInput.dispatchEvent(new Event('input', { bubbles: true }));
        ratingInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    stars.forEach(function (star, index) {
        star.addEventListener('click', function () {
            setRating(this.getAttribute('data-rating'));
        });

        star.addEventListener('mouseenter', function () {
            const rating = this.getAttribute('data-rating');
            highlightStars(rating);
        });

        star.addEventListener('mouseleave', function () {
            highlightStars(ratingInput.value);
        });

        star.addEventListener('keydown', function (event) {
            const currentIndex = Number.parseInt(this.getAttribute('data-rating'), 10) - 1;

            if (['ArrowLeft', 'ArrowDown'].includes(event.key)) {
                event.preventDefault();
                stars[Math.max(0, currentIndex - 1)]?.focus();
                setRating(Math.max(1, currentIndex));
            }

            if (['ArrowRight', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                stars[Math.min(stars.length - 1, currentIndex + 1)]?.focus();
                setRating(Math.min(stars.length, currentIndex + 2));
            }

            if ('Home' === event.key) {
                event.preventDefault();
                stars[0]?.focus();
                setRating(1);
            }

            if ('End' === event.key) {
                event.preventDefault();
                stars[stars.length - 1]?.focus();
                setRating(stars.length);
            }

            if (['Enter', ' '].includes(event.key)) {
                event.preventDefault();
                setRating(this.getAttribute('data-rating'));
            }
        });
    });

    function updateStars(rating) {
        const normalizedRating = Number.parseInt(rating, 10) || 0;

        stars.forEach(function (s, index) {
            const isActive = index < normalizedRating;
            const isSelected = index + 1 === normalizedRating;

            s.setAttribute('aria-checked', isSelected ? 'true' : 'false');
            s.setAttribute('tabindex', index === Math.max(0, normalizedRating - 1) ? '0' : (0 === normalizedRating && 0 === index ? '0' : '-1'));

            if (isActive) {
                s.classList.add('review-form__star-button--active');
            } else {
                s.classList.remove('review-form__star-button--active');
            }
        });
    }

    function highlightStars(rating) {
        stars.forEach(function (s, index) {
            if (index < rating) {
                s.classList.add('review-form__star-button--hover');
            } else {
                s.classList.remove('review-form__star-button--hover');
            }
        });
    }

    document.getElementById('review-form').addEventListener('submit', async function (e) {
        if (e.defaultPrevented) {
            return;
        }

        e.preventDefault();

        const rating = parseInt(ratingInput.value);
        const review = document.getElementById('review').value;

        try {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
            hideMessages();

            const token = await auth0Client.getTokenSilently();

            const response = await fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify({
                    package: PACKAGE_NAME,
                    rating: rating,
                    review: review
                })
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || 'Failed to submit review');
            }

            window.location.reload();
        } catch (err) {
            console.error('Submit error:', err);
            showError('Failed to submit review. Please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit review';
        }
    });

    function showError(msg) {
        if (errorEl) {
            errorEl.textContent = msg;
        }
        setDisplay(errorEl, 'block');
        setDisplay(successEl, 'none');
    }

    function showSuccess(msg) {
        if (successEl) {
            successEl.textContent = msg;
        }
        setDisplay(successEl, 'block');
        setDisplay(errorEl, 'none');
    }

    function hideMessages() {
        setDisplay(errorEl, 'none');
        setDisplay(successEl, 'none');
    }

    updateStars(ratingInput.value);
    initAuth0();
}

document.addEventListener('turbo:load', initRating);
