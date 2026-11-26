<?php
/**
 * Deliverability checks: what Gmail, Yahoo and Microsoft look at before an email
 * reaches the inbox (SPF, DKIM, DMARC, alignment, spam reports, bounces, replies).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Deliverability.
 */
final class Deliverability {

	/**
	 * Option with the last results, by domain.
	 */
	const OPTION = 'aluleth_smtp_deliverability';

	/**
	 * Free email providers: their addresses cannot send through another service.
	 */
	const FREE = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.fr', 'ymail.com', 'outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com', 'aol.com', 'icloud.com', 'me.com', 'gmx.com', 'gmx.de', 'gmx.fr', 'orange.fr', 'free.fr', 'laposte.net', 'proton.me', 'protonmail.com', 'yandex.com', 'mail.ru', 'web.de', 'zoho.com' );

	/**
	 * Sending domains: the domains of the From addresses of the connections.
	 *
	 * @return array domain => connection ids.
	 */
	public static function domains() {
		$out = array();
		foreach ( Connections::all() as $id => $c ) {
			$at = strrchr( (string) $c['from_email'], '@' );
			if ( false === $at ) {
				continue;
			}
			$out[ strtolower( substr( $at, 1 ) ) ][] = $id;
		}
		return $out;
	}

	/**
	 * Last results of a domain.
	 *
	 * @param string $domain Domain.
	 * @return array|null
	 */
	public static function last( $domain ) {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $domain ] ) ? $all[ $domain ] : null;
	}

	/**
	 * DNS records (empty array: none; null: the lookup failed or is not possible here).
	 *
	 * @param string $name Name.
	 * @param int    $type DNS_TXT, DNS_MX or DNS_CNAME.
	 * @return array|null
	 */
	public static function dns( $name, $type ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		/**
		 * Filters DNS answers (tests, or a resolver of your own).
		 *
		 * @param array|null|false $records Records, or false to ask the DNS.
		 * @param string           $name    Name.
		 * @param int              $type    Type.
		 */
		$pre = apply_filters( 'aluleth_smtp/dns', false, $name, $type );
		if ( false !== $pre ) {
			return $pre;
		}
		// dns_get_record() warns when a name does not exist.
		$records = @dns_get_record( $name, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $records ) ? $records : null;
	}

	/**
	 * TXT values of a name.
	 *
	 * @param string $name Name.
	 * @return string[]|null
	 */
	private static function txt( $name ) {
		$records = self::dns( $name, DNS_TXT );
		if ( null === $records ) {
			return null;
		}
		$out = array();
		foreach ( $records as $r ) {
			if ( isset( $r['entries'] ) && is_array( $r['entries'] ) ) {
				$out[] = implode( '', $r['entries'] );
			} elseif ( isset( $r['txt'] ) ) {
				$out[] = (string) $r['txt'];
			}
		}
		return $out;
	}

	/**
	 * Runs every check for a domain and saves the result.
	 *
	 * @param string $domain Domain.
	 * @return array time, checks (key, title, who, state ok|warn|bad|unknown, text, code), inboxes.
	 */
	public static function check( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$ids    = isset( self::domains()[ $domain ] ) ? self::domains()[ $domain ] : array();
		$conns  = array();
		$checks = array();
		foreach ( $ids as $id ) {
			$c = Connections::get( $id );
			if ( $c ) {
				$conns[ $id ] = $c;
			}
		}

		// Gmail through the Gmail API and Outlook.com through Microsoft Graph are the real senders of their own addresses.
		$native  = array(
			'gmail'     => array( 'gmail.com', 'googlemail.com' ),
			'microsoft' => array( 'outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com' ),
		);
		$foreign = false;
		foreach ( $conns as $c ) {
			if ( ! isset( $native[ $c['provider'] ] ) || ! in_array( $domain, $native[ $c['provider'] ], true ) ) {
				$foreign = true;
			}
		}
		if ( $foreign && in_array( $domain, self::FREE, true ) ) {
			$checks[] = self::row(
				'free',
				__( 'Your own domain', 'aluleth-smtp' ),
				__( 'From address', 'aluleth-smtp' ),
				'bad',
				/* translators: %s: domain. */
				sprintf( __( 'Addresses at %s cannot send through an email service: their DMARC rule makes Gmail, Yahoo and Microsoft refuse or hide these emails. Use an address of your own domain as the From address.', 'aluleth-smtp' ), $domain )
			);
		}

		$checks[] = self::spf( $domain, $conns );

		// True only when every connection is known to sign; false as soon as one does not.
		$states = array();
		foreach ( $conns as $c ) {
			$row      = self::dkim( $domain, $c );
			$checks[] = $row;
			$states[] = $row['state'];
		}
		$dkim_ok = in_array( 'bad', $states, true ) ? false : ( $states && ! array_diff( $states, array( 'ok' ) ) ? true : null );

		$checks[] = self::dmarc( $domain, $dkim_ok );

		if ( true === $dkim_ok ) {
			/* translators: %s: domain. */
			$checks[] = self::row( 'align', __( 'Your domain matches the signature', 'aluleth-smtp' ), __( 'Alignment', 'aluleth-smtp' ), 'ok', sprintf( __( 'The From address and the DKIM signature both use %s, as Gmail, Yahoo and Microsoft ask.', 'aluleth-smtp' ), $domain ) );
		} elseif ( false === $dkim_ok ) {
			$checks[] = self::row( 'align', __( 'Your domain matches the signature', 'aluleth-smtp' ), __( 'Alignment', 'aluleth-smtp' ), 'bad', __( 'At least one connection does not sign with your domain yet, so its emails do not pass DMARC. Authenticate the domain at that service (see DKIM above).', 'aluleth-smtp' ) );
		} else {
			$checks[] = self::row( 'align', __( 'Your domain matches the signature', 'aluleth-smtp' ), __( 'Alignment', 'aluleth-smtp' ), 'unknown', __( 'Could not be checked here. Send a check email to a Gmail address, open it and choose “Show original”: SPF, DKIM and DMARC should say PASS.', 'aluleth-smtp' ) );
		}

		$checks   = array_merge( $checks, self::rates() );
		$checks[] = self::mx( $domain );

		$result         = array(
			'time'    => time(),
			'domain'  => $domain,
			'checks'  => $checks,
			'inboxes' => self::inboxes( $checks ),
		);
		$all            = get_option( self::OPTION, array() );
		$all            = is_array( $all ) ? $all : array();
		$all[ $domain ] = $result;
		update_option( self::OPTION, $all, false );
		return $result;
	}

	/**
	 * One check.
	 *
	 * @param string $key   Key.
	 * @param string $title Title.
	 * @param string $who   What was looked at.
	 * @param string $state ok, warn, bad or unknown.
	 * @param string $text  Explanation.
	 * @param string $code  Record to copy.
	 * @return array
	 */
	private static function row( $key, $title, $who, $state, $text, $code = '' ) {
		return compact( 'key', 'title', 'who', 'state', 'text', 'code' );
	}

	/**
	 * SPF: one record, not too many lookups, not "+all".
	 *
	 * @param string $domain Domain.
	 * @param array  $conns  Connections.
	 * @return array
	 */
	private static function spf( $domain, array $conns ) {
		/* translators: %s: domain. */
		$who  = sprintf( __( 'DNS · TXT on %s', 'aluleth-smtp' ), $domain );
		$txt  = self::txt( $domain );
		$want = array();
		foreach ( $conns as $c ) {
			$p = Connections::provider( $c['provider'] );
			if ( $p && '' !== $p->spf_include() ) {
				$want[ $p->spf_include() ] = $p->label();
			}
		}
		if ( null === $txt ) {
			return self::row( 'spf', 'SPF', $who, 'unknown', __( 'This server cannot look up DNS records. Check with your DNS host or an online SPF checker.', 'aluleth-smtp' ) );
		}
		$spf     = array_values(
			array_filter(
				$txt,
				static function ( $t ) {
					return 0 === stripos( trim( $t ), 'v=spf1' );
				}
			)
		);
		$suggest = 'v=spf1 ' . implode(
			' ',
			array_map(
				static function ( $i ) {
					return 'include:' . $i;
				},
				array_keys( $want )
			)
		) . ' ~all';
		if ( ! $spf ) {
			return self::row( 'spf', 'SPF', $who, 'warn', __( 'No SPF record. Gmail and Yahoo ask for SPF or DKIM on every email, and both for senders of many emails. At your DNS host, add a TXT record on the domain itself with this value (add your other senders, like your mailbox provider, before ~all):', 'aluleth-smtp' ), preg_replace( '/\s+/', ' ', $suggest ) );
		}
		if ( count( $spf ) > 1 ) {
			return self::row( 'spf', 'SPF', $who, 'bad', __( 'Two SPF records: inboxes then ignore both. Merge them into one TXT record.', 'aluleth-smtp' ), implode( "\n", $spf ) );
		}
		$record  = trim( $spf[0] );
		$lookups = self::spf_lookups( $record, 0 );
		if ( preg_match( '/(^|\s)\+all(\s|$)/i', $record ) ) {
			return self::row( 'spf', 'SPF', $who, 'bad', __( 'The record ends with “+all”: anyone may send in your name. Replace it with “~all”.', 'aluleth-smtp' ), $record );
		}
		if ( $lookups > 10 ) {
			/* translators: %d: number of DNS lookups. */
			return self::row( 'spf', 'SPF', $who, 'bad', sprintf( __( 'The record needs %d DNS lookups; the limit is 10, after which SPF fails. Remove the services you no longer use.', 'aluleth-smtp' ), $lookups ), $record );
		}
		$missing = array();
		foreach ( $want as $include => $label ) {
			if ( false === stripos( $record, $include ) ) {
				$missing[] = $label;
			}
		}
		$text = __( 'Found, with one record and few enough lookups.', 'aluleth-smtp' );
		if ( $missing ) {
			/* translators: %s: names of services. */
			$text .= ' ' . sprintf( __( 'Not listed: %s. That is fine when the service signs with DKIM for your domain (it usually checks SPF on its own bounce address).', 'aluleth-smtp' ), implode( ', ', $missing ) );
		}
		return self::row( 'spf', 'SPF', $who, 'ok', $text, $record );
	}

	/**
	 * DNS lookups an SPF record needs (includes followed, three levels at most).
	 *
	 * @param string $record Record.
	 * @param int    $depth  Depth.
	 * @return int
	 */
	private static function spf_lookups( $record, $depth ) {
		$n = 0;
		foreach ( preg_split( '/\s+/', trim( $record ) ) as $term ) {
			$term = ltrim( strtolower( $term ), '+-~?' );
			if ( 0 === strpos( $term, 'include:' ) || 0 === strpos( $term, 'redirect=' ) ) {
				++$n;
				if ( $depth < 3 ) {
					$sep  = 0 === strpos( $term, 'include:' ) ? ':' : '=';
					$name = substr( $term, strpos( $term, $sep ) + 1 );
					foreach ( (array) self::txt( $name ) as $t ) {
						if ( 0 === stripos( trim( $t ), 'v=spf1' ) ) {
							$n += self::spf_lookups( $t, $depth + 1 );
						}
					}
				}
			} elseif ( in_array( $term, array( 'a', 'mx', 'ptr' ), true ) || preg_match( '/^(a|mx|ptr|exists)[:\/]/', $term ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * DKIM of one connection: from the service's API, else from the usual DNS names.
	 *
	 * @param string $domain Domain.
	 * @param array  $c      Connection.
	 * @return array
	 */
	private static function dkim( $domain, array $c ) {
		$p = Connections::provider( $c['provider'] );
		/* translators: %s: connection name. */
		$title = sprintf( __( 'DKIM · %s', 'aluleth-smtp' ), $c['label'] );
		$api   = $p ? $p->domain_check( Connections::credentials( $c ), $domain ) : null;
		if ( true === $api ) {
			/* translators: 1: service name, 2: domain. */
			return self::row( 'dkim', $title, __( 'From the service', 'aluleth-smtp' ), 'ok', sprintf( __( '%1$s signs your emails for %2$s.', 'aluleth-smtp' ), $p->label(), $domain ) );
		}
		if ( false === $api ) {
			/* translators: 1: service name, 2: domain. */
			return self::row( 'dkim', $title, __( 'From the service', 'aluleth-smtp' ), 'bad', sprintf( __( '%1$s does not sign for %2$s yet. In %1$s, authenticate the domain: it gives the DNS records to add at your DNS host.', 'aluleth-smtp' ), $p->label(), $domain ) );
		}
		$found = array();
		$sels  = $p ? $p->dkim_selectors() : array();
		if ( 'smtp' === $c['provider'] ) {
			$sels = array( 'default', 'selector1', 'selector2', 'google', 'mail', 'dkim', 'k1', 's1' );
		}
		foreach ( $sels as $sel ) {
			$name = $sel . '._domainkey.' . $domain;
			$txt  = self::txt( $name );
			$cn   = self::dns( $name, DNS_CNAME );
			if ( ( is_array( $txt ) && $txt ) || ( is_array( $cn ) && $cn ) ) {
				$found[] = $sel;
			}
		}
		if ( $found ) {
			/* translators: %s: DKIM selectors. */
			return self::row( 'dkim', $title, __( 'DNS', 'aluleth-smtp' ), 'ok', sprintf( __( 'A DKIM key is published (%s). The key could not confirm with the service that it is the one in use: a check email tells for sure.', 'aluleth-smtp' ), implode( ', ', $found ) ) );
		}
		return self::row( 'dkim', $title, __( 'Not checked', 'aluleth-smtp' ), 'unknown', __( 'The key cannot read the domain settings at the service, and no usual DKIM record was found. Check in the service that the domain is authenticated, or send a check email.', 'aluleth-smtp' ) );
	}

	/**
	 * DMARC: present, and stronger than "none" once every connection signs.
	 *
	 * @param string    $domain  Domain.
	 * @param bool|null $dkim_ok Whether every connection signs for the domain.
	 * @return array
	 */
	private static function dmarc( $domain, $dkim_ok ) {
		$name   = '_dmarc.' . $domain;
		$txt    = self::txt( $name );
		$parent = implode( '.', array_slice( explode( '.', $domain ), -2 ) );
		if ( is_array( $txt ) && ! self::dmarc_record( $txt ) && $parent !== $domain ) {
			$up = self::txt( '_dmarc.' . $parent );
			if ( is_array( $up ) && self::dmarc_record( $up ) ) {
				$txt  = $up;
				$name = '_dmarc.' . $parent;
			}
		}
		/* translators: %s: DNS name. */
		$who = sprintf( __( 'DNS · %s', 'aluleth-smtp' ), $name );
		if ( null === $txt ) {
			return self::row( 'dmarc', 'DMARC', $who, 'unknown', __( 'This server cannot look up DNS records.', 'aluleth-smtp' ) );
		}
		$record = self::dmarc_record( $txt );
		if ( '' === $record ) {
			return self::row( 'dmarc', 'DMARC', $who, 'bad', __( 'No DMARC record. Gmail and Yahoo require one from senders of many emails, and inboxes trust domains that have one. At your DNS host, add a TXT record named _dmarc with this value:', 'aluleth-smtp' ), 'v=DMARC1; p=none' );
		}
		$policy = preg_match( '/(?:^|;)\s*p\s*=\s*(\w+)/i', $record, $m ) ? strtolower( $m[1] ) : '';
		if ( 'none' === $policy ) {
			$better = preg_replace( '/((?:^|;)\s*p\s*=\s*)none/i', '${1}quarantine', $record );
			if ( true === $dkim_ok ) {
				return self::row( 'dmarc', 'DMARC', $who, 'warn', __( 'Set to “none”: inboxes report problems but do nothing about emails sent in your name. Every connection signs with your domain, so you can move to “quarantine”: replace the value with', 'aluleth-smtp' ), $better );
			}
			return self::row( 'dmarc', 'DMARC', $who, 'ok', __( 'Present, set to “none” (watch only). Keep it so until every connection signs with your domain, then move to “quarantine”.', 'aluleth-smtp' ), $record );
		}
		/* translators: %s: DMARC policy. */
		return self::row( 'dmarc', 'DMARC', $who, 'ok', sprintf( __( 'Present, set to “%s”: your domain is protected from people sending in your name.', 'aluleth-smtp' ), $policy ), $record );
	}

	/**
	 * The DMARC record among TXT values.
	 *
	 * @param array $txt Values.
	 * @return string
	 */
	private static function dmarc_record( array $txt ) {
		foreach ( $txt as $t ) {
			if ( 0 === stripos( trim( $t ), 'v=DMARC1' ) ) {
				return trim( $t );
			}
		}
		return '';
	}

	/**
	 * Spam reports and bounces of the last 30 days (when a service reports events).
	 *
	 * @return array Checks.
	 */
	private static function rates() {
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$e     = Tracking::counts( $since );
		$n     = Log::counts( $since );
		$out   = array();
		if ( $e['delivered'] > 0 ) {
			$rate = $e['complaint'] / max( 1, $e['delivered'] );
			if ( $e['delivered'] < 100 ) {
				/* translators: 1: spam reports, 2: delivered emails. */
				$text  = sprintf( _n( '%1$d report out of %2$d delivered emails: too few emails to judge the rate yet.', '%1$d reports out of %2$d delivered emails: too few emails to judge the rate yet.', $e['complaint'], 'aluleth-smtp' ), $e['complaint'], $e['delivered'] );
				$state = $e['complaint'] > 0 ? 'warn' : 'ok';
			} else {
				$state = $rate >= 0.003 ? 'bad' : ( $rate >= 0.001 ? 'warn' : 'ok' );
				/* translators: 1: rate in percent, 2: number of reports. */
				$text = sprintf( _n( '%1$s of delivered emails (%2$d report). Gmail and Yahoo ask to stay under 0.3%%, ideally under 0.1%%.', '%1$s of delivered emails (%2$d reports). Gmail and Yahoo ask to stay under 0.3%%, ideally under 0.1%%.', $e['complaint'], 'aluleth-smtp' ), number_format_i18n( $rate * 100, 2 ) . '%', $e['complaint'] );
			}
			$out[] = self::row( 'spam', __( 'Spam reports', 'aluleth-smtp' ), __( 'Last 30 days', 'aluleth-smtp' ), $state, $text );
		}
		if ( $n['sent'] > 0 && ( $e['delivered'] > 0 || $e['hard_bounce'] > 0 ) ) {
			$rate = $e['hard_bounce'] / max( 1, $n['sent'] );
			if ( $n['sent'] < 50 ) {
				/* translators: 1: bounces, 2: sent emails. */
				$text  = sprintf( _n( '%1$d bounce out of %2$d sent emails: too few emails to judge the rate yet. Bounced addresses are blocked at once.', '%1$d bounces out of %2$d sent emails: too few emails to judge the rate yet. Bounced addresses are blocked at once.', $e['hard_bounce'], 'aluleth-smtp' ), $e['hard_bounce'], $n['sent'] );
				$state = 'ok';
			} else {
				$state = $rate >= 0.05 ? 'bad' : ( $rate >= 0.02 ? 'warn' : 'ok' );
				/* translators: 1: rate in percent, 2: number of bounces. */
				$text = sprintf( _n( '%1$s of sent emails (%2$d address that does not exist). Bounced addresses are blocked at once, so this stays low.', '%1$s of sent emails (%2$d addresses that do not exist). Bounced addresses are blocked at once, so this stays low.', $e['hard_bounce'], 'aluleth-smtp' ), number_format_i18n( $rate * 100, 1 ) . '%', $e['hard_bounce'] );
			}
			$out[] = self::row( 'bounces', __( 'Bounces', 'aluleth-smtp' ), __( 'Last 30 days', 'aluleth-smtp' ), $state, $text );
		}
		if ( ! $out ) {
			$out[] = self::row( 'events', __( 'Spam reports and bounces', 'aluleth-smtp' ), __( 'Last 30 days', 'aluleth-smtp' ), 'unknown', __( 'No events from your services yet. Set up “Bounces and spam reports” on each connection to see these numbers.', 'aluleth-smtp' ) );
		}
		return $out;
	}

	/**
	 * MX: replies to the From address can arrive.
	 *
	 * @param string $domain Domain.
	 * @return array
	 */
	private static function mx( $domain ) {
		$mx = self::dns( $domain, DNS_MX );
		/* translators: %s: domain. */
		$who = sprintf( __( 'DNS · MX on %s', 'aluleth-smtp' ), $domain );
		if ( null === $mx ) {
			return self::row( 'mx', __( 'Replies can come back', 'aluleth-smtp' ), $who, 'unknown', __( 'This server cannot look up DNS records.', 'aluleth-smtp' ) );
		}
		if ( ! $mx ) {
			return self::row( 'mx', __( 'Replies can come back', 'aluleth-smtp' ), $who, 'warn', __( 'The domain receives no email (no MX record): replies to your emails get lost, and some inboxes distrust such senders.', 'aluleth-smtp' ) );
		}
		$hosts = array_slice( wp_list_pluck( $mx, 'target' ), 0, 2 );
		/* translators: %s: mail servers. */
		return self::row( 'mx', __( 'Replies can come back', 'aluleth-smtp' ), $who, 'ok', sprintf( __( 'The domain receives email (%s).', 'aluleth-smtp' ), implode( ', ', $hosts ) ) );
	}

	/**
	 * Scores by inbox, from the checks each one looks at.
	 *
	 * @param array $checks Checks.
	 * @return array name => [passed, counted, note].
	 */
	private static function inboxes( array $checks ) {
		$keys = array(
			'Gmail'             => array( 'free', 'spf', 'dkim', 'dmarc', 'align', 'spam' ),
			'Yahoo'             => array( 'free', 'spf', 'dkim', 'dmarc', 'align', 'spam' ),
			'Microsoft Outlook' => array( 'free', 'spf', 'dkim', 'dmarc', 'align' ),
		);
		$out  = array();
		foreach ( $keys as $inbox => $wanted ) {
			$pass  = 0;
			$count = 0;
			$todo  = '';
			foreach ( $checks as $c ) {
				if ( ! in_array( $c['key'], $wanted, true ) || 'unknown' === $c['state'] ) {
					continue;
				}
				++$count;
				if ( 'ok' === $c['state'] ) {
					++$pass;
				} elseif ( '' === $todo ) {
					$todo = $c['title'];
				}
			}
			$out[ $inbox ] = array( $pass, $count, $todo );
		}
		return $out;
	}
}
