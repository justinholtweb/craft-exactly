# Plugin Store promo images

Marketing images for the Exactly listing on the Craft Plugin Store, rendered in the same theme as the
plugin's marketing page at
[justinholt.com/plugins/craft-exactly](https://justinholt.com/plugins/craft-exactly).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `exactly-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp). **Promos are JPEG, never PNG.** Chrome can only write PNG, so
`build.sh` converts with `sips` at quality 90 and deletes the intermediate.

Unlike Chunky's copy, the loop does not trust Chrome's exit code: Chrome sometimes exits non-zero
after writing a perfectly good screenshot (seen while another headless Chrome was running), and
`set -e` then left a half-built deck with a stray PNG in `out/`. It checks that the file was written
instead.

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover: name, tagline, app icon, **$99 / renewal $79/yr** | — |
| 2 | See exactly what Exact will get | **real** Invoice preview: reconciliation + payload (reverse-charge order) |
| 3 | Four ways to tax a sale | CSS table: domestic, reverse charge, OSS, export, with the VAT-number normalisation |
| 4 | Checks the arithmetic before it sends | **real** previews: a €0.01 rounding line, and a €4.49 gap refused |
| 5 | Never invoices an order twice | **real** order-edit panel, plus the two push results as text |
| 6 | Stays connected. Shows its work. | **real** settings connection block + a document's related log (a 429, then the retry) |
| 7 | Credit notes, backfill, retry | **real** Documents screen + console commands from the README |

The two push results on slide 5 are the messages `Invoices::push()` actually returned for that order
in the fixture run (“Sent to Exact Online as Invoice 26042.” / “This order is already invoice 26042
in Exact Online.”). Change that copy in the plugin and change the slide.

Slide 3 is a diagram, not a screenshot, but its rows are the fixture customers whose invoices appear
on slides 2, 6 and 7, and the codes are the ones the fixture mapped.

## Palette

Accent `#E1141D`, Exact Online's own red, from the icon's tile. `#FF6B70` is the light accent for
small text on the dark background, where the tile red is too dark to read. The family's `#ffd166`
gold, and `#5ee0a0` for "OK". Jersey 20 for display, Inter for body.

## The watermark is the double rule alone

`assets/watermark.svg` is the geometry of `../src/icon-mask.svg`, filled white, with the red tile
stripped: at watermark scale the tile reads as a hard-edged grey box across every slide. It runs off
the bottom-right edge at 2.6% opacity, and is switched off on the cover, where it sat behind the icon
like a shadow.

`assets/icon.svg` is a straight copy of `../src/icon.svg`. Re-copy it whenever the icon changes.

## Screenshots

`shots/` holds real control-panel captures from the plugin-testing harness. There is no Exact Online
account, so the data behind them was made the way `tests/integration/checks.php` makes its own: the
**real** plugin code (account and item resolution, VAT determination, `buildPayload()`, `claim()`,
delivery, the log, rate-limit recording) running against a fake Exact. The fake sat one level lower
than the test suite's `FakeApi`, at the Guzzle handler, so the log rows, durations and rate-limit
figures were written by `services\Api` itself. Fixture shop: *Tegelhuis Delft B.V.*, division
2914473, eight EUR orders. All of it was removed from the harness afterwards.

Two capture runs, both 1× unless noted (each is shown at about its own pixel width, so it lands 1:1
in the output) and cropped further in CSS:

**First run** (1326 CSS px, sidebar cropped off at the CP card's left edge), used on slides 4 and 6:

- `exactly-rounding.png` — Invoice preview with the €0.01 rounding line
- `exactly-refused.png` — Invoice preview refusing a €4.49 gap
- `exactly-connection.png` — top of Settings → Plugins → Exactly
- `exactly-retry-log.png` — the OSS invoice that hit a 429 and was retried
- `exactly-log.png` — the connection log (not used on a slide yet)

**Second run** (after payment status started being re-read from Exact, and the order panel stopped
using Commerce's `.order-flex`), used on slides 2, 5 and 7. These keep the CP's own 24px gutter, so
the page header and its action buttons are not flush with the frame. Captured at a 1100 CSS px
viewport so they display near 1:1 in the 1020px stack:

- `exactly-preview.png` — Invoice preview for the reverse-charge order (INV-2026-04507, invoice 26042)
- `exactly-documents.png` — Exactly → Documents, payment column set by the real `Payments::sync()`
  against the fake: Exact reports the delivered invoices processed (`Status` 50), the receivables
  list holds 26044 in full and 26046 in part, 26043's email failed so it stayed a draft, and 26045
  reads *credited* because its order has a sent credit note (26047). Reshot after that last case was
  fixed; before it, 26045 read "paid".
- `exactly-panel.png` — the order-edit panel at **2×** (`zoom: 2`), reading "Payment: paid"

At a 1100px viewport Craft puts the nav off-canvas: `#global-container` gets
`inset-inline-start: -226px`. Hiding the sidebar's wrapper collapses its grid column while that offset
stays, and the page renders 226px off the left edge. Leave the wrapper in place (it is off-screen at
that width) and hide only the other children.

The other plugins' order-sidebar blocks, and Commerce's own order meta, were hidden in the page before
the panel capture, rather than disabling a dozen plugins in the shared harness.

### `shots/site/`

Standalone crops, no slide chrome, for the marketing page's screenshot band, each at 1× and `@2x`:

- `exactly-preview.png` / `exactly-preview@2x.png` — header through the Reconciliation pane
- `exactly-panel.png` / `exactly-panel@2x.png` — the Exactly panel, 24px of sidebar around it
- `exactly-documents.png` / `exactly-documents@2x.png` — the whole Documents screen

Commerce's own order screen shows `$` amounts (the harness store is USD; the fixture orders were set
to EUR at the row level), which is why slide 5 crops to Exactly's panel alone.

`sips --cropToHeightWidth H W --cropOffset Y X`: an offset of `0 0` is ignored and gives a *centred*
crop; use `1 1` to crop from the top-left.

`fonts.css` is generated by `build.sh` and gitignored; don't edit it.
