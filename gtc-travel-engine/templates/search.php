<?php
/**
 * Search and compare.
 *
 * @package GTC
 * @var array $atts Shortcode attributes.
 */

defined( 'ABSPATH' ) || exit;

$gtc_categories = GTC_Categories::available();
$gtc_active     = isset( $atts['category'] ) && isset( $gtc_categories[ $atts['category'] ] ) ? $atts['category'] : key( $gtc_categories );
$gtc_all        = GTC_Categories::all();

$gtc_default_in  = gmdate( 'Y-m-d', time() + ( 30 * DAY_IN_SECONDS ) );
$gtc_default_out = gmdate( 'Y-m-d', time() + ( 33 * DAY_IN_SECONDS ) );
?>
<div class="gtc gtc-app" data-gtc-app>

	<?php if ( ! $gtc_categories ) : ?>
		<div class="gtc-notice gtc-notice--empty">
			<?php esc_html_e( 'No supplier is connected yet. Enable one under Travel Engine → Suppliers.', 'gtc' ); ?>
		</div>
	<?php else : ?>

	<form class="gtc-searchbar" data-gtc-form>

		<nav class="gtc-tabs" role="tablist">
			<?php foreach ( $gtc_all as $gtc_slug => $gtc_meta ) : ?>
				<?php $gtc_live = isset( $gtc_categories[ $gtc_slug ] ); ?>
				<button
					type="button"
					class="gtc-tab<?php echo $gtc_slug === $gtc_active ? ' is-active' : ''; ?><?php echo $gtc_live ? '' : ' is-disabled'; ?>"
					data-gtc-tab="<?php echo esc_attr( $gtc_slug ); ?>"
					<?php echo $gtc_live ? '' : 'disabled aria-disabled="true"'; ?>
					<?php echo $gtc_live ? '' : 'title="' . esc_attr__( 'No supplier connected for this category yet', 'gtc' ) . '"'; ?>
				><?php echo esc_html( $gtc_meta['label'] ); ?></button>
			<?php endforeach; ?>
		</nav>

		<input type="hidden" name="category" value="<?php echo esc_attr( $gtc_active ); ?>" data-gtc-category>

		<div class="gtc-fields">
			<label class="gtc-field gtc-field--wide">
				<span class="gtc-field__label"><?php esc_html_e( 'Where are you going?', 'gtc' ); ?></span>
				<input
					type="text"
					name="destination"
					value="<?php echo esc_attr( isset( $atts['destination'] ) ? $atts['destination'] : '' ); ?>"
					placeholder="<?php esc_attr_e( 'City or destination', 'gtc' ); ?>"
					autocomplete="off"
					required
				>
			</label>

			<label class="gtc-field">
				<span class="gtc-field__label"><?php esc_html_e( 'Check in', 'gtc' ); ?></span>
				<input type="date" name="check_in" value="<?php echo esc_attr( $gtc_default_in ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
			</label>

			<label class="gtc-field">
				<span class="gtc-field__label"><?php esc_html_e( 'Check out', 'gtc' ); ?></span>
				<input type="date" name="check_out" value="<?php echo esc_attr( $gtc_default_out ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
			</label>

			<label class="gtc-field gtc-field--narrow">
				<span class="gtc-field__label"><?php esc_html_e( 'Rooms', 'gtc' ); ?></span>
				<select name="rooms">
					<?php for ( $gtc_i = 1; $gtc_i <= 4; $gtc_i++ ) : ?>
						<option value="<?php echo esc_attr( $gtc_i ); ?>"><?php echo esc_html( $gtc_i ); ?></option>
					<?php endfor; ?>
				</select>
			</label>

			<label class="gtc-field gtc-field--narrow">
				<span class="gtc-field__label"><?php esc_html_e( 'Adults', 'gtc' ); ?></span>
				<select name="adults">
					<?php for ( $gtc_i = 1; $gtc_i <= 6; $gtc_i++ ) : ?>
						<option value="<?php echo esc_attr( $gtc_i ); ?>"<?php selected( 2, $gtc_i ); ?>><?php echo esc_html( $gtc_i ); ?></option>
					<?php endfor; ?>
				</select>
			</label>

			<label class="gtc-field gtc-field--narrow">
				<span class="gtc-field__label"><?php esc_html_e( 'Children', 'gtc' ); ?></span>
				<select name="children">
					<?php for ( $gtc_i = 0; $gtc_i <= 4; $gtc_i++ ) : ?>
						<option value="<?php echo esc_attr( $gtc_i ); ?>"><?php echo esc_html( $gtc_i ); ?></option>
					<?php endfor; ?>
				</select>
			</label>

			<button type="submit" class="gtc-btn gtc-btn--primary gtc-search-btn">
				<?php esc_html_e( 'Compare prices', 'gtc' ); ?>
			</button>
		</div>
	</form>

	<div class="gtc-results" data-gtc-results hidden>

		<header class="gtc-results__head">
			<div>
				<h2 class="gtc-results__title" data-gtc-title></h2>
				<p class="gtc-results__meta" data-gtc-meta></p>
			</div>

			<label class="gtc-field gtc-field--sort">
				<span class="gtc-field__label"><?php esc_html_e( 'Sort by', 'gtc' ); ?></span>
				<select data-gtc-sort>
					<option value="price_asc"><?php esc_html_e( 'Price: lowest first', 'gtc' ); ?></option>
					<option value="price_desc"><?php esc_html_e( 'Price: highest first', 'gtc' ); ?></option>
					<option value="rating_desc"><?php esc_html_e( 'Guest rating', 'gtc' ); ?></option>
					<option value="stars_desc"><?php esc_html_e( 'Star rating', 'gtc' ); ?></option>
					<option value="saving_desc"><?php esc_html_e( 'Biggest saving', 'gtc' ); ?></option>
				</select>
			</label>
		</header>

		<div class="gtc-suppliers" data-gtc-suppliers></div>

		<div class="gtc-list" data-gtc-list></div>

		<div class="gtc-pager" data-gtc-pager hidden></div>
	</div>

	<div class="gtc-state" data-gtc-state hidden></div>

	<?php endif; ?>
</div>
