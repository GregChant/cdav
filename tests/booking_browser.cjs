/** Browser checks for a disposable fixture created by booking_browser.php. */
const fs = require('fs');
const path = require('path');

if (process.env.CDAV_LIVE_TEST !== '1' || !process.env.CDAV_BOOKING_BROWSER_FIXTURE) {
  throw new Error('Run tests/booking_browser.php against an explicitly isolated test instance.');
}
const fixture = JSON.parse(fs.readFileSync(process.env.CDAV_BOOKING_BROWSER_FIXTURE, 'utf8'));
if (!fixture.isolated || !['127.0.0.1', 'localhost', '[::1]'].includes(new URL(fixture.booking_url).hostname)) {
  throw new Error('A local disposable browser fixture is required.');
}
const { chromium } = require(process.env.CDAV_PLAYWRIGHT_MODULE || 'playwright');
const AxeBuilder = require(process.env.CDAV_AXE_MODULE || '@axe-core/playwright').default;
const output = process.env.CDAV_BOOKING_BROWSER_OUTPUT || '';
const results = [];
const secrets = new Set([fixture.key, fixture.password]);

function check(value, message) {
  if (!value) throw new Error(message);
}

function safe(text) {
  let sanitized = String(text);
  for (const secret of secrets) if (secret) sanitized = sanitized.split(secret).join('[redacted]');
  return sanitized;
}

async function capture(page, filename) {
  if (output) await page.screenshot({path: path.join(output, filename), fullPage: true});
}

