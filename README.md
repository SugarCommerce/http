# http

A request, a response, a router and a template renderer for plain PHP.

Small enough to read in one sitting, complete enough that an application never
touches a superglobal again. No middleware stack, no container, no PSR-7
adapters, no route caching.

```bash
composer require sugarcommerce/http
```

## The shape

```php
use SugarCommerce\Http\{Request, Response, Router};

$request = Request::capture();

$router = new Router();
$router->get( '/orders/{id}', fn( $r, $id ) => Response::json( $this->order( $id ) ) );
$router->post( '/orders', fn( $r ) => $this->create( $r ) );

( $router->dispatch( $request ) ?? Response::error( 'No such endpoint.', 404 ) )->send();
```

`dispatch()` returns null rather than a 404, so the caller decides what nothing
matched looks like — an HTML page on one surface, a JSON body on another.

A segment can carry a constraint the whole segment has to match. When it does
not, the route does not match and the next one is tried:

```php
$router->get( '/orders/{id:\d+}', fn( $r, $id ) => $this->order( (int) $id ) );
$router->get( '/orders/{ref:[A-Z0-9-]{1,64}}', fn( $r, $ref ) => $this->by_reference( $ref ) );
```

A constraint cannot contain `/`. One that does not compile, or a segment left
unclosed, throws when the route is registered rather than never matching.

## Groups

Routes behind the same check are registered together, and the check runs
before any of their handlers. It returns a `Response` to refuse, or a value —
usually the authenticated caller — that each handler receives as its second
argument. A handler that forgot to check cannot exist.

```php
$router->group(
    fn( Request $r ) => $auth->account( $r ) ?? Response::json( array( 'error' => 'Sign in' ), 401 ),
    function ( Router $routes ): void {
        $routes->get( '/api/files', fn( Request $r, string $account ) => files( $account ) );
        $routes->post( '/api/files/{id}/delete', fn( Request $r, string $account, string $id ) => remove( $account, $id ) );
    }
);
```

## Request

Captured once, immutable afterwards. The point is that nothing downstream
reaches for `$_SERVER`: a handler that does cannot be tested without one, and a
test that has to build `$_SERVER` is a test nobody writes.

```php
$request->method;                  // 'POST'
$request->path;                    // '/orders/7'
$request->header( 'X-Signature' ); // any case
$request->cookie( 'session' );     // '' when it is not there
$request->json();                  // decoded body, or array()
$request->input( 'email' );        // form body, then JSON, then query string
$request->has( 'newsletter' );     // submitted at all? (an unchecked box is not)
$request->is_https( TrustedProxies::cloudflare() ); // X-Forwarded-Proto only from them
$request->ip( TrustedProxies::cloudflare() );
$request->bearer();                // `Authorization: Bearer …`, or ''
$request->cookie_path();           // '/', or the surface's prefix
$request->country( TrustedProxies::cloudflare() ); // 'DE', or ''
$request->is_local();              // loopback, and nothing forwarded it
$request->wants_html();            // a browser (or no Accept header), not a JSON client
$request->origin( $site_url );     // 'https://shop.example' — the configured URL wins;
                                   // the Host header is only a fallback for local dev
Request::capture( $surfaces, max_body: 65536 ); // a larger body reads as empty
Request::local_path( $request->input( 'return' ), '/orders' ); // a ?return= that stays on this site
$request->server_vars();           // $_SERVER-shaped, for other libraries
$request->uri_path;                // the path as sent, before surfaces
```

`input()` checks the form body **first**, and that ordering is not cosmetic. An
HTML form posts form-encoded; a version of this that read only JSON handed every
form an empty string, and saved the blanks without an error anywhere, because a
blank string is a valid string.

