<?php
/**
 * Plugin Name:       WP Rewrite Rules Viewer
 * Plugin URI:        https://github.com/Azanumer/wp-rewrite-rules-viewer
 * Description:       Lists every WordPress rewrite rule, lets you test a URL against them to see which rule matches and what query it produces, and flushes the rules with one click.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://azanumer.cv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-rewrite-rules-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WRWV_VERSION', '1.0.0' );
define( 'WRWV_PER_PAGE', 100 );

/**
 * Register the Tools page.
 */
function wrwv_register_page() {
	add_management_page(
		__( 'Rewrite Rules', 'wp-rewrite-rules-viewer' ),
		__( 'Rewrite Rules', 'wp-rewrite-rules-viewer' ),
		'manage_options',
		'wrwv-rewrite-rules',
		'wrwv_render_page'
	);
}
add_action( 'admin_menu', 'wrwv_register_page' );

/**
 * All rewrite rules with a source label.
 *
 * @return array[] Each item: array( 'regex' => string, 'query' => string, 'source' => string ).
 */
function wrwv_get_rules() {
	global $wp_rewrite;

	$rules       = $wp_rewrite->wp_rewrite_rules();
	$extra_top   = $wp_rewrite->extra_rules_top;
	$extra_query = $wp_rewrite->extra_rules;

	$out = array();
	foreach ( (array) $rules as $regex => $query ) {
		if ( isset( $extra_top[ $regex ] ) ) {
			$source = __( 'Top (extra)', 'wp-rewrite-rules-viewer' );
		} elseif ( isset( $extra_query[ $regex ] ) ) {
			$source = __( 'Extra', 'wp-rewrite-rules-viewer' );
		} else {
			$source = __( 'Standard', 'wp-rewrite-rules-viewer' );
		}
		$out[] = array(
			'regex'  => (string) $regex,
			'query'  => (string) $query,
			'source' => $source,
		);
	}
	return $out;
}

/**
 * Test a request path against the rules, exactly the way WordPress does
 * in WP::parse_request(): first rule whose regex matches wins, and
 * $matches[N] placeholders in the query string are replaced.
 *
 * @param string $request Request path, no leading slash (e.g. '2026/11/hello-world/').
 * @param array  $rules   Rules from wrwv_get_rules().
 * @return array|null array( 'regex' => string, 'query' => string, 'vars' => array ) or null when nothing matches.
 */
function wrwv_test_url( $request, $rules ) {
	foreach ( $rules as $rule ) {
		$matches = array();
		// @codingStandardsIgnoreStart — rule regexes come from core/plugins, not user input.
		if ( preg_match( '#^' . $rule['regex'] . '#', $request, $matches ) ) {
			$query = $rule['query'];
			// Replace $matches[1]..$matches[N], highest index first so
			// $matches[10] is not mangled by replacing $matches[1] first.
			for ( $i = count( $matches ) - 1; $i >= 1; $i-- ) {
				$query = str_replace( '$matches[' . $i . ']', $matches[ $i ], $query );
			}
			$vars = array();
			parse_str( $query, $vars );
			return array(
				'regex' => $rule['regex'],
				'query' => $query,
				'vars'  => $vars,
			);
		}
		// @codingStandardsIgnoreEnd
	}
	return null;
}

/**
 * Render the Tools page.
 */
