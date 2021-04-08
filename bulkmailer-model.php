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
	public function get_filter_users( $filters, $event_id, $users = null ) {

		// build select
		$select = "SELECT 
					users.id AS id,
					users.title AS title,
					users.first_name AS first_name,
					users.last_name AS last_name,
					users.email AS email,
					users_meta.company AS company,
					users_meta.job_title AS job_title,
					users_meta.address_1 AS address_1,
					users_meta.address_2 AS address_2,
					users_meta.town_city AS town_city,
					users_meta.county AS county,
					users_meta.postcode AS postcode,
					users_meta.phone AS phone,
					users_meta.mobile AS mobile,
					IF(users_meta.allow_contact=1, 'Yes', 'No') AS allow_contact,
					IF(users_meta.user_list=1, 'Yes', 'No') AS user_list,
					IF(users_meta.post_event_contact=1, 'Yes', 'No') AS post_event_contact,
					IF(users_meta.specific_requirements=1, 'Yes', 'No') AS specific_requirements,
					IF(users_meta.evaluation=1, 'Yes', 'No') AS evaluation,
					tickets.ticket_name AS ticket_name,
					users_lists.list_name AS user_list_name,
					users.event_id AS event_id,
					IF(workshops_users_registrations.id IS NULL, 'NULL', GROUP_CONCAT( CONCAT_WS('~', event_agenda_sessions.session_title, workshops_items.title, ';'))) AS workshops
					";

		// from section
		$from = "
				FROM 
					users 
				LEFT JOIN
					users_meta
				ON 
					users.id = users_meta.id
				JOIN
					users_tickets
				ON
					users.id = users_tickets.user_id
				LEFT JOIN
					users_lists_tickets
				ON
					users_tickets.ticket_id = users_lists_tickets.ticket_id
				JOIN
					tickets
				ON 	
					users_tickets.ticket_id = tickets.id
				LEFT JOIN
					users_lists
				ON 
					users_lists_tickets.user_list_id = users_lists.id
				JOIN
					invoices
				ON
					users_tickets.order_id = invoices.order_id
					
				LEFT JOIN
					workshops_users_registrations
				ON 
					workshops_users_registrations.users_id = users.id
				LEFT JOIN
					workshops_items
				ON
					workshops_items.id = workshops_users_registrations.workshops_items_id
				AND
					workshops_users_registrations.record_status = 1	
				LEFT JOIN 
					agenda_sessions 
				ON 
					agenda_sessions.id = workshops_users_registrations.agenda_sessions_id 
			";

		// start preparing the where
		$where = "	WHERE
					 users.event_id = ?
					 AND
					 users.record_status = 1
					 AND
					 users_tickets.record_status = 1
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
				$where .= " AND workshops_users_registrations.workshops_items_id IS NULL";
			} else {
				$in    = implode( ',', array_fill( 0, count( $filters[ 'breakouts' ] ), '?' ) );
				$where .= " AND workshops_users_registrations.workshops_items_id IN (" . $in . ")";
			}


			// add ref number and var to array
			foreach( $filters[ 'breakouts' ] as $b ) {
				$ref_counter[] = $b;
			}

		}


		if( isset( $filters[ 'extra_questions' ] ) && count( $filters[ 'extra_questions' ] ) > 0 ) {

			$select .= ",CONCAT_WS(';', CONCAT_WS(',', users_extras_questions.label, users_extras.q_value) ) AS extra_questions";

			$from .= "   				
						LEFT JOIN
							users_extras
						ON
							users_extras.user_id = users.id
						
						JOIN
							users_extras_questions_meta
						ON
							users_extras_questions_meta.q_value = users_extras.q_value
							
						JOIN
							users_extras_questions
						ON
							users_extras_questions.q_key = users_extras.q_key ";


			$in    = implode( ',', array_fill( 0, count( $filters[ 'extra_questions' ] ), '?' ) );
			$where .= " AND users_extras_questions_meta.id IN (" . $in . ")";

			foreach( $filters[ 'extra_questions' ] as $b ) {
				$ref_counter[] = $b;
			}
		}

		if( isset( $filters[ 'user_list' ] ) && count( $filters[ 'user_list' ] ) > 0 ) {
			$in    = implode( ',', array_fill( 0, count( $filters[ 'user_list' ] ), '?' ) );
			$where .= " AND users_lists.id IN (" . $in . ")";

			foreach( $filters[ 'user_list' ] as $b ) {
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

		if( isset( $users ) && count( $users ) > 0 ) {
			$in    = implode( ',', array_fill( 0, count( $users ), '?' ) );
			$where .= " AND users.id IN (" . $in . ")";

			foreach( $users as $b ) {
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
				$where .= " AND users.created BETWEEN ? AND ?";

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
				return $sth->fetchAll( \PDO::FETCH_FUNC, [ $this, '_get_filter_users' ] );

			}

			// catch it
		} catch( PDOException $e ) {

			// this needs parsing in to the logging (not yet built)

		} // end try
	}


// 0: id    1: title    2: first_name   3: last_name    4: email    5: company  6: job_title
// 7: address_1 8: address_2    9. town_city    10. county   11. postcode    12. phone   13. mobile
// 14. allow_contact    15. user_list   16.post_event_contact   17. specific_requirements
// 18. evaluation   19. ticket_name     20. user_list_name  21. event_id    22. workshops
	public function _get_filter_users() {

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
		$array[ 'user_list' ]         = $this->valid_string( $data[ 15 ] );
		$array[ 'post_event_contact' ]    = $this->valid_string( $data[ 16 ] );
		$array[ 'specific_requirements' ] = $this->valid_string( $data[ 17 ] );
		$array[ 'evaluation' ]            = $this->valid_string( $data[ 18 ] );
		$array[ 'ticket_name' ]           = $this->valid_string( $data[ 19 ] );
		$array[ 'user_list_name' ]    = $this->valid_string( $data[ 20 ] );
		$array[ 'event_id' ]              = $this->valid_string( $data[ 21 ] );
		$array[ 'workshops' ]             = $this->tidy_workshops( $data[ 22 ] );
		$array                            = array_merge( $this->explode_workshops( $data[ 22 ] ), $array );

		return $array;
	}
