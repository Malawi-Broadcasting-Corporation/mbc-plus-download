# MBC Plus — download link & QR code

One link and one QR code that send people to the **MBC Plus** app in the right store.

**Live:** https://mbc.mw/plus

| Visitor | What happens |
|---|---|
| iPhone / iPad | Redirects to the [App Store](https://apps.apple.com/app/id6746721895) (id `6746721895`) |
| Android | Redirects to [Google Play](https://play.google.com/store/apps/details?id=com.mbc.mbcplus) (`com.mbc.mbcplus`) |
| Desktop / other | Shows a branded page with both store buttons |

<p align="center">
  <img src="docs/preview-mobile.png" alt="Fallback page on mobile" width="260">
  &nbsp;&nbsp;
  <img src="qr/mbc-plus-qr-logo.png" alt="MBC Plus QR code" width="260">
</p>

> ⚠️ **The QR code is printed on marketing material.** The page at `mbc.mw/plus` must stay online
> permanently. Do not delete or move `public_html/plus/` on the mbc.mw server, and keep it in place
> during any WordPress migration or clean-up. If a store link ever changes, edit the page — never the QR.

## Repository layout

```
site/plus/     The page deployed to the server (index.html + images)
qr/            QR codes — SVG/PDF for print, PNG for screens; generate.py rebuilds them
brand/         Original MBC logo files used for the page and QR
docs/          Screenshots
```

## QR codes

| File | Use |
|---|---|
| `qr/mbc-plus-qr-logo.svg` | **Print** (flyers, posters) — with MBC mark |
| `qr/mbc-plus-qr-logo.png` | Social media, slides — with MBC mark |
| `qr/mbc-plus-qr.svg` / `.pdf` / `.png` | Plain versions, no logo |

All encode `https://mbc.mw/plus` with high (H) error correction.

**Print guidelines:** at least 2.5 cm wide on flyers (≈ 1/10 of scanning distance on posters), keep the
white border clear, dark code on a light background, and test the final artwork with an iPhone and an
Android phone before printing in bulk.

Regenerate: `pip install segno pillow && python qr/generate.py`

## Deploying the page

The server is the mbc.mw cPanel account. The page lives at `/home/mbc/public_html/plus/`
(the folder takes priority over WordPress, so the main site is unaffected).

1. Edit files in `site/plus/`.
2. In cPanel → File Manager, upload the changed files into `public_html/plus/` (overwrite).
3. Open https://mbc.mw/plus on a desktop (page should show) and scan the QR with a phone (should open the store).

## Brand

Colours used: maroon `#901820`, red `#d82028`, green `#009048`, black `#111`.

---

# MBC QR codes: self-service tool for marketing

Marketing can create QR codes for anything (events, flyers, other MBC platforms) without a developer.

**Tool:** https://mbc.mw/go/admin · **Codes point to:** `https://mbc.mw/go/<name>`

How it works: each QR code contains a short mbc.mw link, e.g. `mbc.mw/go/gala-programme`. The tool decides
where that link sends people, so **the destination can be changed at any time without reprinting**.
Pausing a link shows a friendly "This link has ended" page. Scans are counted per code (no personal data
is stored: only time, link name and iPhone/Android/other).

| Marketing can… | |
|---|---|
| Create a code | Name, short link, destination (any web page: event page, Google Maps pin, YouTube, form…) |
| Send iPhone and Android to different places | Optional, e.g. app store links, like `/plus` does |
| Download | SVG for print, 2000 px PNG for screens, with or without the MBC logo, in 4 brand colours |
| Edit / pause / delete | Editing changes where an already-printed code goes |
| See scans | Total, this week, last scan, 30-day trend |

The existing `mbc.mw/plus` page and QR are separate and unchanged.

## Files

```
site/go/              -> public_html/go/ on the server
  index.php           the redirect: mbc.mw/go/<name> -> destination
  .htaccess           routes /go/<name> to index.php (takes priority over WordPress, like /plus)
  inc/bootstrap.php   shared code (blocked from the web)
  admin/              the tool (sign-in required); QR codes are drawn in the browser
  data/               LIVE DATA, exists only on the server (blocked from the web)
    config.php        users + settings (create from config.sample.php)
    links.json        the QR codes
    scans.log         scan counts
```

No database is needed. Requires PHP 7.4 or newer (standard on cPanel).

## First-time setup (cPanel)

1. Upload the contents of `site/go/` to `public_html/go/` (create the folder).
2. In `public_html/go/data/`, copy `config.sample.php` to `config.php`.
3. Open https://mbc.mw/go/admin. It shows a **Set up** screen: choose a username and password. It gives you one
   line of text. Paste it into `config.php` under `'users'` and save.
4. Reload https://mbc.mw/go/admin and sign in.
5. More people: once signed in, use **Add teammate** to get a line for each person.

## Updating the tool

Upload changed files from `site/go/` as usual. **Never delete `public_html/go/data/` or upload over its
files.** The repo has no copies of the live data, so uploading the repo's `data/` folder is harmless. Include
`data/` in your cPanel backups: it is the only copy of every QR code's destination.

## Notes

- Short links can't be renamed after creation (the name is inside printed codes). Make a new code instead.
- Delete only codes that were never printed; use **Pause** otherwise.
- Link previews (WhatsApp, Facebook, bots) are not counted as scans.
- iPads that identify as Mac computers get the main destination, not the iPhone one.
