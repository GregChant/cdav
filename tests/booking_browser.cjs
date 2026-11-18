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

function publicUrl(date, language = 'en', extra = {}) {
  return fixture.booking_url + '?' + new URLSearchParams({key: fixture.key, date, lang: language, ...extra});
}

function selectionCsv(starts) {
  return [...starts].sort((left, right) => left - right).join(',');
}

async function follow(page, action, expected = 200) {
  const [response] = await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), action()]);
  check(response && response.status() === expected, 'Navigation status ' + (response ? response.status() : 'missing'));
  return response;
}

async function checkBasket(page, starts, valid = true) {
  check(!new URL(page.url()).searchParams.has('subject') && !new URL(page.url()).searchParams.has('address'),
    'Appointment subject and client address never enter calendar or basket URLs');
  const displayed = await page.locator('#booking-selection li[data-start]').evaluateAll(items => items.map(item => Number(item.dataset.start)));
  check(selectionCsv(displayed) === selectionCsv(starts), 'The basket preserves exactly the chosen units');
  const confirmation = page.locator('#booking-confirm-selection');
  check(await confirmation.count() === (starts.length && valid ? 1 : 0), 'One contact form exists only for a nonempty valid selection');
  if (starts.length && valid) {
    check(await confirmation.locator('input[name=selection]').inputValue() === selectionCsv(starts), 'The single POST preserves the canonical selection');
    check(await confirmation.locator('input[name=start]').count() === 0, 'Grouped confirmation uses selection instead of a legacy start');
    check(await confirmation.locator('#booking-name').count() === 1 && await confirmation.locator('#booking-email').count() === 1,
      'The basket asks for contact details once');
  }
}

async function checkAppointmentDetails(page, subject) {
  check(await page.locator('#booking-confirmation-subject').textContent() === subject,
    'The HTML confirmation retains the exact visitor subject');
  check(await page.locator('#booking-confirmation-address').textContent() === fixture.client_address,
    'The HTML confirmation retains the complete multiline client address');
}

async function changeUnit(page, start, checked, expected = 200, keyboard = false) {
  const form = page.locator('#booking-add-selection');
  const checkbox = form.locator('input[name="add[]"][value="' + start + '"]');
  if (await checkbox.isChecked() === checked) return null;
  check(await form.locator('button[type=submit]').isHidden(), 'JavaScript hides the manual add button after enhancement');
  const before = await page.locator('#booking-selection li[data-start]').evaluateAll(items => items.map(item => Number(item.dataset.start)));
  const intended = checked ? [...before, start] : before.filter(value => value !== start);
  const mutations = [];
  const observe = request => { if (request.method() === 'POST') mutations.push(request.url()); };
  page.on('request', observe);
  let response;
  try {
    if (keyboard) await checkbox.focus();
    response = await follow(page, () => keyboard ? page.keyboard.press('Space') : checkbox.setChecked(checked), expected);
  } finally {
    page.off('request', observe);
  }
  const query = new URL(page.url()).searchParams;
  check(response.request().method() === 'GET' && query.get('selection_action') === 'set' && mutations.length === 0,
    'A checkbox change performs only the server-validated GET set operation');
  await checkBasket(page, intended, expected === 200);
  return response;
}

async function addUnits(page, starts, expected = 200) {
  let response;
  for (const [position, start] of starts.entries()) {
    response = await changeUnit(page, start, true, position === starts.length - 1 ? expected : 200);
  }
  return response;
}

async function calendarDay(page, date, starts) {
  const targetMonth = date.slice(0, 7);
  for (let step = 0; step < 7; step++) {
    const query = new URL(page.url()).searchParams;
    const month = query.get('month') || (query.get('date') || '').slice(0, 7);
    if (month === targetMonth) break;
    const link = page.locator('section.calendar a[rel=' + (month < targetMonth ? 'next' : 'prev') + ']');
    check(await link.count() === 1, 'A permitted calendar month is reachable');
    check((new URL(await link.getAttribute('href'), page.url()).searchParams.get('selection') || '') === selectionCsv(starts),
      'Calendar month links retain the basket');
    await follow(page, () => link.click());
    await checkBasket(page, starts);
  }
  const link = page.locator('a.calendar-day[href*="date=' + date + '"]');
  check(await link.count() === 1, 'The chosen free day is selectable in the month');
  check((new URL(await link.getAttribute('href'), page.url()).searchParams.get('selection') || '') === selectionCsv(starts),
    'Calendar day links retain the basket');
  await follow(page, () => link.click());
  await checkBasket(page, starts);
  check(await page.locator('#booking-date').inputValue() === date, 'The calendar opens the selected local day');
}

async function checkAccessible(page, description) {
  const axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  check(axe.violations.length === 0, description + ': ' + axe.violations.map(violation => violation.id).join(','));
  return axe.violations.length;
}

