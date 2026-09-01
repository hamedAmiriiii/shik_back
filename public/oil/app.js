(function () {
  const API = (window.OIL && window.OIL.api) || "/api/oil";
  const LETTERS = (window.OIL && window.OIL.letters) || [
    "ب", "پ", "ت", "ث", "ج", "د", "س", "ص", "ط", "ع", "ق", "ک", "گ", "ل", "م", "ن", "و", "ه", "ی", "الف",
  ];
  const TOKEN_KEY = "oil_token";
  const SESSION_KEY = "oil_session";

  const LATIN_LETTER = {
    A: "الف", B: "ب", P: "پ", T: "ت", J: "ج", D: "د", S: "س", C: "ص",
    L: "ل", M: "م", N: "ن", V: "و", W: "و", H: "ه", Y: "ی", Q: "ق", G: "گ", K: "ک", E: "ع",
  };
  const FA_DIGITS = "۰۱۲۳۴۵۶۷۸۹";
  const AR_DIGITS = "٠١٢٣٤٥٦٧٨٩";

  const state = {
    token: localStorage.getItem(TOKEN_KEY) || "",
    session: readJson(SESSION_KEY),
    route: parseRoute(),
    customers: [],
    loading: false,
    error: "",
    busy: "",
    authTab: "login",
    form: emptyForm(),
    preview: "",
    ocrNote: "",
    customer: null,
    visits: [],
    q: "",
    showSettings: false,
    reminders: [],
    smsTab: "quota",
    smsQuota: null,
    smsPackages: [],
    smsOrders: [],
  };

  function emptyForm() {
    return {
      serial: "",
      letter: "ب",
      middle: "",
      province: "",
      phone: "",
      km: "",
      next_km: "",
      known: false,
    };
  }

  function readJson(key) {
    try {
      return JSON.parse(localStorage.getItem(key) || "null");
    } catch (e) {
      return null;
    }
  }

  function parseRoute() {
    const h = (location.hash || "#/").replace(/^#/, "") || "/";
    const parts = h.split("/").filter(Boolean);
    if (!parts.length) return { name: "home" };
    if (parts[0] === "login") return { name: "login" };
    if (parts[0] === "new") return { name: "new" };
    if (parts[0] === "sms") return { name: "sms" };
    if (parts[0] === "car" && parts[1]) return { name: "car", plate: decodeURIComponent(parts[1]) };
    return { name: "home" };
  }

  function go(hash) {
    if (location.hash === hash) {
      state.route = parseRoute();
      render();
      return;
    }
    location.hash = hash;
  }

  window.addEventListener("hashchange", () => {
    state.route = parseRoute();
    state.error = "";
    bootRoute();
  });

  function shopName() {
    return (state.session && state.session.shop && state.session.shop.name) || "تعویض روغن";
  }

  function intervalKm() {
    const n = state.session && state.session.shop && state.session.shop.oil_interval_km;
    return Number(n) > 0 ? Number(n) : 5000;
  }

  function smsBalance() {
    if (state.smsQuota && state.smsQuota.balance != null) return Number(state.smsQuota.balance);
    if (state.session && state.session.sms && state.session.sms.balance != null) {
      return Number(state.session.sms.balance);
    }
    return null;
  }

  function toEnDigits(s) {
    return String(s || "")
      .replace(/[۰-۹]/g, (d) => String(FA_DIGITS.indexOf(d)))
      .replace(/[٠-٩]/g, (d) => String(AR_DIGITS.indexOf(d)));
  }

  function onlyDigits(s, max) {
    return toEnDigits(s).replace(/\D/g, "").slice(0, max);
  }

  function normalizeLetter(letter) {
    let l = String(letter || "").trim();
    l = l.replace(/ي|ى|ئ/g, "ی").replace(/ك/g, "ک");
    if (LATIN_LETTER[l.toUpperCase()]) return LATIN_LETTER[l.toUpperCase()];
    return l;
  }

  function parsePlateText(text) {
    const raw = toEnDigits(String(text || "")).replace(/ي|ى|ئ/g, "ی").replace(/ك/g, "ک");
    const compact = raw.replace(/[\s\-_|٫،.]+/g, "");
    let m = compact.match(/^(\d{2})(.{1,4}?)(\d{3})(\d{2})$/);
    if (m) {
      return { serial: m[1], letter: normalizeLetter(m[2]), middle: m[3], province: m[4] };
    }
    m = raw.match(/(\d{2})\s*([A-Za-zآ-یالف]{1,4})\s*(\d{3})\s*(\d{2})/);
    if (m) {
      return { serial: m[1], letter: normalizeLetter(m[2]), middle: m[3], province: m[4] };
    }
    const digits = raw.replace(/\D/g, "");
    if (digits.length >= 7) {
      return {
        serial: digits.slice(0, 2),
        letter: "",
        middle: digits.slice(2, 5),
        province: digits.slice(5, 7),
      };
    }
    return null;
  }

  async function api(path, options) {
    const opts = options || {};
    const headers = Object.assign({ Accept: "application/json" }, opts.headers || {});
    if (state.token) headers.Authorization = "Bearer " + state.token;
    if (opts.body && !(opts.body instanceof FormData) && typeof opts.body !== "string") {
      headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch(API + path, Object.assign({}, opts, { headers }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.message || data.error || "خطا در ارتباط با سرور");
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  function saveSession(payload) {
    state.token = payload.token || state.token;
    state.session = payload;
    if (state.token) localStorage.setItem(TOKEN_KEY, state.token);
    localStorage.setItem(SESSION_KEY, JSON.stringify(payload));
  }

  function clearSession() {
    state.token = "";
    state.session = null;
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(SESSION_KEY);
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function fmtKm(n) {
    return Number(n || 0).toLocaleString("fa-IR");
  }

  function fmtToman(n) {
    if (n == null || n === "") return "—";
    return Number(n).toLocaleString("fa-IR") + " تومان";
  }

  async function bootRoute() {
    const r = state.route;
    if (!state.token) {
      if (r.name !== "login") go("#/login");
      else render();
      return;
    }
    if (r.name === "login") {
      go("#/");
      return;
    }
    if (r.name === "home") {
      await loadCustomers();
      loadQuotaSilent();
    }
    if (r.name === "car") await loadCar(r.plate);
    if (r.name === "sms") await loadSmsHub();
    render();
  }

  async function loadCustomers() {
    state.loading = true;
    render();
    try {
      const q = state.q ? "?q=" + encodeURIComponent(state.q) : "";
      const data = await api("/customers" + q);
      state.customers = data.data || data || [];
      state.error = "";
    } catch (e) {
      if (e.status === 401) {
        clearSession();
        go("#/login");
        return;
      }
      state.error = e.message;
    } finally {
      state.loading = false;
      render();
    }
  }

  async function loadCar(plate) {
    state.loading = true;
    render();
    try {
      const data = await api("/customers/" + encodeURIComponent(plate));
      state.customer = data.customer;
      state.visits = data.visits || [];
      state.error = "";
    } catch (e) {
      state.error = e.message;
      state.customer = null;
      state.visits = [];
    } finally {
      state.loading = false;
      render();
    }
  }

  async function loadReminders() {
    try {
      const data = await api("/reminders");
      state.reminders = data.data || data || [];
    } catch (e) {
      if (e.status === 401) {
        clearSession();
        go("#/login");
        return;
      }
      throw e;
    }
  }

  async function loadQuotaSilent() {
    try {
      const data = await api("/sms-quota");
      state.smsQuota = data;
      if (state.session) {
        state.session.sms = data;
        localStorage.setItem(SESSION_KEY, JSON.stringify(state.session));
      }
      if (state.route.name === "home") render();
    } catch (e) { /* ignore */ }
  }

  async function loadSmsHub() {
    state.loading = true;
    render();
    try {
      const quota = api("/sms-quota");
      const packs = api("/sms-packages");
      const orders = api("/sms-package-orders?per_page=20");
      const reminders = api("/reminders");
      const [q, p, o, r] = await Promise.all([quota, packs, orders, reminders]);
      state.smsQuota = q;
      if (state.session) {
        state.session.sms = q;
        localStorage.setItem(SESSION_KEY, JSON.stringify(state.session));
      }
      state.smsPackages = p.data || p || [];
      state.smsOrders = (o.data || o || []);
      state.reminders = r.data || r || [];
      state.error = "";
    } catch (e) {
      if (e.status === 401) {
        clearSession();
        go("#/login");
        return;
      }
      state.error = e.message;
    } finally {
      state.loading = false;
      render();
    }
  }

  function plateWidget(form) {
    const opts = LETTERS.map(function (l) {
      return '<option value="' + esc(l) + '"' + (form.letter === l ? " selected" : "") + ">" + esc(l) + "</option>";
    }).join("");
    return (
      '<div class="plate" id="plateBox">' +
        '<div class="plate-blue"><div class="flag">🇮🇷</div><div>I.R.<br>IRAN</div></div>' +
        '<div class="plate-body">' +
          '<input class="p-serial" id="pSerial" inputmode="numeric" maxlength="2" value="' + esc(form.serial) + '" placeholder="12">' +
          '<select class="p-letter" id="pLetter">' + opts + "</select>" +
          '<input class="p-middle" id="pMiddle" inputmode="numeric" maxlength="3" value="' + esc(form.middle) + '" placeholder="345">' +
        "</div>" +
        '<div class="plate-iran"><span>ایران</span>' +
          '<input class="p-prov" id="pProv" inputmode="numeric" maxlength="2" value="' + esc(form.province) + '" placeholder="22">' +
        "</div>" +
      "</div>"
    );
  }

  function bindPlateInputs() {
    const serial = document.getElementById("pSerial");
    const letter = document.getElementById("pLetter");
    const middle = document.getElementById("pMiddle");
    const prov = document.getElementById("pProv");
    if (!serial) return;
    serial.addEventListener("input", function () {
      state.form.serial = onlyDigits(this.value, 2);
      this.value = state.form.serial;
      if (this.value.length === 2) letter.focus();
      maybeLookup();
    });
    letter.addEventListener("change", function () {
      state.form.letter = this.value;
      middle.focus();
      maybeLookup();
    });
    middle.addEventListener("input", function () {
      state.form.middle = onlyDigits(this.value, 3);
      this.value = state.form.middle;
      if (this.value.length === 3) prov.focus();
      maybeLookup();
    });
    prov.addEventListener("input", function () {
      state.form.province = onlyDigits(this.value, 2);
      this.value = state.form.province;
      maybeLookup();
    });
  }

  let lookupTimer = null;
  function maybeLookup() {
    const f = state.form;
    if (f.serial.length !== 2 || f.middle.length !== 3 || f.province.length !== 2) return;
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(async function () {
      try {
        const plate = f.serial + f.letter + f.middle + f.province;
        const data = await api("/visits/lookup?plate=" + encodeURIComponent(plate));
        if (data.found && data.visit) {
          state.form.phone = data.visit.phone || state.form.phone;
          state.form.known = true;
          const phoneEl = document.getElementById("phone");
          if (phoneEl && !phoneEl.value) phoneEl.value = state.form.phone;
          const note = document.getElementById("knownNote");
          if (note) {
            note.style.display = "block";
            note.textContent =
              "قبلاً آمده: کیلومتر " + fmtKm(data.visit.km) + " — بعدی " + fmtKm(data.visit.next_km);
          }
        }
      } catch (e) { /* ignore */ }
    }, 280);
  }

  function renderAuth() {
    const tab = state.authTab;
    return (
      '<div class="screen">' +
        '<div class="brand"><div class="brand-mark">رو</div><div><h1>تعویض روغن</h1><p>ورود به پنل تعویض روغنی</p></div></div>' +
        '<div class="card">' +
          '<div class="tabs">' +
            '<button type="button" class="' + (tab === "login" ? "on" : "") + '" data-tab="login">ورود</button>' +
            '<button type="button" class="' + (tab === "register" ? "on" : "") + '" data-tab="register">ثبت‌نام</button>' +
          "</div>" +
          (state.error ? '<div class="alert alert-err">' + esc(state.error) + "</div>" : "") +
          (tab === "login" ? loginForm() : registerForm()) +
        "</div>" +
      "</div>"
    );
  }

  function loginForm() {
    return (
      '<form id="loginForm">' +
        '<label>موبایل</label><input name="username" inputmode="numeric" maxlength="11" required placeholder="09xxxxxxxxx">' +
        '<label>رمز عبور</label><input name="password" type="password" required>' +
        '<div class="mt"><button class="btn btn-primary" type="submit">ورود</button></div>' +
      "</form>"
    );
  }

  function registerForm() {
    return (
      '<form id="registerForm">' +
        '<label>نام تعویض روغنی</label><input name="shop_name" required placeholder="مثلاً تعویض روغن برادران">' +
        '<label>نام</label><input name="name" required>' +
        '<label>نام خانوادگی</label><input name="last_name" required>' +
        '<label>موبایل</label><input name="phone" inputmode="numeric" maxlength="11" required placeholder="09xxxxxxxxx">' +
        '<label>رمز عبور</label><input name="password" type="password" minlength="6" required>' +
        '<div class="row-btns"><button class="btn btn-ghost" type="button" id="sendCode">ارسال کد</button>' +
        '<input name="verification_code" inputmode="numeric" maxlength="5" placeholder="کد ۵ رقمی"></div>' +
        '<div class="mt"><button class="btn btn-primary" type="submit">ثبت‌نام و ورود</button></div>' +
      "</form>"
    );
  }

  function renderHome() {
    const items = state.customers
      .map(function (c) {
        return (
          '<button type="button" class="cust" data-plate="' + esc(c.plate) + '">' +
            '<div class="meta"><div class="name">' + esc(c.plate_display) + "</div>" +
            '<div class="sub">' + esc(c.phone) + " · " + esc(c.created_at_jalali || "") + "</div></div>" +
            '<div class="pill">بعدی ' + fmtKm(c.next_km) + "</div>" +
          "</button>"
        );
      })
      .join("");
    return (
      '<div class="screen">' +
        '<div class="topbar"><div><h2>' + esc(shopName()) + "</h2>" +
          '<div class="muted">مشتریان تعویض روغن' +
            (smsBalance() != null ? " · موجودی پیامک " + fmtKm(smsBalance()) : "") +
          "</div></div>" +
        '<div class="topbar-actions">' +
          '<button class="icon-btn" id="openSms" title="پیامک‌ها">✉</button>' +
          '<button class="icon-btn" id="openSettings" title="تنظیمات">⚙</button>' +
        "</div></div>" +
        '<input class="search" id="searchQ" placeholder="جستجوی پلاک یا موبایل — Enter" value="' + esc(state.q) + '">' +
        (state.error ? '<div class="alert alert-err">' + esc(state.error) + "</div>" : "") +
        (state.loading ? '<div class="muted">در حال بارگذاری…</div>' : "") +
        (items
          ? '<div class="list">' + items + "</div>"
          : '<div class="empty"><div class="big">🛢</div>هنوز مشتری ثبت نشده.<br>دکمه ایجاد را بزنید.</div>') +
        '<button class="fab" id="fabNew" title="ایجاد">+</button>' +
      "</div>" +
      (state.showSettings ? settingsOverlay() : "")
    );
  }

  function settingsOverlay() {
    const shop = (state.session && state.session.shop) || {};
    return (
      '<div class="overlay" id="settingsOverlay"><div class="box" style="text-align:right">' +
        "<h3 style=\"margin-top:0\">تنظیمات</h3>" +
        '<label>نام تعویض روغنی</label><input id="setName" value="' + esc(shop.name || "") + '">' +
        '<label>فاصله تعویض بعدی (کیلومتر)</label><input id="setInterval" inputmode="numeric" value="' + esc(shop.oil_interval_km || 5000) + '">' +
        '<div class="row-btns"><button class="btn btn-ghost" id="closeSettings">بستن</button>' +
        '<button class="btn btn-primary" id="saveSettings">ذخیره</button></div>' +
        '<div class="mt"><button class="btn btn-ghost" id="openSmsFromSettings">موجودی و پیامک‌ها</button></div>' +
        '<div class="mt"><button class="btn btn-danger" id="logoutBtn">خروج</button></div>' +
      "</div></div>"
    );
  }

  function renderNew() {
    const f = state.form;
    return (
      '<div class="screen">' +
        '<div class="topbar"><button class="icon-btn" id="backHome">→</button><h2>ثبت تعویض</h2><span></span></div>' +
        (state.error ? '<div class="alert alert-err">' + esc(state.error) + "</div>" : "") +
        (state.ocrNote ? '<div class="alert alert-info">' + esc(state.ocrNote) + "</div>" : "") +
        '<div class="camera-box" id="camBox">' +
          (state.preview
            ? '<img alt="پلاک" src="' + state.preview + '">'
            : '<div class="camera-hint">پلاک ماشین را با دوربین بگیرید.<br>خواندن پلاک روی خود گوشی انجام می‌شود.</div>') +
        "</div>" +
        '<input class="hidden-file" id="camInput" type="file" accept="image/*" capture="environment">' +
        '<input class="hidden-file" id="galInput" type="file" accept="image/*">' +
        '<div class="row-btns">' +
          '<button class="btn btn-primary" type="button" id="takePhoto">عکس پلاک</button>' +
          '<button class="btn btn-ghost" type="button" id="fromGallery">گالری</button>' +
        "</div>" +
        '<div class="mt">' + plateWidget(f) + "</div>" +
        '<div class="alert alert-info" id="knownNote" style="display:' + (f.known ? "block" : "none") + '"></div>' +
        '<label>موبایل صاحب ماشین</label><input id="phone" inputmode="numeric" maxlength="11" value="' + esc(f.phone) + '" placeholder="09xxxxxxxxx">' +
        '<div class="km-grid">' +
          '<div><label>کیلومتر فعلی</label><input id="km" inputmode="numeric" value="' + esc(f.km) + '" placeholder="12500"></div>' +
          '<div><label>تعویض بعدی</label><input id="nextKm" inputmode="numeric" value="' + esc(f.next_km) + '" placeholder="خودکار"></div>' +
        "</div>" +
        '<p class="muted mt">اگر پلاک خوانده نشد، همین‌جا دستی وارد کنید.</p>' +
        '<div class="mt"><button class="btn btn-primary" id="saveVisit">ثبت و ارسال پیامک</button></div>' +
      "</div>" +
      (state.busy ? '<div class="overlay"><div class="box"><div class="spin"></div><div>' + esc(state.busy) + "</div></div></div>" : "")
    );
  }

  function renderCar() {
    const c = state.customer;
    if (!c) {
      return (
        '<div class="screen"><div class="topbar"><button class="icon-btn" id="backHome">→</button><h2>مشتری</h2></div>' +
        (state.error ? '<div class="alert alert-err">' + esc(state.error) + "</div>" : '<div class="muted">یافت نشد</div>') +
        "</div>"
      );
    }
    const hist = state.visits
      .map(function (v) {
        return (
          '<div class="hist-item"><div>' + esc(v.created_at_jalali || "") + "</div>" +
          "<div>کیلومتر " + fmtKm(v.km) + " → بعدی " + fmtKm(v.next_km) + "</div></div>"
        );
      })
      .join("");
    return (
      '<div class="screen">' +
        '<div class="topbar"><button class="icon-btn" id="backHome">→</button><h2>' + esc(c.plate_display) + "</h2></div>" +
        '<div class="card"><div class="muted">موبایل</div><div style="font-weight:800;font-size:18px">' + esc(c.phone) + "</div>" +
        '<div class="muted mt">آخرین تعویض ' + fmtKm(c.km) + " — بعدی " + fmtKm(c.next_km) + "</div>" +
        '<div class="muted">تعداد مراجعه: ' + esc(c.visit_count) + "</div></div>" +
        '<h3>سوابق</h3><div class="hist">' + hist + "</div>" +
        '<div class="mt"><button class="btn btn-primary" id="newForCar">تعویض جدید</button></div>' +
      "</div>"
    );
  }

  function renderSms() {
    const tab = state.smsTab || "quota";
    const bal = smsBalance();
    const packs = (state.smsPackages || [])
      .map(function (p) {
        return (
          '<div class="pkg">' +
            '<div class="meta"><div class="name">' + esc(p.name) + "</div>" +
            '<div class="sub">' + fmtKm(p.sms_count) + " پیامک</div></div>" +
            '<div class="price">' + esc(fmtToman(p.price_toman)) + "</div>" +
            '<button class="btn btn-primary" style="width:auto;padding:8px 12px" data-buy="' + esc(p.id) + '">خرید</button>' +
          "</div>"
        );
      })
      .join("");
    const orders = (state.smsOrders || [])
      .map(function (o) {
        return (
          '<div class="cust">' +
            '<div class="meta"><div class="name">' + esc(o.package_name || "بسته") + " · " + fmtKm(o.sms_count) + " پیامک</div>" +
            '<div class="sub">' + esc(fmtToman(o.price_toman)) + (o.admin_note ? " · " + esc(o.admin_note) : "") + "</div></div>" +
            '<div class="pill">' + esc(o.status_label || o.status) + "</div>" +
          "</div>"
        );
      })
      .join("");
    const items = (state.reminders || [])
      .map(function (r) {
        const status = r.sms_sent ? "ارسال شد" : "ناموفق";
        const due = r.days_until_due == null
          ? ""
          : (r.days_until_due <= 0 ? "نوبت الان" : r.days_until_due + " روز مانده");
        return (
          '<div class="cust">' +
            '<div class="meta"><div class="name">' + esc(r.plate_display) + "</div>" +
            '<div class="sub">' + esc(r.phone) + " · " + esc(r.created_at_jalali || "") + "</div>" +
            '<div class="sub">' + esc(r.message).replace(/\n/g, " — ") + "</div></div>" +
            '<div class="pill">' + esc(status) + (due ? " · " + due : "") + "</div>" +
          "</div>"
        );
      })
      .join("");
    let body = "";
    if (tab === "quota") {
      body =
        '<div class="balance-card"><div class="muted">موجودی پیامک</div>' +
        '<div class="n">' + (bal == null ? "—" : fmtKm(bal)) + "</div>" +
        '<div class="muted">هر ۷۰ کاراکتر یک پیامک حساب می‌شود</div></div>' +
        "<h3>خرید بسته</h3>" +
        (packs ? '<div class="list">' + packs + "</div>" : '<div class="muted">بسته‌ای تعریف نشده.</div>') +
        '<h3 class="mt">درخواست‌های خرید</h3>' +
        (orders ? '<div class="list">' + orders + "</div>" : '<div class="muted">هنوز درخواستی ندارید.</div>') +
        '<p class="muted mt">پس از ثبت، ادمین تأیید می‌کند و موجودی شارژ می‌شود.</p>';
    } else {
      body =
        '<p class="muted">ماشین‌هایی که تا ۱۰ روز دیگر به کیلومتر تعویض می‌رسند.</p>' +
        '<button class="btn btn-primary mb" id="runReminders">بررسی نوبت‌ها و ارسال</button>' +
        (items
          ? '<div class="list">' + items + "</div>"
          : '<div class="empty"><div class="big">✉</div>هنوز پیامک یادآوری ارسال نشده.</div>');
    }
    return (
      '<div class="screen">' +
        '<div class="topbar"><button class="icon-btn" id="backHome">→</button><h2>پیامک</h2><span></span></div>' +
        (state.error ? '<div class="alert alert-err">' + esc(state.error) + "</div>" : "") +
        '<div class="sms-tabs">' +
          '<button type="button" class="' + (tab === "quota" ? "on" : "") + '" data-sms-tab="quota">موجودی و خرید</button>' +
          '<button type="button" class="' + (tab === "reminders" ? "on" : "") + '" data-sms-tab="reminders">یادآوری‌ها</button>' +
        "</div>" +
        (state.loading ? '<div class="muted">در حال بارگذاری…</div>' : "") +
        body +
      "</div>" +
      (state.busy ? '<div class="overlay"><div class="box"><div class="spin"></div><div>' + esc(state.busy) + "</div></div></div>" : "")
    );
  }

  function render() {
    const root = document.getElementById("app");
    if (!root) return;
    if (!state.token || state.route.name === "login") root.innerHTML = renderAuth();
    else if (state.route.name === "new") root.innerHTML = renderNew();
    else if (state.route.name === "car") root.innerHTML = renderCar();
    else if (state.route.name === "sms") root.innerHTML = renderSms();
    else root.innerHTML = renderHome();
    bind();
  }

  function bind() {
    document.querySelectorAll("[data-tab]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        state.authTab = btn.getAttribute("data-tab");
        state.error = "";
        render();
      });
    });
    const login = document.getElementById("loginForm");
    if (login) {
      login.addEventListener("submit", async function (e) {
        e.preventDefault();
        const fd = new FormData(login);
        state.error = "";
        try {
          const data = await api("/login", {
            method: "POST",
            body: { username: fd.get("username"), password: fd.get("password") },
          });
          saveSession(data);
          go("#/");
        } catch (err) {
          state.error = err.message;
          render();
        }
      });
    }
    const sendCode = document.getElementById("sendCode");
    if (sendCode) {
      sendCode.addEventListener("click", async function () {
        const form = document.getElementById("registerForm");
        const phone = form.phone.value;
        try {
          await api("/register/send-code", { method: "POST", body: { phone: phone } });
          state.error = "";
          alert("کد تأیید ارسال شد.");
        } catch (err) {
          state.error = err.message;
          render();
        }
      });
    }
    const register = document.getElementById("registerForm");
    if (register) {
      register.addEventListener("submit", async function (e) {
        e.preventDefault();
        const fd = new FormData(register);
        try {
          const data = await api("/register", {
            method: "POST",
            body: {
              name: fd.get("name"),
              last_name: fd.get("last_name"),
              phone: fd.get("phone"),
              password: fd.get("password"),
              shop_name: fd.get("shop_name"),
              verification_code: fd.get("verification_code"),
            },
          });
          saveSession(data);
          go("#/");
        } catch (err) {
          state.error = err.message;
          render();
        }
      });
    }
    const fab = document.getElementById("fabNew");
    if (fab) fab.addEventListener("click", function () {
      state.form = emptyForm();
      state.preview = "";
      state.ocrNote = "";
      go("#/new");
    });
    const search = document.getElementById("searchQ");
    if (search) {
      search.addEventListener("input", function () { state.q = this.value; });
      search.addEventListener("keydown", function (e) {
        if (e.key === "Enter") loadCustomers();
      });
    }
    document.querySelectorAll("[data-plate]").forEach(function (el) {
      el.addEventListener("click", function () {
        go("#/car/" + encodeURIComponent(el.getAttribute("data-plate")));
      });
    });
    const back = document.getElementById("backHome");
    if (back) back.addEventListener("click", function () { go("#/"); });
    const openSettings = document.getElementById("openSettings");
    if (openSettings) openSettings.addEventListener("click", function () {
      state.showSettings = true;
      render();
    });
    const openSms = document.getElementById("openSms");
    if (openSms) openSms.addEventListener("click", function () { go("#/sms"); });
    const openSmsFromSettings = document.getElementById("openSmsFromSettings");
    if (openSmsFromSettings) openSmsFromSettings.addEventListener("click", function () {
      state.showSettings = false;
      go("#/sms");
    });
    const runReminders = document.getElementById("runReminders");
    if (runReminders) runReminders.addEventListener("click", async function () {
      state.busy = "در حال بررسی نوبت‌ها…";
      state.error = "";
      render();
      try {
        const data = await api("/reminders/run", { method: "POST" });
        state.busy = "";
        const lines = (data.inspected || []).map(function (i) {
          const d = i.days_until_due == null ? "—" : i.days_until_due;
          const extra = i.remaining_km != null
            ? " — " + i.remaining_km + " کیلومتر ≈ " + (i.interval_days || "—") + " روز از ثبت"
            : "";
          return (i.plate || "") + ": " + (i.action || "") + " (" + d + " روز مانده)" + extra;
        });
        alert((data.message || "انجام شد.") + (lines.length ? "\n\n" + lines.join("\n") : ""));
        await loadSmsHub();
      } catch (err) {
        state.busy = "";
        state.error = err.message;
        render();
      }
    });
    document.querySelectorAll("[data-sms-tab]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        state.smsTab = btn.getAttribute("data-sms-tab");
        render();
      });
    });
    document.querySelectorAll("[data-buy]").forEach(function (btn) {
      btn.addEventListener("click", async function () {
        const id = btn.getAttribute("data-buy");
        const pack = (state.smsPackages || []).find(function (p) { return String(p.id) === String(id); });
        const label = pack
          ? (pack.name + " — " + fmtKm(pack.sms_count) + " پیامک — " + fmtToman(pack.price_toman))
          : "این بسته";
        if (!confirm("درخواست خرید «" + label + "» ثبت شود؟ بعد از تأیید ادمین موجودی شارژ می‌شود.")) {
          return;
        }
        state.busy = "در حال ثبت درخواست…";
        render();
        try {
          const data = await api("/sms-packages/" + id + "/purchase", { method: "POST" });
          state.busy = "";
          state.smsTab = "quota";
          alert(data.message || "درخواست ثبت شد.");
          await loadSmsHub();
        } catch (err) {
          state.busy = "";
          state.error = err.message;
          render();
        }
      });
    });
    const closeSettings = document.getElementById("closeSettings");
    if (closeSettings) closeSettings.addEventListener("click", function () {
      state.showSettings = false;
      render();
    });
    const saveSettings = document.getElementById("saveSettings");
    if (saveSettings) saveSettings.addEventListener("click", async function () {
      try {
        const data = await api("/shop", {
          method: "PATCH",
          body: {
            shop_name: document.getElementById("setName").value,
            oil_interval_km: Number(document.getElementById("setInterval").value || 5000),
          },
        });
        state.session = Object.assign({}, state.session, data);
        localStorage.setItem(SESSION_KEY, JSON.stringify(state.session));
        state.showSettings = false;
        render();
      } catch (err) {
        alert(err.message);
      }
    });
    const logoutBtn = document.getElementById("logoutBtn");
    if (logoutBtn) logoutBtn.addEventListener("click", async function () {
      try { await api("/logout", { method: "POST" }); } catch (e) { /* ignore */ }
      clearSession();
      state.showSettings = false;
      go("#/login");
    });
    bindPlateInputs();
    const phone = document.getElementById("phone");
    if (phone) phone.addEventListener("input", function () {
      state.form.phone = onlyDigits(this.value, 11);
      this.value = state.form.phone;
    });
    const km = document.getElementById("km");
    if (km) km.addEventListener("input", function () {
      state.form.km = onlyDigits(this.value, 7);
      this.value = state.form.km;
      const next = document.getElementById("nextKm");
      if (next && (!state.form.next_km || next.dataset.auto !== "0")) {
        const n = Number(state.form.km || 0) + intervalKm();
        state.form.next_km = String(n);
        next.value = state.form.next_km;
      }
    });
    const nextKm = document.getElementById("nextKm");
    if (nextKm) nextKm.addEventListener("input", function () {
      this.dataset.auto = "0";
      state.form.next_km = onlyDigits(this.value, 7);
      this.value = state.form.next_km;
    });
    const takePhoto = document.getElementById("takePhoto");
    const camInput = document.getElementById("camInput");
    if (takePhoto && camInput) takePhoto.addEventListener("click", function () { camInput.click(); });
    const fromGallery = document.getElementById("fromGallery");
    const galInput = document.getElementById("galInput");
    if (fromGallery && galInput) fromGallery.addEventListener("click", function () { galInput.click(); });
    if (camInput) camInput.addEventListener("change", function () { if (this.files[0]) handlePhoto(this.files[0]); });
    if (galInput) galInput.addEventListener("change", function () { if (this.files[0]) handlePhoto(this.files[0]); });
    const saveVisit = document.getElementById("saveVisit");
    if (saveVisit) saveVisit.addEventListener("click", submitVisit);
    const newForCar = document.getElementById("newForCar");
    if (newForCar && state.customer) {
      newForCar.addEventListener("click", function () {
        const p = state.customer.plate_parts || {};
        state.form = emptyForm();
        state.form.serial = p.serial || "";
        state.form.letter = p.letter || "ب";
        state.form.middle = p.middle || "";
        state.form.province = p.province || "";
        state.form.phone = state.customer.phone || "";
        state.form.known = true;
        state.preview = "";
        state.ocrNote = "پلاک از مشتری قبلی پر شد.";
        go("#/new");
      });
    }
  }

  function preprocessImage(img) {
    const canvas = document.createElement("canvas");
    const maxW = 1280;
    let w = img.width;
    let h = img.height;
    if (w > maxW) {
      h = Math.round((h * maxW) / w);
      w = maxW;
    }
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d");
    ctx.drawImage(img, 0, 0, w, h);
    const imageData = ctx.getImageData(0, 0, w, h);
    const d = imageData.data;
    let min = 255;
    let max = 0;
    for (let i = 0; i < d.length; i += 4) {
      const g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
      if (g < min) min = g;
      if (g > max) max = g;
      d[i] = d[i + 1] = d[i + 2] = g;
    }
    const range = max - min || 1;
    for (let i = 0; i < d.length; i += 4) {
      let v = ((d[i] - min) / range) * 255;
      v = v > 145 ? 255 : 0;
      d[i] = d[i + 1] = d[i + 2] = v;
    }
    ctx.putImageData(imageData, 0, 0);
    return canvas;
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error("خواندن تصویر ناموفق بود"));
      };
      img.src = url;
    });
  }

  async function handlePhoto(file) {
    state.busy = "در حال خواندن پلاک روی گوشی…";
    state.error = "";
    render();
    try {
      const img = await loadImage(file);
      const canvas = preprocessImage(img);
      state.preview = URL.createObjectURL(file);
      let text = "";
      if (window.Tesseract) {
        const worker = await Tesseract.createWorker("eng", 1, {
          logger: function (m) {
            if (m.status === "recognizing text" && m.progress) {
              state.busy = "خواندن پلاک… " + Math.round(m.progress * 100) + "٪";
              const box = document.querySelector(".overlay .box div:last-child");
              if (box) box.textContent = state.busy;
            }
          },
        });
        await worker.setParameters({
          tessedit_char_whitelist: "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ",
          tessedit_pageseg_mode: "6",
        });
        const result = await worker.recognize(canvas);
        text = (result && result.data && result.data.text) || "";
        await worker.terminate();
      }
      const parsed = parsePlateText(text);
      if (parsed && parsed.serial && parsed.middle && parsed.province) {
        state.form.serial = parsed.serial;
        state.form.middle = parsed.middle;
        state.form.province = parsed.province;
        if (parsed.letter && LETTERS.indexOf(parsed.letter) !== -1) state.form.letter = parsed.letter;
        state.ocrNote = parsed.letter
          ? "پلاک خوانده شد. اگر اشتباه است اصلاح کنید."
          : "اعداد پلاک خوانده شد. حرف پلاک را خودتان انتخاب کنید.";
        maybeLookup();
      } else {
        state.ocrNote = "پلاک خوانده نشد. پلاک و موبایل را دستی وارد کنید.";
      }
    } catch (err) {
      state.ocrNote = "پردازش تصویر انجام نشد. پلاک را دستی وارد کنید.";
      state.error = err.message || "";
    } finally {
      state.busy = "";
      render();
    }
  }

  async function submitVisit() {
    const f = state.form;
    if (f.serial.length !== 2 || f.middle.length !== 3 || f.province.length !== 2) {
      state.error = "پلاک را کامل وارد کنید.";
      render();
      return;
    }
    if (!/^09\d{9}$/.test(f.phone)) {
      state.error = "شماره موبایل را ۱۱ رقمی وارد کنید.";
      render();
      return;
    }
    if (!f.km) {
      state.error = "کیلومتر ماشین را وارد کنید.";
      render();
      return;
    }
    state.busy = "در حال ثبت…";
    state.error = "";
    render();
    try {
      const body = {
        serial: f.serial,
        letter: f.letter,
        middle: f.middle,
        province: f.province,
        phone: f.phone,
        km: Number(f.km),
      };
      if (f.next_km) body.next_km = Number(f.next_km);
      const data = await api("/visits", { method: "POST", body: body });
      state.busy = "";
      alert(data.message || "ثبت شد.");
      go("#/");
    } catch (err) {
      state.busy = "";
      state.error = err.message;
      render();
    }
  }

  bootRoute();
})();
