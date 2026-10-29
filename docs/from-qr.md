# from-qr

Decode the value inside a QR code image locally:

```sh
from-qr screenshot.png
from-qr screenshot.png | pbcopy # macOS clipboard
from-qr screenshot.png --json
from-qr authenticator-setup.png        # current 2FA code, detected automatically
from-qr authenticator-setup.png --totp # require a TOTP setup QR
from-qr authenticator-setup.png --raw  # original otpauth URI
```

Requires `zbarimg`: install with `brew install zbar` on macOS or
`sudo apt install zbar-tools` on Ubuntu. `qrencode` creates QR images;
it does not decode them.

For authenticator setup QRs containing `otpauth://totp/...`, output is the
current 2FA code, followed by a newline. It uses the setup URI's Base32 key,
algorithm, digits, and period. Defaults are SHA1, six digits and 30 seconds;
SHA256, SHA512, eight digits and positive integer periods are also supported.
Codes use your system clock and expire when the configured period ends.
Generation is local; no account is contacted or enrolled.

`--totp` requires a valid TOTP setup QR. `--raw` prints the original payload
instead. These flags cannot be combined. TOTP mode requires one setup URI;
multiple QR results containing an authenticator URI are rejected. HOTP,
authenticator migration QRs and custom encoders such as Steam are unsupported.

Other QR output contains only the decoded payload, followed by a newline.
URLs, Wi-Fi configuration, contact cards, and other values are printed as
encoded; the command does not open URLs. Multiple ordinary QR codes are
printed separated by newlines, in the decoder's scan order. Embedded line
breaks and whitespace are preserved. Other barcode types are ignored.

`--json` returns `{"code":"123456"}` for TOTP, or `{"text":"decoded value"}`
for ordinary QRs and `--raw`. Multiple ordinary payloads are combined
with newlines in `text`, just as in plain output. For binary payloads, use plain
output: JSON replaces invalid UTF-8 bytes with the replacement character.

Returns 0 on success, 1 for missing/unreadable images, decoder errors, a missing
dependency, invalid/unsupported TOTP setup, or no QR code. Plain-mode errors go
to stderr; JSON-mode errors return `error` and `error_type` on stdout.

`from-qr --help` and `from-qr completion zsh` work without ZBar installed.
Zsh completion is generated lazily from the command's attributes.

TOTP follows [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238) and the
[authenticator URI format](https://github.com/google/google-authenticator/wiki/Key-Uri-Format).
