"""Regenerate the MBC Plus QR codes.  pip install segno pillow
Run from the repo root:  python qr/generate.py
"""
import base64, io, re
import segno
from PIL import Image, ImageDraw

URL = "https://mbc.mw/plus"
DARK = "#1a1214"
MARK = "brand/mbc-mark-original.png"

q = segno.make(URL, error="h")  # high error correction: survives the centre logo

# plain versions
q.save("qr/mbc-plus-qr.svg", scale=20, border=4, dark=DARK)
q.save("qr/mbc-plus-qr.png", scale=50, border=4, dark=DARK)
q.save("qr/mbc-plus-qr.pdf", scale=20, border=4)

mark = Image.open(MARK).convert("RGBA")
mark = mark.crop(mark.getbbox())

# PNG with logo (plate = 20% of width)
buf = io.BytesIO(); q.save(buf, kind="png", scale=50, border=4, dark=DARK); buf.seek(0)
qr = Image.open(buf).convert("RGBA"); W = qr.size[0]; pad = int(W * 0.20)
plate = Image.new("RGBA", (pad, pad), (0, 0, 0, 0))
ImageDraw.Draw(plate).rounded_rectangle([0, 0, pad - 1, pad - 1], radius=int(pad * 0.22), fill="white")
m = mark.copy(); m.thumbnail((int(pad * 0.78),) * 2, Image.LANCZOS)
plate.alpha_composite(m, ((pad - m.size[0]) // 2, (pad - m.size[1]) // 2))
qr.alpha_composite(plate, ((W - pad) // 2, (W - pad) // 2))
qr.convert("RGB").save("qr/mbc-plus-qr-logo.png", optimize=True)

# SVG with logo
buf = io.BytesIO(); q.save(buf, kind="svg", scale=10, border=4, dark=DARK, xmldecl=False)
svg = buf.getvalue().decode(); W = int(re.search(r'width="(\d+)"', svg).group(1))
b = io.BytesIO(); mark.save(b, "PNG", optimize=True); data = base64.b64encode(b.getvalue()).decode()
pad = W * 0.20; x = (W - pad) / 2; r = min(pad * 0.78 / mark.size[0], pad * 0.78 / mark.size[1])
iw, ih = mark.size[0] * r, mark.size[1] * r
svg = svg.replace("</svg>",
    f'<rect x="{x:.2f}" y="{x:.2f}" width="{pad:.2f}" height="{pad:.2f}" rx="{pad*0.22:.2f}" fill="#fff"/>'
    f'<image x="{(W-iw)/2:.2f}" y="{(W-ih)/2:.2f}" width="{iw:.2f}" height="{ih:.2f}" href="data:image/png;base64,{data}"/></svg>')
if 'xmlns="http://www.w3.org/2000/svg"' not in svg:
    svg = svg.replace("<svg", '<svg xmlns="http://www.w3.org/2000/svg"', 1)
open("qr/mbc-plus-qr-logo.svg", "w").write(svg)
print("QR codes written to qr/ ->", URL)