(async () => {
  const options = {headless: true};
  if (process.env.CDAV_CHROMIUM_EXECUTABLE) options.executablePath = process.env.CDAV_CHROMIUM_EXECUTABLE;
  if (process.env.CDAV_BROWSER_NO_SANDBOX === '1') options.args = ['--no-sandbox'];
  const browser = await chromium.launch(options);
  try {
    if (output) fs.mkdirSync(output, {recursive: true});
    const desktop = await browser.newContext({viewport: {width: 1280, height: 900}, locale: 'fr-CH'});
    const page = await desktop.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(safe(error.message)));
    const url = fixture.booking_url + '?' + new URLSearchParams({key: fixture.key, date: fixture.date, lang: 'fr'});
    let response = await page.goto(url);
    check(response.status() === 200, 'Desktop booking GET status ' + response.status());
    check(await page.locator('h1').textContent() === 'Prendre rendez-vous', 'French title');
    check(await page.getByRole('radio').count() > 0, 'At least one real free slot');
    check(await page.locator('input[name=token]').inputValue(), 'Native CSRF input');
    let axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check(axe.violations.length === 0, 'Public accessibility: ' + axe.violations.map(v => v.id).join(','));
    results.push({case: 'U01/U02 desktop French', http: response.status(), axeViolations: axe.violations.length});
    await capture(page, 'booking-ui-desktop-slots.png');

    // Chromium may expose several keyboard segments inside a native date input.
    await page.locator('#booking-date').focus();
    for (let tab = 0; tab < 5; tab++) {
      await page.keyboard.press('Tab');
      if (await page.evaluate(() => document.activeElement.tagName) === 'BUTTON') break;
    }
    check(await page.evaluate(() => document.activeElement.tagName) === 'BUTTON', 'Date field leads to its submit button');
    await page.getByRole('radio').first().focus();
    await page.keyboard.press('Space');
    check(await page.getByRole('radio').first().isChecked(), 'Slot is selectable with the keyboard');
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.id) === 'booking-name', 'Slot leads to the name input');
    await page.keyboard.type('CDav Browser QA');
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.id) === 'booking-email', 'Name leads to email');
    await page.keyboard.type('browser-qa@example.test');
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.id) === 'booking-phone', 'Email leads to phone');
    await page.keyboard.type('+41000000000');
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.tagName) === 'BUTTON', 'Hidden anti-spam field does not take focus');
    const posted = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/booking.php'));
    await page.keyboard.press('Enter');
    response = await posted;
    await page.waitForLoadState();
    check(response.status() === 201, 'Keyboard booking confirmation status ' + response.status());
    check(await page.locator('#booking-confirmation').textContent() === 'Votre rendez-vous est réservé', 'Booking confirmation shown');
    check(await page.locator('form[method=post]').count() === 0, 'No accidental repeat form after confirmation');
    axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check(axe.violations.length === 0, 'Confirmation accessibility: ' + axe.violations.map(v => v.id).join(','));
    results.push({case: 'U02/U03 keyboard confirmation', http: response.status(), axeViolations: axe.violations.length});
    await capture(page, 'booking-ui-desktop-confirmation.png');

    const mobile = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, locale: 'en-GB'});
    const phone = await mobile.newPage();
    phone.on('pageerror', error => errors.push(safe(error.message)));
    response = await phone.goto(url.replace('&lang=fr', '&lang=en'));
    check(response.status() === 200, 'Mobile GET');
    check(await phone.locator('h1').textContent() === 'Book an appointment', 'English title');
    check(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No horizontal overflow at 390px');
    axe = await new AxeBuilder({page: phone}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check(axe.violations.length === 0, 'Mobile accessibility: ' + axe.violations.map(v => v.id).join(','));
    await capture(phone, 'booking-ui-mobile-slots.png');
    results.push({case: 'U01 mobile English', http: response.status(), axeViolations: axe.violations.length, width: 390});

    await phone.getByRole('radio').first().check();
    await phone.locator('#booking-name').fill('CDav Browser QA');
    await phone.locator('#booking-email').fill('browser-qa@example.test');
    await phone.locator('input[name=request]').evaluate(el => { el.value = 'expired.invalid'; });
    const refused = phone.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/booking.php'));
    await phone.getByRole('button', {name: 'Confirm appointment'}).click();
    response = await refused;
    await phone.waitForLoadState();
    check(response.status() === 409, 'Expired form returns conflict status ' + response.status());
    check(await phone.locator('#booking-error').textContent(), 'Expired form has an announced error');
    await capture(phone, 'booking-ui-mobile-expired-form.png');
    results.push({case: 'U03 expired form', http: response.status(), announcedError: true});

    const emptyUrl = fixture.booking_url + '?' + new URLSearchParams({key: fixture.key, date: fixture.closed_date, lang: 'en'});
    response = await phone.goto(emptyUrl);
    check(response.status() === 200, 'Closed-day page GET');
    check(await phone.getByRole('radio').count() === 0, 'A native all-day absence leaves no available slot');
    check(await phone.locator('form[method=post]').count() === 0, 'An empty day has no confirmation form');
    check((await phone.getByRole('status').textContent()).includes('No slots are available'), 'An empty day is explained');
    await capture(phone, 'booking-ui-mobile-empty-day.png');
    results.push({case: 'U03 no slots', http: response.status(), noConfirmationForm: true});

    // The server accepts the real form POST, but its response never reaches the browser.
    const lost = await desktop.newPage();
    lost.on('pageerror', error => errors.push(safe(error.message)));
    await lost.goto(url);
    await lost.getByRole('radio').first().check();
    await lost.locator('#booking-name').fill('CDav Browser Lost Response');
    await lost.locator('#booking-email').fill('browser-lost@example.test');
    await lost.locator('form[method=post]').evaluate(form => {
      const format = document.createElement('input');
      format.type = 'hidden'; format.name = 'format'; format.value = 'json';
      form.appendChild(format);
    });
    let acceptedResolve;
    let acceptedReject;
    const accepted = new Promise((resolve, reject) => { acceptedResolve = resolve; acceptedReject = reject; });
    await lost.route('**/booking.php*', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      try {
        const request = route.request();
        const body = request.postData();
        const values = new URLSearchParams(body);
        secrets.add(values.get('token')); secrets.add(values.get('request'));
        const upstream = await route.fetch();
        check(upstream.status() === 201, 'The lost-response request is accepted once');
        const receipt = await upstream.json();
        await route.abort('connectionreset');
        acceptedResolve({url: request.url(), body, contentType: request.headers()['content-type'], receipt, status: upstream.status()});
      } catch (error) {
        acceptedReject(error);
        await route.abort('failed').catch(() => {});
      }
    });
    const disconnected = lost.waitForEvent('requestfailed', request => request.method() === 'POST');
    await lost.getByRole('button', {name: 'Confirmer le rendez-vous'}).click({noWaitAfter: true});
    const upstream = await accepted;
    const failedRequest = await disconnected;
    check((failedRequest.failure()?.errorText || '').includes('ERR_CONNECTION_RESET'), 'The browser actually loses the accepted response');
    const retry = await desktop.request.post(upstream.url, {data: upstream.body, headers: {'Content-Type': upstream.contentType}});
    check(retry.status() === 200, 'Identical retry returns the existing receipt');
    const retryReceipt = await retry.json();
    check(retryReceipt.reference === upstream.receipt.reference && retryReceipt.replayed === true, 'The retry preserves its reference without another booking');
    results.push({case: 'U03 accepted response lost and identical retry', firstUpstream: upstream.status,
      retry: retry.status(), sameReference: true, replayed: true, networkFailure: 'connectionreset'});
    check(errors.length === 0, 'No page script errors: ' + errors.join(';'));

    const admin = await desktop.newPage();
    await admin.goto(fixture.home_url);
    await admin.locator('input[name=username]').fill(fixture.login);
    await admin.locator('input[name=password]').fill(fixture.password);
    await admin.locator('input[type=submit]').click();
    await admin.waitForLoadState('domcontentloaded');
    response = await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id);
    check(response.status() === 200, 'Browser native admin GET');
    check(await admin.locator('select[name=CDAV_BOOKING_ENABLED]').count() === 1, 'Native global setting rendered');
    check(await admin.locator('input[name=weekly_1_0_start]').inputValue() === '09:00', 'Native professional profile rendered');
    await capture(admin, 'booking-ui-admin-profile.png');
    results.push({case: 'Native admin browser', http: response.status(), profileLoaded: true});

    const summary = {suite: 'tests/booking_browser.cjs', browser: browser.version(),
      php: fixture.php, dolibarr: fixture.dolibarr, results};
    if (output) fs.writeFileSync(path.join(output, 'booking-ui-results.json'), JSON.stringify(summary, null, 2) + '\n');
    for (const result of results) console.log(JSON.stringify(result));
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(safe(error.stack || error));
  process.exitCode = 1;
});
