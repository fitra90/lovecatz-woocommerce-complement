<?php
/**
 * Dynamic renderer for a J&T Cargo response.
 *
 * Nothing here is hard-coded: sections, rows and columns are produced from the
 * view model built by LWC_JTC_Response_View, which itself is driven entirely by
 * the payload the server returned.
 *
 * @package LoveCatzWC
 *
 * @var array $view  View model from LWC_JTC_Response_View::build().
 * @var array $args  Rendering options: heading, show_raw.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = isset( $view ) && is_array( $view ) ? $view : array();
$args = isset( $args ) && is_array( $args ) ? $args : array();

$status       = isset( $view['status'] ) ? $view['status'] : 'ok';
$status_label = isset( $view['status_label'] ) ? $view['status_label'] : '';
$groups       = isset( $view['groups'] ) ? $view['groups'] : array();
$collections  = isset( $view['collections'] ) ? $view['collections'] : array();
$notices      = isset( $view['notices'] ) ? $view['notices'] : array();
$show_empty   = ! empty( $view['show_empty'] );
$heading      = isset( $args['heading'] ) ? $args['heading'] : '';
$show_raw     = ! isset( $args['show_raw'] ) || ! empty( $args['show_raw'] );

$status_class = 'lwc-jtc-vw__status--ok';
if ( in_array( $status, array( 'error', 'transport_error', 'business_error' ), true ) ) {
	$status_class = 'lwc-jtc-vw__status--error';
} elseif ( in_array( $status, array( 'empty', 'unknown_structure' ), true ) ) {
	$status_class = 'lwc-jtc-vw__status--warn';
}

// Was any value converted from a non-Latin script?
$converted_count = 0;
foreach ( $groups as $group ) {
	foreach ( $group['items'] as $item ) {
		if ( ! empty( $item['value']['converted'] ) ) {
			++$converted_count;
		}
	}
}
foreach ( $collections as $collection ) {
	foreach ( $collection['rows'] as $row ) {
		foreach ( $row['cells'] as $cell ) {
			if ( ! empty( $cell['converted'] ) ) {
				++$converted_count;
			}
		}
	}
}
?>
<div class="lwc-jtc-vw">
	<style>
		.lwc-jtc-vw { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 13px; color: #1d2327; }
		.lwc-jtc-vw * { box-sizing: border-box; }
		.lwc-jtc-vw h3 { margin: 0 0 8px; font-size: 13px; font-weight: 600; }
		.lwc-jtc-vw__head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
		.lwc-jtc-vw__status { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
		.lwc-jtc-vw__status--ok { background: #edfaef; color: #0f7a3e; border: 1px solid #b8e0c2; }
		.lwc-jtc-vw__status--error { background: #fbeaea; color: #a01b1b; border: 1px solid #e6b8b8; }
		.lwc-jtc-vw__status--warn { background: #fff8e1; color: #7a5a00; border: 1px solid #e6d59b; }
		.lwc-jtc-vw__notice { margin: 0 0 8px; padding: 8px 10px; border-left: 4px solid #ccd0d4; background: #f6f7f7; }
		.lwc-jtc-vw__notice--error { border-left-color: #d63638; background: #fbeaea; }
		.lwc-jtc-vw__notice--warning { border-left-color: #dba617; background: #fff8e1; }
		.lwc-jtc-vw__notice--info { border-left-color: #2271b1; background: #f0f6fc; }
		.lwc-jtc-vw__section { margin-bottom: 14px; }
		.lwc-jtc-vw__title { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #50575e; margin: 0 0 6px; }
		.lwc-jtc-vw__table { width: 100%; border-collapse: collapse; background: #fff; }
		.lwc-jtc-vw__table th, .lwc-jtc-vw__table td { border: 1px solid #dcdcde; padding: 6px 8px; text-align: left; vertical-align: top; }
		.lwc-jtc-vw__table th { background: #f6f7f7; font-weight: 600; width: 34%; }
		.lwc-jtc-vw__grid th { width: auto; }
		.lwc-jtc-vw__code { font-family: Consolas, Monaco, monospace; font-weight: 600; letter-spacing: .02em; }
		.lwc-jtc-vw__empty { color: #8c8f94; }
		.lwc-jtc-vw__converted { color: #646970; font-size: 11px; display: block; margin-top: 2px; }
		.lwc-jtc-vw__meta { color: #646970; font-size: 12px; margin: 0 0 10px; }
		.lwc-jtc-vw details { margin-top: 10px; }
		.lwc-jtc-vw details pre { max-height: 320px; overflow: auto; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; margin: 6px 0 0; font-size: 11px; }
		.lwc-jtc-vw__none { color: #646970; font-style: italic; }
	</style>

	<?php if ( '' !== $heading ) : ?>
		<h3><?php echo esc_html( $heading ); ?></h3>
	<?php endif; ?>

	<div class="lwc-jtc-vw__head">
		<span class="lwc-jtc-vw__status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
		<?php if ( ! empty( $view['interface'] ) ) : ?>
			<span class="lwc-jtc-vw__meta"><?php echo esc_html( $view['interface'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $view['field_count'] ) ) : ?>
			<span class="lwc-jtc-vw__meta"><?php
				/* translators: %d: number of fields returned by the server. */
				printf( esc_html__( '%d field', 'lovecatz-wc' ), (int) $view['field_count'] );
			?></span>
		<?php endif; ?>
		<?php if ( ! empty( $view['row_count'] ) ) : ?>
			<span class="lwc-jtc-vw__meta"><?php
				/* translators: %d: number of repeated rows returned by the server. */
				printf( esc_html__( '%d baris', 'lovecatz-wc' ), (int) $view['row_count'] );
			?></span>
		<?php endif; ?>
	</div>

	<?php foreach ( $notices as $notice ) : ?>
		<?php
		$type = isset( $notice['type'] ) ? $notice['type'] : 'info';
		$text = isset( $notice['text'] ) ? $notice['text'] : '';
		if ( '' === $text ) {
			continue;
		}
		?>
		<div class="lwc-jtc-vw__notice lwc-jtc-vw__notice--<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $text ); ?></div>
	<?php endforeach; ?>

	<?php if ( 0 === count( $groups ) && 0 === count( $collections ) ) : ?>
		<p class="lwc-jtc-vw__none"><?php echo esc_html__( 'Tidak ada data yang bisa ditampilkan. Tidak ada nilai yang ditambahkan atau dikarang.', 'lovecatz-wc' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $groups as $group ) : ?>
		<?php if ( empty( $group['items'] ) ) { continue; } ?>
		<section class="lwc-jtc-vw__section">
			<p class="lwc-jtc-vw__title"><?php echo esc_html( $group['title'] ); ?></p>
			<table class="lwc-jtc-vw__table">
				<tbody>
				<?php foreach ( $group['items'] as $item ) : ?>
					<?php
					$value = isset( $item['value'] ) ? $item['value'] : array();
					$text  = isset( $value['text'] ) ? $value['text'] : '';
					if ( empty( $value['has_value'] ) && ! $show_empty ) {
						continue;
					}
					if ( empty( $value['has_value'] ) ) {
						$text = LWC_JTC_Text::PLACEHOLDER;
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $item['label'] ); ?></th>
						<td class="<?php echo 'code' === $item['type'] ? 'lwc-jtc-vw__code' : ''; ?> <?php echo empty( $value['has_value'] ) ? 'lwc-jtc-vw__empty' : ''; ?>">
							<?php echo esc_html( $text ); ?>
							<?php if ( ! empty( $value['converted'] ) && ! empty( $value['original'] ) && (string) $value['original'] !== (string) $text ) : ?>
								<span class="lwc-jtc-vw__converted"><?php
									/* translators: %s: original text as sent by the server. */
									printf( esc_html__( 'Asli: %s', 'lovecatz-wc' ), esc_html( (string) $value['original'] ) );
								?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endforeach; ?>

	<?php foreach ( $collections as $collection ) : ?>
		<?php if ( empty( $collection['rows'] ) ) { continue; } ?>
		<section class="lwc-jtc-vw__section">
			<p class="lwc-jtc-vw__title"><?php echo esc_html( $collection['title'] ); ?></p>
			<table class="lwc-jtc-vw__table lwc-jtc-vw__grid">
				<thead>
				<tr>
					<?php foreach ( $collection['columns'] as $column ) : ?>
						<th scope="col"><?php echo esc_html( $column['label'] ); ?></th>
					<?php endforeach; ?>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $collection['rows'] as $row ) : ?>
					<tr>
						<?php foreach ( $collection['columns'] as $column ) : ?>
							<?php
							$key   = $column['key'];
							$cell  = isset( $row['cells'][ $key ] ) ? $row['cells'][ $key ] : array();
							$text  = isset( $cell['text'] ) ? $cell['text'] : LWC_JTC_Text::PLACEHOLDER;
							?>
							<td class="<?php echo empty( $cell['has_value'] ) ? 'lwc-jtc-vw__empty' : ''; ?>">
								<?php echo esc_html( $text ); ?>
								<?php if ( ! empty( $cell['converted'] ) && ! empty( $cell['original'] ) && (string) $cell['original'] !== (string) $text ) : ?>
									<span class="lwc-jtc-vw__converted"><?php
										printf( esc_html__( 'Asli: %s', 'lovecatz-wc' ), esc_html( (string) $cell['original'] ) );
									?></span>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( ! empty( $collection['truncated'] ) ) : ?>
				<p class="lwc-jtc-vw__meta"><?php
					printf(
						/* translators: 1: rows shown, 2: rows returned by the server. */
						esc_html__( 'Menampilkan %1$d dari %2$d baris yang dikirim server.', 'lovecatz-wc' ),
						(int) count( $collection['rows'] ),
						(int) $collection['total']
					);
				?></p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<?php if ( $converted_count > 0 ) : ?>
		<p class="lwc-jtc-vw__meta"><?php
			printf(
				/* translators: %d: number of values converted from non-Latin script. */
				esc_html__( '%d nilai diterjemahkan/dialihaksarakan ke huruf Latin. Data asli tidak diubah.', 'lovecatz-wc' ),
				(int) $converted_count
			);
		?></p>
	<?php endif; ?>

	<?php if ( $show_raw && ! empty( $view['raw_json'] ) ) : ?>
		<details>
			<summary><?php echo esc_html__( 'Respons asli (tidak diubah)', 'lovecatz-wc' ); ?></summary>
			<pre><?php echo esc_html( $view['raw_json'] ); ?></pre>
		</details>
	<?php endif; ?>
</div>
