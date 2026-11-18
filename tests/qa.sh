#!/usr/bin/env bash
# Run from any directory; live writes remain explicitly opt-in and test guarded.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
: "${DOLIBARR_ROOT:?Set DOLIBARR_ROOT to the native Dolibarr htdocs directory}"
cdav_qa_php="${CDAV_PHP_BIN:-php}"
"$cdav_qa_php" -r 'printf("PHP %s\n", PHP_VERSION);'
rg --files -g '*.php' -0 | while IFS= read -r -d '' cdav_qa_file; do
    "$cdav_qa_php" -l "$cdav_qa_file" > /dev/null
done
printf '%s\n' 'PHP lint passed.'
for cdav_qa_suite in run freebusy contact_photos attachments booking booking_calendar booking_multi booking_identity booking_details booking_notification booking_validity booking_ui; do
    "$cdav_qa_php" "tests/$cdav_qa_suite.php"
done
node --check js/booking.js
node --check js/booking_admin.js
node tests/booking_selection_js.cjs
if [[ -n "${CDAV_PHPCS_STANDARD:-}" ]]; then
    phpcs --standard="$CDAV_PHPCS_STANDARD" \
        class/CDavBookingAvailability.php class/CDavBookingCalendarReader.php \
        class/CDavBookingService.php class/CDavBookingIdentity.php class/CDavBookingDetails.php \
        class/CDavBookingSchema.php class/CDavBookingNotification.php public/booking.php lib/cdav_transport.lib.php \
        admin/booking.php tpl/booking.tpl.php \
        tests/bootstrap.php tests/booking.php tests/booking_calendar.php tests/booking_multi.php \
        tests/booking_identity.php tests/booking_details.php tests/booking_notification.php tests/booking_validity.php tests/booking_ui.php \
        tests/booking_live.php tests/booking_multi_live.php tests/booking_profiles_live.php tests/booking_browser.php
fi
if [[ "${CDAV_LIVE_TEST:-0}" == 1 ]]; then
    : "${CDAV_LIVE_URL:?Set the DAV URL of the isolated test instance}"
    : "${CDAV_BOOKING_LIVE_URL:?Set the booking URL of the isolated test instance}"
    : "${CDAV_BOOKING_TEST_FIXTURES:?Set to 1 only for a disposable test tree}"
    "$cdav_qa_php" tests/live_integration.php
    "$cdav_qa_php" tests/booking_live.php
    "$cdav_qa_php" tests/booking_multi_live.php
    if [[ -n "${CDAV_BOOKING_MAIL_SINK:-}" ]]; then
        "$cdav_qa_php" tests/booking_profiles_live.php
    fi
fi
