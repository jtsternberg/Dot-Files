# Messages #images GIF picker shows gray boxes

## Symptom

In Messages.app, the **#images** picker (+ → #images) renders a correct grid of
tiles but every tile is a blank gray box. Clicking a box shows:

> **Could Not Share Image**
> "CFNetworkDownload_XXXXXX.tmp" couldn't be moved to "Caches" because either
> the former doesn't exist, or the folder containing the latter doesn't exist.

## Root cause

The #images picker is Apple's `HashtagImagesExtension` (from
`SearchToShareCore.framework`). Each GIF tile is a looping **mp4** preview.

CFNetwork downloads each mp4 to a temp file, then moves it into the extension's
sandbox **`Caches`** folder. If that `Caches` folder is missing, the move fails,
no local file exists to decode, and the tile stays gray. This persists forever
because macOS does not rebuild the folder for this sandboxed extension.

Container path:

```
~/Library/Containers/com.apple.siri.parsec.HashtagImagesApp.HashtagImagesExtension/Data/Library/Caches
```

A disk-cleaner (CleanMyMac, OnyX, etc.) purging `~/Library/.../Caches`, or a
manual cache wipe, will delete it — and can delete it again later.

## Diagnosis

Confirm the write failure in the extension's log while reproducing (search
#images during the window):

```bash
/usr/bin/log stream --level debug --style compact --timeout 45s \
  --predicate 'process CONTAINS[c] "HashtagImages" OR senderImagePath CONTAINS[c] "SearchToShare"' \
  | grep -iE 'Failed to write temp mp4|mp4|Caches'
```

Look for repeated: `(SearchToShareCore) Failed to write temp mp4 file to: <private>`

Then confirm the folder is missing (every other `Data/Library` sibling exists):

```bash
C=~/Library/Containers/com.apple.siri.parsec.HashtagImagesApp.HashtagImagesExtension/Data/Library
ls -ld "$C/Caches"   # -> No such file or directory
```

## Fix

Recreate the folder with the same ownership/permissions as its siblings
(`Preferences`, `Images` → mode `700`, owned by the user):

```bash
C=~/Library/Containers/com.apple.siri.parsec.HashtagImagesApp.HashtagImagesExtension/Data/Library
mkdir -p "$C/Caches" && chmod 700 "$C/Caches"
```

Then quit Messages (⌘Q) and reopen — the extension caches its instance, so a
restart is needed. If still gray, bounce the extension directly:

```bash
killall HashtagImagesExtension
```

## Notes

- `log` is a zsh builtin here; use the absolute path **`/usr/bin/log`** for the
  system logging tool.
- Reading the unified log (`/usr/bin/log show|stream`) requires running outside
  the command sandbox.
