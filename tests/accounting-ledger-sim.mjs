/**
 * شبیه‌ساز منطق پوسترها و گزارش دفتر (بدون Laravel/DB).
 * فرمول‌ها با AccountingSalePoster / DocumentPoster / ReturnPoster / ReportService هم‌خوان است.
 */
const NATURE = {
  '11101': 'debit', '11111': 'debit', '11120': 'debit',
  '11201': 'debit', '11301': 'debit', '11302': 'debit', '11303': 'debit',
  '11401': 'debit', '12101': 'debit',
  '21101': 'credit', '21201': 'credit',
  '311': 'credit',
  '411': 'credit', '412': 'debit', '431': 'credit',
  '511': 'debit',
  '611': 'debit', '612': 'debit', '613': 'debit',
};
const KIND = {
  '11101': 'asset', '11111': 'asset', '11120': 'asset',
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
