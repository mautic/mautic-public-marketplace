import { createAuth0Client } from '@auth0/auth0-spa-js';

const params = new URLSearchParams(window.location.search);
const AUTH0_DOMAIN = document.body.getAttribute('data-auth0-domain');
const AUTH0_CLIENT_ID = document.body.getAttribute('data-auth0-client-id');

if (params.has('code') && params.has('state') && AUTH0_DOMAIN && AUTH0_CLIENT_ID) {
    (async () => {
        try {
            const auth0Client = await createAuth0Client({
                domain: AUTH0_DOMAIN,
                clientId: AUTH0_CLIENT_ID,
                cacheLocation: 'localstorage',
                authorizationParams: {
                    redirect_uri: window.location.origin + '/auth/callback'
                }
            });

            const result = await auth0Client.handleRedirectCallback();
            const returnTo = result?.appState?.returnTo || '/';

            window.history.replaceState({}, document.title, returnTo);
            if (returnTo !== window.location.pathname) {
                window.location.assign(returnTo);
            }
        } catch (e) {
            console.error('Auth0 redirect error:', e);
        }
    })();
}
