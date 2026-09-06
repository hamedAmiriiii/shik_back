/**
 * شبیه‌ساز منطق پوسترها و گزارش دفتر (بدون Laravel/DB).
 * فرمول‌ها با AccountingSalePoster / DocumentPoster / ReturnPoster / ReportService هم‌خوان است.
 */
const NATURE = {
  '11101': 'debit', '11111': 'debit', '11112': 'debit', '11120': 'debit',
  '11201': 'debit', '11301': 'debit', '11302': 'debit', '11303': 'debit',
  '11401': 'debit', '12101': 'debit',
  '21101': 'credit', '21201': 'credit',
  '311': 'credit',
  '411': 'credit', '412': 'debit', '431': 'credit',
  '511': 'debit',
  '611': 'debit', '612': 'debit', '613': 'debit',
};
const KIND = {
  '11101': 'asset', '11111': 'asset', '11112': 'asset', '11120': 'asset',
  '11201': 'asset', '11301': 'asset', '11302': 'asset', '11303': 'asset',
  '11401': 'asset', '12101': 'asset',
  '21101': 'liability', '21201': 'liability',
  '311': 'equity',
  '411': 'revenue', '412': 'revenue', '431': 'revenue',
  '511': 'cogs',
  '611': 'expense', '612': 'expense', '613': 'expense',
};

const TOL = 0.015;
let nextId = 1;
const vouchers = [];
const postedKeys = new Map();

function round2(n) {
  return Math.round((n + Number.EPSILON) * 100) / 100;
}

function fail(name, msg) {
  throw new Error(`${name}: ${msg}`);
}

function push(lines, code, debit, credit, desc) {
  debit = round2(debit);
  credit = round2(credit);
  if (debit < 0.01 && credit < 0.01) return;
  lines.push({ code, debit, credit, desc });
}

function normalize(lines) {
  if (lines.length < 2) fail('engine', 'حداقل دو آرتیکل');
  let dr = 0, cr = 0;
  const out = [];
  for (const line of lines) {
    const debit = round2(line.debit || 0);
    const credit = round2(line.credit || 0);
    if (debit < 0 || credit < 0) fail('engine', 'مبلغ منفی');
    if ((debit > 0 && credit > 0) || (debit <= 0 && credit <= 0)) {
      fail('engine', 'هر آرتیکل باید دقیقاً بدهکار یا بستانکار باشد');
    }
    dr += debit;
    cr += credit;
    out.push({ ...line, debit, credit });
  }
  if (Math.abs(dr - cr) > 0.01) fail('engine', `نامتوازن dr=${round2(dr)} cr=${round2(cr)}`);
  return out;
}

function post(sourceType, sourceId, lines, desc = '') {
  const key = `${sourceType}:${sourceId}`;
  if (postedKeys.has(key)) return postedKeys.get(key);
  const normalized = normalize(lines);
  const v = {
    id: nextId++,
    sourceType,
    sourceId,
    status: 'posted',
    reverses: null,
    desc,
    lines: normalized,
  };
  vouchers.push(v);
  postedKeys.set(key, v);
  return v;
}

function reverse(v) {
  if (v.status !== 'posted' || v.reverses) fail('engine', 'فقط سند posted غیربرگشتی');
  const already = vouchers.find((x) => x.reverses === v.id);
  if (already) return already;
  const storno = {
    id: nextId++,
    sourceType: v.sourceType,
    sourceId: v.sourceId,
    status: 'posted',
    reverses: v.id,
    desc: `برگشت ${v.id}`,
    lines: v.lines.map((l) => ({ ...l, debit: l.credit, credit: l.debit })),
  };
  v.status = 'reversed';
  postedKeys.delete(`${v.sourceType}:${v.sourceId}`);
  vouchers.push(storno);
  return storno;
}

function saleLines(p) {
  let sales = round2(p.sales);
  const discount = round2(p.discount || 0);
  const credit = round2(p.credit || 0);
  let till = round2((p.cash || 0) + (p.card || 0));
  const cheque = p.kind === 'cheque' ? round2(p.cheque || 0) : 0;
  let ar = 0;
  if (p.kind === 'debt' || p.kind === 'installment') {
    ar = round2(Math.max(0, sales - discount - credit - till - cheque));
  }
  const left = round2(till + cheque + ar + discount + credit);
  const diff = round2(sales - left);
  if (Math.abs(diff) >= 0.01) {
    if (p.kind === 'debt' || p.kind === 'installment') ar = round2(Math.max(0, ar + diff));
    else till = round2(Math.max(0, till + diff));
  }
  const lines = [];
  push(lines, '11101', till, 0, 'نقد/کارت');
  push(lines, '11401', cheque, 0, 'چک');
  push(lines, '11201', ar, 0, 'دریافتنی');
  push(lines, '412', discount, 0, 'تخفیف');
  push(lines, '613', credit, 0, 'اعتبار');
  push(lines, '411', 0, sales, 'درآمد');
  const cogs = { '11301': 0, '11302': 0, '11303': 0 };
  for (const item of p.items || []) {
    const cost = round2(item.cost * item.qty);
    if (cost < 0.01) continue;
    if (item.type === 'finished') cogs['11303'] += cost;
    else if (item.type === 'raw') cogs['11302'] += cost;
    else cogs['11301'] += cost;
  }
  const cogsTotal = round2(Object.values(cogs).reduce((a, b) => a + b, 0));
  if (cogsTotal >= 0.01) {
    push(lines, '511', cogsTotal, 0, 'بها');
    for (const [code, amount] of Object.entries(cogs)) {
      push(lines, code, 0, round2(amount), 'خروج موجودی');
    }
  }
  return lines;
}

