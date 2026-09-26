# Obsidian config

A copy of the vault's `.obsidian/` settings.

To resume the session that created this and the `setup-obsidian-vault`:

```
graveyard resurrect f651445f
```

## Using `setup-obsidian-vault`

`setup-obsidian-vault -n` - validates the current Obsidian vault configuration.
`setup-obsidian-vault` - symlink the items in this dir to the current Obsidian vault (looks for `.obsidian/` dir recursively)
`setup-obsidian-vault --new` - create a new Obsidian vault `.obsidian/` dir, and symlink the items in this dir to it.

## hotkeys.json

On macOS, `Mod` and `Meta` both mean ⌘. The defaults listed below come from `app.hotkeyManager.defaultKeys` in Obsidian 1.14.2.

When you customize a command, your list replaces its defaults entirely. That's why some default keys (e.g. ⌘P) also appear in the file: they had to be written back in to keep them.

| Command | Hotkeys | Built-in default | Change |
|---|---|---|---|
| Open command palette | ⌘P, **⌘⇧P** | ⌘P | ⌘⇧P added |
| Navigate back | *(none)* | ⌘⌥← | **Default removed** |
| Navigate forward | *(none)* | ⌘⌥→ | **Default removed** |
| Go to next tab | ⌃Tab, ⌘⇧], **⌘⌥→** | ⌃Tab, ⌘⇧] | ⌘⌥→ added (freed from Navigate forward) |
| Go to previous tab | ⌃⇧Tab, ⌘⇧[, **⌘⌥←** | ⌃⇧Tab, ⌘⇧[ | ⌘⌥← added (freed from Navigate back) |
| Bookmarks: Bookmark… | ⌘⌥B | none | New |
| Bookmarks: Show bookmarks | ⌃⌘B | none | New |
| Move current file to another folder | ⌘M | none | New |
| Fold all headings and lists | ⌃⌘F | none | New |
| Unfold all headings and lists | ⌃⌘⇧F | none | New |
| Add file property | ⌘;, **⌘⇧;** | ⌘; | ⌘⇧; added |
| Toggle strikethrough | ⌘⇧X | none | New |
| Toggle highlight | ⌘⇧H | none | New |

These two override macOS system shortcuts while Obsidian has focus:

- **⌃⌘F** is normally the full-screen toggle.
- **⌘M** is normally minimize-window.
