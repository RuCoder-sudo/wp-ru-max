# Telegram proxy relay for WP Ru-max

The Cloudflare Worker, Google Apps Script, and PHP proxy options use the same
small relay protocol. A relay must accept an HTTPS `POST` with JSON:

```json
{
  "method": "sendMessage",
  "token": "BOT_TOKEN",
  "params": { "chat_id": "@channel", "text": "Test" },
  "secret": "YOUR_SHARED_SECRET"
}
```

It must forward only `sendMessage` and `sendPhoto` to
`https://api.telegram.org/bot{token}/{method}` and return Telegram's JSON
response unchanged. The relay secret must be at least 16 characters and match
the value saved in **Social Networks → Telegram → Proxy settings**. Do not
publish the secret or use a proxy operated by an untrusted party: the relay
handles the bot token and outgoing message content.

## Cloudflare Worker

Create a Worker, add a secret named `TG_PROXY_SECRET` in its settings, paste
the code below, deploy it, and enter its HTTPS Worker URL and the same secret
in WP Ru-max.

```js
export default {
  async fetch(request, env) {
    if (request.method !== "POST") {
      return new Response("Method not allowed", { status: 405 });
    }

    let input;
    try {
      input = await request.json();
    } catch {
      return Response.json({ ok: false, description: "Invalid JSON" }, { status: 400 });
    }

    if (!env.TG_PROXY_SECRET || input.secret !== env.TG_PROXY_SECRET) {
      return Response.json({ ok: false, description: "Unauthorized" }, { status: 401 });
    }
    if (!["sendMessage", "sendPhoto"].includes(input.method) ||
        !/^\d+:[A-Za-z0-9_-]{20,}$/.test(input.token || "") ||
        !input.params || typeof input.params !== "object") {
      return Response.json({ ok: false, description: "Invalid request" }, { status: 400 });
    }

    const upstream = await fetch(
      `https://api.telegram.org/bot${input.token}/${input.method}`,
      {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(input.params),
      }
    );
    return new Response(await upstream.text(), {
      status: upstream.status,
      headers: { "content-type": "application/json; charset=utf-8" },
    });
  },
};
```

## Google Apps Script

Create a script, save the following code, set the script property
`TG_PROXY_SECRET`, deploy it as a web app that can be called by the WordPress
server, and enter the deployed `/exec` URL and the same secret in WP Ru-max.

```js
function doPost(e) {
  var input;
  try {
    input = JSON.parse((e.postData && e.postData.contents) || '{}');
  } catch (error) {
    return json({ ok: false, description: 'Invalid JSON' });
  }

  var secret = PropertiesService.getScriptProperties().getProperty('TG_PROXY_SECRET');
  if (!secret || input.secret !== secret) {
    return json({ ok: false, description: 'Unauthorized' });
  }
  if (['sendMessage', 'sendPhoto'].indexOf(input.method) === -1 ||
      !/^\d+:[A-Za-z0-9_-]{20,}$/.test(input.token || '') ||
      !input.params || typeof input.params !== 'object') {
    return json({ ok: false, description: 'Invalid request' });
  }

  try {
    var response = UrlFetchApp.fetch(
      'https://api.telegram.org/bot' + input.token + '/' + input.method,
      {
        method: 'post',
        contentType: 'application/json',
        payload: JSON.stringify(input.params),
        muteHttpExceptions: true
      }
    );
    return ContentService.createTextOutput(response.getContentText())
      .setMimeType(ContentService.MimeType.JSON);
  } catch (error) {
    return json({ ok: false, description: String(error) });
  }
}

function json(value) {
  return ContentService.createTextOutput(JSON.stringify(value))
    .setMimeType(ContentService.MimeType.JSON);
}
```

## PHP proxy endpoint

Host this file on a server with HTTPS and PHP cURL. Set the environment
variable `TG_PROXY_SECRET` on that server, then enter the endpoint URL and the
same secret in WP Ru-max. Keep access logs from recording request bodies.

```php
<?php
header( 'Content-Type: application/json; charset=utf-8' );

$input = json_decode( file_get_contents( 'php://input' ), true );
$secret = getenv( 'TG_PROXY_SECRET' );
$methods = array( 'sendMessage', 'sendPhoto' );

if ( ! is_array( $input ) || ! $secret || ! hash_equals( $secret, (string) ( $input['secret'] ?? '' ) ) ) {
    http_response_code( 401 );
    echo json_encode( array( 'ok' => false, 'description' => 'Unauthorized' ) );
    exit;
}
if (
    ! in_array( $input['method'] ?? '', $methods, true )
    || ! preg_match( '/^\d+:[A-Za-z0-9_-]{20,}$/', (string) ( $input['token'] ?? '' ) )
    || ! is_array( $input['params'] ?? null )
) {
    http_response_code( 400 );
    echo json_encode( array( 'ok' => false, 'description' => 'Invalid request' ) );
    exit;
}

$url = 'https://api.telegram.org/bot' . $input['token'] . '/' . $input['method'];
$curl = curl_init( $url );
curl_setopt_array( $curl, array(
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => json_encode( $input['params'] ),
    CURLOPT_HTTPHEADER      => array( 'Content-Type: application/json' ),
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_CONNECTTIMEOUT  => 15,
    CURLOPT_TIMEOUT         => 45,
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
) );
$body = curl_exec( $curl );
$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
$error = curl_error( $curl );
curl_close( $curl );

if ( false === $body ) {
    http_response_code( 502 );
    echo json_encode( array( 'ok' => false, 'description' => 'Telegram connection failed: ' . $error ) );
    exit;
}
http_response_code( $status ?: 502 );
echo $body;
```

## Direct HTTP proxy

The **HTTP proxy (server:port)** option uses PHP cURL's HTTP CONNECT support to
reach the official Telegram API over HTTPS. Enter `host:port`; the optional
username and password are used for Basic proxy authentication. The host must
support HTTPS CONNECT. A proxy directory page (for example, a page listing
proxies) is not itself a working proxy endpoint.

## Test and troubleshooting

Use **Send test** beside the target chat after saving the settings. A successful
test confirms that the entered token can send to that chat through the selected
route. It does not check every chat or prove that the hosting provider will
allow uninterrupted connections. If the direct route times out, ask the host
whether outbound HTTPS to `api.telegram.org:443` is permitted. If a relay returns
an error, check its deployment URL, shared secret, outbound network access, and
that it returns Telegram's JSON response unchanged.
