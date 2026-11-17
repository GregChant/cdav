-- Technical receipt membership only; dates and business data stay in ActionComm.
CREATE TABLE IF NOT EXISTS llx_cdav_booking_request_event (
  request_hash char(64) NOT NULL,
  segment_no smallint NOT NULL,
  fk_actioncomm integer DEFAULT NULL,
  PRIMARY KEY (request_hash, segment_no),
  KEY idx_cdav_booking_segment_event (fk_actioncomm),
  CONSTRAINT fk_cdav_booking_segment_request FOREIGN KEY (request_hash) REFERENCES llx_cdav_booking_request (request_hash) ON DELETE CASCADE,
  CONSTRAINT fk_cdav_booking_segment_event FOREIGN KEY (fk_actioncomm) REFERENCES llx_actioncomm (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