`ip()` goes through [network](https://github.com/sugarcommerce/network)'s
`ClientIp` rather than reading a header, because the rule is easy to get wrong:
a forwarding header may only be believed when `REMOTE_ADDR` is inside a trusted
range. Trusting `CF-Connecting-IP` unconditionally means anyone who reaches the
origin directly — a misconfigured firewall, a leaked origin address — can claim
to be any address they like, which poisons every log and every rate limit keyed
on it. The default is to trust nothing; name your proxies.

`country()` is believed the same way: `CF-IPCountry` from anywhere but the
proxies you name is a header anybody can type, and a shopper could pick their
own prices with it.

`is_local()` is for the development conveniences that must never be reachable
from outside, and it deliberately **never** consults a forwarding header. A
client can put `127.0.0.1` in `X-Forwarded-For` and an edge will pass it along;
a tunnel or reverse proxy on the same box arrives from loopback carrying exactly
those headers. So the socket address must be loopback and nothing may have
forwarded the request at all. An empty address is never local: "we could not
tell" is not "this machine".

`bearer()` reads the header only. A key in a URL ends up in logs, and one in a
body is one a form on another site could be made to send.

`cookie_path()` keeps a prefixed surface's cookies under its prefix, so a
seller's session does not ride along on every buyer download when every
surface shares one hostname locally.

## Cookies

A cookie set from a handler is a header the Response carries, so it can be
tested and thrown away like any other:

```php
Response::redirect( '/orders' )->with( array(
    'Set-Cookie' => Cookie::header( 'session', $value, $session->cookie_options( $request->secure ) ),
) );
```

`header()` takes setcookie()'s own options array. It refuses a name that is not
a token and a SameSite that is not Strict, Lax or None — some browsers fall back
to None on a value they do not know — and strips control characters from every
attribute, so nothing in one can add attributes or a second header. An expiry
becomes both `Expires` and `Max-Age`.

A plain PHP page that sends its own headers can do the whole job in one call:

```php
$secure = $request->is_https( TrustedProxies::cloudflare() );

Cookie::set( 'theme', 'dark', 86_400 * 365, array( 'secure' => $secure ) );
Cookie::clear( 'theme', array( 'path' => '/' ) ); // the path it was set with
Cookie::read( 'theme' );                          // from $_COOKIE; '' when absent

Cookie::set_header( 'theme', 'dark', 3600 );      // the same lines, unsent
Cookie::clear_header( 'theme' );
```

`set()` and `clear()` default to `Path=/`, `HttpOnly` and `SameSite=Lax`, and
send through `header()` above, so the same checks apply; several cookies in one
response each get their own line. `Secure` is off unless you pass it, because a
Secure cookie on a plain-HTTP development host is dropped by the browser without
a word — pass it from the request. A `$max_age` of 0 is a session cookie;
below 0 clears it.

`clear()` has to be given the path and domain the cookie was set with. A browser
keys a cookie on its name, domain and path together: clearing `/` does nothing to
a cookie set at `/admin`, and the visitor stays signed in after signing out.

`read()` treats a cookie sent as `name[]=x`, which PHP hands over as an array, as
not sent.

## Surfaces

One codebase serving several audiences usually wants them on separate hostnames
in production and on one port in development. Writing that twice is how the two
drift until something only reproduces on the server.

```php
$surfaces = new Surfaces( array( 'api', 'admin' ), default: 'site', keep_prefix: array( 'api' ) );

$request = Request::capture( $surfaces );
```

| Request | surface | path | prefix |
|---|---|---|---|
| `admin.example.com/orders` | `admin` | `/orders` | `` |
| `localhost/admin/orders` | `admin` | `/orders` | `/admin` |
| `api.example.com/orders` | `api` | `/api/orders` | `` |
| `localhost/api/orders` | `api` | `/api/orders` | `/api` |
| `example.com/pricing` | `site` | `/pricing` | `` |

Both deployments arrive at the same path, so one routing table serves both.
`keep_prefix` is for an API whose published routes already include `/api`, so
clients and CSP entries do not have to differ by environment.

Wherever a hostname is a boundary — an access policy at the CDN guarding
`admin.example.com`, say — name the hosts path prefixes are for. Otherwise
`www.example.com/admin` reaches the admin surface too, around the guard:

```php
new Surfaces( array( 'api', 'admin' ), default: 'site', path_hosts: array( 'localhost', '127.0.0.1' ) );
```

The prefix comes back separately so a surface can rebuild its own links.
Use `$request->url( '/orders' )` for every redirect and form action — the ones
you forget work on the server and not on your machine.

## Response

Built and returned, not echoed. A handler that writes output directly cannot be
tested without capturing the output buffer, and cannot be wrapped by anything
that wants to add a header afterwards.

```php
Response::json( $data );
Response::error( 'Not authorised.', 401 );
Response::error( 'Over your plan limit.', 402, array( 'reason' => 'plan_limit' ) );
Response::html( $markup );
Response::text( $body );
Response::redirect( '/somewhere' );
Response::preflight( Headers::cors() );
Response::download( $csv, 'text/csv; charset=utf-8', ContentDisposition::attachment( $name ) );

$response->with( array( 'Retry-After' => '60' ) );

Response::defer( fn() => $mailer->send( $receipt ) ); // after the visitor has their page
```

`error()` exists because the alternative is what every API ends up with: the
same literal string written out at twenty call sites, three of which say
something slightly different for no reason. Existing headers win in `with()`,
so a handler cannot have its content type rewritten by something wrapping it.

`download()` is `private, no-store` and `nosniff`: a file built per request is
somebody's own data, and a CSV that looks like HTML must never be rendered as a
page. It takes the Content-Disposition value whole. Building one properly is
RFC 6266 — an ASCII fallback plus `filename*=UTF-8''…`, with quotes and control
characters handled — and that already lives in
[s3-signer](https://github.com/sugarcommerce/s3-signer)'s
`ContentDisposition::attachment()`. Use it rather than writing a second copy.

`redirect()` goes to a path on this site or an absolute `http`/`https` URL, and
throws on anything else: `//evil.example` and `/\evil.example` are other sites
to a browser, `javascript:` and `data:` are not places at all, and a relative
`orders` resolves against wherever the visitor happens to be. A local path is
held to `Request::local_path()`'s rules. An absolute URL is still used as given,
so a `?return=` the visitor sent goes through `local_path()` first.

`defer()` is the one place this package schedules work. Under PHP-FPM the
connection is closed first, so the visitor neither waits for the work nor can
time it; elsewhere it still runs, just before the process ends.

## Headers

So that sniff guards, framing protection, referrer policy and a content
security policy are not things an individual handler can forget.

```php
Headers::html();                    // strict CSP with a nonce
Headers::html( array( 'script-src' => array( 'https://cdn.example.com' ) ) );
Headers::json();                    // no-store, nosniff, no CORS
Headers::cors( 'https://example.com' );
Headers::with_hsts( $headers, $request->is_https() );
Headers::nonce();                   // for the one inline script you meant
Headers::nonce_attribute();         // ' nonce="…"', ready for the tag
Headers::html( frame_ancestors: '*' );   // a card other sites may frame
Headers::embeddable( $headers );         // the same, to headers you have
Headers::merge_sources( $uploads, $viewer ); // directive => sources, appended
Headers::cors_for( $request->header( 'origin' ), $sellers_sites );
Headers::strict( $directives, $nonce );  // 'self' only, sources replaced
```

The defaults are strict — no CORS, no CDN, no inline script — and everything is
opened up by argument. A permissive default is one nobody ever tightens.

Extra CSP sources are **appended** to a directive rather than replacing it,
which is what keeps the nonce in place. `Headers::html()` builds that nonce
once per request; render it into the one `<script>` you meant to allow.

### A strict baseline

`html()` starts permissive enough to work anywhere — `'unsafe-inline'` styles,
images from any `https:` host — and can only add to that. `strict()` starts from
`'self'` alone and **replaces** a directive's sources, for an application that
knows exactly what it loads:

```php
$headers = Headers::strict(
    array(
        'script-src' => array( "'self'", 'https://challenges.cloudflare.com' ),
        'style-src'  => array( "'self'", 'https://fonts.googleapis.com' ),
        'frame-src'  => 'https://challenges.cloudflare.com',
        'img-src'    => null,                            // left out entirely
    ),
    nonce: $nonce,                                       // default: Headers::nonce()
    referrer: 'strict-origin-when-cross-origin',         // the default
    permissions: array( 'geolocation', 'microphone', 'camera', 'payment' ), // the default
);
```

The baseline is `default-src`, `style-src`, `font-src`, `connect-src`,
`form-action` and `base-uri` of `'self'`, `script-src 'self'` plus the nonce,
`img-src 'self' data:` and `frame-ancestors 'none'`. The directives you name
come first, in your order, then whatever of the baseline you did not mention —
so a policy written out in full comes back byte for byte as written. The nonce
stays in `script-src` however it is replaced (after `'self'`, or first), because
a script-src without it drops every inline script silently. There is no
Content-Type: these headers belong on JSON and text responses as much as pages.
A referrer policy browsers do not know, or a nonce that is not base64, throws —
the browser would ignore the header rather than fall back to anything stricter.

`with_hsts()` only adds the header over TLS. Sending it on plain HTTP is
meaningless, and on a local host actively harmful — the browser remembers it and
then refuses to load `http://localhost` for six months, for every project you
have.

Framing is refused by default (`frame-ancestors 'none'` and `X-Frame-Options:
DENY`), which is what stops a page being laid under a transparent button of
somebody else's. A page meant to be framed names who may frame it, and
`X-Frame-Options` goes — it can only say DENY or SAMEORIGIN, and would go on
refusing whatever the policy allowed. `embeddable()` parses the policy and
replaces the one directive, rather than searching for `'none'` and silently
doing nothing the day the default changes.

`merge_sources()` appends: a directive named twice ends up with both sets of
sources, where `+` on arrays would keep only the first and the page would break
in the browser rather than in a test.

`cors_for()` echoes the caller's origin only when it matches one on the list
exactly — scheme, host and port — so a suffix match cannot let
`evil-example.com` in on `example.com`'s name, and `null` matches nothing.
`Vary: Origin` comes back either way, so a cache cannot hand one caller's answer
to another.

## Url

A link back to a list page, with only the filters that have a value:

```php
Url::to( '/admin/customers', array( 'q' => 'alf', 'status' => '' ) ); // '/admin/customers?q=alf'
Url::to( '/admin/customers', array( 'q' => '' ) );                    // '/admin/customers'
Url::to( '/orders', array( 'customer' => 0 ), drop_zero: true );      // '/orders'
```

null and `''` are always dropped, and 0 and `'0'` with `drop_zero` — so a form
submitted empty does not leave `?q=&status=&sort=` on every link after it. The
path is held to `Request::local_path()`'s rules and anything else throws: a path
built from the request that turned out to be `//evil.example` would send every
link on the page to another site.

## View

Plain PHP templates, one layout deep.

```php
$view = new View( __DIR__ . '/views' );

$view->render( 'orders/show', array( 'order' => $order ) );
$view->render( 'emails/receipt', $data, layout: '' );
```

Every template is handed `$e()` for escaping, so forgetting it takes effort:

```php
<h1><?= $e( $order['reference'] ) ?></h1>
```

The layout receives the rendered template as `$content`, plus the same data —
a `content` key in the data never replaces it. A missing template throws rather
than rendering a blank page, and so does a name with a `..` segment, a
backslash or a control character in it: a template is code, and a name built
from the URL must not be able to run any `.php` file on the disk.

A template sees its data, `$e()` and nothing else — a key called `path` or
`data` arrives as given, and `$this` is not there. A template that throws takes
its half-rendered output with it.

Not for templates you did not write — `require` executes them, so a template is
code. This is a renderer, not a sandbox.

## Asset links

```php
<script src="<?= $e( Url::asset( '/assets/app.js', __DIR__ . '/public' ) ) ?>" defer></script>
// /assets/app.js?v=1785589200 — a new URL whenever the file changes
```

Browsers and CDNs keep a static file as long as they were told to, so a deploy reaches nobody until the cache runs out. The file's modification time in the URL makes a changed file a new URL and leaves an unchanged one cached. A missing file is linked as given; a path with `..`, `.` or a backslash throws, because it is looked up on the disk.

## Testing

```bash
composer test
```

181 tests — routing, constraints and groups, versioned asset links, templates in their own scope, surfaces by host and by prefix, input order,
cookies and their header, bearer tokens, country and loopback checks against
forged forwarding headers, downloads, deferred work, framing, source merging,
the CSP nonce, the strict baseline written out in full, CORS for named
origins, and query strings that stay on the site.

## License

GPL-2.0-or-later
