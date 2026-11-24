<?php
/**
 * Paying again, a year at a time.
 *
 * A recurring occasion — birthdays, work anniversaries — is paid for one
 * year at a time, for everybody on the list as it stands on the day it is
 * paid. The first payment is taken in the wizard; this is what happens a
 * year later.
 *
 * Three reminders go out before the renewal falls due, at the weeks named
 * in Settings. If the day arrives unpaid the occasion pauses: no further
 * cards are written until it is renewed. That is the client's decision,
 * taken deliberately, and it is the reason this file exists rather than
 * the occasion simply running on unpaid.
 *
 * An occasion that happens once for each person — a welcome — is finished
 * when its cards are written. It has no renewal and is never touched here.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Yearly renewal.
 */
class CardsCo_Renewal {

	/**
	 * How many reminders there are.
	 */
	const NOTICES = 3;

	/**
	 * Listen for the renew button.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Take the renewal payment.
	 *
	 * The wizard's own pay action refuses anything already running, which
	 * is right for a first payment and wrong for every one after it, so
	 * renewing has its own way in rather than loosening that guard.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( empty( $_POST['cardsco_renew_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
			return;
		}

		if ( ! isset( $_POST['_cardsco_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cardsco_nonce'] ) ), 'cardsco_renew' ) ) {
			return;
		}

		$company_id = CardsCo_Access::company_id();
		$programme  = CardsCo_Programme::get( absint( $_POST['programme'] ?? 0 ) );

		/* Somebody else's occasion is simply not there. */
		if ( ! $company_id || ! $programme || (int) $programme['company_id'] !== $company_id ) {
			return;
		}

		$back = CardsCo_Shortcodes::page_for( 'cardsco_occasions' );
		$back = $back ? $back : home_url( '/' );

