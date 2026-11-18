/* Observable behavior of the public checkbox enhancement; no browser or package is required. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'booking.js'), 'utf8');

function fixture(options = {}) {
  const listeners = [];
  const readyListeners = [];
  const windowListeners = [];
  const timers = new Map();
  const submissions = [];
  const action = {value: 'add'};
  const button = {hidden: false};
  const fields = {date: '2026-11-20', selection: '1795338000', checked: ['1795179600']};
  const attributes = {};
  let timerId = 0;
  const form = {
    method: options.method || 'get',
    dataset: {},
    elements: {namedItem: () => options.noAction ? null : action},
    querySelector: () => button,
    addEventListener: (type, handler) => listeners.push({type, handler}),
    setAttribute: (key, value) => { attributes[key] = value; },
    removeAttribute: key => { delete attributes[key]; },
    submit: () => { submissions.push({method: form.method, action: action.value, ...fields, checked: [...fields.checked]}); }
  };
  if (!options.noRequestSubmit) form.requestSubmit = () => {
    if (options.throwSubmit) throw new Error('Submission failed');
    form.submit();
  };
  const document = {
    readyState: options.loading ? 'loading' : 'complete',
    getElementById: id => id === 'booking-add-selection' && !options.noForm ? form : null,
    addEventListener: (type, handler) => readyListeners.push({type, handler})
  };
  const context = {
    document,
    window: {addEventListener: (type, handler) => windowListeners.push({type, handler})},
    setTimeout: handler => { timers.set(++timerId, handler); return timerId; },
    clearTimeout: id => timers.delete(id),
    fetch: () => { throw new Error('The checkbox enhancement must never use fetch'); }
  };
  vm.runInNewContext(source, context, {filename: 'booking.js'});
  return {
    form, action, button, fields, attributes, submissions, listeners,
    ready: () => readyListeners.forEach(item => item.handler()),
    restore: (persisted = true) => windowListeners.filter(item => item.type === 'pageshow').forEach(item => item.handler({persisted})),
    change: (checkbox = true) => listeners.filter(item => item.type === 'change').forEach(item => item.handler({target: {matches: () => checkbox}})),
    flush: () => { const pending = [...timers.values()]; timers.clear(); pending.forEach(handler => handler()); },
    rerun: () => vm.runInNewContext(source, context, {filename: 'booking.js'})
  };
}

const checked = fixture();
assert.equal(checked.button.hidden, true, 'The manual add button is hidden only after enhancement');
assert.equal(checked.submissions.length, 0, 'Loading the enhancement never submits a reservation');
checked.change();
checked.flush();
assert.deepEqual(checked.submissions, [{method: 'get', action: 'set', date: '2026-11-20', selection: '1795338000', checked: ['1795179600']}],
  'Checking a slot submits the current GET form and retains date and other-day basket context');

const unchecked = fixture();
unchecked.fields.checked = [];
unchecked.change();
unchecked.flush();
assert.equal(unchecked.submissions.length, 1, 'Unchecking the last slot still submits a GET update');
assert.deepEqual(unchecked.submissions[0].checked, [], 'An empty checked set is preserved for server-side removal');
assert.equal(unchecked.submissions[0].selection, '1795338000', 'The script leaves other-day selection handling to the server');

const rapid = fixture();
rapid.change();
rapid.fields.checked.push('1795181400');
rapid.change();
rapid.fields.checked.shift();
rapid.change();
rapid.flush();
assert.equal(rapid.submissions.length, 1, 'Rapid checkbox changes are submitted together');
assert.deepEqual(rapid.submissions[0].checked, ['1795181400'], 'The final checked state is submitted rather than an earlier state');

const restored = fixture();
restored.change();
restored.flush();
restored.restore();
assert.equal(restored.attributes['aria-busy'], undefined, 'Returning through browser history clears the previous navigation state');
restored.fields.checked = [];
restored.change();
restored.flush();
assert.equal(restored.submissions.length, 2, 'A restored page can update its selection again');

const initialShow = fixture();
initialShow.change();
initialShow.restore(false);
initialShow.flush();
assert.equal(initialShow.submissions.length, 1, 'The first pageshow does not cancel a checkbox change while images are loading');

const unrelated = fixture();
unrelated.change(false);
unrelated.flush();
assert.equal(unrelated.submissions.length, 0, 'Changing an unrelated field never submits a booking');

const legacy = fixture({noRequestSubmit: true});
legacy.change();
legacy.flush();
assert.equal(legacy.submissions[0].method, 'get', 'Browsers without requestSubmit retain the read-only GET fallback');

const failed = fixture({throwSubmit: true});
failed.change();
failed.flush();
assert.equal(failed.button.hidden, false, 'A submission exception restores the manual button');
assert.equal(failed.attributes['aria-busy'], undefined, 'A submission exception clears the busy state');

for (const options of [{method: 'post'}, {noAction: true}, {noForm: true}]) {
  const skipped = fixture(options);
  skipped.change();
  skipped.flush();
  assert.equal(skipped.button.hidden, false, 'An ineligible form retains its native controls');
  assert.equal(skipped.submissions.length, 0, 'No POST or unrelated form can be auto-submitted');
}

const loading = fixture({loading: true});
assert.equal(loading.button.hidden, false, 'The fallback remains available while the document loads');
loading.ready();
loading.ready();
loading.rerun();
assert.equal(loading.listeners.length, 1, 'Repeated initialization attaches one change listener');
loading.change();
loading.flush();
assert.equal(loading.submissions.length, 1, 'Deferred initialization submits one read-only update');
process.stdout.write('CDav checkbox selection JavaScript regressions passed.\n');
