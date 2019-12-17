<?php


// what is the email config today
private function email_config() {

	$app = \Slim\Slim::getInstance();
	if( $app->config( "smtp_enabled" ) === "true" ) {
		return "smtp";
	} else {
		// default
		return "api";
	}
}



public function connection() {

	if( $this->email_config() == "smtp" ) {
		$this->smtp_init();
	} else {
		$this->api_connection();
	}
}



/**
 * @return array - You're going to get the log ID and the Mandrill ID if sent via API
 * @throws \Exception
 */
public function send() {

	if( $this->email_config() == "smtp" ) {
		return $this->smtp_send();
	} else {
		return $this->api_send();
	}
}



private function api_connection() {

	$app = \Slim\Slim::getInstance();

	try {
		$this->mandrill = new \Mandrill( $app->config( "smtp_password" ) );
	} catch( \Mandrill_Error $e ) {
		echo 'A mandrill error occurred: ' . get_class( $e ) . ' - ' . $e->getMessage();

	}

}


public function to_email( $email, $ref = null, $name = null) {
	$this->to_email[] = array(
		'email' => $email,
		'name'  => $name,
		'type'  => 'to',
		'ref'   => $ref
	);
}

public function cc_email( $email, $name = null) {
	$this->to_email[] = array(
		'email' => $email,
		'name'  => $name,
		'type'  => 'cc'
	);
}

public function bcc_email( $email, $name = null) {
	$this->to_email[] = array(
		'email' => $email,
		'name'  => $name,
		'type'  => 'bcc'
	);
}

private function email_builder( $emails ) {
	$final = [];

	foreach( $emails as $email ) {
		if( isset($email['name']) && !empty($email['name']) ) {
			$final[$email['email']] = $email['name'];
		} else {
			$final[] = $email['name'];
		}
	}

	return $final;
}





public function api_send() {

	try {

		$message = array(
			'html'                      => $this->email_html,
			'text'                      => $this->email_text,
			'subject'                   => $this->email_subject,
			'from_email'                => $this->from_email,
			'from_name'                 => $this->from_name,
			'to'                        => $this->to_email,
			'headers'                   => array( 'Reply-To' => $this->from_email ),
			'important'                 => $this->important,
			'track_opens'               => $this->track_opens,
			'track_clicks'              => $this->track_clicks,
			'auto_text'                 => null,
			'auto_html'                 => null,
			'inline_css'                => null,
			'url_strip_qs'              => null,
			'preserve_recipients'       => null,
			'view_content_link'         => $this->view_content_link,
			'bcc_address'               => $this->bcc_address,
			'tracking_domain'           => null,
			'signing_domain'            => null,
			'return_path_domain'        => null,
			'merge'                     => true,
			'merge_language'            => 'mailchimp',
			'global_merge_vars'         => array(
				array(
					'name'    => 'merge1',
					'content' => 'merge1 content',
				),
			),
			'merge_vars'                => array(),
			'tags'                      => array(),
			'subaccount'                => null,
			'google_analytics_domains'  => array( $this->domain ),
			'google_analytics_campaign' => '',
			'metadata'                  => array( /*'website' => 'www.example.com'*/ ),
			'recipient_metadata'        => array(),
			'attachments'               => $this->attachments,
			'images'                    => array(),
		);
		$async   = $this->async;
		$ip_pool = 'Main Pool';
		$send_at = $this->send_at;

		// deal with result
		$result = $this->mandrill->messages->send( $message, $async, $ip_pool, $send_at );

		return $this->api_finalise( $result );

	} catch( \Mandrill_Error $e ) {
		throw new \Exception('A mandrill error occurred: ' . get_class( $e ) . ' - ' . $e->getMessage());
	}
}



public function api_finalise( $results ) {

	if( ! is_array( $results ) ) {
		return $results;
	}

	$result = reset( $results );

	// quick reset
	$this->outcome = false;

	$log_id = null;

	$status = [];

	switch( $result[ 'status' ] ) {
		case "queued":
			$status['id'] = $result['_id'];
			$status['email'] = $this->to_email;
			$status['status'] = $result['status'];
			$status['reason'] = null;

			$this->outcome = true;
			break;
		case "rejected":
			$status['id'] = $result['_id'];
			$status['email'] = $this->to_email;
			$status['status'] = $result['status'];
			$status['reason'] = ($result[ 'reject_reason' ]?:NULL);

			$this->outcome = "rejected";

			break;
		case "invalid":
			$status['id'] = $result['_id'];
			$status['email'] = $this->to_email;
			$status['status'] = $result['status'];
			$status['reason'] = ($result[ 'reject_reason' ]?:NULL);

			$this->outcome = "invalid";
			break;
		case "sent":
			// queuing so api can callback when sent
			$status['id'] = $result['_id'];
			$status['email'] = $this->to_email;
			$status['status'] = "queued";
			$status['reason'] = NULL;


			$this->outcome = true;
			break;
		default:
			$status['id'] = $result['_id'];
			$status['email'] = $this->to_email;
			$status['status'] = "Unknown";
			$status['reason'] = ($result[ 'reject_reason' ]?:NULL);


			$this->outcome = "unknown";
			break;
	}


	$this->completed_arr[] = $status;

	// reset this param
	$this->to_email = null;

	return [
		'mandrill_id' => $result['_id'],
		'log_id' => $log_id
	];
}