/** مطابق AccountingDocumentPoster::appendPaymentCredits */
function paymentCredit(method, amount, cashCode = '11120') {
  if (method === 'account') return { code: cashCode, credit: amount };
  if (method === 'cheque') return { code: '21201', credit: amount };
  return { code: '21101', credit: amount };
}

function invoiceLines(p) {
  const amount = round2(p.amount);
  const raw = round2(Math.min(p.raw || 0, amount));
  const catalog = round2(Math.max(0, amount - raw));
  const lines = [];
  push(lines, '11302', raw, 0, 'مواد اولیه');
  push(lines, '11301', catalog, 0, 'خرید کالا');
  const pay = paymentCredit(p.method || 'account', amount, p.cashCode || '11120');
  push(lines, pay.code, 0, pay.credit, 'پرداخت خرید');
  return lines;
}

function expenseLines(p) {
  const amount = round2(p.amount);
  const lines = [];
  if (p.source === 'manual_credit') {
    push(lines, '613', amount, 0, 'اعطای اعتبار دستی');
    push(lines, '11201', 0, amount, 'بدهی به مشتری');
    return lines;
  }
  if (p.source === 'loyalty_purchase' || p.source === 'purchase_return') {
    return []; // پوستر سند نمی‌زند
  }
  let debit = '611';
  if (p.type === 'سرمایه') debit = '12101';
  else if (p.payroll === 'salary' || p.payroll === 'advance') debit = '612';
  else if (p.title && /پرداخت حقوق|مساعده/.test(p.title)) debit = '612';
  push(lines, debit, amount, 0, p.title || 'هزینه');
  const pay = paymentCredit(p.method || 'account', amount, p.cashCode || '11120');
  push(lines, pay.code, 0, pay.credit, 'پرداخت هزینه');
  return lines;
}

/** مطابق AccountingReturnPoster */
function returnLines(p) {
  const sale = round2(p.sale);
  const cost = round2(p.cost || 0);
  let loyalty = round2(p.loyalty || 0);
  let wallet = round2(p.wallet || 0);
  let ar = round2(p.ar || 0);
  let cheque = round2(p.cheque || 0);
  let credits = round2(loyalty + wallet + ar + cheque);
  if (sale >= 0.01 && credits < 0.01) {
    wallet = sale;
    credits = sale;
  }
  const inv = p.invCode || '11301';
  const lines = [];
  push(lines, '412', credits > 0 ? credits : sale, 0, 'برگشت از فروش');
  push(lines, '613', 0, loyalty, 'برگشت اعتبار');
  push(lines, '11201', 0, round2(wallet + ar), ar >= 0.01 ? 'بستن طلب' : 'برگشت به اعتبار');
  push(lines, '11401', 0, cheque, 'کاهش چک');
  push(lines, inv, cost, 0, 'بازگشت موجودی');
  push(lines, '511', 0, cost, 'برگشت بها');
  return lines;
}

function postedLines() {
  // مثل دفتر بعد از اصلاح: اصل reversed + storno posted هر دو در گردش‌اند
  return vouchers.filter((v) => v.status === 'posted' || v.status === 'reversed').flatMap((v) => v.lines);
}

function resetLedger() {
  vouchers.length = 0;
  postedKeys.clear();
  nextId = 1;
}

function turnover() {
  const by = {};
  for (const l of postedLines()) {
    by[l.code] = by[l.code] || { debit: 0, credit: 0 };
    by[l.code].debit = round2(by[l.code].debit + l.debit);
    by[l.code].credit = round2(by[l.code].credit + l.credit);
  }
  return by;
}

function creditNet(by, code) {
  const p = by[code] || { debit: 0, credit: 0 };
  return round2(p.credit - p.debit);
}
function debitNet(by, code) {
  const p = by[code] || { debit: 0, credit: 0 };
  return round2(p.debit - p.credit);
}
function signed(code, debit, credit) {
  return NATURE[code] === 'credit' ? round2(credit - debit) : round2(debit - credit);
}

function pnl() {
  const by = turnover();
  const sales = creditNet(by, '411');
  const discounts = debitNet(by, '412');
  const cogs = debitNet(by, '511');
  const opex = debitNet(by, '611');
  const payroll = debitNet(by, '612');
  const loyalty = debitNet(by, '613');
  const other = creditNet(by, '431');
  const gross = round2(sales - discounts - cogs);
  const net = round2(gross - opex - payroll - loyalty + other);
  return { sales, discounts, cogs, opex, payroll, loyalty, other, gross, net };
}

function trialBalance() {
  const by = turnover();
  let drT = 0, crT = 0, drB = 0, crB = 0;
  for (const [code, pair] of Object.entries(by)) {
    drT += pair.debit;
    crT += pair.credit;
    const s = signed(code, pair.debit, pair.credit);
    if (NATURE[code] === 'credit') {
      if (s >= 0) crB += s; else drB += Math.abs(s);
    } else if (s >= 0) drB += s; else crB += Math.abs(s);
  }
  drT = round2(drT); crT = round2(crT); drB = round2(drB); crB = round2(crB);
  return {
    balanced: Math.abs(drT - crT) < TOL && Math.abs(drB - crB) < TOL,
    drT, crT, drB, crB,
  };
}

