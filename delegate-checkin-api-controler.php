<?php


/**
 * Processes an incoming API request
 */
public function set_dinner_delegates() {

	$res = new \Slim\Http\Response();


	try {
		// parse serialised
		parse_str( $this->app->request()->post( "include" ), $include );
		parse_str( $this->app->request()->post( "titles" ), $titles );

		if( ! $include || ! $titles ) {

			throw new \Exception();
		}

		// save me
		$this->add_session( "include", $include );
		$this->add_session( "titles", $titles );


		$res->setStatus( 200 );
		$res->write( 'Success' );


	} catch( \Exception $e ) {
		$this->error( "Ensure one or more delegates and titles are selected. If issues continue, let Digital know." );
		$res->setStatus( 400 );
		$res->write( 'You made a bad request' );

	}


	$res->finalize();

}

/**
 * attempt to fetch data and pass to csv builder
 */
public function export_dinner_checkin( $uid ) {

	$dc = $this->model->get_checkin_by_uid( $uid );

	if( ! $dc ) {
		$this->app->flash( "error", "An error has occurred, please try again." );
		$this->app->redirect( "/admin/dinner-checkin/list" );
	}

	$csv_data = $this->model->sort_checkin_for_display( $dc );

	// setup csv helper
	$helper           = new csv_export_helper();
	$helper->headings = $this->human_titles($csv_data->headings);
	$helper->csv_data = $csv_data->delegates;
	$helper->filename = $dc->title . "_export_" . time();
	$helper->export();

}