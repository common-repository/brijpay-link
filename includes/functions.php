<?php

/**
 * @return int
 */
function brijpay_scheduler_configuration_interval() {
	$freq_interval = absint( get_option( 'brijpay_scheduler_freq_interval' ) );

	return $freq_interval ?: 1;
}

/**
 * @return int Timestamp
 */
function brijpay_scheduler_configuration_interval_type() {
	$interval_type = get_option( 'brijpay_scheduler_interval_type', 'day' );

	$interval_types_ts = [
		'hour'  => HOUR_IN_SECONDS,
		'day'   => DAY_IN_SECONDS,
		'week'  => WEEK_IN_SECONDS,
		'month' => MONTH_IN_SECONDS,
	];

	return $interval_types_ts[ $interval_type ];
}

/**
 * @return int Timestamp
 */
function brijpay_scheduler_start_from_interval_type() {
	$timestamp     = time() + brijpay_scheduler_configuration_interval_type();
	$interval_type = get_option( 'brijpay_scheduler_interval_type', 'day' );
	$start_time    = brijpay_scheduler_configuration_start_time();

	if ( ! empty ( $start_time ) && ! empty( $interval_type ) && 'hour' !== $interval_type ) {
		list( $hours, $minutes ) = explode( ':', $start_time );

		$dt = new DateTime();
		$dt->setTimestamp( $timestamp );
		$dt->setTime( $hours, $minutes );
		$timestamp = $dt->getTimestamp();
	}

	return $timestamp;
}

/**
 * @return string Timestamp
 */
function brijpay_scheduler_configuration_start_time() {
	return get_option( 'brijpay_scheduler_start_time' );
}
