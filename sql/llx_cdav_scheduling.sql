-- Preserve iCalendar scheduling metadata that has no native Dolibarr field
-- (ORGANIZER, ATTENDEE and VALARM). The calendar event remains the source of
-- truth for dates, labels, location, notes and free/busy state.

CREATE TABLE IF NOT EXISTS llx_cdav_scheduling (
  fk_actioncomm integer NOT NULL,
  calendardata mediumtext NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (fk_actioncomm),
  CONSTRAINT fk_cdav_scheduling_actioncomm
    FOREIGN KEY (fk_actioncomm) REFERENCES llx_actioncomm (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