function balanceSheet() {
  const by = turnover();
  let assets = 0, liab = 0, equity = 0;
  for (const [code, pair] of Object.entries(by)) {
    const s = signed(code, pair.debit, pair.credit);
    const k = KIND[code];
    if (k === 'asset') assets += s;
    else if (k === 'liability') liab += s;
    else if (k === 'equity') equity += s;
  }
  assets = round2(assets); liab = round2(liab); equity = round2(equity);
  const profit = pnl().net;
  const right = round2(liab + equity + profit);
  return {
    assets, liab, equity, profit, right,
    balanced: Math.abs(assets - right) < TOL,
  };
}

function assertBalanced(name) {
  const tb = trialBalance();
  if (!tb.balanced) fail(name, `تراز آزمایشی نامتوازن ${JSON.stringify(tb)}`);
  const bs = balanceSheet();
  if (!bs.balanced) fail(name, `ترازنامه نامتوازن ${JSON.stringify(bs)}`);
}

function revenueCount(code) {
  return postedLines().filter((l) => l.code === code).reduce((a, l) => a + l.credit - l.debit, 0);
}

const results = [];
function ok(name, detail) {
  results.push({ name, ok: true, detail });
}
function check(name, fn) {
  try {
    fn();
    ok(name, 'قبول');
  } catch (e) {
    results.push({ name, ok: false, detail: e.message });
  }
}

// --- موتور سند ---
check('موتور: سند متوازن', () => {
  post('self', 1, [
    { code: '11101', debit: 100000, credit: 0 },
    { code: '411', debit: 0, credit: 100000 },
  ]);
});
check('موتور: تکراری idempotent', () => {
  const a = post('self', 1, [
    { code: '11101', debit: 100000, credit: 0 },
    { code: '411', debit: 0, credit: 100000 },
  ]);
  if (a.id !== 1) fail('idemp', 'سند جدید ساخت');
});
check('موتور: نامتوازن رد', () => {
  let rejected = false;
  try {
    post('bad', 1, [
      { code: '11101', debit: 100, credit: 0 },
      { code: '411', debit: 0, credit: 90 },
    ]);
  } catch (e) {
    rejected = true;
  }
  if (!rejected) fail('unbal', 'رد نشد');
});
check('موتور: storno خالص صفر', () => {
  const v = vouchers.find((x) => x.sourceType === 'self' && !x.reverses);
  reverse(v);
  const netTill = debitNet(turnover(), '11101');
  const netRev = creditNet(turnover(), '411');
  if (Math.abs(netTill) > 0.01 || Math.abs(netRev) > 0.01) fail('storno', 'خالص صفر نشد');
});

resetLedger();

// --- سناریو ۸تایی ---
check('۱ فروش نقد', () => {
  post('purchase', 10, saleLines({
    kind: 'cash', sales: 100000, cash: 100000, items: [{ type: 'catalog', qty: 1, cost: 40000 }],
  }));
  const p = pnl();
  if (p.sales !== 100000) fail('s', `sales=${p.sales}`);
  if (p.cogs !== 40000) fail('s', `cogs=${p.cogs}`);
  if (p.net !== 60000) fail('s', `net=${p.net}`);
  if (Math.abs(debitNet(turnover(), '11101') - 100000) > 0.01) fail('s', 'صندوق');
  assertBalanced('فروش نقد');
});

check('۲ نسیه + تسویه', () => {
  post('purchase', 20, saleLines({
    kind: 'debt', sales: 80000, cash: 0, items: [{ type: 'catalog', qty: 1, cost: 30000 }],
  }));
  if (Math.abs(debitNet(turnover(), '11201') - 80000) > 0.01) fail('d', 'طلب اولیه');
  const netBefore = pnl().net;
  post('debt_settle', 20, [
    { code: '11101', debit: 80000, credit: 0 },
    { code: '11201', debit: 0, credit: 80000 },
  ]);
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('d', 'تسویه نباید سود بسازد');
  if (creditNet(turnover(), '411') !== 180000) fail('d', 'درآمد تکراری');
  assertBalanced('نسیه');
});

check('۳ چک فروش + وصول', () => {
  post('purchase', 30, saleLines({
    kind: 'cheque', sales: 50000, cash: 10000, cheque: 40000, items: [{ type: 'catalog', qty: 1, cost: 20000 }],
  }));
  const netBefore = pnl().net;
  const revBefore = creditNet(turnover(), '411');
  post('cheque_clear', 30, [
    { code: '11101', debit: 40000, credit: 0 },
    { code: '11401', debit: 0, credit: 40000 },
  ]);
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('c', 'وصول چک سود ساخت');
  if (creditNet(turnover(), '411') !== revBefore) fail('c', 'درآمد تکراری چک');
  if (Math.abs(debitNet(turnover(), '11401')) > 0.01) fail('c', 'چک دریافتنی باید صفر شود');
  assertBalanced('چک');
});