async function checkBranding(page) {
  check(await page.locator('#booking-company').textContent().then(text => text.includes(fixture.company_name)),
    'The public header identifies the configured native company');
  check(await page.locator('#booking-professional-name').textContent() === fixture.professional_name,
    'The public professional name follows the native full-name formatter');
  const logo = page.locator('#booking-company img');
  const photo = page.locator('#booking-professional img');
  check(await logo.count() === 1 && await photo.count() === 1, 'Company logo and professional photo are both rendered');
  await page.waitForFunction(() => ['#booking-company img', '#booking-professional img'].every(selector => {
    const image = document.querySelector(selector);
    return image && image.complete && image.naturalWidth > 0;
  }));
  const logoUrl = new URL(await logo.getAttribute('src'), page.url());
  check(logoUrl.origin === new URL(fixture.booking_url).origin && logoUrl.pathname.endsWith('/viewimage.php')
    && logoUrl.searchParams.get('modulepart') === 'mycompany', 'Company logo uses the native public image endpoint');
  const imageResponse = await page.request.get(logoUrl.href);
  check(imageResponse.status() === 200 && (imageResponse.headers()['content-type'] || '').startsWith('image/'),
    'The native company logo is served as a public image');
  const favicon = page.locator('link[rel=icon]');
  check(await favicon.count() === 1, 'The public page declares one tab icon');
  const faviconUrl = new URL(await favicon.getAttribute('href'), page.url());
  check(faviconUrl.origin === logoUrl.origin && faviconUrl.pathname.endsWith('/viewimage.php')
    && faviconUrl.searchParams.get('modulepart') === 'mycompany'
    && faviconUrl.searchParams.get('file') === fixture.company_favicon_file && faviconUrl.href !== logoUrl.href,
    'The tab icon prefers the native square thumbnail independently of the header logo');
  const faviconResponse = await page.request.get(faviconUrl.href);
  check(faviconResponse.status() === 200 && (faviconResponse.headers()['content-type'] || '').startsWith('image/'),
    'The native square tab icon is served as a public image');
  const dimensions = await page.evaluate(url => new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve([image.naturalWidth, image.naturalHeight]);
    image.onerror = () => reject(new Error('The native tab icon could not be decoded'));
    image.src = url;
  }), faviconUrl.href);
  check(dimensions[0] === 2 && dimensions[1] === 2
    && await logo.evaluate(image => image.naturalWidth === 4 && image.naturalHeight === 2),
    'The favicon is genuinely square while the independent native header logo is rectangular');
  check((await photo.getAttribute('src')).startsWith('data:image/png;base64,'), 'The bounded native professional photo needs no separate public-card key');
  const professional = page.locator('#booking-professional');
  check(await professional.locator('a[href^="mailto:"]').getAttribute('href') === 'mailto:' + encodeURIComponent(fixture.professional_email),
    'The professional email has its expected mailto link');
  check(await professional.locator('a[href="' + fixture.professional_phone_href + '"]').count() === 1
    && await professional.locator('a[href="' + fixture.professional_mobile_href + '"]').count() === 1,
    'Office and mobile numbers have usable native telephone links');
  check(await professional.locator('a[href*="vcard"], a[href*="securekey"], a[href*="user/card.php"]').count() === 0,
    'Booking does not publish a broader user-card or vCard access URL');
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
    check((await page.locator('#booking-address-help').textContent()).includes('collaborateur'), 'French public wording uses collaborateur');
    check(await page.locator('#booking-profile-name').textContent() === fixture.profile_label, 'The public page identifies its named booking profile');
    check(await page.getByRole('radio').count() > 0, 'At least one real free slot');
    check(await page.locator('input[name="add[]"]').count() === 0, 'Default profile keeps the single-slot radio flow');
    check(await page.locator('section.calendar').count() === 1 && await page.locator('.calendar-day').count() >= 28,
      'The public page renders its actual monthly availability');
    await checkBranding(page);
    check(await page.locator('input[name=token]').inputValue(), 'Native CSRF input');
    let axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check(axe.violations.length === 0, 'Public accessibility: ' + axe.violations.map(v => v.id).join(','));
    results.push({case: 'U01/U02 desktop French', http: response.status(), axeViolations: axe.violations.length,
      nativeCompanyLogoLoaded: true, nativeProfessionalPhotoLoaded: true, professionalContactLinks: true, nativeSquareFaviconLoaded: true});
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
    check(await page.evaluate(() => document.activeElement.id) === 'booking-subject', 'Phone leads to the appointment subject');
    await page.keyboard.type(fixture.appointment_subjects.single);
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.id) === 'booking-address', 'Subject leads to the client appointment address');
    await page.keyboard.insertText(fixture.client_address);
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.tagName) === 'BUTTON', 'Hidden anti-spam field does not take focus');
    const posted = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/booking.php'));
    await page.keyboard.press('Enter');
    response = await posted;
    await page.waitForLoadState();
    check(response.status() === 201, 'Keyboard booking confirmation status ' + response.status());
    check(await page.locator('#booking-confirmation').textContent() === 'Votre rendez-vous est réservé', 'Booking confirmation shown');
    await checkAppointmentDetails(page, fixture.appointment_subjects.single);
    check(await page.locator('form[method=post]').count() === 0, 'No accidental repeat form after confirmation');
    axe = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check(axe.violations.length === 0, 'Confirmation accessibility: ' + axe.violations.map(v => v.id).join(','));
    results.push({case: 'U02/U03 keyboard confirmation', http: response.status(), axeViolations: axe.violations.length});
    await capture(page, 'booking-ui-desktop-confirmation.png');
    const again = page.locator('#booking-again');
    check(await again.textContent() === 'Réserver un autre rendez-vous', 'Confirmation offers the explicit next-appointment action');
    const againQuery = new URL(await again.getAttribute('href'), page.url()).searchParams;
    check([...againQuery.keys()].sort().join(',') === 'date,key,lang' && againQuery.get('key') === fixture.key,
      'Another appointment starts with the same publication context and no contact, selection or old request ticket');
    response = await follow(page, () => again.click());
    check(response.request().method() === 'GET' && await page.locator('#booking-profile-name').textContent() === fixture.profile_label
      && await page.locator('#booking-name').inputValue() === '' && await page.locator('#booking-subject').inputValue() === ''
      && await page.locator('#booking-selection').count() === 0 && await page.getByRole('radio').count() > 0,
      'Another appointment reopens current availability and an empty form on the same named profile');
    results.push({case: 'Book another appointment after confirmation', method: 'GET', emptyForm: true, sameProfile: true});

    const mobile = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, locale: 'en-GB'});
    const phone = await mobile.newPage();
    phone.on('pageerror', error => errors.push(safe(error.message)));
    response = await phone.goto(url.replace('&lang=fr', '&lang=en'));
    check(response.status() === 200, 'Mobile GET');
    check(await phone.locator('h1').textContent() === 'Book an appointment', 'English title');
    check((await phone.locator('#booking-address-help').textContent()).includes('collaborator'), 'English public wording uses collaborator');
    await checkBranding(phone);
    check(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No horizontal overflow at 390px');
    axe = await new AxeBuilder({page: phone}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    check(axe.violations.length === 0, 'Mobile accessibility: ' + axe.violations.map(v => v.id).join(','));
    await capture(phone, 'booking-ui-mobile-slots.png');
    results.push({case: 'U01 mobile English', http: response.status(), axeViolations: axe.violations.length, width: 390});

    await phone.getByRole('radio').first().check();
    await phone.locator('#booking-name').fill('CDav Browser QA');
    await phone.locator('#booking-email').fill('browser-qa@example.test');
    await phone.locator('#booking-subject').fill(fixture.appointment_subjects.single);
    await phone.locator('#booking-address').fill(fixture.client_address);
    await phone.locator('input[name=request]').evaluate(el => { el.value = 'expired.invalid'; });
    const refused = phone.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/booking.php'));
    await phone.getByRole('button', {name: 'Confirm appointment'}).click();
    response = await refused;
    await phone.waitForLoadState();
    check(response.status() === 409, 'Expired form returns conflict status ' + response.status());
    check(await phone.locator('#booking-error').textContent(), 'Expired form has an announced error');
    check(await phone.locator('#booking-subject').inputValue() === fixture.appointment_subjects.single
      && await phone.locator('#booking-address').inputValue() === fixture.client_address, 'An expired form preserves the visitor subject and multiline address');
    await capture(phone, 'booking-ui-mobile-expired-form.png');
    results.push({case: 'U03 expired form', http: response.status(), announcedError: true});

    const emptyUrl = fixture.booking_url + '?' + new URLSearchParams({key: fixture.key, date: fixture.closed_date, lang: 'en'});
    response = await phone.goto(emptyUrl);
    check(response.status() === 200, 'Closed-day page GET');
    check(await phone.getByRole('radio').count() === 0, 'A native all-day absence leaves no available slot');
    check(await phone.locator('form[method=post]').count() === 0, 'An empty day has no confirmation form');
    check((await phone.getByRole('status').allTextContents()).some(text => text.includes('No slots are available')), 'An empty day is explained');
    check(await phone.locator('a.calendar-day[href*="date=' + fixture.closed_date + '"]').count() === 0,
      'An all-day native absence is not selectable in the month');
    check(await phone.locator('button.calendar-day.unavailable[disabled]').count() > 0, 'Unavailable days remain visibly disabled');
    await capture(phone, 'booking-ui-mobile-empty-day.png');
    results.push({case: 'U03 no slots', http: response.status(), noConfirmationForm: true});

    // The server accepts the real form POST, but its response never reaches the browser.
    const lost = await desktop.newPage();
    lost.on('pageerror', error => errors.push(safe(error.message)));
    await lost.goto(url);
    await lost.getByRole('radio').first().check();
    await lost.locator('#booking-name').fill('CDav Browser Lost Response');
    await lost.locator('#booking-email').fill('browser-lost@example.test');
    await lost.locator('#booking-subject').fill(fixture.appointment_subjects.lost);
    await lost.locator('#booking-address').fill(fixture.client_address);
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
        check(values.get('subject') === fixture.appointment_subjects.lost && values.get('address').replace(/\r\n/g, '\n') === fixture.client_address,
          'Lost-response replay contains the same explicit subject and complete client address');
        secrets.add(values.get('token')); secrets.add(values.get('request'));
        const upstream = await route.fetch();
        check(upstream.status() === 201, 'The lost-response request is accepted once');
        const receipt = await upstream.json();
        check(!('subject' in receipt) && !('address' in receipt)
          && !JSON.stringify(receipt).includes(fixture.appointment_subjects.lost)
          && !JSON.stringify(receipt).includes(fixture.client_address), 'Public receipt JSON excludes visitor subject and address');
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
    response = await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=' + fixture.profile_id);
    check(response.status() === 200, 'Browser native admin GET');
    check(await admin.locator('select[name=CDAV_BOOKING_ENABLED]').count() === 1, 'Native global setting rendered');
    check(await admin.locator('label[for=owner_id]').textContent() === 'Collaborateur', 'Native administration uses the collaborator label');
    check(await admin.locator('select[name=profile_id]').inputValue() === String(fixture.profile_id), 'The administration selects the requested named profile');
    check(await admin.locator('input[name=weekly_1_0_start]').inputValue() === '09:00', 'Native professional profile rendered');
    check(await admin.locator('select[name=allow_contiguous]').inputValue() === '0'
      && await admin.locator('select[name=allow_split]').inputValue() === '0', 'Both extended booking modes default to disabled');
    await capture(admin, 'booking-ui-admin-profile.png');
    results.push({case: 'Native admin browser', http: response.status(), profileLoaded: true});

    async function createNamedProfile(label, duration, linkBusiness) {
      await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=0');
      check(await admin.locator('select[name=profile_id]').inputValue() === '0', 'New profile remains an explicit independent selection');
      const form = admin.locator('form:has(input[name=action][value=save_profile])');
      await form.locator('#profile_label').fill(label);
      await form.locator('select[name=profile_enabled]').selectOption('1');
      await form.locator('select[name=timezone]').selectOption('Europe/Zurich');
      await form.locator('input[name=duration]').fill(String(duration));
      await form.locator('input[name=notice]').fill('0');
      for (const day of [6, 7]) {
        for (const [range, start, end] of [[0, '09:00', '12:00'], [1, '13:00', '17:00']]) {
          await form.locator('input[name=weekly_' + day + '_' + range + '_start]').fill(start);
          await form.locator('input[name=weekly_' + day + '_' + range + '_end]').fill(end);
        }
      }
      if (linkBusiness && fixture.thirdparty_id) {
        const profilesBefore = await admin.locator('select[name=profile_id] option').count();
        await form.locator('select[name=thirdparty_id]').selectOption(String(fixture.thirdparty_id));
        await follow(admin, () => form.locator('button[name=refresh_projects]').click());
        check(await admin.locator('select[name=profile_id] option').count() === profilesBefore,
          'Refreshing the native third-party projects does not create a profile');
        check(await form.locator('#profile_label').inputValue() === label
          && await form.locator('input[name=duration]').inputValue() === String(duration),
          'Refreshing projects preserves the pending name and booking settings');
        await form.locator('select[name=project_id]').selectOption(String(fixture.project_id));
      }
      await follow(admin, () => form.locator('button.button-save').click());
      const id = Number(await form.locator('input[name=profile_id]').inputValue());
      check(id > 0 && id !== fixture.profile_id
        && await admin.locator('select[name=profile_id]').inputValue() === String(id),
        'Saving a new calendar selects its stable independent profile identifier');
      check(await form.locator('#profile_label').inputValue() === label
        && await form.locator('input[name=duration]').inputValue() === String(duration), 'The selected profile retains its own name and duration');
      if (linkBusiness && fixture.thirdparty_id) {
        check(await form.locator('select[name=thirdparty_id]').inputValue() === String(fixture.thirdparty_id)
          && await form.locator('select[name=project_id]').inputValue() === String(fixture.project_id),
          'Native third-party and project selectors persist only this calendar context');
      }
      await follow(admin, () => admin.locator('button[name=action][value=rotate_token]').click());
      const link = await admin.locator('#booking_public_link').inputValue();
      secrets.add(new URL(link).searchParams.get('key'));
      return {id, label, duration, link};
    }

    const namedFirst = await createNamedProfile(fixture.secondary_profile_label, 60, true);
    const namedSecond = await createNamedProfile(fixture.tertiary_profile_label, 90, false);
    check(namedFirst.id !== namedSecond.id, 'Two profiles of the same collaborator have different stable identifiers');
    const namedPage = await desktop.newPage();
    namedPage.on('pageerror', error => errors.push(safe(error.message)));
    async function checkNamedProfile(profile, expectedDuration = profile.duration) {
      const target = new URL(profile.link);
      target.searchParams.set('date', fixture.date);
      target.searchParams.set('lang', 'en');
      const current = await namedPage.goto(target.href);
      check(current.status() === 200 && await namedPage.locator('#booking-profile-name').textContent() === profile.label,
        'Each public key displays exactly its own named calendar');
      check((await namedPage.locator('main > p').allTextContents()).some(text => text.includes(' ' + expectedDuration + ' minutes')),
        'The public calendar uses its own independently saved duration');
      const text = await namedPage.locator('body').textContent();
      check(!text.includes(fixture.private_thirdparty_name) && !text.includes(fixture.private_project_name)
        && await namedPage.locator('[name=thirdparty_id], [name=project_id], [name=profile_id]').count() === 0,
        'Public calendar names do not expose private business-context names or selectors');
    }
    await checkNamedProfile(namedFirst);
    await checkNamedProfile(namedSecond);
    await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=' + namedFirst.id);
    await admin.locator('form:has(input[name=action][value=save_profile]) input[name=duration]').fill('75');
    await follow(admin, () => admin.locator('button.button-save').click());
    await checkNamedProfile(namedFirst, 75);
    await checkNamedProfile(namedSecond);
    await page.goto(publicUrl(fixture.date));
    check(await page.locator('#booking-profile-name').textContent() === fixture.profile_label
      && await page.getByRole('radio').count() > 0, 'Editing another profile preserves the initial profile and publication key');
    await follow(admin, () => admin.locator('button[name=action][value=rotate_token]').click());
    const renewedLink = await admin.locator('#booking_public_link').inputValue();
    secrets.add(new URL(renewedLink).searchParams.get('key'));
    check((await namedPage.goto(namedFirst.link)).status() === 404, 'Renewing one profile revokes only its old link');
    namedFirst.link = renewedLink;
    await checkNamedProfile(namedFirst, 75);
    await checkNamedProfile(namedSecond);
    await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=' + namedSecond.id);
    await follow(admin, () => admin.locator('button[name=action][value=disable_profile]').click());
    check((await namedPage.goto(namedSecond.link)).status() === 404, 'Revoking the selected profile disables its own public link');
    await checkNamedProfile(namedFirst, 75);
    check((await page.goto(publicUrl(fixture.date))).status() === 200, 'Revoking another calendar leaves the initial link active');

    async function saveValidity(from, until, refresh = false) {
      await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=' + namedFirst.id);
      const form = admin.locator('form:has(input[name=action][value=save_profile])');
      await form.locator('#valid_from').fill(from);
      await form.locator('#valid_until').fill(until);
      if (refresh && fixture.thirdparty_id) {
        await follow(admin, () => form.locator('button[name=refresh_projects]').click());
        check(await form.locator('#valid_from').inputValue() === from && await form.locator('#valid_until').inputValue() === until,
          'Refreshing native projects preserves both optional validity fields before saving');
        await form.locator('select[name=project_id]').selectOption(String(fixture.project_id));
      }
      await follow(admin, () => form.locator('button.button-save').click());
      check(await form.locator('#valid_from').inputValue() === from && await form.locator('#valid_until').inputValue() === until,
        'The native administration persists each optional validity date');
      const target = new URL(namedFirst.link);
      target.searchParams.set('lang', 'en');
      const current = await namedPage.goto(target.href);
      check(current.status() === 200, 'The shared key opens a profile directly without a date or month parameter');
    }

    await saveValidity(fixture.validity_date, fixture.validity_date, true);
    check(await namedPage.locator('#booking-date').getAttribute('min') === fixture.validity_date
      && await namedPage.locator('#booking-date').getAttribute('max') === fixture.validity_date
      && await namedPage.locator('#booking-date').inputValue() === fixture.validity_date,
      'Equal inclusive dates automatically open the only future bookable day');
    const onlyDate = await namedPage.locator('a.calendar-day').evaluateAll(items => items.map(item => new URL(item.href).searchParams.get('date')));
    check(onlyDate.length === 1 && onlyDate[0] === fixture.validity_date
      && await namedPage.locator('section.calendar a[rel=prev], section.calendar a[rel=next]').count() === 0,
      'One-day validity offers exactly one date and no month outside the profile window');
    check((await namedPage.locator('#booking-validity').textContent()).includes(fixture.validity_date)
      && await namedPage.getByRole('radio').count() > 0, 'The public window and its current available periods agree');
    await capture(namedPage, 'booking-ui-single-day-validity.png');
    results.push({case: 'Optional one-day profile validity', automaticFutureDay: true, inclusiveBounds: true, nativeRefreshPreserved: true});

    await saveValidity(fixture.validity_date, fixture.validity_until);
    check(await namedPage.locator('#booking-date').getAttribute('min') === fixture.validity_date
      && await namedPage.locator('#booking-date').getAttribute('max') === fixture.validity_until,
      'A week-long profile retains both inclusive bounds');
    for (const link of await namedPage.locator('a.calendar-day').evaluateAll(items => items.map(item => item.href))) {
      const offeredDate = new URL(link).searchParams.get('date');
      check(offeredDate >= fixture.validity_date && offeredDate <= fixture.validity_until, 'Calendar links never cross the configured validity bounds');
    }
    await saveValidity('', fixture.validity_until);
    check(await namedPage.locator('#booking-date').getAttribute('min') === fixture.calendar_today
      && await namedPage.locator('#booking-date').getAttribute('max') === fixture.validity_until, 'An optional end works independently of a start');
    await saveValidity(fixture.validity_date, '');
    check(await namedPage.locator('#booking-date').getAttribute('min') === fixture.validity_date
      && await namedPage.locator('#booking-date').getAttribute('max') > fixture.validity_until, 'An optional start retains the separate rolling horizon');
    await saveValidity('', '');
    check(await namedPage.locator('#booking-validity').count() === 0
      && await namedPage.locator('#booking-date').getAttribute('min') === fixture.calendar_today
      && !await namedPage.locator('#booking-date').isDisabled(), 'Clearing both dates restores unlimited profile validity within the existing horizon');
    results.push({case: 'Optional validity bounds and unlimited profile', weekBounds: true, independentStart: true, independentEnd: true, bothCleared: true});

    const profileOptionCount = await admin.locator('select[name=profile_id] option').count();
    const absentProfile = await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=2147483647');
    check(absentProfile.status() === 404, 'An unknown explicit admin profile identifier fails with 404');
    await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=' + fixture.profile_id);
    check(await admin.locator('select[name=profile_id] option').count() === profileOptionCount,
      'Reading an unknown profile does not silently create an additional calendar');
    await capture(admin, 'booking-ui-admin-multiple-profiles.png');
    results.push({case: 'Two independent named profiles for one collaborator', profilesCreated: 2,
      independentSettings: true, independentLinkRotation: true, independentRevocation: true,
      nativeBusinessContext: Boolean(fixture.thirdparty_id), privateNamesHidden: true});

    async function saveSelectionPolicy(contiguous, split, settings = {}) {
      const duration = settings.duration || 30;
      const step = settings.step || 30;
      const morningStart = settings.morningStart || '09:00';
      await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=' + fixture.profile_id);
      await admin.locator('select[name=allow_contiguous]').selectOption(contiguous ? '1' : '0');
      await admin.locator('select[name=allow_split]').selectOption(split ? '1' : '0');
      await admin.locator('input[name=duration]').fill(String(duration));
      await admin.locator('input[name=step]').fill(String(step));
      for (let day = 1; day <= 7; day++) {
        await admin.locator('input[name=weekly_' + day + '_0_start]').fill(morningStart);
      }
      await admin.locator('input[name=horizon]').fill('6');
      await admin.locator('select[name=horizon_unit]').selectOption('months');
      const form = admin.locator('form:has(input[name=action][value=save_profile])');
      await follow(admin, () => form.locator('button.button-save').click());
      response = await admin.goto(fixture.admin_url + '?owner_id=' + fixture.owner_id + '&profile_id=' + fixture.profile_id);
      check(response.status() === 200, 'Native profile settings reload after saving');
      check(await admin.locator('select[name=allow_contiguous]').inputValue() === (contiguous ? '1' : '0')
        && await admin.locator('select[name=allow_split]').inputValue() === (split ? '1' : '0'), 'Native admin persists each independent booking permission');
      check(await admin.locator('input[name=horizon]').inputValue() === '6'
        && await admin.locator('select[name=horizon_unit]').inputValue() === 'months', 'Six calendar months persist through the native form');
      check(await admin.locator('input[name=duration]').inputValue() === String(duration)
        && await admin.locator('input[name=step]').inputValue() === String(step), 'Native duration and step persist through the profile form');
      for (let day = 1; day <= 7; day++) {
        for (const [range, start, end] of [[0, morningStart, '12:00'], [1, '13:00', '17:00']]) {
          check(await admin.locator('input[name=weekly_' + day + '_' + range + '_start]').inputValue() === start
            && await admin.locator('input[name=weekly_' + day + '_' + range + '_end]').inputValue() === end,
          'Changing the booking permissions preserves native weekly hours');
        }
      }
    }

    await saveSelectionPolicy(true, false);
    response = await page.goto(publicUrl(fixture.contiguous_date));
    check(response.status() === 200, 'Continuous profile is publicly available');
    check(await page.getByRole('radio').count() === 0 && await page.locator('input[name="add[]"]').count() > 1,
      'An explicitly enabled extended mode offers checkboxes');
    check(await page.locator('#booking-date').getAttribute('max') === fixture.six_month_limit,
      'Date selection uses the exact profile-local six-calendar-month limit');
    for (let month = 0; month < 7; month++) {
      const links = await page.locator('a.calendar-day').evaluateAll(items => items.map(item => item.getAttribute('href')));
      check(links.every(href => new URL(href, fixture.booking_url).searchParams.get('date') <= fixture.six_month_limit),
        'No calendar day link exceeds the configured deadline');
      const next = page.locator('section.calendar a[rel=next]');
      if (await next.count() === 0) break;
      check(month < 6, 'Month navigation is bounded by six calendar months');
      await follow(page, () => next.click());
    }
    check(new URL(page.url()).searchParams.get('month') === fixture.six_month_limit.slice(0, 7),
      'Calendar navigation reaches the final permitted month');
    check(await page.locator('section.calendar a[rel=next]').count() === 0, 'The final month has no later navigation link');
    results.push({case: 'Monthly calendar and native six-month policy', lastMonth: fixture.six_month_limit.slice(0, 7), bounded: true});

    await page.goto(publicUrl(fixture.contiguous_date));
    check(fixture.contiguous_starts[1] - fixture.contiguous_starts[0] === 1800, 'Continuous fixture units are adjacent');
    const rapidRequests = [];
    const recordRapid = request => {
      if (request.isNavigationRequest() && request.url().includes('/public/booking.php')) rapidRequests.push(request);
    };
    page.on('request', recordRapid);
    await follow(page, () => page.evaluate(starts => {
      for (const start of starts) document.querySelector('#booking-add-selection input[name="add[]"][value="' + start + '"]').click();
    }, fixture.contiguous_starts));
    page.off('request', recordRapid);
    check(rapidRequests.length === 1 && rapidRequests[0].method() === 'GET'
      && new URL(rapidRequests[0].url()).searchParams.getAll('add[]').length === 2,
      'Two rapid checkbox changes produce one read-only GET with the final checked state');
    await checkBasket(page, fixture.contiguous_starts);
    results.push({case: 'Automatic rapid checkbox selection', getRequests: 1, bookingPosts: 0, units: 2});
    await page.locator('#booking-name').fill('CDav Browser Continuous');
    await page.locator('#booking-email').fill('browser-continuous@example.test');
    await page.locator('#booking-subject').fill(fixture.appointment_subjects.continuous);
    await page.locator('#booking-address').fill(fixture.client_address);
    await capture(page, 'booking-ui-continuous-basket.png');
    response = await follow(page, () => page.locator('#booking-confirm-selection button[type=submit]').click(), 201);
    await checkAppointmentDetails(page, fixture.appointment_subjects.continuous);
    check(await page.locator('#booking-confirmation-segments li').count() === 1, 'Adjacent units have one continuous confirmation segment');
    check(await page.locator('form[method=post]').count() === 0, 'Continuous confirmation cannot be resubmitted accidentally');
    const continuousAxe = await checkAccessible(page, 'Continuous confirmation accessibility');
    await capture(page, 'booking-ui-continuous-confirmation.png');
    results.push({case: 'Continuous two-unit booking', http: response.status(), units: 2, segments: 1, durationMinutes: 60, axeViolations: continuousAxe});

    await page.goto(publicUrl(fixture.contiguous_date));
    const disallowed = [fixture.contiguous_starts[0] + 3600, fixture.contiguous_starts[0] + 4 * 3600];
    response = await addUnits(page, disallowed, 422);
    check(await page.locator('#booking-error').count() === 1 && await page.locator('#booking-confirm-selection').count() === 0,
      'Continuous-only permission does not admit a separated selection');
    results.push({case: 'Continuous-only policy rejects separated units', http: response.status(), noConfirmationForm: true});

    await saveSelectionPolicy(false, true);
    response = await phone.goto(publicUrl(fixture.split_date));
    check(response.status() === 200, 'Split profile is publicly available');
    check(await phone.getByRole('radio').count() === 0 && await phone.locator('input[name="add[]"]').count() > 1,
      'Split booking is independently enabled with checkboxes');
    await addUnits(phone, fixture.split_starts.slice(0, 2));
    await checkBasket(phone, fixture.split_starts.slice(0, 2));
    await follow(phone, () => phone.locator('#booking-selection li[data-start="' + fixture.split_starts[0] + '"] .booking-selection-remove').click());
    await checkBasket(phone, [fixture.split_starts[1]]);
    await follow(phone, () => phone.locator('#booking-selection-clear').click());
    await checkBasket(phone, []);
    await calendarDay(phone, fixture.split_date, []);
    await addUnits(phone, fixture.split_starts.slice(0, 2));
    const nextMonth = phone.locator('section.calendar a[rel=next]');
    check(new URL(await nextMonth.getAttribute('href'), phone.url()).searchParams.get('selection') === selectionCsv(fixture.split_starts.slice(0, 2)),
      'Next-month navigation carries the pending selection');
    await follow(phone, () => nextMonth.click());
    await checkBasket(phone, fixture.split_starts.slice(0, 2));
    await follow(phone, () => phone.locator('section.calendar a[rel=prev]').click());
    await checkBasket(phone, fixture.split_starts.slice(0, 2));
    await calendarDay(phone, fixture.other_split_date, fixture.split_starts.slice(0, 2));
    await addUnits(phone, [fixture.split_starts[2]]);
    await checkBasket(phone, fixture.split_starts);
    await changeUnit(phone, fixture.split_starts[2], false);
    await checkBasket(phone, fixture.split_starts.slice(0, 2));
    check(new URL(phone.url()).searchParams.getAll('add[]').length === 0, 'Unchecking the final current-day slot submits an empty day set');
    await changeUnit(phone, fixture.split_starts[2], true, 200, true);
    await checkBasket(phone, fixture.split_starts);
    results.push({case: 'Automatic deselection and keyboard selection', remainingOtherDayUnits: 2, keyboard: true, bookingPosts: 0});
    await phone.locator('#booking-date').fill(fixture.closed_date);
    await follow(phone, () => phone.locator('form:has(#booking-date) button[type=submit]').click());
    await checkBasket(phone, fixture.split_starts);
    check(await phone.locator('#booking-add-selection').count() === 0
      && (await phone.getByRole('status').allTextContents()).some(text => text.includes('No slots are available')),
      'An empty current day preserves the valid basket from other days without an add form');
    await phone.locator('#booking-date').fill(fixture.other_split_date);
    await follow(phone, () => phone.locator('form:has(#booking-date) button[type=submit]').click());
    await checkBasket(phone, fixture.split_starts);
    results.push({case: 'No-slot day preserves another-day basket', units: 3, currentDayHasNoForm: true});
    await follow(phone, () => phone.locator('a[lang=fr]').click());
    await checkBasket(phone, fixture.split_starts);
    await follow(phone, () => phone.locator('a[lang=en]').click());
    await checkBasket(phone, fixture.split_starts);
    check(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No horizontal overflow with a multi-day basket at 390px');
    const basketAxe = await checkAccessible(phone, 'Split basket accessibility');
    await capture(phone, 'booking-ui-mobile-split-basket.png');
    results.push({case: 'Split basket navigation and removal', units: 3, days: 2, monthNavigation: true, languagePreserved: true,
      removal: true, cleared: true, axeViolations: basketAxe, width: 390});

    await phone.locator('#booking-name').fill('CDav Browser Split');
    await phone.locator('#booking-email').fill('browser-split@example.test');
    await phone.locator('#booking-phone').fill('+41000000001');
    await phone.locator('#booking-subject').fill(fixture.appointment_subjects.split);
    await phone.locator('#booking-address').fill(fixture.client_address);
    response = await follow(phone, () => phone.locator('#booking-confirm-selection button[type=submit]').click(), 201);
    await checkAppointmentDetails(phone, fixture.appointment_subjects.split);
    check(await phone.locator('#booking-confirmation-segments li').count() === 3, 'Two working ranges and another day have three confirmation segments');
    check(await phone.locator('form[method=post]').count() === 0, 'Grouped confirmation replaces the complete contact form');
    check(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No horizontal overflow in the multi-day confirmation');
    const splitAxe = await checkAccessible(phone, 'Split confirmation accessibility');
    await capture(phone, 'booking-ui-mobile-split-confirmation.png');
    results.push({case: 'Split multi-day booking', http: response.status(), units: 3, segments: 3, days: 2, axeViolations: splitAxe});

    const fourHourSettings = {duration: 240, step: 240, morningStart: '08:00'};
    await saveSelectionPolicy(true, false, fourHourSettings);
    for (const [language, client] of [['fr', page], ['en', phone]]) {
      response = await client.goto(publicUrl(fixture.long_date, language));
      check(response.status() === 200, 'Four-hour continuous-only profile GET');
      check((await client.locator('p.notice').allTextContents()).some(text => text.includes(language === 'fr' ? 'sans pause' : 'no break')),
        'Continuous-only guidance explicitly excludes a break in both languages');
      const offered = await client.locator('#booking-add-selection input[name="add[]"]').evaluateAll(items => items.map(item => Number(item.value)));
      check(selectionCsv(offered) === selectionCsv(fixture.long_starts), 'The actual calendar offers exactly 08:00–12:00 and 13:00–17:00');
      const addForm = client.locator('#booking-add-selection');
      check(new URL(await addForm.getAttribute('action'), client.url()).hash === '#booking-selection', 'Adding units targets the basket fragment');
      response = await addUnits(client, fixture.long_starts, 422);
      await checkBasket(client, fixture.long_starts, false);
      check(new URL(client.url()).hash === '#booking-selection', 'The rejected addition scrolls to its basket');
      const localError = client.locator('#booking-selection #booking-selection-error[role=alert]');
      check(await localError.count() === 1, 'The rejected lunch break has one local accessible explanation');
      check((await localError.textContent()).includes(language === 'fr' ? 'séparés par une pause' : 'separated by a break')
        && (await localError.textContent()).includes(language === 'fr' ? 'Retirez les créneaux séparés' : 'Remove the separate slots'),
        'The local error explains the profile restriction and its recovery in the chosen language');
      await client.waitForFunction(() => {
        const bounds = document.querySelector('#booking-selection-error').getBoundingClientRect();
        return bounds.top >= 0 && bounds.bottom <= innerHeight;
      });
      check(await client.locator('#booking-selection .selection-summary').count() === 0
        && await client.locator('#booking-selection-segments').count() === 0, 'An invalid selection has neither a misleading zero total nor computed periods');
      check(await client.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'The local lunch-break explanation fits its viewport');
      const errorAxe = await checkAccessible(client, 'Lunch-break restriction accessibility in ' + language);
      await capture(client, 'booking-ui-lunch-rejected-' + language + '.png');
      await follow(client, () => client.locator('#booking-selection li[data-start="' + fixture.long_starts[1] + '"] .booking-selection-remove').click());
      await checkBasket(client, [fixture.long_starts[0]]);
      check(await client.locator('#booking-selection-error').count() === 0
        && (await client.locator('#booking-selection .selection-summary').textContent()).includes('240'), 'Removing the afternoon restores a valid 240-minute booking');
      if (language === 'en') {
        await client.locator('#booking-name').fill('CDav Browser Lunch Recovery');
        await client.locator('#booking-email').fill('browser-recovery@example.test');
        await client.locator('#booking-subject').fill(fixture.appointment_subjects.recovery);
        await client.locator('#booking-address').fill(fixture.client_address);
        response = await follow(client, () => client.locator('#booking-confirm-selection button[type=submit]').click(), 201);
        await checkAppointmentDetails(client, fixture.appointment_subjects.recovery);
        check(await client.locator('#booking-confirmation-segments li').count() === 1, 'Recovered four-hour booking creates one continuous segment');
      }
      results.push({case: 'Four-hour lunch restriction and recovery ' + language, rejectedHttp: 422,
        localErrorInViewport: true, zeroTotalHidden: true, recovery: language === 'en' ? 'booked201' : 'validForm', axeViolations: errorAxe});
    }

    await saveSelectionPolicy(true, true, fourHourSettings);
    await phone.goto(publicUrl(fixture.long_split_date));
    await addUnits(phone, fixture.long_split_starts);
    await checkBasket(phone, fixture.long_split_starts);
    check((await phone.locator('#booking-selection .selection-summary').textContent()).includes('480')
      && await phone.locator('#booking-selection-segments li').count() === 2, 'Allowing split periods displays 480 minutes in two segments');
    await capture(phone, 'booking-ui-four-hour-split-basket.png');
    await phone.locator('#booking-name').fill('CDav Browser Long Split');
    await phone.locator('#booking-email').fill('browser-long-split@example.test');
    await phone.locator('#booking-subject').fill(fixture.appointment_subjects.longsplit);
    await phone.locator('#booking-address').fill(fixture.client_address);
    response = await follow(phone, () => phone.locator('#booking-confirm-selection button[type=submit]').click(), 201);
    await checkAppointmentDetails(phone, fixture.appointment_subjects.longsplit);
    check(await phone.locator('#booking-confirmation-segments li').count() === 2, 'The two four-hour periods produce one group confirmation');
    const longAxe = await checkAccessible(phone, 'Four-hour split confirmation accessibility');
    await capture(phone, 'booking-ui-four-hour-split-confirmation.png');
    results.push({case: 'Two four-hour lunch periods allowed', http: response.status(), units: 2, segments: 2,
      durationMinutes: 480, axeViolations: longAxe});

    const noJavaScript = await browser.newContext({viewport: {width: 390, height: 844}, javaScriptEnabled: false});
    const fallback = await noJavaScript.newPage();
    await fallback.goto(publicUrl(fixture.fallback_date));
    const fallbackForm = fallback.locator('#booking-add-selection');
    check(await fallbackForm.locator('button[type=submit]').isVisible(), 'The manual add button remains available without JavaScript');
    const fallbackRequests = [];
    fallback.on('request', request => {
      if (request.isNavigationRequest() && request.url().includes('/public/booking.php')) fallbackRequests.push(request);
    });
    for (const start of fixture.fallback_starts) await fallbackForm.locator('input[name="add[]"][value="' + start + '"]').check();
    check(fallbackRequests.length === 0, 'Checkboxes without JavaScript do not submit before the explicit add action');
    await follow(fallback, () => fallbackForm.locator('button[type=submit]').click());
    await checkBasket(fallback, fixture.fallback_starts);
    check(fallbackRequests.length === 1 && fallbackRequests[0].method() === 'GET'
      && new URL(fallback.url()).searchParams.get('selection_action') === 'add', 'The no-JavaScript fallback performs one explicit read-only add');
    await follow(fallback, () => fallback.locator('#booking-selection li[data-start="' + fixture.fallback_starts[1] + '"] .booking-selection-remove').click());
    await checkBasket(fallback, [fixture.fallback_starts[0]]);
    await capture(fallback, 'booking-ui-no-javascript-basket.png');
    results.push({case: 'Manual selection fallback without JavaScript', manualAdd: true, removal: true, bookingPosts: 0, width: 390});
    await noJavaScript.close();
    check(errors.length === 0, 'No page script errors after extended bookings: ' + errors.join(';'));

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
