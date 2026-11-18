<?php
/**
 * The masthead on every account screen.
 *
 * Three ways to set the logo, in the order they are tried:
 *
 *   1. a logo="..." attribute on the shortcode, the same way
 *      [cardsco_nav logo="..."] already works, so a single page can override
 *   2. the cardsco logo_url setting, for every auth page at once
 *   3. a drawn mark and wordmark, so the pages are not broken before
 *      anybody has set either
 *
 * The drawn mark is a fallback, not a brand. Anything public-facing should
 * be running on the real file.
 *
 * @package CardsCo
 *
 * @var array $links Shortcode attributes, when the view has them.
 */

defined( 'ABSPATH' ) || exit;

$cc_logo = '';

if ( isset( $links ) && is_array( $links ) && ! empty( $links['logo'] ) ) {
	$cc_logo = (string) $links['logo'];
}

if ( ! $cc_logo ) {
	$cc_logo = (string) CardsCo_Settings::get( 'logo_url', '' );
}

/*
 * Falling back to the logo that ships with the plugin, rather than to a
 * drawn placeholder. WordPress blocks SVG uploads to the media library by
 * default, but nothing stops a plugin serving its own — so this works with
 * no upload and no shortcode attribute at all.
 */
if ( ! $cc_logo ) {
	$cc_logo = CARDSCO_URL . 'assets/img/logo.svg';
}

$cc_alt  = (string) CardsCo_Settings::get( 'logo_alt', get_bloginfo( 'name' ) );
$cc_home = (string) CardsCo_Settings::get( 'marketing_url', home_url( '/' ) );
?>
<a class="cc-auth-logo" href="<?php echo esc_url( $cc_home ); ?>">
	<?php if ( $cc_logo ) : ?>
		<img class="cc-auth-logo-img" src="<?php echo esc_url( $cc_logo ); ?>" alt="<?php echo esc_attr( $cc_alt ); ?>" />
	<?php else : ?>
		<span class="cc-auth-logo-mark" aria-hidden="true">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<rect x="2" y="4" width="20" height="16" rx="2"></rect>
				<path d="m2 7 10 7 10-7"></path>
			</svg>
		</span>
		<span class="cc-auth-logo-text">CardsCo</span>
	<?php endif; ?>
</a>
