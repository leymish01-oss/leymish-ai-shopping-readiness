/* LeyMish Store Team — the approvals inbox (0.3.0, P-023 §1).

   Every decision is made here, in wp-admin, behind manage_woocommerce and a nonce. The site token never reaches
   this file: PHP holds it and calls the LeyMish service server-side.

   Everything is built with textContent and createElement, so nothing the service returns is ever parsed as HTML. */
(function () {
  "use strict";
  var cfg = window.LASR_APPROVALS || {};
  var t = cfg.i18n || {};
  var root = document.getElementById("lst-approvals");
  if (!root || !cfg.ajax) return;

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === "text") n.textContent = attrs[k];
      else if (k === "cls") n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === "string" ? document.createTextNode(c) : c); });
    return n;
  }
  function when(ts) { return ts ? new Date(ts * 1000).toLocaleString() : ""; }

  function post(action, extra) {
    var body = new URLSearchParams();
    body.set("action", action);
    body.set("_ajax_nonce", cfg.nonce);
    Object.keys(extra || {}).forEach(function (k) {
      var v = extra[k];
      if (Array.isArray(v)) v.forEach(function (x) { body.append(k + "[]", x); });
      else if (v && typeof v === "object") Object.keys(v).forEach(function (i) {
        Object.keys(v[i]).forEach(function (f) { body.append(k + "[" + i + "][" + f + "]", v[i][f]); });
      });
      else body.set(k, v);
    });
    return fetch(cfg.ajax, { method: "POST", credentials: "same-origin", body: body }).then(function (r) { return r.json(); });
  }

  // ---------------------------------------------------------------- one waiting change
  var chosen = {};   // id -> true, for the bulk buttons

  function card(item, done) {
    var status = el("p", { cls: "lst-status", role: "status" });
    var buttons = el("p", { cls: "lst-actions" });
    var editor = el("div", { cls: "lst-editor", hidden: "hidden" });

    function settle(message, gone) {
      status.textContent = message;
      buttons.textContent = "";
      editor.hidden = true;
      if (gone) done(item.id);
    }
    function send(decision, edit) {
      Array.prototype.forEach.call(buttons.querySelectorAll("button"), function (b) { b.disabled = true; });
      status.textContent = t.loading || "…";
      post("lasr_approvals_decide", Object.assign({ id: item.id, decision: decision }, edit || {}))
        .then(function (r) {
          if (!r || !r.success) {
            status.textContent = (r && r.data && r.data.message) || t.failed;
            Array.prototype.forEach.call(buttons.querySelectorAll("button"), function (b) { b.disabled = false; });
            return;
          }
          if (decision === "reject") settle(t.rejected, true);
          else settle(r.data && r.data.applied ? t.applied : t.queued, true);
        })
        .catch(function () {
          status.textContent = t.offline;
          Array.prototype.forEach.call(buttons.querySelectorAll("button"), function (b) { b.disabled = false; });
        });
    }

    var tick = el("input", { type: "checkbox", id: "lst-pick-" + item.id, "aria-label": (item.product || "") + " " + (item.field || "") });
    tick.addEventListener("change", function () {
      if (tick.checked) chosen[item.id] = true; else delete chosen[item.id];
    });

    // Now vs Proposed, side by side and both plain text.
    var change = el("div", { cls: "lst-change" }, [
      el("p", { cls: "lst-k", text: t.now }), el("p", { cls: "lst-now", text: String(item.now === "" ? "(empty)" : item.now) }),
      el("p", { cls: "lst-k", text: t.proposed }), el("p", { cls: "lst-proposed", text: String(item.proposed) }),
    ]);

    // Attributes the vocabulary refused: shown, never hidden, so an owner can see what we left out and why.
    var dropped = (item.dropped || []).length
      ? el("details", { cls: "lst-dropped" }, [el("summary", { text: t.dropped + " (" + item.dropped.length + ")" }),
        el("ul", {}, item.dropped.map(function (d) { return el("li", { text: String(d) }); }))])
      : null;

    function openEditor() {
      editor.textContent = "";
      if (item.editable === "attributes") {
        var rows = String(item.proposed).split(";").map(function (s) { return s.trim(); }).filter(Boolean).map(function (pair) {
          var at = pair.indexOf(":");
          return { name: at < 0 ? pair : pair.slice(0, at).trim(), value: at < 0 ? "" : pair.slice(at + 1).trim() };
        });
        var inputs = rows.map(function (r, i) {
          var nameIn = el("input", { type: "text", value: r.name, size: "16", "aria-label": "Attribute " + (i + 1) + " name" });
          var valIn = el("input", { type: "text", value: r.value, size: "34", maxlength: "60", "aria-label": "Attribute " + (i + 1) + " value" });
          editor.appendChild(el("p", { cls: "lst-attr-row" }, [nameIn, " ", valIn]));
          return { name: nameIn, value: valIn };
        });
        editor.appendChild(el("p", { cls: "description", text: "Up to 6 attributes, 60 characters each, facts from your product page only." }));
        addEditorButtons(function () {
          return { edit_attributes: inputs.map(function (i) { return { name: i.name.value, value: i.value.value }; }) };
        });
      } else {
        var box = el("textarea", { rows: "4", cls: "large-text", "aria-label": t.proposed });
        box.value = String(item.proposed);
        editor.appendChild(box);
        addEditorButtons(function () { return { edit_value: box.value }; });
      }
      editor.hidden = false;
      var first = editor.querySelector("input, textarea");
      if (first) first.focus();
    }
    function addEditorButtons(collect) {
      var save = el("button", { type: "button", cls: "button button-primary", text: t.save });
      var cancel = el("button", { type: "button", cls: "button", text: t.cancel });
      save.addEventListener("click", function () { send("approve", collect()); });
      cancel.addEventListener("click", function () { editor.hidden = true; });
      editor.appendChild(el("p", {}, [save, " ", cancel]));
    }

    function button(label, cls, fn) {
      var b = el("button", { type: "button", cls: "button " + cls, text: label });
      if (!cfg.connected) { b.disabled = true; b.title = t.notConn; }
      b.addEventListener("click", fn);
      buttons.appendChild(b);
      buttons.appendChild(document.createTextNode(" "));
      return b;
    }
    button(t.approve, "button-primary", function () { send("approve"); });
    if (item.editable) button(t.edit, "", openEditor);
    button(t.reject, "", function () { send("reject"); });

    return el("li", { cls: "lst-approval" }, [
      el("p", { cls: "lst-approval-head" }, [tick, " ",
        el("label", { for: "lst-pick-" + item.id }, [el("strong", { text: (item.product || item.kind) + " · " + (item.field || "") })])]),
      item.why ? el("p", { cls: "description", text: item.why }) : null,
      change, dropped, buttons, editor, status,
    ]);
  }

  // ---------------------------------------------------------------- the page
  function draw(data) {
    chosen = {};
    root.textContent = "";
    var waiting = data.waiting || [];
    var history = data.history || [];

    root.appendChild(el("h2", { text: t.waiting + " (" + waiting.length + ")" }));
    if (!waiting.length) {
      root.appendChild(el("p", { text: t.empty }));
    } else {
      var bulk = el("p", { cls: "lst-bulk" });
      var bulkStatus = el("span", { cls: "lst-status", role: "status" });
      var all = el("button", { type: "button", cls: "button", text: t.selectAll });
      all.addEventListener("click", function () {
        Array.prototype.forEach.call(root.querySelectorAll(".lst-approval input[type=checkbox]"), function (c) {
          if (!c.checked) { c.checked = true; c.dispatchEvent(new Event("change")); }
        });
      });
      function bulkButton(label, decision, cls) {
        var b = el("button", { type: "button", cls: "button " + cls, text: label });
        if (!cfg.connected) { b.disabled = true; b.title = t.notConn; }
        b.addEventListener("click", function () {
          var ids = Object.keys(chosen);
          if (!ids.length) { bulkStatus.textContent = t.chooseSome; return; }
          b.disabled = true;
          bulkStatus.textContent = t.loading;
          post("lasr_approvals_decide", { ids: ids, decision: decision })
            .then(function (r) { load(); })
            .catch(function () { bulkStatus.textContent = t.offline; b.disabled = false; });
        });
        return b;
      }
      bulk.appendChild(all);
      bulk.appendChild(document.createTextNode(" "));
      bulk.appendChild(bulkButton(t.bulkYes, "approve", "button-primary"));
      bulk.appendChild(document.createTextNode(" "));
      bulk.appendChild(bulkButton(t.bulkNo, "reject", ""));
      bulk.appendChild(document.createTextNode(" "));
      bulk.appendChild(bulkStatus);
      root.appendChild(bulk);

      var list = el("ul", { cls: "lst-approvals" });
      var left = waiting.length;
      waiting.forEach(function (item) {
        list.appendChild(card(item, function (id) {
          delete chosen[id];
          left -= 1;
          if (left <= 0) load();   // the inbox is empty: redraw so history and the badge catch up
        }));
      });
      root.appendChild(list);
    }

    root.appendChild(el("h2", { text: t.history }));
    // 2.0.1: old suggestions that expired before the attribute rules are one plain row, not a wall of raw text
    var expired = history.filter(function (h) { return h.status === "expired"; });
    var real = history.filter(function (h) { return h.status !== "expired"; });
    var outcome = t.outcome || {};
    var rows = real.map(function (h) {
      var by = h.by ? h.by + (h.decided_at ? " · " + when(h.decided_at) : "") : (t.unknownBy || "");
      return el("tr", {}, [
        el("td", { text: when(h.at) }), el("td", { text: h.product || "" }), el("td", { text: h.field || "" }),
        el("td", { text: String(h.now === "" ? t.empty_was : h.now) }), el("td", { text: String(h.proposed) }),
        el("td", { text: (outcome[h.status] || h.status) + (h.error ? ": " + h.error : "") }),
        el("td", { text: by }),
      ]);
    });
    if (expired.length) {
      rows.push(el("tr", { cls: "lst-expired" }, [el("td", { colspan: "7" }, [
        el("strong", { text: (t.expired || "Expired before the new rules (%d)").replace("%d", expired.length) }),
        document.createTextNode(" " + (t.expiredWhy || "")),
      ])]));
    }
    if (!rows.length) rows = [el("tr", {}, [el("td", { colspan: "7", text: t.nothing || "Nothing yet." })])];
    var cols = t.cols || ["When", "Product", "Field", "Was", "Became", "Outcome", "Approved by"];
    root.appendChild(el("div", { cls: "lst-table-wrap" }, [el("table", { cls: "widefat striped lst-table" }, [
      el("thead", {}, [el("tr", {}, cols.map(function (h) { return el("th", { scope: "col", text: h }); }))]),
      el("tbody", {}, rows),
    ])]));
  }

  function load() {
    root.textContent = "";
    root.appendChild(el("p", { text: t.loading }));
    post("lasr_approvals", {})
      .then(function (r) {
        if (r && r.success) draw(r.data);
        else { root.textContent = ""; root.appendChild(el("p", { text: (r && r.data && r.data.message) || t.failed })); }
      })
      .catch(function () { root.textContent = ""; root.appendChild(el("p", { text: t.offline })); });
  }

  load();
})();