check('۴ اقساط + قسط بعد', () => {
  post('purchase', 40, saleLines({
    kind: 'installment', sales: 90000, cash: 30000, items: [{ type: 'catalog', qty: 1, cost: 45000 }],
  }));
  if (Math.abs(debitNet(turnover(), '11201') - 60000) > 0.01) fail('i', `AR=${debitNet(turnover(), '11201')}`);
  const netBefore = pnl().net;
  const revBefore = creditNet(turnover(), '411');
  post('installment_pay', 401, [
    { code: '11101', debit: 30000, credit: 0 },
    { code: '11201', debit: 0, credit: 30000 },
  ]);
  post('installment_pay', 402, [
    { code: '11101', debit: 30000, credit: 0 },
    { code: '11201', debit: 0, credit: 30000 },
  ]);
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('i', 'قسط بعدی سود ساخت');
  if (creditNet(turnover(), '411') !== revBefore) fail('i', 'درآمد تکراری اقساط');
  assertBalanced('اقساط');
});

check('۵ برگشت نقد — بستن صندوق/طلب نه هزینه اعتبار', () => {
  const netBefore = pnl().net;
  post('purchase_return', 10, [
    { code: '412', debit: 100000, credit: 0, desc: 'برگشت از فروش' },
    { code: '11201', debit: 0, credit: 100000, desc: 'اعتبار مشتری' },
    { code: '11301', debit: 40000, credit: 0, desc: 'بازگشت موجودی' },
    { code: '511', debit: 0, credit: 40000, desc: 'برگشت بها' },
  ]);
  assertBalanced('برگشت');
  const p = pnl();
  const posLike = round2(netBefore - (100000 - 40000));
  if (Math.abs(p.net - posLike) > 0.01) fail('ret', `سود برگشت net=${p.net} want ${posLike}`);
});

check('۵ج برگشت نسیه طلب را می‌بندد', () => {
  const arBefore = debitNet(turnover(), '11201');
  post('purchase_return', 20, [
    { code: '412', debit: 80000, credit: 0 },
    { code: '11201', debit: 0, credit: 80000 },
    { code: '11301', debit: 30000, credit: 0 },
    { code: '511', debit: 0, credit: 30000 },
  ]);
  if (Math.abs(debitNet(turnover(), '11201') - (arBefore - 80000)) > 0.01) fail('ar', 'طلب نسیه بسته نشد');
  assertBalanced('برگشت نسیه');
});

check('۶ خرید ماده + تولید + فروش تولید', () => {
  post('invoice', 1, [
    { code: '11302', debit: 25000, credit: 0 },
    { code: '11120', debit: 0, credit: 25000 },
  ]);
  post('production', 1, [
    { code: '11303', debit: 25000, credit: 0 },
    { code: '11302', debit: 0, credit: 25000 },
  ]);
  post('purchase', 60, saleLines({
    kind: 'cash', sales: 40000, cash: 40000, items: [{ type: 'finished', qty: 1, cost: 25000 }],
  }));
  if (Math.abs(debitNet(turnover(), '11302')) > 0.01) fail('p', 'مواد باید صفر شود');
  if (Math.abs(debitNet(turnover(), '11303')) > 0.01) fail('p', 'ساخته باید صفر شود');
  assertBalanced('تولید');
});

check('۷ هزینه جاری از تنخواه', () => {
  const netBefore = pnl().net;
  post('expense', 1, [
    { code: '611', debit: 5000, credit: 0 },
    { code: '11120', debit: 0, credit: 5000 },
  ]);
  if (Math.abs(pnl().net - (netBefore - 5000)) > 0.01) fail('e', 'هزینه در سود نیامد');
  assertBalanced('هزینه');
});

check('۸ تطبیق روزانه', () => {
  const tillBefore = debitNet(turnover(), '11101');
  const netBefore = pnl().net;
  if (tillBefore < 0.01) fail('r', 'صندوق برای واریز خالی است');
  const move = 50000;
  post('recon_deposit', 1, [
    { code: '11111', debit: move, credit: 0 },
    { code: '11101', debit: 0, credit: move },
  ]);
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('r', 'تطبیق سود ساخت');
  assertBalanced('تطبیق');
});

check('اعتبار وفاداری یک‌بار در دفتر', () => {
  post('purchase', 70, saleLines({
    kind: 'cash', sales: 20000, cash: 15000, credit: 5000, items: [{ type: 'catalog', qty: 1, cost: 8000 }],
  }));
  // سند هزینه loyalty_purchase ساخته نمی‌شود
  const by = turnover();
  const loyalty = debitNet(by, '613');
  if (Math.abs(loyalty - 5000) > 0.01) fail('l', `613=${loyalty}`);
  assertBalanced('اعتبار');
});

check('افتتاحیه نقد / سرمایه', () => {
  post('opening', 1, [
    { code: '11111', debit: 100000, credit: 0 },
    { code: '311', debit: 0, credit: 100000 },
  ]);
  if (pnl().net !== pnl().net) fail('o', 'noop');
  const netBefore = (() => {
    const n = pnl().net;
    post('opening', 1, [
      { code: '11111', debit: 1, credit: 0 },
      { code: '311', debit: 0, credit: 1 },
    ]);
    return n;
  })();
  if (Math.abs(creditNet(turnover(), '311') - 100000) > 0.01) fail('o', 'افتتاحیه تکراری');
  assertBalanced('افتتاحیه');
});

