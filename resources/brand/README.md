# Teme’s icon

Teme’s own icon: a T built from blocks, in the admin’s teal (the owner’s design, chosen 2026-11-04). It marks Teme’s own surfaces — the admin’s browser tab and phone icon until a site uploads its own, and a small credit on the sign-in screens. A site’s public pages never show it.

| File | What it is |
|---|---|
| `teme-icon.svg` | The master for light grounds: a white tile, the T in the admin’s accent `#0a6273`, with `#48adc2` and `#8fcfdc` |
| `teme-icon-dark.svg` | The master for dark grounds: a `#1b2224` tile, the T in the dark admin’s accent `#48adc2`, with `#2a8fa3` and `#1f6f80` |
| `export/icon/` | `teme-icon-{16,32,48,64,70,128,150,256,512,1024}.png` and `teme-icon.ico` (16 to 256), from `teme-icon.svg` |
| `export/icon-dark/` | The same set from `teme-icon-dark.svg` |

Edit the SVG, then export again; never edit the PNGs or the `.ico` by hand. The files the browser fetches are copied from here into `public/`.

The two dots read from 32 px up; at 16 px the T and its blocks carry the mark.
