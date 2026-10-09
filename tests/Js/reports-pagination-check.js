/* Executes the Customer Reports pagination script exactly as the browser would
   (it is extracted from the rendered Blade view) and checks the behaviour the
   UI promises: 11 records at 10 per page, correct ranges, correct disabled
   states, one table at a time, no extra requests while paging, and filters
   recalculating the page count.

   Run directly (node tests/Js/reports-pagination-check.js) or through
   Tests\Feature\AdminReportTest::test_customer_pagination_pages_eleven_records_of_ten_per_page.
   Exits non-zero when any check fails. */
const fs = require('fs');
const path = require('path');

const viewPath = path.resolve(__dirname, '../../resources/views/admin/reports.blade.php');
const raw = fs.readFileSync(viewPath, 'utf8');
const section = raw.slice(raw.indexOf("@section('scripts')"));
const match = section.match(/<script>([\s\S]*)<\/script>/);
if (!match) {
  console.error('FAIL: scripts block not found in ' + viewPath);
  process.exit(1);
}

// Blade interpolations become literals; the rest of the script is untouched.
const code = match[1]
  .replace(/\{\{\s*csrf_token\(\)\s*\}\}/g, 'csrf-token')
  .replace(/\{\{\s*url\("[^"]*"\)\s*\}\}/g, '/admin/reports')
  .replace(/\{\{\s*\$reportType\s*\}\}/g, 'customer')
  .replace(/\{\{[^}]*\}\}/g, '2026-10-01');

const escapeHtml = (s) => String(s)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;');

let fetchCalls = 0;
let payload;

const container = {
  innerHTML: '',
  getBoundingClientRect: () => ({ top: 50 }),
  scrollIntoView() {},
};
const resultsCard = { style: {} };
const resultCount = { textContent: '' };
const formStub = {
  id: 'customerReportForm',
  getAttribute: (name) => (name === 'action' ? '/admin/reports/customer' : null),
  querySelectorAll: () => [],
};

const documentStub = {
  getElementById: (id) => (
    id === 'reportResults' ? container
    : id === 'resultsCard' ? resultsCard
    : id === 'resultCount' ? resultCount
    : id === 'customerReportForm' ? formStub
    : null
  ),
  querySelector: () => ({ content: 'csrf-token' }),
  createElement: () => {
    let text = '';
    return {
      appendChild(node) { text += node.__text; },
      get innerHTML() { return escapeHtml(text); },
      set innerHTML(value) { text = value; },
    };
  },
  createTextNode: (t) => ({ __text: String(t) }),
  addEventListener() {},
};

const windowStub = { location: { href: '' }, addEventListener() {} };

async function stubFetch() {
  fetchCalls++;
  return { ok: true, json: async () => payload };
}

const failures = [];
function assert(condition, message) {
  if (condition) {
    console.log('ok:   ' + message);
  } else {
    failures.push(message);
    console.log('FAIL: ' + message);
  }
}
function count(haystack, needle) { return haystack.split(needle).length - 1; }

/* The opening tag of the control holding a marker, so its
   disabled/active state can be checked. */
function ctrlFor(html, marker) {
  const index = html.indexOf(marker);
  if (index === -1) return '';
  const open = Math.max(html.lastIndexOf('<a', index), html.lastIndexOf('<span', index));
  return open === -1 ? '' : html.slice(open, html.indexOf('>', index) + 1);
}

function customers(n) {
  return Array.from({ length: n }, (_, i) => ({
    id: i + 1,
    name: 'Customer ' + (i + 1),
    email: 'c' + (i + 1) + '@test.dev',
    status: 'active',
    verified: true,
    studentId: 'SID-' + (i + 1),
    phoneNumber: '09000000' + i,
    totalSpent: 0,
    createdAt: '2026-01-01T00:00:00Z',
    rentals_count: 0,
  }));
}

function loadScript() {
  // eslint-disable-next-line no-new-func
  new Function('document', 'window', 'fetch', 'FormData', 'URLSearchParams', 'alert',
    code + '\n;window.__t = { loadReport, paginateTable, state: () => ({ page: _reportPage.customer, data: _reportData.customer }) };'
  )(
    documentStub,
    windowStub,
    stubFetch,
    function () { return {}; },
    URLSearchParams,
    function (message) { throw new Error('unexpected alert: ' + message); }
  );
  return windowStub.__t;
}

(async () => {
  payload = {
    reportId: 'RPT-1',
    summary: { total: 11, verified: 11, pending: 0, blacklisted: 0, active: 11 },
    data: customers(11),
  };

  const t = loadScript();
  await new Promise((resolve) => setTimeout(resolve, 20)); // the page's own auto-load
  await t.loadReport(formStub);

  let html = container.innerHTML;
  console.log('\n-- page 1 of 11 records at 10 per page --');
  assert(fetchCalls >= 1, 'report loaded from the server');
  assert(html.includes('Showing 1&ndash;10 of 11 records'), 'range reads "Showing 1-10 of 11 records"');
  assert(count(html, '<table') === 1, 'exactly one table (got ' + count(html, '<table') + ')');
  assert(count(html, 'admin-table-wrap') === 1, 'table and footer share one card (got ' + count(html, 'admin-table-wrap') + ')');
  assert(count(html, '<tr>') === 11, 'header row + 10 data rows (got ' + count(html, '<tr>') + ')');
  assert(html.includes('Customer 1') && html.includes('Customer 10'), 'records 1-10 are shown');
  assert(!html.includes('Customer 11'), 'record 11 is not shown on page 1');
  assert(ctrlFor(html, 'Previous').includes('is-disabled'), 'Previous is disabled on page 1');
  assert(ctrlFor(html, 'Next').startsWith('<a') && !ctrlFor(html, 'Next').includes('is-disabled'), 'Next is enabled on page 1');
  assert(html.includes("paginateTable('customer',2)"), 'page number 2 generated from the total');
  assert(html.includes('<span class="admin-pagination__item is-active" aria-current="page">1</span>'), 'page 1 is marked active');

  console.log('\n-- Next to page 2 --');
  const callsBeforePaging = fetchCalls;
  t.paginateTable('customer', 2);
  html = container.innerHTML;
  assert(fetchCalls === callsBeforePaging, 'paging serves cached records without a new request');
  assert(html.includes('Showing 11&ndash;11 of 11 records'), 'range reads "Showing 11-11 of 11 records"');
  assert(count(html, '<table') === 1, 'still exactly one table (no duplicate)');
  assert(count(html, '<tr>') === 2, 'header row + 1 data row (got ' + count(html, '<tr>') + ')');
  assert(html.includes('Customer 11'), 'only record 11 is shown');
  assert(!html.includes('Customer 10'), 'page 1 rows are gone');
  assert(ctrlFor(html, 'Previous').startsWith('<a') && !ctrlFor(html, 'Previous').includes('is-disabled'), 'Previous is enabled on page 2');
  assert(ctrlFor(html, 'Next').includes('is-disabled'), 'Next is disabled on page 2');
  assert(html.includes('<span class="admin-pagination__item is-active" aria-current="page">2</span>'), 'page 2 is marked active');
  assert(t.state().page === 2, 'internal page state is 2');

  console.log('\n-- page number 1 back to the first page --');
  t.paginateTable('customer', 1);
  html = container.innerHTML;
  assert(html.includes('Showing 1&ndash;10 of 11 records'), 'range reads "Showing 1-10 of 11 records" again');
  assert(count(html, '<table') === 1, 'still exactly one table');
  assert(html.includes('Customer 1'), 'first record shown again');

  console.log('\n-- out-of-range targets are clamped --');
  t.paginateTable('customer', 99);
  assert(container.innerHTML.includes('Showing 11&ndash;11 of 11 records'), 'page 99 clamps to the last page');
  t.paginateTable('customer', -4);
  assert(container.innerHTML.includes('Showing 1&ndash;10 of 11 records'), 'negative page clamps to page 1');

  console.log('\n-- a filter narrows the result set to 5 records --');
  const callsBeforeFilter = fetchCalls;
  payload = {
    reportId: 'RPT-2',
    summary: { total: 5, verified: 5, pending: 0, blacklisted: 0, active: 5 },
    data: customers(5),
  };
  t.paginateTable('customer', 2); // sit on page 2 before the filter changes
  await t.loadReport(formStub);
  html = container.innerHTML;
  assert(fetchCalls === callsBeforeFilter + 1, 'the filter issued exactly one new request');
  assert(t.state().page === 1, 'filters reset paging back to page 1');
  assert(html.includes('Showing 1&ndash;5 of 5 records'), 'range recalculated to "Showing 1-5 of 5 records"');
  assert(!html.includes('paginateTable'), 'single page renders no page controls');
  assert(count(html, '<table') === 1, 'one table after the filter reload');

  console.log('\n-- an empty result leaves no stale pagination behind --');
  payload = {
    reportId: 'RPT-3',
    summary: { total: 0, verified: 0, pending: 0, blacklisted: 0, active: 0 },
    data: [],
  };
  await t.loadReport(formStub);
  assert(!container.innerHTML.includes('Showing'), 'no range text for an empty result set');
  assert(t.state().data.length === 0, 'cached records were replaced, not appended');

  console.log('\n' + (failures.length ? failures.length + ' FAILURE(S)' : 'ALL CHECKS PASSED'));
  process.exit(failures.length ? 1 : 0);
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
