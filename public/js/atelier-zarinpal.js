/**
 * کامپوننت پرداخت زرین‌پال برای فرانت (فروشگاه / روغن).
 *
 * AtelierZarinpal.catalog({ apiBase, token })
 * AtelierZarinpal.start({ apiBase, token, type, itemId, returnUrl })
 * AtelierZarinpal.readReturn()  // بعد از برگشت از درگاه
 *
 * type: "sms_package" | "shop_plan"
 */
(function (root) {
  function joinUrl(base, path) {
    return String(base || "").replace(/\/+$/, "") + "/" + String(path || "").replace(/^\/+/, "");
  }

  async function request(opts) {
    const headers = Object.assign(
      { Accept: "application/json", "Content-Type": "application/json" },
      opts.headers || {}
    );
    if (opts.token) headers.Authorization = "Bearer " + opts.token;
    const res = await fetch(joinUrl(opts.apiBase, opts.path), {
      method: opts.method || "GET",
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      const err = new Error(data.message || data.error || "خطا در ارتباط با درگاه");
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  var AtelierZarinpal = {
    catalog: function (opts) {
      opts = opts || {};
      return request({
        apiBase: opts.apiBase,
        token: opts.token,
        path: "payments/catalog",
      });
    },

    start: async function (opts) {
      opts = opts || {};
      const type = opts.type;
      const itemId = opts.itemId || opts.item_id || opts.id;
      if (!type || !itemId) {
        throw new Error("type و itemId لازم است.");
      }
      const data = await request({
        apiBase: opts.apiBase,
        token: opts.token,
        method: "POST",
        path: "payments/start",
        body: {
          type: type,
          item_id: Number(itemId),
          return_url: opts.returnUrl || opts.return_url || (typeof location !== "undefined" ? location.href.split("#")[0] : undefined),
        },
      });
      const url = data.payment_url || (data.payment && data.payment.payment_url);
      if (!url) {
        throw new Error("آدرس درگاه برنگشت.");
      }
      if (opts.redirect !== false && typeof location !== "undefined") {
        location.href = url;
      }
      return data;
    },

    status: function (opts) {
      opts = opts || {};
      return request({
        apiBase: opts.apiBase,
        token: opts.token,
        path: "payments/" + encodeURIComponent(opts.authority),
      });
    },

    readReturn: function (search) {
      const q = new URLSearchParams(search || (typeof location !== "undefined" ? location.search : ""));
      const payment = q.get("payment");
      if (!payment) return null;
      return {
        ok: payment === "ok",
        payment: payment,
        authority: q.get("authority"),
        type: q.get("type"),
        item_id: q.get("item_id"),
        ref_id: q.get("ref_id"),
        message: q.get("message"),
      };
    },
  };

  root.AtelierZarinpal = AtelierZarinpal;
})(typeof window !== "undefined" ? window : this);
