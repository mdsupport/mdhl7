CREATE TABLE IF NOT EXISTS `hl7log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `msg_type` varchar(10) DEFAULT NULL,
  `msg_partner` varchar(40) DEFAULT NULL,
  `msg_date` datetime DEFAULT current_timestamp(),
  `link_source` varchar(40) DEFAULT NULL,
  `link_key` bigint(20) DEFAULT NULL,
  `msg_body` text DEFAULT NULL,
  `msg_response` text DEFAULT NULL,
  `msg_result` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_msg` (`msg_type`,`msg_partner`,`msg_date`),
  KEY `ix_emr` (`msg_type`,`link_source`,`link_key`)
) ENGINE=InnoDB;