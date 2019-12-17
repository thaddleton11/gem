<?php


class icalendar_helper {


	public static $filename;
	public static $start;
	public static $end;
	public static $summary;
	public static $location;
	public static $description;


	// creates and downloads ics file
	public static function download() {

		ob_start();

		header('Content-Type: text/calendar; charset=utf-8');
		header('Content-Disposition: inline; filename='.self::$filename.'.ics');

		echo "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//glasgows.co.uk
TZID:Europe/Brussels X-LIC-
BEGIN:VEVENT
UID:".uniqid()."
DTSTART:".self::parse_date(self::$start)."
DTEND:".self::parse_date(self::$end)."
SUMMARY: ".self::$summary."
LOCATION: ".self::$location."
DESCRIPTION:",self::$description."
END:VEVENT
END:VCALENDAR";


		ob_flush();
		flush();
		ob_end_flush();
		exit();
	}



	// get calendar entry from DB via UID and inits the download
	// fail = returns false.
	public static function download_by_uid( $uid ) {

		$cal = icalendar_model::get_icalendar_by_uid( $uid );

		// if empty array
		if( empty($cal) ) return false;

		try {
			self::$filename = $cal['filename'];
			self::$start = $cal['start_date'];
			self::$end = $cal['end_date'];
			self::$summary = $cal['summary'];
			self::$location = $cal['location'];
			self::$description = $cal['description'];

			// go go go
			self::download();

			return true;

		} catch( \Exception $e ) {
			return false;
		}




	}



	private static function parse_date($date) {

		$timestamp = strtotime($date);

		/*$dt = new \DateTime($timestamp);
		return $dt->format('Ymd\THis\Z');*/

		return date('Ymd\THis', $timestamp);
	}

	// continue with Slims "decoration"
	public function call() {

		$this->next->call();

	}

}