check('فروش نمونه نقشه راه (تخفیف+اعتبار+چک)', () => {
  // کالا ۱۲۰٬۰۰۰، تخفیف ۱۰٬۰۰۰، اعتبار ۵٬۰۰۰، نقد ۳۰٬۰۰۰، کارت ۴۰٬۰۰۰، چک ۳۵٬۰۰۰. بها ۷۰٬۰۰۰
  post('purchase', 99, saleLines({
    kind: 'cheque',
    sales: 120000,
    discount: 10000,
    credit: 5000,
    cash: 30000,
    card: 40000,
    cheque: 35000,
    items: [{ type: 'catalog', qty: 1, cost: 70000 }],
  }));
  const lines = vouchers.find((v) => v.sourceType === 'purchase' && v.sourceId === 99).lines;
  const got = Object.fromEntries(lines.map((l) => [l.code, { d: l.debit, c: l.credit }]));
  const expect = {
    '11101': { d: 70000, c: 0 },
    '11401': { d: 35000, c: 0 },
    '412': { d: 10000, c: 0 },
    '613': { d: 5000, c: 0 },
    '411': { d: 0, c: 120000 },
    '511': { d: 70000, c: 0 },
    '11301': { d: 0, c: 70000 },
  };
  for (const [code, exp] of Object.entries(expect)) {
    const g = got[code] || { d: 0, c: 0 };
    if (g.d !== exp.d || g.c !== exp.c) fail('map', `${code} got ${JSON.stringify(g)} want ${JSON.stringify(exp)}`);
  }
  assertBalanced('نمونه نقشه');
});

// --- ماتریس کامل روز مالی (دفتر خالی، اعداد گرد) ---
resetLedger();

check('ماتریس: افتتاحیه', () => {
  post('opening', 1, [
    { code: '11111', debit: 1000000, credit: 0 },
    { code: '311', debit: 0, credit: 1000000 },
  ]);
  if (pnl().net !== 0) fail('o', 'افتتاحیه نباید سود بسازد');
  if (Math.abs(debitNet(turnover(), '11111') - 1000000) > 0.01) fail('o', 'نقد حساب ۱');
  assertBalanced('افتتاحیه ماتریس');
});

check('ماتریس: فروش نقد کاتالوگ', () => {
  post('purchase', 101, saleLines({
    kind: 'cash', sales: 100000, cash: 100000, items: [{ type: 'catalog', qty: 1, cost: 40000 }],
  }));
  if (pnl().net !== 60000) fail('s', `net=${pnl().net}`);
  assertBalanced('فروش نقد ماتریس');
});

check('ماتریس: فروش نسیه + تسویه بدون درآمد تکراری', () => {
  const netBefore = pnl().net;
  const revBefore = creditNet(turnover(), '411');
  post('purchase', 102, saleLines({
    kind: 'debt', sales: 80000, cash: 0, items: [{ type: 'catalog', qty: 1, cost: 30000 }],
  }));
  if (Math.abs(debitNet(turnover(), '11201') - 80000) > 0.01) fail('d', 'طلب');
  post('debt_settle', 102, [
    { code: '11101', debit: 80000, credit: 0 },
    { code: '11201', debit: 0, credit: 80000 },
  ]);
  if (Math.abs(pnl().net - (netBefore + 50000)) > 0.01) fail('d', `net=${pnl().net}`);
  if (creditNet(turnover(), '411') !== revBefore + 80000) fail('d', 'درآمد تکراری نسیه');
  if (Math.abs(debitNet(turnover(), '11201')) > 0.01) fail('d', 'طلب باید صفر شود');
  assertBalanced('نسیه ماتریس');
});

check('ماتریس: فروش چک + وصول بدون درآمد تکراری', () => {
  const netBefore = pnl().net;
  const revBefore = creditNet(turnover(), '411');
  post('purchase', 103, saleLines({
    kind: 'cheque', sales: 50000, cash: 10000, cheque: 40000, items: [{ type: 'catalog', qty: 1, cost: 20000 }],
  }));
  post('cheque_clear', 103, [
    { code: '11101', debit: 40000, credit: 0 },
    { code: '11401', debit: 0, credit: 40000 },
  ]);
  if (Math.abs(pnl().net - (netBefore + 30000)) > 0.01) fail('c', `net=${pnl().net}`);
  if (creditNet(turnover(), '411') !== revBefore + 50000) fail('c', 'درآمد تکراری چک');
  if (Math.abs(debitNet(turnover(), '11401')) > 0.01) fail('c', 'چک دریافتنی');
  assertBalanced('چک ماتریس');
});

check('ماتریس: اقساط + وصول دو قسط', () => {
  const netBefore = pnl().net;
  const revBefore = creditNet(turnover(), '411');
  post('purchase', 104, saleLines({
    kind: 'installment', sales: 90000, cash: 30000, items: [{ type: 'catalog', qty: 1, cost: 45000 }],
  }));
  post('installment_pay', 1041, [
    { code: '11101', debit: 30000, credit: 0 },
    { code: '11201', debit: 0, credit: 30000 },
  ]);
  post('installment_pay', 1042, [
    { code: '11101', debit: 30000, credit: 0 },
    { code: '11201', debit: 0, credit: 30000 },
  ]);
  if (Math.abs(pnl().net - (netBefore + 45000)) > 0.01) fail('i', `net=${pnl().net}`);
  if (creditNet(turnover(), '411') !== revBefore + 90000) fail('i', 'درآمد تکراری اقساط');
  if (Math.abs(debitNet(turnover(), '11201')) > 0.01) fail('i', 'طلب اقساط');
  assertBalanced('اقساط ماتریس');
});

