/* LeyMish Store Team dashboard (prototype). Renders the 8 sections from the JSON the page embeds.
   Everything is built with textContent (no HTML from data), and works with the keyboard: tabs follow the WAI-ARIA
   tabs pattern (arrow keys move between tabs). */
(function () {
  "use strict";
  var app = document.getElementById("lst-app");
  var raw = document.getElementById("lst-data");
  if (!app || !raw) return;
  var d = {};
  try { d = JSON.parse(raw.textContent) || {}; } catch (e) { d = {}; }
  var live = app.getAttribute("data-source") === "store";

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
  function table(head, rows) {
    return el("table", { cls: "widefat striped lst-table" }, [
      el("thead", {}, [el("tr", {}, head.map(function (h) { return el("th", { scope: "col", text: h }); }))]),
      el("tbody", {}, rows.length ? rows.map(function (r) { return el("tr", {}, r.map(function (c) { return typeof c === "string" || typeof c === "number" ? el("td", { text: String(c) }) : el("td", {}, [c]); })); })
        : [el("tr", {}, [el("td", { colspan: String(head.length), text: "Nothing here yet." })])]),
    ]);
  }
  var agentName = {};
  (d.team || []).forEach(function (a) { agentName[a.id] = a.name; });
  var name = function (id) { return agentName[id] || id; };

  var sections = {
    overview: ["Overview", function () {
      var o = d.overview || {};
      var c = d.credits || {};
      var st = d.store || {};
      // P-025: which plan this store is on, in plain words (only when the service says so; the sample has none).
      var plan = st.plan_label ? el("p", { cls: "lst-plan" }, [el("span", { cls: "lst-plan-badge", text: st.plan_label }), el("span", { cls: "lst-plan-about", text: st.plan_about || "" })]) : null;
      return [
        plan,
        el("div", { cls: "lst-cards" }, [
          el("div", { cls: "lst-card" }, [el("div", { cls: "lst-k", text: "Approvals waiting" }), el("div", { cls: "lst-v", text: String(o.approvals_waiting || 0) })]),
          el("div", { cls: "lst-card" }, [el("div", { cls: "lst-k", text: "Done this week" }), el("div", { cls: "lst-v", text: String(o.done_this_week || 0) })]),
          el("div", { cls: "lst-card" }, [el("div", { cls: "lst-k", text: "Credits left" }), el("div", { cls: "lst-v", text: String(c.left == null ? "—" : c.left) })]),
        ]),
        el("h2", { text: "This week's goals" }),
        el("ul", { cls: "lst-goals" }, (o.goals || []).map(function (g) { return el("li", { text: g }); })),
        el("h2", { text: "Latest" }),
        el("ul", { cls: "lst-feed" }, (d.activity || []).slice(0, 5).map(function (a) { return el("li", {}, [el("strong", { text: name(a.agent) + ": " }), a.message]); })),
      ];
    }],
    team: ["Team", function () {
      return [el("p", { cls: "description", text: "Who's hired for this store, what each one does and what it may touch." }),
        table(["Agent", "Role", "Skills", "Status"], (d.team || []).map(function (a) {
          return [a.name, a.role, (a.skills || []).join(", "), a.hired ? "Hired" : a.available ? "Available on your plan" : "On a higher plan"];
        }))];
    }],
    plan: ["Weekly plan", function () {
      var p = d.plan;
      if (!p) return [el("p", { text: "The CEO writes the first plan on Sunday night (Monday morning in Australia)." })];
      return [el("p", { cls: "description", text: "Week of " + p.week }),
        el("ul", { cls: "lst-goals" }, (p.goals || []).map(function (g) { return el("li", { text: g }); })),
        table(["Agent", "Task", "Why", "Credits", "Status"], (p.tasks || []).map(function (t) { return [name(t.agent), t.kind, t.why || "", t.credits, t.status]; }))];
    }],
    // 0.3.0 (P-023): deciding happens on its own page, Store Team → Approvals, which also has edit-then-approve,
    // bulk and the history. This tab is the read-only preview of what is waiting.
    approvals: ["Approvals", function () {
      var list = d.approvals || [];
      var inbox = app.getAttribute("data-inbox");
      var open = inbox ? el("p", {}, [el("a", { cls: "button button-primary", href: inbox, text: list.length ? "Review " + list.length + " waiting" : "Open your approvals inbox" })]) : null;
      if (!list.length) return [el("p", { text: "Nothing waiting for you." }), open];
      return [el("p", { cls: "description", text: live ? "Nothing below happens until you approve it, on the Approvals page." : "Demo: these are real proposals for mishbio.us. Connect your store to decide on its own." }),
        open,
        el("ul", { cls: "lst-approvals" }, list.map(function (a) {
          var pv = a.preview;
          return el("li", { cls: "lst-approval" }, [
            el("strong", { text: name(a.agent) + " · " + (pv ? pv.product + " · " + pv.field : a.kind) }), el("p", { text: a.why || "" }),
            pv ? el("div", { cls: "lst-change" }, [el("p", { cls: "lst-k", text: "Now" }), el("p", { text: pv.before }), el("p", { cls: "lst-k", text: "Proposed" }), el("p", { text: pv.after })])
              : el("p", { cls: "description", text: "The exact change appears here before you decide." }),
            el("p", { cls: "description", text: (a.credits || 0) + " credits" }),
          ]);
        }))];
    }],
    activity: ["Activity", function () {
      var wrap = el("div");
      var sel = el("select", { id: "lst-agent-filter" }, [el("option", { value: "", text: "All agents" })].concat((d.team || []).map(function (a) { return el("option", { value: a.id, text: a.name }); })));
      var list = el("ul", { cls: "lst-feed" });
      function draw() {
        list.textContent = "";
        (d.activity || []).filter(function (a) { return !sel.value || a.agent === sel.value; }).forEach(function (a) {
          list.appendChild(el("li", {}, [el("time", { text: when(a.at) }), " ", el("strong", { text: name(a.agent) + ": " }), a.message]));
        });
      }
      sel.addEventListener("change", draw);
      draw();
      wrap.appendChild(el("p", { cls: "description", text: "The real log of what the agents decided and did. Nothing here is made up for show." }));
      wrap.appendChild(el("label", { for: "lst-agent-filter", text: "Show: " }));
      wrap.appendChild(sel);
      wrap.appendChild(list);
      return [wrap];
    }],
    results: ["Results", function () {
      return [el("p", { cls: "description", text: "Every change, before and after. Each one can be undone." }),
        table(["When", "Agent", "Product", "Change", "Before", "After", ""], (d.results || []).map(function (r) {
          var show = function (v) { return v ? Object.values(v).map(String).join(" ").slice(0, 160) || "(empty)" : "(empty)"; };
          var cell;
          if (r.status === "reverted") cell = el("span", { cls: "description", text: "Reverted" });
          else if (live && r.id) {
            var st = el("span", { role: "status" });
            var b = el("button", { type: "button", cls: "button button-small", text: "Revert" });
            b.addEventListener("click", function () {
              b.disabled = true;
              fetch(app.getAttribute("data-ajax"), { method: "POST", credentials: "same-origin",
                body: new URLSearchParams({ action: "lasr_team_revert", _ajax_nonce: app.getAttribute("data-nonce"), id: r.id }) })
                .then(function (x) { return x.json(); })
                .then(function (x) { st.textContent = x.success ? " Reverted." : " Couldn't revert."; })
                .catch(function () { st.textContent = " Couldn't reach the server."; b.disabled = false; });
            });
            cell = el("span", {}, [b, st]);
          } else cell = el("span", { text: "" });
          return [when(r.done_at), name(r.agent), r.product || "", r.kind, show(r.before), show(r.after), cell];
        }))];
    }],
    credits: ["Credits", function () {
      var c = d.credits || {};
      var pct = c.budget ? Math.min(100, Math.round(100 * (c.used || 0) / c.budget)) : 0;
      return [el("p", { text: (c.used || 0) + " of " + (c.budget || 0) + " credits used this month · " + (c.left || 0) + " left · daily cap " + (c.daily_cap || 0) }),
        el("div", { cls: "lst-bar", role: "progressbar", "aria-valuenow": String(pct), "aria-valuemin": "0", "aria-valuemax": "100", "aria-label": "Credits used" }, [el("i", { style: "width:" + pct + "%" })]),
        el("p", { cls: "description", text: "When credits run out, work pauses until next month. You're never billed extra." }),
        table(["Action", "Credits"], Object.keys(c.prices || {}).map(function (k) { return [k.replace(/_/g, " "), c.prices[k]]; }))];
    }],
    settings: ["Settings", function () {
      var s = d.settings || {};
      var words = { auto: "Done automatically (logged, undoable)", ask: "Asks you first", never: "Never" };
      var mode = s.autonomy === "ask" ? "Ask before every change (nothing happens until you approve)" : "Normal (small, undoable fixes happen automatically; everything else asks)";
      return [el("p", {}, [el("strong", { text: "Mode: " }), mode]), el("h2", { text: "What the team may do" }),
        table(["Action", "Rule"], Object.keys(s.policy || {}).map(function (k) { return [k.replace(/_/g, " "), words[s.policy[k]] || s.policy[k]]; })),
        el("p", { cls: "description", text: "Changing these rules, budgets and the pause switch arrive in the next version. Payments, shipping, tax, refunds, users, plugins and deleting anything are always off." })];
    }],
  };

  var keys = Object.keys(sections);
  var tablist = el("div", { role: "tablist", "aria-label": "Store Team sections", cls: "nav-tab-wrapper lst-tabs" });
  var panel = el("div", { role: "tabpanel", id: "lst-panel", tabindex: "0", cls: "lst-panel" });
  var tabs = keys.map(function (k, i) {
    var t = el("button", { type: "button", role: "tab", id: "lst-tab-" + k, "aria-controls": "lst-panel", cls: "nav-tab", text: sections[k][0] });
    t.addEventListener("click", function () { show(i); });
    t.addEventListener("keydown", function (e) {
      if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
        e.preventDefault();
        var j = (i + (e.key === "ArrowRight" ? 1 : keys.length - 1)) % keys.length;
        show(j);
        tabs[j].focus();
      }
    });
    tablist.appendChild(t);
    return t;
  });
  function show(i) {
    tabs.forEach(function (t, j) {
      t.setAttribute("aria-selected", i === j ? "true" : "false");
      t.tabIndex = i === j ? 0 : -1;
      t.classList.toggle("nav-tab-active", i === j);
    });
    panel.setAttribute("aria-labelledby", tabs[i].id);
    panel.textContent = "";
    sections[keys[i]][1]().forEach(function (n) { if (n) panel.appendChild(n); });
    try { history.replaceState(null, "", "#" + keys[i]); } catch (e) { /* not important */ }
  }
  app.textContent = "";
  app.appendChild(tablist);
  app.appendChild(panel);
  var start = keys.indexOf((location.hash || "").slice(1));
  show(start < 0 ? 0 : start);
})();
