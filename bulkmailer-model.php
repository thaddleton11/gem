<?php


public function set_email_data_from_webhooks( $webhooks ) {
	// build query
	$sql = "INSERT INTO 
					email_data
						(
						mandrill_id,
						opens,
						opens_detail,
						clicks,
						clicks_detail,
						resends,
						smtp_events,
						updated
						)
				VALUES (
						?,
						?,
						?,
						?,
						?,
						?,
						?,
						?
						)
				ON DUPLICATE KEY UPDATE
						opens = VALUES(opens),
						opens_detail = VALUES(opens_detail),
						clicks = VALUES(clicks),
						clicks_detail = VALUES(clicks_detail),
						resends = VALUES(resends),
						smtp_events = VALUES(smtp_events),
						updated = VALUES(updated);
						";

	// create database object and connection
	$core = pdoCore::getInstance();

	// prepare query
	$sth = $core->dbh->prepare( $sql );

	try {
		// begin transaction
		$core->dbh->beginTransaction();

		foreach( $webhooks as $webhook ) {
			// just msg part of hook
			$msg = $webhook->msg;


			// sod off objects
			$opens   = $msg->opens;
			$clicks  = $msg->clicks;
			$resends = $msg->resends;
			$smtp    = $msg->smtp_events;


			$sth->execute([
				$msg->_id,
				count( $opens ),
				serialize( $opens ),
				count( $clicks ),
				serialize( $clicks ),
				serialize( $resends ),
				serialize( $smtp ),
				date( "Y/m/d H:i:s" )
			]);

		}

		// go go go
		$core->dbh->commit();

		// catch it
	} catch( \PDOException $e ) {
		// needs own class/method
		$writer = fopen( 'assets/logs/webhooks.txt', 'a' );
		fwrite( $writer, "\r\n ***************Set Email Error***************** " . $e->getMessage() );
		fclose($writer);
	} // end try


	/**
	 * This query is dynamically built depending on the filters chosen by the user
	 *
	 */
	public function get_filter_delegates( $filters, $event_id, $delegates = null ) {

		// build select
		$select = "SELECT 
					delegates.id AS id,
					delegates.title AS title,
					delegates.first_name AS first_name,
					delegates.last_name AS last_name,
					delegates.email AS email,
					delegates_meta.company AS company,
					delegates_meta.job_title AS job_title,
					delegates_meta.address_1 AS address_1,
					delegates_meta.address_2 AS address_2,
					delegates_meta.town_city AS town_city,
					delegates_meta.county AS county,
					delegates_meta.postcode AS postcode,
					delegates_meta.phone AS phone,
					delegates_meta.mobile AS mobile,
					IF(delegates_meta.allow_contact=1, 'Yes', 'No') AS allow_contact,
					IF(delegates_meta.delegate_list=1, 'Yes', 'No') AS delegate_list,
					IF(delegates_meta.post_event_contact=1, 'Yes', 'No') AS post_event_contact,
					IF(delegates_meta.specific_requirements=1, 'Yes', 'No') AS specific_requirements,
					IF(delegates_meta.evaluation=1, 'Yes', 'No') AS evaluation,
					tickets.ticket_name AS ticket_name,
					delegates_lists.list_name AS delegate_list_name,
					delegates.event_id AS event_id,
					IF(workshops_delegates_registrations.id IS NULL, 'NULL', GROUP_CONCAT( CONCAT_WS('~', event_agenda_sessions.session_title, workshops_items.title, ';'))) AS workshops
					";

		// from section
		$from = "
				FROM 
					delegates 
				LEFT JOIN
					delegates_meta
				ON 
					delegates.id = delegates_meta.id
				JOIN
					orders_tickets
				ON
					delegates.id = orders_tickets.user_id
				LEFT JOIN
					delegates_lists_tickets
				ON
					orders_tickets.ticket_id = delegates_lists_tickets.ticket_id
				JOIN
					tickets
				ON 	
					orders_tickets.ticket_id = tickets.id
				LEFT JOIN
					delegates_lists
				ON 
					delegates_lists_tickets.delegate_list_id = delegates_lists.id
				JOIN
					invoices
				ON
					orders_tickets.order_id = invoices.order_id
					
				LEFT JOIN
					workshops_delegates_registrations
				ON 
					workshops_delegates_registrations.delegates_id = delegates.id
				LEFT JOIN
					workshops_items
				ON
					workshops_items.id = workshops_delegates_registrations.workshops_items_id
				AND
					workshops_delegates_registrations.record_status = 1	
				LEFT JOIN 
					event_agenda_sessions 
				ON 
					event_agenda_sessions.id = workshops_delegates_registrations.event_agenda_sessions_id 
			";

		// start preparing the where
		$where = "	WHERE
					 delegates.event_id = ?
					 AND
					 delegates.record_status = 1
					 AND
					 orders_tickets.record_status = 1
					 AND
					 invoices.invoice_status NOT IN (4,5,6)
					 ";


		// group by, *whistles*, group by
		$group_by = " GROUP BY orders_tickets.id,orders_tickets.user_id ORDER BY workshops_items.created";

		// question mark counter for binds
		$ref_counter[ 1 ] = $event_id;

		if( isset( $filters[ 'breakouts' ] ) && count( $filters[ 'breakouts' ] ) > 0 ) {

			// build 'IN' and add WHERE
			if( in_array( "none", $filters[ 'breakouts' ] ) ) {
				$where .= " AND workshops_delegates_registrations.workshops_items_id IS NULL";
			} else {
				$in    = implode( ',', array_fill( 0, count( $filters[ 'breakouts' ] ), '?' ) );
				$where .= " AND workshops_delegates_registrations.workshops_items_id IN (" . $in . ")";
			}


			// add ref number and var to array
			foreach( $filters[ 'breakouts' ] as $b ) {
				$ref_counter[] = $b;
			}

		}


		if( isset( $filters[ 'extra_questions' ] ) && count( $filters[ 'extra_questions' ] ) > 0 ) {

			$select .= ",CONCAT_WS(';', CONCAT_WS(',', delegates_extras_questions.label, delegates_extras.q_value) ) AS extra_questions";

			$from .= "   				
						LEFT JOIN
							delegates_extras
						ON
							delegates_extras.user_id = delegates.id
						
						JOIN
							delegates_extras_questions_meta
						ON
							delegates_extras_questions_meta.q_value = delegates_extras.q_value
							
						JOIN
							delegates_extras_questions
						ON
							delegates_extras_questions.q_key = delegates_extras.q_key ";


			$in    = implode( ',', array_fill( 0, count( $filters[ 'extra_questions' ] ), '?' ) );
			$where .= " AND delegates_extras_questions_meta.id IN (" . $in . ")";

			foreach( $filters[ 'extra_questions' ] as $b ) {
				$ref_counter[] = $b;
			}
		}

		if( isset( $filters[ 'delegate_list' ] ) && count( $filters[ 'delegate_list' ] ) > 0 ) {
			$in    = implode( ',', array_fill( 0, count( $filters[ 'delegate_list' ] ), '?' ) );
			$where .= " AND delegates_lists.id IN (" . $in . ")";

			foreach( $filters[ 'delegate_list' ] as $b ) {
				$ref_counter[] = $b;
			}
		}

		if( isset( $filters[ 'tickets' ] ) && count( $filters[ 'tickets' ] ) > 0 ) {
			$in    = implode( ',', array_fill( 0, count( $filters[ 'tickets' ] ), '?' ) );
			$where .= " AND tickets.id IN (" . $in . ")";

			foreach( $filters[ 'tickets' ] as $b ) {
				$ref_counter[] = $b;
			}
		}

		if( isset( $delegates ) && count( $delegates ) > 0 ) {
			$in    = implode( ',', array_fill( 0, count( $delegates ), '?' ) );
			$where .= " AND delegates.id IN (" . $in . ")";

			foreach( $delegates as $b ) {
				$ref_counter[] = $b;
			}
		}

		if( isset( $filters[ 'date_to' ] ) || isset( $filters[ 'date_from' ] ) ) {
			// always want the end of the day
			$to_raw = ( isset( $filters[ 'date_to' ] ) && $filters[ 'date_to' ] ? $filters[ 'date_to' ] . " 23:59:59" : time() );

			// else beginning of time
			$from_raw = ( isset( $filters[ 'date_from' ] ) && $filters[ 'date_from' ] ? $filters[ 'date_from' ] : "01/01/1970 00:00:00" );

			try {
				// from date
				$d      = new \DateTime( $from_raw );
				$from_d = $d->format( "Y-m-d H:i:s" );
				// to date
				$d    = new \DateTime( $to_raw );
				$to_d = $d->format( "Y-m-d H:i:s" );

				// build me some sql
				$where .= " AND delegates.created BETWEEN ? AND ?";

				$ref_counter[] = $from_d;
				$ref_counter[] = $to_d;

			} catch( \Exception $e ) {
				// I don't want anything to happen here
			}

		}


		// unpaid delegits
		if( isset( $filters[ 'invoice_status' ] ) && in_array( "unpaid", $filters[ 'invoice_status' ] ) ) {
			$where .= " AND invoices.invoice_status IN (1,2)";
		}

		// paid delegits
		if( isset( $filters[ 'invoice_status' ] ) && in_array( "paid", $filters[ 'invoice_status' ] ) ) {
			$where .= " AND invoices.invoice_status IN (3, 7)";
		}


		// create database object and connection
		$core = pdoCore::getInstance();
		// prepare query
		$sth = $core->dbh->prepare( $select . $from . $where . $group_by );

		// loop through refs
		foreach( $ref_counter as $key => $field ) {
			$sth->bindValue( (int) $key, $field );
		}


		// try or catch error expression
		try {

			// if we can execute the query
			if( $sth->execute() ) {
				return $sth->fetchAll( \PDO::FETCH_FUNC, [ $this, '_get_filter_delegates' ] );

			}

			// catch it
		} catch( PDOException $e ) {

			// this needs parsing in to the logging (not yet built)

		} // end try
	}


// 0: id    1: title    2: first_name   3: last_name    4: email    5: company  6: job_title
// 7: address_1 8: address_2    9. town_city    10. county   11. postcode    12. phone   13. mobile
// 14. allow_contact    15. delegate_list   16.post_event_contact   17. specific_requirements
// 18. evaluation   19. ticket_name     20. delegate_list_name  21. event_id    22. workshops
	public function _get_filter_delegates() {

		$data = func_get_args();

		$array[ 'id' ]                    = $this->valid_int( $data[ 0 ] );
		$array[ 'title' ]                 = $this->valid_string( $data[ 1 ] );
		$array[ 'first_name' ]            = $this->valid_string( $data[ 2 ] );
		$array[ 'last_name' ]             = $this->valid_string( $data[ 3 ] );
		$array[ 'email' ]                 = $this->valid_email( $data[ 4 ] );
		$array[ 'company' ]               = $this->valid_string( $data[ 5 ] );
		$array[ 'job_title' ]             = $this->valid_string( $data[ 6 ] );
		$array[ 'address_1' ]             = $this->valid_string( $data[ 7 ] );
		$array[ 'address_2' ]             = $this->valid_string( $data[ 8 ] );
		$array[ 'town_city' ]             = $this->valid_string( $data[ 9 ] );
		$array[ 'county' ]                = $this->valid_string( $data[ 10 ] );
		$array[ 'postcode' ]              = $this->valid_string( $data[ 11 ] );
		$array[ 'phone' ]                 = $this->valid_string( $data[ 12 ] );
		$array[ 'mobile' ]                = $this->valid_string( $data[ 13 ] );
		$array[ 'allow_contact' ]         = $this->valid_string( $data[ 14 ] );
		$array[ 'delegate_list' ]         = $this->valid_string( $data[ 15 ] );
		$array[ 'post_event_contact' ]    = $this->valid_string( $data[ 16 ] );
		$array[ 'specific_requirements' ] = $this->valid_string( $data[ 17 ] );
		$array[ 'evaluation' ]            = $this->valid_string( $data[ 18 ] );
		$array[ 'ticket_name' ]           = $this->valid_string( $data[ 19 ] );
		$array[ 'delegate_list_name' ]    = $this->valid_string( $data[ 20 ] );
		$array[ 'event_id' ]              = $this->valid_string( $data[ 21 ] );
		$array[ 'workshops' ]             = $this->tidy_workshops( $data[ 22 ] );
		$array                            = array_merge( $this->explode_workshops( $data[ 22 ] ), $array );

		return $array;
	}