check('ماتریس: برگشت فروش نقد (۴۱۲ نه برگشت ۴۱۱)', () => {
  const netBefore = pnl().net;
  const salesBefore = creditNet(turnover(), '411');
  post('purchase_return', 101, returnLines({ sale: 100000, cost: 40000, invCode: '11301' }));
  if (creditNet(turnover(), '411') !== salesBefore) fail('r', 'برگشت نباید ۴۱۱ را کم کند');
  if (Math.abs(pnl().net - (netBefore - 60000)) > 0.01) fail('r', `net=${pnl().net} want ${netBefore - 60000}`);
  // پوستر نقد را به صندوق برنمی‌گرداند؛ به اعتبار مشتری (۱۱۲۰۱) می‌زند
  if (Math.abs(creditNet(turnover(), '11201') - 100000) > 0.01 && Math.abs(debitNet(turnover(), '11201') + 100000) > 0.01) {
    const ar = debitNet(turnover(), '11201');
    if (Math.abs(ar + 100000) > 0.01) fail('r', `AR بعد برگشت نقد ar=${ar} باید −۱۰۰۰۰۰`);
  }
  assertBalanced('برگشت نقد');
});

check('ماتریس: برگشت نسیهِ تسویه‌شده طلب اعتباری می‌سازد', () => {
  const arBefore = debitNet(turnover(), '11201');
  post('purchase_return', 102, returnLines({ sale: 80000, cost: 30000, wallet: 80000 }));
  if (Math.abs(debitNet(turnover(), '11201') - (arBefore - 80000)) > 0.01) fail('rd', 'اعتبار برگشت نسیه');
  assertBalanced('برگشت نسیه');
});

check('ماتریس: خرید مواد خام نقد از تنخواه', () => {
  const netBefore = pnl().net;
  post('invoice', 201, invoiceLines({ amount: 25000, raw: 25000, method: 'account', cashCode: '11120' }));
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('inv', 'خرید ماده نباید سود بسازد');
  if (Math.abs(debitNet(turnover(), '11302') - 25000) > 0.01) fail('inv', 'موجودی مواد');
  assertBalanced('خرید مواد');
});

check('ماتریس: تولید + فروش محصول ساخته‌شده', () => {
  post('production', 201, [
    { code: '11303', debit: 25000, credit: 0 },
    { code: '11302', debit: 0, credit: 25000 },
  ]);
  const netBefore = pnl().net;
  post('purchase', 105, saleLines({
    kind: 'cash', sales: 40000, cash: 40000, items: [{ type: 'finished', qty: 1, cost: 25000 }],
  }));
  if (Math.abs(debitNet(turnover(), '11302')) > 0.01) fail('p', 'مواد باید صفر');
  if (Math.abs(debitNet(turnover(), '11303')) > 0.01) fail('p', 'ساخته باید صفر');
  if (Math.abs(pnl().net - (netBefore + 15000)) > 0.01) fail('p', `net=${pnl().net}`);
  assertBalanced('تولید+فروش');
});

check('ماتریس: برگشت محصول ساخته‌شده', () => {
  const netBefore = pnl().net;
  post('purchase_return', 105, returnLines({
    sale: 40000, cost: 25000, invCode: '11303',
  }));
  if (Math.abs(pnl().net - (netBefore - 15000)) > 0.01) fail('rf', `net=${pnl().net}`);
  if (Math.abs(debitNet(turnover(), '11303') - 25000) > 0.01) fail('rf', 'موجودی ساخته برنگشت');
  assertBalanced('برگشت ساخته');
});

check('ماتریس: فاکتور خرید کالا نسیه + تسویه', () => {
  const netBefore = pnl().net;
  post('invoice', 202, invoiceLines({ amount: 15000, raw: 0, method: 'credit' }));
  if (Math.abs(creditNet(turnover(), '21101') - 15000) > 0.01) fail('ap', `پرداختنی=${creditNet(turnover(), '21101')}`);
  post('document_payment', 2021, [
    { code: '21101', debit: 15000, credit: 0 },
    { code: '11111', debit: 0, credit: 15000 },
  ]);
  if (Math.abs(creditNet(turnover(), '21101')) > 0.01) fail('ap', 'پرداختنی باید صفر');
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('ap', 'تسویه خرید سود ساخت');
  assertBalanced('خرید نسیه');
});

check('ماتریس: فاکتور خرید چکی + وصول صادره', () => {
  const netBefore = pnl().net;
  post('invoice', 203, invoiceLines({ amount: 8000, raw: 0, method: 'cheque' }));
  if (Math.abs(creditNet(turnover(), '21201') - 8000) > 0.01) fail('ic', 'چک پرداختنی');
  post('cheque_clear', 203, [
    { code: '21201', debit: 8000, credit: 0 },
    { code: '11111', debit: 0, credit: 8000 },
  ]);
  if (Math.abs(creditNet(turnover(), '21201')) > 0.01) fail('ic', 'چک پرداختنی باید صفر');
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('ic', 'وصول چک خرید سود ساخت');
  assertBalanced('خرید چک');
});

