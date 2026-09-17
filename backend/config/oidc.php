<?php

/*
|--------------------------------------------------------------------------
| OpenID Connect
|--------------------------------------------------------------------------
|
| The federated login of ADR-011. The backend is a confidential Relying Party
| using the Authorization Code Flow with PKCE and requests only the `openid`
| scope. Nothing here is read at boot: a missing value makes the login fail
| closed only when it is used. The client secret is a secret and never belongs
| in version control.
|
*/

return [

    'issuer' => env('OIDC_ISSUER'),

    'client_id' => env('OIDC_CLIENT_ID'),

    'client_secret' => env('OIDC_CLIENT_SECRET'),

    'redirect_uri' => env('OIDC_REDIRECT_URI'),

];
