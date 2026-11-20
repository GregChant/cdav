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
for cdav_qa_suite in sync_bootstrap multiget calendar_factorization calendar_read_cost carddav_refactoring run freebusy contact_photos attachments business_policy contact_business calendar_policy appointment_status agenda_form calendar_description invitation_policy invitation_scheduling booking booking_projects booking_calendar booking_multi booking_identity booking_details booking_notification booking_publication booking_validity booking_ui; do
    "$cdav_qa_php" "tests/$cdav_qa_suite.php"
done
node --check js/booking.js
node --check js/booking_admin.js
node --check tests/booking_browser.cjs
node tests/booking_selection_js.cjs
if [[ -n "${CDAV_PHPCS_STANDARD:-}" ]]; then
    phpcs --standard="$CDAV_PHPCS_STANDARD" \
        class/CDavBookingAvailability.php class/CDavBookingCalendarReader.php \
        class/CDavBookingService.php class/CDavBookingIdentity.php class/CDavBookingDetails.php \
        class/CDavBookingSchema.php class/CDavBookingNotification.php class/CDavBookingPublication.php public/booking.php lib/cdav_transport.lib.php \
        lib/cdav_bootstrap.inc.php lib/cdav_config.lib.php class/CDavMultiGetReport.php class/CDavCardDAVPlugin.php \
        class/CDavAppointmentStatus.php class/CDavAgendaForm.php core/triggers/interface_99_modCDav_CDavAppointmentStatus.class.php class/CDavCalendarReadCache.php class/CDavBusinessPolicy.php class/CDavCalendarWritePolicy.php class/CDavInvitationPolicy.php class/CDavCalDAVPlugin.php class/CDavSchedulingPlugin.php \
        admin/booking.php tpl/booking.tpl.php \
        tests/sync_bootstrap.php tests/multiget.php tests/calendar_factorization.php tests/calendar_read_cost.php tests/carddav_refactoring.php tests/bootstrap.php tests/booking.php tests/booking_projects.php tests/booking_calendar.php tests/booking_multi.php \
        tests/booking_identity.php tests/booking_details.php tests/booking_notification.php tests/booking_publication.php tests/booking_validity.php tests/booking_ui.php \
        tests/appointment_status.php tests/agenda_form.php tests/status_live_cases.php tests/business_policy.php tests/contact_business.php tests/calendar_policy.php tests/calendar_description.php tests/invitation_policy.php tests/invitation_scheduling.php \
        tests/business_live_cases.php tests/business_delete_rollback_cases.php tests/business_project_acl_cases.php tests/invitation_live_cases.php tests/calendar_read_live_cases.php \
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