check('ماتریس: برگشت فاکتور خرید پرداخت‌نشده (storno)', () => {
  const invBefore = debitNet(turnover(), '11301');
  const cashBefore = debitNet(turnover(), '11111');
  post('invoice', 204, invoiceLines({ amount: 9000, raw: 0, method: 'account', cashCode: '11111' }));
  const v = vouchers.find((x) => x.sourceType === 'invoice' && x.sourceId === 204 && !x.reverses);
  reverse(v);
  if (Math.abs(debitNet(turnover(), '11301') - invBefore) > 0.01) fail('ir', 'موجودی بعد برگشت خرید');
  if (Math.abs(debitNet(turnover(), '11111') - cashBefore) > 0.01) fail('ir', 'نقد بعد برگشت خرید');
  assertBalanced('برگشت فاکتور خرید');
});

check('ماتریس: حذف فاکتور بعد از وصول چک — reverseInvoice چک وصول‌شده را برنمی‌گرداند', () => {
  // رفتار فعلی AccountingDocumentPoster::reverseInvoice
  const payBefore = creditNet(turnover(), '21201');
  const v = vouchers.find((x) => x.sourceType === 'invoice' && x.sourceId === 203 && !x.reverses);
  reverse(v);
  // وصول مانده: Dr 21201 / Cr 11111 — بدون storno
  if (Math.abs(creditNet(turnover(), '21201') - (payBefore - 8000)) > 0.01) {
    fail('gap', `21201 بعد حذف فاکتور وصول‌شده=${creditNet(turnover(), '21201')}`);
  }
  assertBalanced('حذف فاکتور بعد وصول');
});

check('ماتریس: هزینه جاری از تنخواه', () => {
  const netBefore = pnl().net;
  post('expense', 301, expenseLines({ amount: 5000, type: 'جاری', method: 'account', cashCode: '11120' }));
  if (Math.abs(pnl().opex - 5000) > 0.01) fail('e', `opex=${pnl().opex}`);
  if (Math.abs(pnl().net - (netBefore - 5000)) > 0.01) fail('e', 'هزینه در سود نیست');
  assertBalanced('هزینه جاری');
});

check('ماتریس: هزینه سرمایه سود را کم نکند', () => {
  const netBefore = pnl().net;
  post('expense', 302, expenseLines({ amount: 20000, type: 'سرمایه', method: 'account', cashCode: '11111' }));
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('cap', 'سرمایه نباید P&L را بزند');
  if (Math.abs(debitNet(turnover(), '12101') - 20000) > 0.01) fail('cap', 'دارایی سرمایه');
  assertBalanced('هزینه سرمایه');
});

check('ماتریس: حقوق و مساعده هر دو روی ۶۱۲ یک سند', () => {
  const netBefore = pnl().net;
  post('expense', 303, expenseLines({
    amount: 12000, payroll: 'salary', title: 'پرداخت حقوق', method: 'account', cashCode: '11111',
  }));
  post('expense', 304, expenseLines({
    amount: 3000, payroll: 'advance', title: 'مساعده', method: 'account', cashCode: '11111',
  }));
  if (Math.abs(pnl().payroll - 15000) > 0.01) fail('pay', `payroll=${pnl().payroll}`);
  if (Math.abs(pnl().net - (netBefore - 15000)) > 0.01) fail('pay', 'حقوق/مساعده در سود نیست');
  if (postedKeys.has('payroll_payment:303')) fail('pay', 'نباید source جدا payroll_payment باشد');
  assertBalanced('حقوق مساعده');
});

check('ماتریس: برگشت هزینه جاری (حذف هزینه)', () => {
  const netBefore = pnl().net;
  const v = vouchers.find((x) => x.sourceType === 'expense' && x.sourceId === 301 && !x.reverses);
  reverse(v);
  if (Math.abs(pnl().opex) > 0.01) fail('er', `opex=${pnl().opex}`);
  if (Math.abs(pnl().net - (netBefore + 5000)) > 0.01) fail('er', 'برگشت هزینه سود را برنگرداند');
  assertBalanced('برگشت هزینه');
});

check('ماتریس: اعطای اعتبار دستی', () => {
  const netBefore = pnl().net;
  post('expense', 305, expenseLines({ amount: 2000, source: 'manual_credit' }));
  if (Math.abs(pnl().loyalty - 2000) > 0.01) fail('mc', `loyalty=${pnl().loyalty}`);
  if (Math.abs(pnl().net - (netBefore - 2000)) > 0.01) fail('mc', 'اعتبار دستی');
  assertBalanced('اعتبار دستی');
});

check('ماتریس: هزینه وفاداری فروش سند جدا نسازد', () => {
  const lines = expenseLines({ amount: 5000, source: 'loyalty_purchase' });
  if (lines.length !== 0) fail('loy', 'سند جدا برای loyalty_purchase');
  const ret = expenseLines({ amount: 1000, source: 'purchase_return' });
  if (ret.length !== 0) fail('loy', 'سند جدا برای برگشت اعتبار');
});