function wrwv_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-rewrite-rules-viewer' ) );
	}

	$notice = '';
	if ( isset( $_POST['wrwv_flush'] ) ) {
		check_admin_referer( 'wrwv_flush' );
		flush_rewrite_rules( false );
		$notice = __( 'Rewrite rules flushed and regenerated.', 'wp-rewrite-rules-viewer' );
	}

	$rules = wrwv_get_rules();

	// --- Test a URL -----------------------------------------------------
	$test_result  = null;
	$test_request = '';
	if ( isset( $_GET['wrwv_test'] ) ) {
		$test_request = isset( $_GET['wrwv_url'] ) ? sanitize_text_field( wp_unslash( $_GET['wrwv_url'] ) ) : '';
		$test_request = trim( $test_request, '/' );
		// A subdirectory install prefixes the home path; strip it so the
		// path matches what WP_Rewrite sees.
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $test_request, $home_path . '/' ) ) {
			$test_request = substr( $test_request, strlen( $home_path ) + 1 );
		}
		if ( '' !== $test_request ) {
			$test_result = wrwv_test_url( $test_request, $rules );
		}
	}

	// --- Filter + paginate the rule list --------------------------------
	$search = isset( $_GET['wrwv_search'] ) ? sanitize_text_field( wp_unslash( $_GET['wrwv_search'] ) ) : '';
	if ( '' !== $search ) {
		$needle = strtolower( $search );
		$rules  = array_values( array_filter( $rules, function ( $rule ) use ( $needle ) {
			return false !== strpos( strtolower( $rule['regex'] ), $needle )
				|| false !== strpos( strtolower( $rule['query'] ), $needle );
		} ) );
	}

	$total_rules = count( $rules );
	$page        = isset( $_GET['wrwv_page'] ) ? max( 1, (int) $_GET['wrwv_page'] ) : 1;
	$pages       = max( 1, (int) ceil( $total_rules / WRWV_PER_PAGE ) );
	$page        = min( $page, $pages );
	$shown       = array_slice( $rules, ( $page - 1 ) * WRWV_PER_PAGE, WRWV_PER_PAGE );

	$base_url = add_query_arg( array( 'page' => 'wrwv-rewrite-rules' ), admin_url( 'tools.php' ) );
	if ( '' !== $search ) {
		$base_url = add_query_arg( 'wrwv_search', $search, $base_url );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Rewrite Rules', 'wp-rewrite-rules-viewer' ); ?></h1>
		<p><?php esc_html_e( 'Every rewrite rule WordPress currently holds, in matching order — the first rule whose pattern matches a URL wins. Use the tester below to see exactly which rule handles a URL and what query it produces.', 'wp-rewrite-rules-viewer' ); ?></p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Test a URL', 'wp-rewrite-rules-viewer' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
			<input type="hidden" name="page" value="wrwv-rewrite-rules" />
			<input type="hidden" name="wrwv_test" value="1" />
			<label for="wrwv_url"><?php esc_html_e( 'URL path:', 'wp-rewrite-rules-viewer' ); ?></label>
			<input type="text" id="wrwv_url" name="wrwv_url" size="60" value="<?php echo esc_attr( isset( $_GET['wrwv_url'] ) ? sanitize_text_field( wp_unslash( $_GET['wrwv_url'] ) ) : '' ); ?>" placeholder="2026/11/hello-world/" />
			<input type="submit" class="button" value="<?php esc_attr_e( 'Test', 'wp-rewrite-rules-viewer' ); ?>" />
		</form>

		<?php if ( isset( $_GET['wrwv_test'] ) ) : ?>
			<?php if ( '' === $test_request ) : ?>
				<p><?php esc_html_e( 'Enter a URL path to test.', 'wp-rewrite-rules-viewer' ); ?></p>
			<?php elseif ( null === $test_result ) : ?>
				<div class="notice notice-warning inline"><p><?php printf( esc_html__( 'No rule matched "%s" — WordPress would serve a 404 for it.', 'wp-rewrite-rules-viewer' ), esc_html( $test_request ) ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 900px; margin-top: 10px;">
					<tbody>
						<tr><th style="width: 160px;"><?php esc_html_e( 'Matched rule', 'wp-rewrite-rules-viewer' ); ?></th><td><code><?php echo esc_html( $test_result['regex'] ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Resulting query', 'wp-rewrite-rules-viewer' ); ?></th><td><code><?php echo esc_html( $test_result['query'] ); ?></code></td></tr>
						<tr><th><?php esc_html_e( 'Query vars', 'wp-rewrite-rules-viewer' ); ?></th><td><code><?php echo esc_html( wp_json_encode( $test_result['vars'] ) ); ?></code></td></tr>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<hr />

		<h2>
			<?php printf( esc_html__( 'Rules (%d)', 'wp-rewrite-rules-viewer' ), (int) $total_rules ); ?>
			<form method="post" style="display: inline; margin-left: 15px;">
				<?php wp_nonce_field( 'wrwv_flush' ); ?>
				<input type="submit" name="wrwv_flush" class="button" value="<?php esc_attr_e( 'Flush Rewrite Rules', 'wp-rewrite-rules-viewer' ); ?>" />
			</form>
		</h2>

		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
			<input type="hidden" name="page" value="wrwv-rewrite-rules" />
			<input type="search" name="wrwv_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter rules…', 'wp-rewrite-rules-viewer' ); ?>" />
			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'wp-rewrite-rules-viewer' ); ?>" />
			<?php if ( '' !== $search ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wrwv-rewrite-rules' ), admin_url( 'tools.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'wp-rewrite-rules-viewer' ); ?></a>
			<?php endif; ?>
		</form>

		<table class="widefat striped" style="margin-top: 10px;">
			<thead>
				<tr>
					<th style="width: 50px;">#</th>
					<th><?php esc_html_e( 'Regex', 'wp-rewrite-rules-viewer' ); ?></th>
					<th><?php esc_html_e( 'Query', 'wp-rewrite-rules-viewer' ); ?></th>
					<th style="width: 110px;"><?php esc_html_e( 'Source', 'wp-rewrite-rules-viewer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $shown as $i => $rule ) : ?>
					<tr>
						<td><?php echo (int) ( ( $page - 1 ) * WRWV_PER_PAGE + $i + 1 ); ?></td>
						<td><code><?php echo esc_html( $rule['regex'] ); ?></code></td>
						<td><code><?php echo esc_html( $rule['query'] ); ?></code></td>
						<td><?php echo esc_html( $rule['source'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $shown ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No rules found.', 'wp-rewrite-rules-viewer' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo paginate_links( array(
						'base'      => add_query_arg( 'wrwv_page', '%#%', $base_url ),
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => __( '&laquo; Previous', 'wp-rewrite-rules-viewer' ),
						'next_text' => __( 'Next &raquo;', 'wp-rewrite-rules-viewer' ),
					) );
					?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
