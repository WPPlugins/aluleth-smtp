<?php
/**
 * The site's own colours and fonts, read from the theme (theme.json) and from
 * the Elementor kit, so forms, popups and member pages can match the site.
 *
 * Each colour or font has a token ("wp:primary", "el:accent") that is turned
 * into the site's CSS variable with a fallback, for example
 * var(--e-global-color-accent, #ff7a45): when the owner changes the colour in
 * the site settings, the form follows.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Site colours and fonts.
 */
final class Site_Style {

	/**
	 * Cache for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Colours and fonts found on the site.
	 *
	 * @return array{colors: array[], fonts: array[]} Colors: token, name, hex, source. Fonts: token, name, family, source.
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$colors = array();
		$fonts  = array();

		// Elementor kit (global colours and fonts).
		$kit = (int) get_option( 'elementor_active_kit' );
		if ( $kit && defined( 'ELEMENTOR_VERSION' ) ) {
			$settings = get_post_meta( $kit, '_elementor_page_settings', true );
			if ( is_array( $settings ) ) {
				foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
					foreach ( isset( $settings[ $group ] ) ? (array) $settings[ $group ] : array() as $item ) {
						$hex = isset( $item['color'] ) ? self::hex( $item['color'] ) : '';
						if ( $hex && ! empty( $item['_id'] ) ) {
							$colors[] = array(
								'token'  => 'el:' . sanitize_key( $item['_id'] ),
								'name'   => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : $item['_id'],
								'hex'    => $hex,
								'source' => 'Elementor',
							);
						}
					}
				}
				foreach ( array( 'system_typography', 'custom_typography' ) as $group ) {
					foreach ( isset( $settings[ $group ] ) ? (array) $settings[ $group ] : array() as $item ) {
						$family = isset( $item['typography_font_family'] ) ? self::family( $item['typography_font_family'] ) : '';
						if ( $family && ! empty( $item['_id'] ) ) {
							$fonts[] = array(
								'token'  => 'el:' . sanitize_key( $item['_id'] ),
								'name'   => ( isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : $item['_id'] ) . ' · ' . $family,
								'family' => $family,
								'source' => 'Elementor',
							);
						}
					}
				}
			}
		}

		// Theme (theme.json): the theme's and the owner's palette, not WordPress' default one.
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'theme', 'custom' ) as $origin ) {
				foreach ( isset( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ? $palette[ $origin ] : array() as $item ) {
					$hex = isset( $item['color'] ) ? self::hex( $item['color'] ) : '';
					if ( $hex && ! empty( $item['slug'] ) ) {
						$colors[] = array(
							'token'  => 'wp:' . sanitize_key( $item['slug'] ),
							'name'   => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : $item['slug'],
							'hex'    => $hex,
							'source' => __( 'Theme', 'aluleth-smtp' ),
						);
					}
				}
			}
			$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
			foreach ( array( 'theme', 'custom' ) as $origin ) {
				foreach ( isset( $families[ $origin ] ) && is_array( $families[ $origin ] ) ? $families[ $origin ] : array() as $item ) {
					$family = isset( $item['fontFamily'] ) ? self::family( $item['fontFamily'] ) : '';
					if ( $family && ! empty( $item['slug'] ) ) {
						$fonts[] = array(
							'token'  => 'wp:' . sanitize_key( $item['slug'] ),
							'name'   => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : $item['slug'],
							'family' => $family,
							'source' => __( 'Theme', 'aluleth-smtp' ),
						);
					}
				}
			}
		}

		/**
		 * Filters the colours and fonts found on the site (for themes and builders not read yet).
		 *
		 * @param array $style colors, fonts.
		 */
		self::$cache = (array) apply_filters(
			'aluleth/site_style',
			array(
				'colors' => $colors,
				'fonts'  => $fonts,
			)
		);
		return self::$cache;
	}

	/**
	 * A colour found by its token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function color( $token ) {
		foreach ( self::get()['colors'] as $color ) {
			if ( $color['token'] === $token ) {
				return $color;
			}
		}
		return null;
	}

	/**
	 * A font found by its token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function font( $token ) {
		foreach ( self::get()['fonts'] as $font ) {
			if ( $font['token'] === $token ) {
				return $font;
			}
		}
		return null;
	}

	/**
	 * CSS value of a colour token or hex: the site's variable with the colour as fallback.
	 *
	 * @param string $value '#rrggbb' or a token.
	 * @return array{css: string, hex: string}|null
	 */
	public static function color_css( $value ) {
		$hex = self::hex( $value );
		if ( $hex ) {
			return array(
				'css' => $hex,
				'hex' => $hex,
			);
		}
		$color = self::color( (string) $value );
		if ( ! $color ) {
			return null;
		}
		$slug = substr( $color['token'], 3 );
		$var  = 0 === strpos( $color['token'], 'el:' ) ? '--e-global-color-' . $slug : '--wp--preset--color--' . $slug;
		return array(
			'css' => 'var(' . $var . ',' . $color['hex'] . ')',
			'hex' => $color['hex'],
		);
	}

	/**
	 * CSS value of a font token.
	 *
	 * @param string $token Token.
	 * @return string Empty when unknown.
	 */
	public static function font_css( $token ) {
		$font = self::font( (string) $token );
		if ( ! $font ) {
			return '';
		}
		$slug     = substr( $font['token'], 3 );
		$var      = 0 === strpos( $font['token'], 'el:' ) ? '--e-global-typography-' . $slug . '-font-family' : '--wp--preset--font-family--' . $slug;
		$fallback = false !== strpos( $font['family'], ',' ) ? $font['family'] : '"' . $font['family'] . '",sans-serif';
		return 'var(' . $var . ',' . $fallback . ')';
	}

	/**
	 * Readable text colour on a background: dark or white.
	 *
	 * @param string $hex Background '#rrggbb'.
	 * @return string
	 */
	public static function on( $hex ) {
		$rgb = array_map( 'hexdec', str_split( substr( $hex, 1 ), 2 ) );
		$lin = array_map(
			static function ( $c ) {
				$c /= 255;
				return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			},
			$rgb
		);
		$lum = 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
		// Contrast with white vs with #0e1116 (luminance about 0.0058).
		return ( 1.05 / ( $lum + 0.05 ) ) >= ( ( $lum + 0.05 ) / 0.0558 ) ? '#ffffff' : '#0e1116';
	}

	/**
	 * Normalises a colour to '#rrggbb'.
	 *
	 * @param mixed $value Colour.
	 * @return string Empty when not a plain hex colour.
	 */
	public static function hex( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m ) ) {
			return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		}
		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
	}

	/**
	 * Cleans a font family list (letters, digits, spaces, quotes, commas, dashes).
	 *
	 * @param mixed $value Font family.
	 * @return string
	 */
	public static function family( $value ) {
		$value = trim( preg_replace( '/[^A-Za-z0-9 ,\'"\-_]/', '', (string) $value ) );
		return substr( $value, 0, 120 );
	}
}