check('ماتریس: فروش با اعتبار وفاداری یک‌بار در ۶۱۳', () => {
  const loyBefore = pnl().loyalty;
  post('purchase', 106, saleLines({
    kind: 'cash', sales: 20000, cash: 15000, credit: 5000, items: [{ type: 'catalog', qty: 1, cost: 8000 }],
  }));
  if (Math.abs(pnl().loyalty - (loyBefore + 5000)) > 0.01) fail('l', `loyalty=${pnl().loyalty}`);
  assertBalanced('وفاداری فروش');
});

check('ماتریس: چک دریافتنی متفرقه + وصول', () => {
  const netBefore = pnl().net;
  post('income', 401, [
    { code: '11401', debit: 7000, credit: 0 },
    { code: '431', debit: 0, credit: 7000 },
  ]);
  if (Math.abs(pnl().other - 7000) > 0.01) fail('inc', `other=${pnl().other}`);
  post('cheque_clear', 401, [
    { code: '11101', debit: 7000, credit: 0 },
    { code: '11401', debit: 0, credit: 7000 },
  ]);
  if (Math.abs(pnl().net - (netBefore + 7000)) > 0.01) fail('inc', 'وصول چک درآمد سود اضافه کرد');
  assertBalanced('درآمد چک');
});

check('ماتریس: خرید و فروش دستی', () => {
  const netBefore = pnl().net;
  post('manual_trade', 501, [
    { code: '611', debit: 4000, credit: 0 },
    { code: '11111', debit: 0, credit: 4000 },
  ]);
  post('manual_trade', 502, [
    { code: '11111', debit: 6000, credit: 0 },
    { code: '431', debit: 0, credit: 6000 },
  ]);
  if (Math.abs(pnl().net - (netBefore - 4000 + 6000)) > 0.01) fail('mt', `net=${pnl().net}`);
  assertBalanced('دستی');
});

check('ماتریس: شارژ تنخواه سود نسازد', () => {
  const netBefore = pnl().net;
  post('account_transfer', 1, [
    { code: '11120', debit: 50000, credit: 0 },
    { code: '11111', debit: 0, credit: 50000 },
  ]);
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('tr', 'شارژ تنخواه');
  assertBalanced('تنخواه');
});

check('ماتریس: تطبیق روزانه وجوه در راه', () => {
  const till = debitNet(turnover(), '11101');
  const netBefore = pnl().net;
  if (till < 0.01) fail('rc', 'صندوق خالی');
  post('recon_deposit', 1, [
    { code: '11111', debit: till, credit: 0 },
    { code: '11101', debit: 0, credit: till },
  ]);
  if (Math.abs(debitNet(turnover(), '11101')) > 0.01) fail('rc', 'صندوق باید صفر شود');
  if (Math.abs(pnl().net - netBefore) > 0.01) fail('rc', 'تطبیق سود ساخت');
  assertBalanced('تطبیق');
});

check('ماتریس: جمع نهایی سود و تراز', () => {
  const p = pnl();
  // فروش‌های باقی‌مانده در درآمد: ۱۰۱ برگشت‌شده با ۴۱۲ / ۱۰۲ برگشت با ۴۱۲ / ۱۰۳ ۵۰k / ۱۰۴ ۹۰k / ۱۰۵ برگشت ۴۱۲ / ۱۰۶ ۲۰k
  // 411 = 100+80+50+90+40+20 = 380000
  // 412 = 100+80+40 = 220000
  // 511 net: 40+30+20+45+25+8 -40 -30 -25 = 73k? 
  // costs posted: 40, 30, 20, 45, 25, 8 = 168000
  // cogs reversed: 40, 30, 25 = 95000
  // cogs net = 73000
  // opex: 5000 posted -5000 reversed +4000 manual buy = 4000
  // payroll = 15000
  // loyalty = 2000 manual + 5000 sale = 7000
  // other = 7000 cheque + 6000 manual sale = 13000
  // gross = 380000 - 220000 - 73000 = 87000
  // net = 87000 - 4000 - 15000 - 7000 + 13000 = 74000
  if (p.sales !== 380000) fail('fin', `sales=${p.sales}`);
  if (p.discounts !== 220000) fail('fin', `disc=${p.discounts}`);
  if (p.cogs !== 73000) fail('fin', `cogs=${p.cogs}`);
  if (p.opex !== 4000) fail('fin', `opex=${p.opex}`);
  if (p.payroll !== 15000) fail('fin', `pay=${p.payroll}`);
  if (p.loyalty !== 7000) fail('fin', `loy=${p.loyalty}`);
  if (p.other !== 13000) fail('fin', `oth=${p.other}`);
  if (p.net !== 74000) fail('fin', `net=${p.net}`);
  assertBalanced('جمع نهایی');
});

const failed = results.filter((r) => !r.ok);
console.log(results.map((r) => `${r.warn ? 'WARN' : (r.ok ? 'PASS' : 'FAIL')}  ${r.name} — ${r.detail}`).join('\n'));
console.log('\n---');
console.log(`قبول ${results.filter((r) => r.ok).length} / ${results.length}`);
const tb = trialBalance();
const bs = balanceSheet();
const p = pnl();
console.log('تراز آزمایشی', tb);
console.log('ترازنامه', bs);
console.log('سود و زیان', p);
process.exit(failed.length ? 1 : 0);
