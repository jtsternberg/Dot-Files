# from-qr

Decode the value inside a QR code image locally:

```sh
from-qr screenshot.png
from-qr screenshot.png | pbcopy # macOS clipboard
from-qr screenshot.png --json
```

Requires `zbarimg`: install with `brew install zbar` on macOS or
`sudo apt install zbar-tools` on Ubuntu. `qrencode` creates QR images;
it does not decode them.

Plain output contains only the decoded payload, followed by a newline. URLs,
Wi-Fi configuration, contact cards, and other values are printed as encoded;
the command does not open URLs or act on the contents. Multiple QR codes are
printed separated by newlines, in the decoder's scan order. Embedded line
breaks and whitespace are preserved. Other barcode types are ignored.

`--json` returns `{"text":"decoded value"}`. Multiple payloads are combined
with newlines in `text`, just as in plain output. For binary payloads, use plain
output: JSON replaces invalid UTF-8 bytes with the replacement character.

Returns 0 on success, 1 for missing/unreadable images, decoder errors, a missing
dependency, or no QR code. Plain-mode errors go to stderr; JSON-mode errors
return `error` and `error_type` on stdout.

`from-qr --help` and `from-qr completion zsh` work without ZBar installed.
Zsh completion is generated lazily from the command's attributes.