		$stop = static function ( $why ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'cardsco_error', rawurlencode( $why ), $back ) );
			exit;
		};

		if ( ! in_array( (string) $programme['status'], array( 'active', 'paused' ), true ) ) {
			$stop( __( 'That occasion is not running yet, so there is nothing to renew.', 'cardsco' ) );
		}

		if ( ! self::recurring( $programme ) ) {
			$stop( __( 'That occasion is paid for once and does not renew.', 'cardsco' ) );
		}

		$to = CardsCo_Checkout::start( $programme );

		if ( is_wp_error( $to ) ) {
			$stop( $to->get_error_message() );
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Whether this occasion is paid for year by year.
	 *
	 * Two things have to be true: it comes round again, and it runs off
	 * dates people hold. An occasion set to "once, then stop" is paid for
	 * once, however often the category usually recurs.
	 *
	 * @param array $programme Programme row.
	 * @return bool
	 */
	public static function recurring( array $programme ) {
		if ( 'once' === (string) ( $programme['recurrence'] ?? 'annual' ) ) {
			return false;
		}

		$type = CardsCo_Category::primary_type(
			(string) $programme['category'],
			(int) $programme['company_id']
		);

		/*
		 * A type that happens once for each person — a welcome — does not
		 * come round, even though the category is marked annual. Asking the
		 * type rather than the category is what keeps a welcome out of the
		 * renewal run.
		 */
		if ( $type && 'once' === (string) $type['recurrence'] ) {
			return false;
		}

		return (bool) $type;
	}

	/**
	 * The weeks before the date that each reminder goes out, longest first.
	 *
	 * @return int[]
	 */
	public static function offsets() {
		$weeks = array();

		for ( $n = 1; $n <= self::NOTICES; $n++ ) {
			$weeks[] = max( 1, (int) CardsCo_Settings::get( 'renewal_notice_' . $n ) );
		}

		$weeks = array_values( array_unique( $weeks ) );
		rsort( $weeks );

		return $weeks;
	}

	/**
	 * Record that a payment has been taken, and push the date out a year.
	 *
	 * Called for every payment, first or later, so a renewal simply moves
	 * the date on. An occasion that was paused for non-payment comes back
	 * on here, which is the only way it ever does.
	 *
	 * @param int $programme_id Programme.
	 * @return bool Whether anything was changed.
	 */
	public static function paid( $programme_id ) {
		$programme = CardsCo_Programme::get( $programme_id );

		if ( ! $programme || ! self::recurring( $programme ) ) {
			return false;
		}

		/*
		 * A year from today, not a year from the date that just fell due:
		 * somebody who renews three weeks late has paid for a year from the
		 * day they paid, and dating it backwards would charge them for
		 * weeks they did not have.
		 */
		CardsCo_Programme::update(
			$programme_id,
			array(
				'renews_on'       => gmdate( 'Y-m-d', strtotime( '+1 year', (int) current_time( 'timestamp' ) ) ),
				'renewal_notices' => 0,
			)
		);

		if ( 'paused' === (string) $programme['status'] ) {
			self::resume( $programme_id );
		}

		return true;
	}

	/**
	 * Everything with a renewal date on or before a given day.
	 *
	 * @param string $on_or_before A Y-m-d date.
	 * @param int    $limit        Most to return.
	 * @return array[] Programme rows.
	 */
	protected static function due_by( $on_or_before, $limit = 200 ) {
		global $wpdb;

		$table = cardsco_table( 'programmes' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE renews_on IS NOT NULL
				   AND renews_on <= %s
				   AND status IN ( 'active', 'paused' )
				 ORDER BY renews_on ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$on_or_before,
				absint( $limit )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Send any reminders due, and pause anything that has fallen due unpaid.
	 *
	 * Hung off the daily scan. Safe to run more than once a day: a reminder
	 * is counted as it is sent, and the count is what decides whether the
	 * next one is due, so nobody is emailed twice for the same step.
	 *
	 * @return array What happened, for the log.
	 */
	public static function run() {
		$done = array(
			'reminded' => 0,
			'paused'   => 0,
			'looked'   => 0,
		);

		$offsets = self::offsets();
		$longest = $offsets ? max( $offsets ) : 4;
		$today   = (int) current_time( 'timestamp' );

		/*
		 * Everything whose renewal is within the first reminder's reach,
		 * which is also everything already overdue.
		 */
		$horizon = gmdate( 'Y-m-d', strtotime( '+' . $longest . ' weeks', $today ) );

		foreach ( self::due_by( $horizon ) as $programme ) {
			$done['looked']++;

			if ( ! self::recurring( $programme ) ) {
				continue;
			}

			$due = strtotime( (string) $programme['renews_on'] );

			if ( ! $due ) {
				continue;
			}

			/* Due today or already past, and still not paid: stop writing. */
			if ( $due <= $today ) {
				if ( 'paused' !== (string) $programme['status'] ) {
					self::pause( (int) $programme['id'] );
					$done['paused']++;
				}

				continue;
			}

			$sent = (int) $programme['renewal_notices'];

			if ( $sent >= count( $offsets ) ) {
				continue;
			}

			/*
			 * Reminders are counted, not dated, so a site whose cron did not
			 * run for a fortnight sends the one that is now due rather than
			 * silently skipping it.
			 */
			$next_at = strtotime( '-' . $offsets[ $sent ] . ' weeks', $due );

			if ( $next_at > $today ) {
				continue;
			}

			if ( self::remind( $programme, $sent + 1, count( $offsets ) ) ) {
				CardsCo_Programme::update( (int) $programme['id'], array( 'renewal_notices' => $sent + 1 ) );
				$done['reminded']++;
			}
		}

		return $done;
	}

	/**
	 * Stop writing cards until this occasion is paid for.
	 *
	 * The scheduler works off the occasion rows and never reads a
	 * programme's status, so switching the status alone would pause
	 * nothing at all and cards would keep going out unpaid. The rows are
	 * what has to be switched off, which is the same thing taking somebody
	 * off a list does.
	 *
	 * @param int $programme_id Programme.
	 * @return void
	 */
	public static function pause( $programme_id ) {
		$programme_id = absint( $programme_id );
		$people       = CardsCo_Programme::recipient_ids( $programme_id );

		CardsCo_Programme::set_occasions_active( $programme_id, $people, 0 );
		CardsCo_Programme::update( $programme_id, array( 'status' => 'paused' ) );

		self::tell_them_it_stopped( CardsCo_Programme::get( $programme_id ) );
	}

	/**
	 * Start writing cards again.
	 *
	 * @param int $programme_id Programme.
	 * @return void
	 */
	public static function resume( $programme_id ) {
		$programme_id = absint( $programme_id );
		$people       = CardsCo_Programme::recipient_ids( $programme_id );

		CardsCo_Programme::set_occasions_active( $programme_id, $people, 1 );
		CardsCo_Programme::update( $programme_id, array( 'status' => 'active' ) );
	}

	/**
	 * What the renewal will cost, as it stands today.
	 *
	 * The list is charged as it is on the day it is paid, so somebody added
	 * in June is on the next year's bill.
	 *
	 * @param array $programme Programme row.
	 * @return array{people:int,each:float,total:float,currency:string}
	 */
	public static function quote( array $programme ) {
		$people = count( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) );
		$each   = (float) CardsCo_Dashboard::card_price( $programme, (int) $programme['company_id'] );

		return array(
			'people'   => $people,
			'each'     => $each,
			'total'    => $each * $people,
			'currency' => (string) CardsCo_Settings::get( 'currency', null, (int) $programme['company_id'] ),
		);
	}

	/**
	 * Who hears about the money.
	 *
	 * @param array $programme Programme row.
	 * @return string An email address, or empty.
	 */
	protected static function billing_email( array $programme ) {
		$company = CardsCo_Company::get( (int) $programme['company_id'] );

		if ( $company && ! empty( $company['billing_email'] ) ) {
			return (string) $company['billing_email'];
		}

		if ( $company && ! empty( $company['contact_email'] ) ) {
			return (string) $company['contact_email'];
		}

		/* Nobody named for billing: the first owner on the account. */
		foreach ( (array) CardsCo_Company::users( (int) $programme['company_id'] ) as $member ) {
			if ( 'active' !== (string) ( $member['status'] ?? '' ) ) {
				continue;
			}

			$user = get_user_by( 'id', (int) $member['user_id'] );

			if ( $user && ! empty( $user->user_email ) ) {
				return (string) $user->user_email;
			}
		}

		return '';
	}

	/**
	 * Where they go to pay.
	 *
	 * The link lands on the occasions page rather than starting a payment
	 * of its own. A link in an email cannot carry a nonce that will still
	 * be valid for the person who opens it, and a plain address that takes
	 * money is the sort of thing that gets forwarded.
	 *
	 * @param array $programme Programme row.
	 * @return string
	 */
	public static function pay_link( array $programme ) {
		$page = CardsCo_Shortcodes::page_for( 'cardsco_occasions' );
		$page = $page ? $page : home_url( '/' );

		return add_query_arg( 'renew', (int) $programme['id'], $page );
	}

	/**
	 * One reminder.
	 *
	 * @param array $programme Programme row.
	 * @param int   $number    Which reminder this is, from 1.
	 * @param int   $of        How many there are.
	 * @return bool Whether it was sent.
	 */
	protected static function remind( array $programme, $number, $of ) {
		$to = self::billing_email( $programme );

		if ( ! $to ) {
			return false;
		}

		$category = CardsCo_Category::get( (string) $programme['category'] );
		$label    = $category ? $category['label'] : __( 'your occasion', 'cardsco' );
		$quote    = self::quote( $programme );
		$due      = date_i18n( 'j F Y', strtotime( (string) $programme['renews_on'] ) );
		$money    = CardsCo_Shortcodes::money(
			number_format( $quote['total'], 2, '.', '' ),
			$quote['currency']
		);

		$subject = $number >= $of
			? sprintf(
				/* translators: 1: the occasion, 2: the date it is due. */
				__( 'Last reminder: %1$s renews on %2$s', 'cardsco' ),
				$label,
				$due
			)
			: sprintf(
				/* translators: 1: the occasion, 2: the date it is due. */
				__( '%1$s renews on %2$s', 'cardsco' ),
				$label,
				$due
			);

		$body  = sprintf(
			/* translators: 1: the occasion, 2: the date. */
			__( 'Your %1$s cards are paid for up to %2$s.', 'cardsco' ),
			$label,
			$due
		) . "\n\n";

		$body .= sprintf(
			/* translators: 1: how many people, 2: the total. */
			__( 'The coming year covers %1$s, which comes to %2$s. The list is charged as it stands on the day you renew, so anyone you add before then is included.', 'cardsco' ),
			sprintf(
				/* translators: %s: how many people. */
				_n( '%s person', '%s people', $quote['people'], 'cardsco' ),
				number_format_i18n( $quote['people'] )
			),
			wp_strip_all_tags( $money )
		) . "\n\n";

		$body .= __( 'Renew here:', 'cardsco' ) . "\n" . self::pay_link( $programme ) . "\n\n";

		$body .= sprintf(
			/* translators: %s: the date. */
			__( 'If it is not renewed by %s the cards stop until it is. Nothing is written or charged in the meantime.', 'cardsco' ),
			$due
		) . "\n";

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * The one that goes out when it has actually stopped.
	 *
	 * Being paused is the thing somebody most needs to be told about, and
	 * it is the one message that cannot be a reminder before the event.
	 *
	 * @param array|null $programme Programme row.
	 * @return bool
	 */
	protected static function tell_them_it_stopped( $programme ) {
		if ( ! is_array( $programme ) ) {
			return false;
		}

		$to = self::billing_email( $programme );

		if ( ! $to ) {
			return false;
		}

		$category = CardsCo_Category::get( (string) $programme['category'] );
		$label    = $category ? $category['label'] : __( 'your occasion', 'cardsco' );

		$body  = sprintf(
			/* translators: %s: the occasion. */
			__( '%s has paused because the year it was paid for has ended.', 'cardsco' ),
			$label
		) . "\n\n";

		$body .= __( 'No more cards will be written until it is renewed, and nothing is being charged. Everything you set up is kept exactly as it is.', 'cardsco' ) . "\n\n";
		$body .= __( 'Start it again here:', 'cardsco' ) . "\n" . self::pay_link( $programme ) . "\n";

		return (bool) wp_mail(
			$to,
			sprintf(
				/* translators: %s: the occasion. */
				__( '%s has paused', 'cardsco' ),
				$label
			),
			$body
		);
	}
}
