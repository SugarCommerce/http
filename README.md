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

## Request

Captured once, immutable afterwards. The point is that nothing downstream
reaches for `$_SERVER`: a handler that does cannot be tested without one, and a
test that has to build `$_SERVER` is a test nobody writes.

```php
$request->method;                  // 'POST'
$request->path;                    // '/orders/7'
$request->header( 'X-Signature' ); // any case
$request->json();                  // decoded body, or array()
$request->input( 'email' );        // form body, then JSON, then query string
$request->is_https();
$request->ip( TrustedProxies::cloudflare() );
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

$response->with( array( 'Retry-After' => '60' ) );
```

`error()` exists because the alternative is what every API ends up with: the
same literal string written out at twenty call sites, three of which say
something slightly different for no reason. Existing headers win in `with()`,
so a handler cannot have its content type rewritten by something wrapping it.

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
```

The defaults are strict — no CORS, no CDN, no inline script — and everything is
opened up by argument. A permissive default is one nobody ever tightens.

Extra CSP sources are **appended** to a directive rather than replacing it,
which is what keeps the nonce in place. `Headers::html()` builds that nonce
once per request; render it into the one `<script>` you meant to allow.

`with_hsts()` only adds the header over TLS. Sending it on plain HTTP is
meaningless, and on a local host actively harmful — the browser remembers it and
then refuses to load `http://localhost` for six months, for every project you
have.

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

The layout receives the rendered template as `$content`, plus the same data. A
missing template throws rather than rendering a blank page.

Not for templates you did not write — `require` executes them, so a template is
code. This is a renderer, not a sandbox.

## Testing

```bash
composer test
```

## License

GPL-2.0-or-later
