/* MBC QR codes admin. Talks to api.php; draws QR codes in the browser (qrcode.js, MIT). */
(function () {
  "use strict";

  var CSRF = document.querySelector('meta[name="csrf"]').content;
  var COLOURS = [
    { name: "MBC dark", hex: "#1a1214" },
    { name: "Maroon", hex: "#901820" },
    { name: "Green", hex: "#009048" },
    { name: "Black", hex: "#000000" }
  ];
  var state = { links: [], stats: {}, base: "", editing: null, slugTouched: false, qr: null, colour: COLOURS[0].hex };

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var form = $("#form");

  // ---------- helpers ----------
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    for (var k in attrs || {}) {
      if (k === "text") n.textContent = attrs[k];
      else if (k === "class") n.className = attrs[k];
      else if (k.slice(0, 2) === "on") n.addEventListener(k.slice(2), attrs[k]);
      else n.setAttribute(k, attrs[k]);
    }
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === "string" ? document.createTextNode(c) : c); });
    return n;
  }

  function api(action, body) {
    return fetch("api.php?action=" + action, {
      method: body ? "POST" : "GET",
      headers: body ? { "Content-Type": "application/json", "X-CSRF-Token": CSRF } : {},
      body: body ? JSON.stringify(body) : undefined,
      credentials: "same-origin"
    }).then(function (r) {
      if (r.status === 401) { location.reload(); throw new Error("Signed out"); }
      return r.json().catch(function () { return { error: "The server sent an unexpected reply (" + r.status + ")." }; })
        .then(function (d) { if (!r.ok || d.error) throw new Error(d.error || "Something went wrong."); return d; });
    });
  }

  function toast(msg) {
    var t = $("#toast"); t.textContent = msg; t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(function () { t.hidden = true; }, 2600);
  }

  function slugify(s) {
    return s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 60);
  }

  function ago(iso) {
    if (!iso) return "never";
    var s = (Date.now() - new Date(iso).getTime()) / 1000;
    if (s < 60) return "just now";
    if (s < 3600) return Math.floor(s / 60) + " min ago";
    if (s < 86400) return Math.floor(s / 3600) + " h ago";
    if (s < 86400 * 30) return Math.floor(s / 86400) + " days ago";
    return new Date(iso).toLocaleDateString();
  }

  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { toast("Copied " + text); });
    } else {
      window.prompt("Copy this link:", text);
    }
  }

  // ---------- list ----------
  function load() {
    return api("list").then(function (d) {
      state.links = d.links; state.stats = d.stats; state.base = d.base_url;
      $("#base").textContent = d.base_url.replace(/^https?:\/\//, "");
      render();
    }).catch(function (e) { $("#list").replaceChildren(el("p", { class: "alert", text: e.message })); });
  }

  function spark(days) {
    var out = el("div", { class: "spark", "aria-hidden": "true" }), vals = [], max = 1;
    for (var i = 29; i >= 0; i--) {
      var d = new Date(Date.now() - i * 86400000).toISOString().slice(0, 10);
      var v = (days || {})[d] || 0; vals.push(v); if (v > max) max = v;
    }
    vals.forEach(function (v) { out.appendChild(el("i", { style: "height:" + Math.max(1, Math.round(v / max * 22)) + "px" })); });
    return out;
  }

  function render() {
    var q = $("#search").value.trim().toLowerCase();
    var links = state.links.slice().sort(function (a, b) { return (b.created || "").localeCompare(a.created || ""); })
      .filter(function (l) { return !q || (l.title + " " + l.slug + " " + l.url + " " + (l.notes || "")).toLowerCase().indexOf(q) >= 0; });
    var list = $("#list");
    if (!state.links.length) {
      return list.replaceChildren(el("div", { class: "empty" }, [
        el("p", { text: "No QR codes yet." }),
        el("p", { class: "small", text: "Create your first one with the form. For example: a gala table card pointing to the event programme." })
      ]));
    }
    if (!links.length) return list.replaceChildren(el("p", { class: "muted", text: "Nothing matches “" + q + "”." }));

    list.replaceChildren.apply(list, links.map(function (l) {
      var s = state.stats[l.slug] || { total: 0, week: 0, i: 0, a: 0, o: 0, last: null };
      var short = state.base + l.slug;
      var title = el("h3", null, [l.title, l.active ? null : el("span", { class: "tag", text: "Paused" })]);
      var extra = [];
      if (l.ios) extra.push("iPhone → " + l.ios);
      if (l.android) extra.push("Android → " + l.android);
      return el("article", { class: "item" + (l.active ? "" : " paused") }, [
        title,
        el("div", { class: "short", text: short.replace(/^https?:\/\//, "") }),
        el("div", { class: "dest", text: "→ " + l.url + (extra.length ? "  ·  " + extra.join("  ·  ") : "") }),
        l.notes ? el("div", { class: "notes", text: l.notes }) : null,
        el("div", { class: "stats", title: "iPhone " + s.i + " · Android " + s.a + " · Other " + s.o }, [
          el("b", { text: String(s.total) }), el("span", { text: "scans · " + s.week + " this week" }),
          el("div", { class: "small muted", text: "last " + ago(s.last) }), spark(s.days)
        ]),
        el("div", { class: "actions" }, [
          el("button", { class: "btn primary", text: "Get QR code", onclick: function () { openQr(l); } }),
          el("button", { class: "btn", text: "Edit", onclick: function () { startEdit(l); } }),
          el("button", { class: "btn", text: "Copy link", onclick: function () { copy(short); } }),
          el("a", { class: "btn", text: "Test", href: short, target: "_blank", rel: "noopener" }),
          el("button", { class: "btn", text: l.active ? "Pause" : "Resume", onclick: function () { setActive(l, !l.active); } }),
          el("button", { class: "btn danger", text: "Delete", onclick: function () { remove(l); } })
        ])
      ]);
    }));
  }

  function setActive(l, active) {
    if (!active && !confirm("Pause “" + l.title + "”?\n\nAnyone who scans it will see “This link has ended” until you resume it.")) return;
    api("active", { slug: l.slug, active: active }).then(function () { toast(active ? "Resumed" : "Paused"); load(); })
      .catch(function (e) { alert(e.message); });
  }

  function remove(l) {
    if (!confirm("Delete “" + l.title + "”?\n\nIf this QR code is printed anywhere, it will stop working. Its scan history is kept.\n\nTip: use Pause instead if it might be needed again.")) return;
    api("delete", { slug: l.slug }).then(function () { toast("Deleted"); if (state.editing === l.slug) resetForm(); load(); })
      .catch(function (e) { alert(e.message); });
  }

  // ---------- form ----------
  function resetForm() {
    form.reset(); state.editing = null; state.slugTouched = false;
    form.slug.disabled = false;
    $("#formTitle").textContent = "New QR code";
    $("#saveBtn").textContent = "Create QR code";
    $("#cancelEdit").hidden = true;
    $("#formError").hidden = true;
  }

  function startEdit(l) {
    resetForm();
    state.editing = l.slug;
    ["title", "slug", "url", "ios", "android", "notes"].forEach(function (k) { form[k].value = l[k] || ""; });
    form.slug.disabled = true;
    if (l.ios || l.android) form.querySelector("details").open = true;
    $("#formTitle").textContent = "Edit QR code";
    $("#saveBtn").textContent = "Save changes";
    $("#cancelEdit").hidden = false;
    form.scrollIntoView({ behavior: "smooth", block: "start" });
    form.url.focus({ preventScroll: true });
  }

  form.title.addEventListener("input", function () {
    if (!state.editing && !state.slugTouched) form.slug.value = slugify(form.title.value);
  });
  form.slug.addEventListener("input", function () {
    state.slugTouched = true;
    var pos = form.slug.selectionStart, v = form.slug.value.toLowerCase().replace(/[^a-z0-9-]/g, "-");
    if (v !== form.slug.value) { form.slug.value = v; form.slug.setSelectionRange(pos, pos); }
  });
  // be forgiving: "mbc.mw/xyz" -> "https://mbc.mw/xyz"
  ["url", "ios", "android"].forEach(function (k) {
    form[k].addEventListener("blur", function () {
      var v = form[k].value.trim();
      if (v && !/^[a-z]+:\/\//i.test(v) && /^[\w-]+(\.[\w-]+)+/.test(v)) form[k].value = "https://" + v;
    });
  });
  $("#cancelEdit").addEventListener("click", resetForm);

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var err = $("#formError"); err.hidden = true;
    var btn = $("#saveBtn"); btn.disabled = true;
    var isNew = !state.editing;
    api("save", {
      new: isNew, slug: state.editing || form.slug.value, title: form.title.value, url: form.url.value,
      ios: form.ios.value, android: form.android.value, notes: form.notes.value
    }).then(function (d) {
      resetForm();
      toast(isNew ? "QR code created" : "Saved. The printed QR now goes to the new address.");
      return load().then(function () { if (isNew) openQr(d.link); });
    }).catch(function (e) {
      err.textContent = e.message; err.hidden = false;
    }).then(function () { btn.disabled = false; });
  });

  $("#search").addEventListener("input", render);
  $("#logout").addEventListener("click", function () { api("logout", {}).then(function () { location.reload(); }); });

  // ---------- QR drawing ----------
  // Same geometry as qr/generate.py: error correction H, 4-module border, white rounded plate = 20% of width, mark = 78% of plate.
  var mark = null; // { canvas, dataUrl, w, h }
  function loadMark() {
    if (mark) return Promise.resolve(mark);
    return new Promise(function (res, rej) {
      var img = new Image();
      img.onload = function () {
        var c = el("canvas"); c.width = img.width; c.height = img.height;
        var x = c.getContext("2d"); x.drawImage(img, 0, 0);
        var d = x.getImageData(0, 0, c.width, c.height).data, t = c.height, b = 0, l = c.width, r = 0;
        for (var y = 0; y < c.height; y++) for (var xx = 0; xx < c.width; xx++) {
          if (d[(y * c.width + xx) * 4 + 3] > 8) { if (y < t) t = y; if (y > b) b = y; if (xx < l) l = xx; if (xx > r) r = xx; }
        }
        var w = r - l + 1, h = b - t + 1;
        var crop = el("canvas"); crop.width = w; crop.height = h;
        crop.getContext("2d").drawImage(c, l, t, w, h, 0, 0, w, h);
        // smaller copy to embed in SVG files
        var k = Math.min(1, 360 / Math.max(w, h)), small = el("canvas");
        small.width = Math.round(w * k); small.height = Math.round(h * k);
        small.getContext("2d").drawImage(crop, 0, 0, small.width, small.height);
        mark = { canvas: crop, dataUrl: small.toDataURL("image/png"), w: w, h: h };
        res(mark);
      };
      img.onerror = rej;
      img.src = "mbc-mark-qr.png";
    });
  }

  function matrix(text) {
    var q = qrcode(0, "H"); q.addData(text); q.make();
    var n = q.getModuleCount(), rows = [];
    for (var r = 0; r < n; r++) { var row = []; for (var c = 0; c < n; c++) row.push(q.isDark(r, c)); rows.push(row); }
    return rows;
  }

  function plateGeom(N) {
    var pad = N * 0.20, x = (N - pad) / 2, k = Math.min(pad * 0.78 / mark.w, pad * 0.78 / mark.h);
    var iw = mark.w * k, ih = mark.h * k;
    return { pad: pad, x: x, rx: pad * 0.22, iw: iw, ih: ih, ix: (N - iw) / 2, iy: (N - ih) / 2 };
  }

  function buildSvg(m, colour, logo) {
    var n = m.length, B = 4, N = n + 2 * B, d = "";
    for (var r = 0; r < n; r++) {
      for (var c = 0; c < n; c++) {
        if (!m[r][c]) continue;
        var s = c; while (c + 1 < n && m[r][c + 1]) c++;
        d += "M" + (s + B) + " " + (r + B) + "h" + (c - s + 1) + "v1h-" + (c - s + 1) + "z";
      }
    }
    var f = function (v) { return +v.toFixed(3); };
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + N + " " + N + '" width="' + N * 10 + '" height="' + N * 10 + '" shape-rendering="crispEdges">' +
      '<rect width="' + N + '" height="' + N + '" fill="#fff"/><path fill="' + colour + '" d="' + d + '"/>';
    if (logo) {
      var g = plateGeom(N);
      svg += '<rect x="' + f(g.x) + '" y="' + f(g.x) + '" width="' + f(g.pad) + '" height="' + f(g.pad) + '" rx="' + f(g.rx) + '" fill="#fff" shape-rendering="geometricPrecision"/>' +
        '<image x="' + f(g.ix) + '" y="' + f(g.iy) + '" width="' + f(g.iw) + '" height="' + f(g.ih) + '" href="' + mark.dataUrl + '"/>';
    }
    return svg + "</svg>";
  }

  function buildPng(m, colour, logo, px) {
    var n = m.length, B = 4, N = n + 2 * B, s = Math.max(1, Math.floor(px / N)), W = N * s;
    var c = el("canvas"); c.width = W; c.height = W;
    var x = c.getContext("2d");
    x.fillStyle = "#fff"; x.fillRect(0, 0, W, W); x.fillStyle = colour;
    for (var r = 0; r < n; r++) for (var cc = 0; cc < n; cc++) if (m[r][cc]) x.fillRect((cc + B) * s, (r + B) * s, s, s);
    if (logo) {
      var g = plateGeom(N);
      x.fillStyle = "#fff"; x.beginPath();
      if (x.roundRect) x.roundRect(g.x * s, g.x * s, g.pad * s, g.pad * s, g.rx * s); else x.rect(g.x * s, g.x * s, g.pad * s, g.pad * s);
      x.fill();
      x.imageSmoothingQuality = "high";
      x.drawImage(mark.canvas, g.ix * s, g.iy * s, g.iw * s, g.ih * s);
    }
    return c;
  }

  function download(blob, name) {
    var a = el("a", { href: URL.createObjectURL(blob), download: name });
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }

  // ---------- QR dialog ----------
  var dlg = $("#qrDialog");
  var sw = $("#swatches");
  COLOURS.forEach(function (c) {
    sw.appendChild(el("button", {
      type: "button", title: c.name, "aria-label": c.name, style: "background:" + c.hex, "aria-pressed": String(c.hex === state.colour),
      onclick: function () {
        state.colour = c.hex;
        sw.querySelectorAll("button").forEach(function (b) { b.setAttribute("aria-pressed", String(b === this)); }, this);
        drawPreview();
      }
    }));
  });
  $("#withLogo").addEventListener("change", drawPreview);
  dlg.addEventListener("click", function (e) { if (e.target === dlg || e.target.hasAttribute("data-close")) dlg.close(); });

  function openQr(l) {
    var short = state.base + l.slug;
    state.qr = { link: l, url: short, m: matrix(short) };
    $("#qrTitle").textContent = l.title;
    $("#qrUrl").textContent = short.replace(/^https?:\/\//, "");
    loadMark().then(drawPreview, function () { $("#withLogo").checked = false; $("#withLogo").disabled = true; drawPreview(); });
    if (!dlg.open) dlg.showModal();
  }

  function drawPreview() {
    if (!state.qr) return;
    $("#qrPreview").innerHTML = buildSvg(state.qr.m, state.colour, $("#withLogo").checked && !!mark);
  }

  function fileBase() { return "mbc-qr-" + state.qr.link.slug + ($("#withLogo").checked ? "" : "-plain"); }

  $("#dlSvg").addEventListener("click", function () {
    download(new Blob([buildSvg(state.qr.m, state.colour, $("#withLogo").checked && !!mark)], { type: "image/svg+xml" }), fileBase() + ".svg");
  });
  $("#dlPng").addEventListener("click", function () {
    buildPng(state.qr.m, state.colour, $("#withLogo").checked && !!mark, 2000).toBlob(function (b) { download(b, fileBase() + ".png"); }, "image/png");
  });
  $("#copyLink").addEventListener("click", function () { copy(state.qr.url); });

  load();
})();
