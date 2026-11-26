---
title: Sending files and raw bodies
type: how-to
audience: plugin
summary: How a plugin with http sends a multipart upload with files, or a raw body whose exact bytes it signs, through the outbound door, and the 10 MB limit on what it sends.
rests_on: [packages/teme-contracts/src/Http/OutboundHttp.php, packages/teme-contracts/src/Http/HttpFile.php, app/Domain/Plugins/Doors/PluginHttp.php, packages/teme-testing/src/SentRequest.php, docs/architecture/plugins.md]
---

# Sending files and raw bodies

`OutboundHttp` sends JSON and forms. Two more methods cover what a posting plugin needs next: `postMultipart()` uploads files, as an image to a social service does, and `postRaw()` sends exactly the bytes you give it, so you can sign them first, as a signed webhook or a fediverse inbox expects.

Both are in `teme/contracts` from the release that adds them; their `@since` says which. Both need the `http` capability, and every rule of the outbound door applies, as for `postJson()`: `https` to port 443, only hosts the person agreed to, only public addresses, no redirects followed, and the time limit.

## Upload files

```php
$answer = $this->http->postMultipart('https://media.example.com/upload', ['status' => 'New post'], [
    new HttpFile(field: 'media', filename: 'photo.jpg', contentType: 'image/jpeg', bytes: $jpeg),
]);
```

The fields come first, as for `postForm()`: each a string, or a list of strings to repeat its name. Each `HttpFile` is sent under its own field, with the name and content type you give. It carries either `bytes`, or a `path` to a file inside your plugin's own folder. A path anywhere else, or one that leaves the folder with `..` or a link, is refused with an `InvalidArgumentException`, because a plugin reads only its own files. Teme sets the `Content-Type` header, with the boundary; one you pass is dropped.

## Send a raw body

```php
$body = json_encode($activity, JSON_THROW_ON_ERROR);
$answer = $this->http->postRaw($inbox, $body, 'application/activity+json', [
    'Digest' => 'SHA-256='.base64_encode(hash('sha256', $body, true)),
    'Signature' => $signature,
]);
```

The body goes out byte for byte: Teme doesn't encode, trim or change it, so a signature over it stays true. The content type you give is the one sent, on one line; a `Content-Type` header in the headers is dropped. Other headers pass as they do for `postJson()`.

## The limits

What you send may be up to 10 MB: the body of `postMultipart()` and `postRaw()`, fields, files and the framing together. Over that, Teme throws `RequestFailed` before it looks the host up, and sends nothing. A file's size is read before the file is, so a file over the limit is never read into memory. `postJson()` and `postForm()` keep their 1 MB.

The answer's limit does not change: 1 MB, or 16 MB inside a long job. Past it, `AnswerTooLarge`.

## Test it

The testing kit's `FakeOutbound` answers these as it does the others, and each `SentRequest` has the body as sent. Call `multipart()` on it to read the parts, each with its name, file name, content type and bytes, and `header('Content-Type')` for the type. See [Testing a plugin or theme](../testing.md).

Next: [Feeds and the site](feeds-and-site.md), and the generated [contracts reference](../../../reference/contracts.md).
