<?php
/**
 * J&T Cargo one-part waybill template (76 mm × 175 mm).
 *
 * Renders the 23-field label described in OneTemplate.xls. Field numbering
 * matches the legend in that workbook.
 *
 * Rendering rules:
 *  - every value comes from LWC_JTC_Label::collect(), which resolves it from
 *    the carrier response (through LWC_JTC_Field_Map) or from real order data;
 *  - a field with no value hides its element instead of printing a guess;
 *  - a waybill that is not assigned yet renders the safe placeholder only;
 *  - all text is Latin script (Indonesian) by the time it reaches here.
 *
 * @package LoveCatzWC
 *
 * @var array $data Prepared by LWC_JTC_Label::collect().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_waybill = '' !== (string) $data['waybill'];
$has_weight  = null !== $data['billing_weight'];
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>">
<head>
<meta charset="UTF-8">
<title><?php
	/* translators: %s: order ID */
	printf( esc_html__( 'Label J&T Cargo — pesanan #%s', 'lovecatz-wc' ), esc_html( (string) $data['order_id'] ) );
?></title>
<style>
	@page { size: 76mm 175mm; margin: 0; }
	html, body { width: 76mm; margin: 0; padding: 0; background: #fff; color: #000;
		font-family: Arial, Helvetica, "Segoe UI", "Trebuchet MS", sans-serif; }
	.no-print { padding: 4mm; background: #fff8d6; border-bottom: 0.3mm dashed #c9a227; font-size: 9pt; color: #5b4a0a; text-align: center; }
	@media print { .no-print { display: none; } body { margin: 0; } }
	.lwc-jtc-label { box-sizing: border-box; width: 76mm; height: 175mm; padding: 2mm; position: relative; overflow: hidden;
		font-size: 8pt; line-height: 1.15; }
	.lwc-jtc-section { width: 100%; box-sizing: border-box; }
	.lwc-jtc-rule { border: 0; border-top: 0.2mm dashed #000; margin: 0.6mm 0; }
	.lwc-jtc-section--header { display: flex; align-items: center; height: 14mm; }
	.lwc-jtc-logo { display: block; }
	.lwc-jtc-logo-img { height: 11mm; width: auto; display: block; }
	.lwc-jtc-header-right { margin-left: auto; text-align: right; }
	.lwc-jtc-cod-badge { display: inline-block; border: 0.4mm solid #000; padding: 0.5mm 1.2mm; font-weight: 700; font-size: 9pt; }
	.lwc-jtc-hotline { font-size: 7pt; margin-top: 0.5mm; }
	.lwc-jtc-section--waybill { display: flex; align-items: stretch; height: 11mm; }
	.lwc-jtc-waybill { font-size: 20pt; font-weight: 700; letter-spacing: 0.5pt; flex: 1; overflow: hidden; white-space: nowrap; }
	.lwc-jtc-product { font-size: 9pt; font-weight: 700; align-self: center; padding-left: 1mm; border-left: 0.2mm solid #000; }
	.lwc-jtc-section--origin { display: flex; align-items: center; height: 8mm; font-size: 8pt; }
	.lwc-jtc-consolidated { border: 0.4mm solid #000; border-radius: 1mm; padding: 0.1mm 1mm; font-weight: 700; font-size: 7pt; margin-right: 1mm; line-height: 1.2; white-space: nowrap; }
	.lwc-jtc-origin { font-weight: 700; font-size: 12pt; margin-right: 2mm; }
	.lwc-jtc-print-time { font-size: 7pt; }
	.lwc-jtc-party { display: flex; align-items: flex-start; }
	.lwc-jtc-party-badge { border: 0.3mm solid #000; border-radius: 1mm; padding: 0.2mm 0.9mm;
		font-weight: 700; font-size: 7pt; flex: 0 0 auto; margin-right: 1mm; margin-top: 0.2mm; white-space: nowrap; }
	.lwc-jtc-party-body { flex: 1; min-width: 0; }
	.lwc-jtc-party-name { font-weight: 700; font-size: 9pt; word-break: break-all; }
	.lwc-jtc-party-company { font-size: 7.5pt; }
	.lwc-jtc-party-address { font-size: 7.5pt; word-break: break-word; }
	.lwc-jtc-three-segment { font-size: 7pt; writing-mode: vertical-rl; text-orientation: mixed; letter-spacing: 0.3pt; padding: 0 0.5mm;
		text-align: center; }
	.lwc-jtc-section--recipient { height: 22mm; }
	.lwc-jtc-section--sender { height: 16mm; }
	.lwc-jtc-section--insurance { display: flex; align-items: center; height: 5mm; font-size: 8pt; }
	.lwc-jtc-insurance-badge { border: 0.3mm solid #000; border-radius: 1mm; padding: 0.2mm 0.9mm;
		font-weight: 700; font-size: 7pt; margin-right: 1mm; white-space: nowrap; }
	.lwc-jtc-insurance-row { display: flex; flex: 1; gap: 2mm; }
	.lwc-jtc-insurance-cell { flex: 1; }
	.lwc-jtc-insurance-cell strong { font-weight: 700; }
	.lwc-jtc-section--notes { height: 4mm; font-size: 7pt; padding-top: 0.5mm; }
	.lwc-jtc-section--barcode { height: 18mm; display: flex; align-items: center; justify-content: center; flex-direction: column; }
	.lwc-jtc-barcode { width: 68mm; height: 12mm; }
	.lwc-jtc-barcode-caption { font-size: 8pt; letter-spacing: 0.5pt; margin-top: 0.5mm; }
	.lwc-jtc-section--remarks { height: 18mm; position: relative; padding-top: 1mm; }
	.lwc-jtc-remarks-label { font-weight: 700; font-size: 9pt; }
	.lwc-jtc-remarks-body { font-size: 7.5pt; word-break: break-word; max-height: 13mm; overflow: hidden; }
	.lwc-jtc-inspected { position: absolute; right: 1mm; top: 1mm; border: 0.3mm solid #000; padding: 1mm 1.5mm; font-size: 7.5pt; }
	.lwc-jtc-section--signature { display: flex; align-items: flex-start; height: 15mm; padding-top: 0.5mm; }
	.lwc-jtc-qr { width: 14mm; height: 14mm; margin-right: 1.5mm; flex: 0 0 14mm; }
	.lwc-jtc-signature-body { flex: 1; font-size: 7pt; line-height: 1.2; }
	.lwc-jtc-signature-label { font-weight: 700; font-size: 8pt; }
	.lwc-jtc-watermark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
		pointer-events: none; font-size: 48pt; font-weight: 700; color: rgba(0, 0, 0, 0.08); letter-spacing: 1pt;
		transform: rotate(-20deg); white-space: nowrap; }
	.lwc-jtc-preview-tag { display: inline-block; background: #ffd76b; color: #5b3a00; font-weight: 700; font-size: 7pt;
		padding: 0.3mm 1mm; margin-left: 1mm; vertical-align: middle; }
</style>
</head>
<body>
<div class="no-print">
	<?php
	if ( $data['is_preview'] ) {
		echo esc_html__( 'Pratinjau — nomor resi belum tersedia. Klik Cetak pada dialog browser untuk mencetak label.', 'lovecatz-wc' );
	} else {
		echo esc_html__( 'Klik Cetak pada dialog browser untuk mencetak label.', 'lovecatz-wc' );
	}
	?>
</div>
<div class="lwc-jtc-label">

	<?php if ( '' !== $data['three_segment_wm'] ) : ?>
		<div class="lwc-jtc-watermark" aria-hidden="true"><?php echo esc_html( $data['three_segment_wm'] ); ?></div>
	<?php endif; ?>

	<!-- 1. Company logo -->
	<!-- 23. Customer service line (only when configured; never guessed) -->
	<section class="lwc-jtc-section lwc-jtc-section--header">
		<div class="lwc-jtc-logo">
			<?php if ( ! empty( $data['company_logo_url'] ) ) : ?>
				<img class="lwc-jtc-logo-img" src="<?php echo esc_url( $data['company_logo_url'] ); ?>" alt="<?php echo esc_attr( $data['company_logo_text'] ); ?>">
			<?php elseif ( '' !== $data['company_logo_text'] ) : ?>
				<span style="font-size:16pt;font-weight:700;color:#0f7a3e;letter-spacing:0.5pt;"><?php echo esc_html( $data['company_logo_text'] ); ?></span>
			<?php endif; ?>
		</div>
		<div class="lwc-jtc-header-right">
			<?php if ( '' !== $data['cod_amount'] ) : ?>
				<span class="lwc-jtc-cod-badge"><?php echo esc_html__( 'COD', 'lovecatz-wc' ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $data['hotline'] ) : ?>
				<div class="lwc-jtc-hotline"><?php
					/* translators: %s: customer service number from the store configuration. */
					printf( esc_html__( 'Layanan: %s', 'lovecatz-wc' ), esc_html( $data['hotline'] ) );
				?></div>
			<?php endif; ?>
		</div>
	</section>

	<hr class="lwc-jtc-rule">

	<!-- 2. Waybill number (large) -->
	<!-- 5. Product type (server value; hidden when the server omits it) -->
	<section class="lwc-jtc-section lwc-jtc-section--waybill">
		<div class="lwc-jtc-waybill"><?php
			echo $has_waybill
				? esc_html( $data['waybill'] )
				: esc_html__( 'RESI BELUM ADA', 'lovecatz-wc' );
			if ( $data['is_preview'] ) {
				echo ' <span class="lwc-jtc-preview-tag">' . esc_html__( 'PRATINJAU', 'lovecatz-wc' ) . '</span>';
			}
		?></div>
		<?php if ( '' !== $data['product_type'] ) : ?>
			<div class="lwc-jtc-product"><?php echo esc_html( $data['product_type'] ); ?></div>
		<?php endif; ?>
	</section>

	<!-- 6. Consolidated badge -->
	<!-- 7. Origin code -->
	<!-- 8. Print time + optional sheet sequence -->
	<section class="lwc-jtc-section lwc-jtc-section--origin">
		<?php if ( $data['consolidated'] ) : ?>
			<span class="lwc-jtc-consolidated"><?php echo esc_html_x( 'Konsolidasi', 'consolidated-shipment-badge', 'lovecatz-wc' ); ?></span>
		<?php endif; ?>
		<?php if ( '' !== $data['origin_code'] ) : ?>
			<span class="lwc-jtc-origin"><?php echo esc_html( $data['origin_code'] ); ?></span>
		<?php endif; ?>
		<span class="lwc-jtc-print-time"><?php
			echo esc_html( $data['print_time'] );
			if ( '' !== $data['sheet_seq'] ) {
				echo ' · ' . esc_html( $data['sheet_seq'] );
			}
		?></span>
	</section>

	<hr class="lwc-jtc-rule">

	<!-- 9. Recipient marker -->
	<!-- 10. Recipient name + phone + company + address -->
	<!-- 4. Three-segment code (right-side vertical) -->
	<section class="lwc-jtc-section lwc-jtc-section--recipient lwc-jtc-party">
		<div class="lwc-jtc-party-badge"><?php echo esc_html_x( 'Penerima', 'recipient-badge', 'lovecatz-wc' ); ?></div>
		<div class="lwc-jtc-party-body">
			<div class="lwc-jtc-party-name"><?php
				echo esc_html( trim( $data['recipient']['name'] . ' ' . $data['recipient']['phone'] ) );
			?></div>
			<?php if ( $data['recipient']['has_company'] ) : ?>
				<div class="lwc-jtc-party-company"><?php echo esc_html( $data['recipient']['company'] ); ?></div>
			<?php endif; ?>
			<div class="lwc-jtc-party-address"><?php echo esc_html( $data['recipient']['address'] ); ?></div>
		</div>
		<?php if ( '' !== $data['three_segment'] ) : ?>
			<div class="lwc-jtc-three-segment" title="<?php echo esc_attr( $data['three_segment'] ); ?>">
				<?php echo esc_html( $data['three_segment'] ); ?>
			</div>
		<?php endif; ?>
	</section>

	<hr class="lwc-jtc-rule">

	<!-- 11. Sender marker -->
	<!-- 12. Sender name + phone + company + address -->
	<section class="lwc-jtc-section lwc-jtc-section--sender lwc-jtc-party">
		<div class="lwc-jtc-party-badge"><?php echo esc_html_x( 'Pengirim', 'sender-badge', 'lovecatz-wc' ); ?></div>
		<div class="lwc-jtc-party-body">
			<div class="lwc-jtc-party-name"><?php
				echo esc_html( trim( $data['sender']['name'] . ' ' . $data['sender']['phone'] ) );
			?></div>
			<?php if ( ! empty( $data['sender']['company'] ) ) : ?>
				<div class="lwc-jtc-party-company"><?php echo esc_html( $data['sender']['company'] ); ?></div>
			<?php endif; ?>
			<div class="lwc-jtc-party-address"><?php echo esc_html( $data['sender']['address'] ); ?></div>
		</div>
	</section>

	<hr class="lwc-jtc-rule">

	<!-- 13. Insurance marker -->
	<!-- 14. COD amount -->
	<!-- 15. Freight collect amount -->
	<!-- 16. Billing weight -->
	<section class="lwc-jtc-section lwc-jtc-section--insurance">
		<?php if ( $data['has_insurance'] ) : ?>
			<span class="lwc-jtc-insurance-badge"><?php echo esc_html_x( 'Asuransi', 'insurance-badge', 'lovecatz-wc' ); ?></span>
		<?php endif; ?>
		<div class="lwc-jtc-insurance-row">
			<?php if ( '' !== $data['cod_amount'] ) : ?>
				<div class="lwc-jtc-insurance-cell">
					<strong><?php echo esc_html__( 'COD', 'lovecatz-wc' ); ?></strong>
					<?php echo ' ' . esc_html( $data['cod_currency_symbol'] ) . ' ' . esc_html( $data['cod_amount'] ); ?>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $data['freight_collect'] ) : ?>
				<div class="lwc-jtc-insurance-cell">
					<strong><?php echo esc_html__( 'Ongkir', 'lovecatz-wc' ); ?></strong>
					<?php echo ' ' . esc_html( $data['freight_collect'] ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $has_weight ) : ?>
				<div class="lwc-jtc-insurance-cell">
					<strong><?php echo esc_html__( 'Berat', 'lovecatz-wc' ); ?></strong>
					<?php
					echo ' ' . esc_html( rtrim( rtrim( number_format( (float) $data['billing_weight'], 2, '.', '' ), '0' ), '.' ) );
					if ( '' !== $data['billing_weight_unit'] ) {
						echo ' ' . esc_html( $data['billing_weight_unit'] );
					}
					?>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( '' !== $data['remarks'] ) : ?>
		<!-- 20. Remarks (short strip) -->
		<div class="lwc-jtc-section lwc-jtc-section--notes">
			<span class="lwc-jtc-remarks-label"><?php echo esc_html__( 'Catatan:', 'lovecatz-wc' ); ?></span>
			<?php echo esc_html( wp_trim_words( $data['remarks'], 6, '…' ) ); ?>
		</div>
	<?php endif; ?>

	<hr class="lwc-jtc-rule">

	<!-- 17. Waybill barcode -->
	<section class="lwc-jtc-section lwc-jtc-section--barcode">
		<?php if ( $has_waybill ) : ?>
			<svg class="lwc-jtc-barcode" id="lwc-jtc-waybill-barcode" data-value="<?php echo esc_attr( $data['waybill'] ); ?>" xmlns="http://www.w3.org/2000/svg"></svg>
		<?php else : ?>
			<div class="lwc-jtc-barcode" style="display:flex;align-items:center;justify-content:center;border:0.2mm dashed #000;color:#888;"><?php echo esc_html__( 'Belum ada nomor resi', 'lovecatz-wc' ); ?></div>
		<?php endif; ?>
		<?php if ( $has_waybill ) : ?>
			<div class="lwc-jtc-barcode-caption"><?php echo esc_html( $data['waybill'] ); ?></div>
		<?php endif; ?>
	</section>

	<hr class="lwc-jtc-rule">

	<!-- 21. Inspection stamp -->
	<!-- 20. Remarks -->
	<section class="lwc-jtc-section lwc-jtc-section--remarks">
		<div class="lwc-jtc-remarks-label"><?php echo esc_html__( 'Catatan', 'lovecatz-wc' ); ?></div>
		<div class="lwc-jtc-remarks-body"><?php echo '' !== $data['remarks'] ? esc_html( $data['remarks'] ) : esc_html__( '—', 'lovecatz-wc' ); ?></div>
	</section>

	<hr class="lwc-jtc-rule">

	<!-- 18. Recipient signature row + terms -->
	<!-- 19. Contact channel (only when configured) -->
	<section class="lwc-jtc-section lwc-jtc-section--signature">
		<img class="lwc-jtc-qr" alt="" src="<?php echo esc_url( 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . rawurlencode( $data['qr_data'] ) ); ?>">
		<div class="lwc-jtc-signature-body">
			<div class="lwc-jtc-signature-label"><?php echo esc_html__( 'Tanda tangan / waktu', 'lovecatz-wc' ); ?></div>
			<?php if ( '' !== $data['signature_terms'] ) : ?>
				<div><?php echo esc_html( $data['signature_terms'] ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $data['wechat_label'] ) : ?>
				<div style="margin-top:1mm;"><?php echo esc_html( $data['wechat_label'] ); ?></div>
			<?php endif; ?>
		</div>
	</section>

</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
	var svg = document.getElementById('lwc-jtc-waybill-barcode');
	if (svg && window.JsBarcode) {
		try {
			JsBarcode(svg, svg.getAttribute('data-value') || '', {
				format: 'CODE128',
				width: 1.2,
				height: 44,
				displayValue: false,
				margin: 0
			});
		} catch (err) {
			svg.outerHTML = '<div class="lwc-jtc-barcode" style="display:flex;align-items:center;justify-content:center;border:0.2mm dashed #c33;color:#c33;">' + (err && err.message ? err.message : 'barcode') + '</div>';
		}
	}
	setTimeout(function () { window.print(); }, 350);
})();
</script>
</body>
</html>
