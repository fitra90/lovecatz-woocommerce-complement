<?php
/**
 * Text pipeline for everything J&T Cargo sends back.
 *
 * The Open Platform answers in Chinese whenever the upstream record was created
 * in Chinese. This class never edits the server payload: it reads a value and
 * returns a display-safe projection of it (original string kept alongside).
 *
 * Pipeline per value:
 *   1. emptiness check      -> null / '' / empty array means "no value"
 *   2. dictionary           -> known logistics phrases, translated to Indonesian
 *   3. glossary             -> known terms inside a longer sentence
 *   4. transliteration      -> Han -> Latin (ICU Transliterator, pinyin fallback)
 *   5. safe placeholder     -> rendered by the caller, never invented content
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWC_JTC_Text {

	/** Em dash used when a value is missing. It carries no invented meaning. */
	const PLACEHOLDER = '—';

	/**
	 * Does the string contain non-Latin script we must convert?
	 *
	 * Covers Han, Hiragana/Katakana, Hangul, and CJK/fullwidth punctuation.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function has_cjk( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		return (bool) preg_match( '/[\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}\x{3000}-\x{303F}]/u', $value );
	}

	/**
	 * Decide whether the server actually supplied a value.
	 *
	 * Only null, empty string and empty array count as "missing". The literal
	 * strings "0" and "0.0" are real values and are kept.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function has_value( $value ) {
		if ( null === $value ) {
			return false;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( is_bool( $value ) ) {
			return true;
		}
		if ( is_object( $value ) ) {
			return true;
		}
		return '' !== trim( (string) $value );
	}

	/**
	 * Convert a raw server value into a display-ready structure.
	 *
	 * @param mixed $value Raw value from the API response (never modified).
	 * @param array $args  {
	 *     Optional.
	 *     @type bool   $translate    Translate/transliterate non-Latin script. Default true.
	 *     @type bool   $placeholder  Return the safe placeholder when empty. Default false.
	 *     @type string $glue         Joiner for array values. Default ', '.
	 * }
	 * @return array {
	 *     @type string $text        Display text (Latin whenever possible).
	 *     @type string $original    Untouched server value as string, for auditing.
	 *     @type bool   $has_value   Whether the server supplied a value.
	 *     @type bool   $placeholder True when $text is the safe placeholder.
	 *     @type string $method      none|dictionary|glossary|transliterate|partial.
	 *     @type bool   $converted   True when script conversion happened.
	 * }
	 */
	public static function display( $value, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'translate'   => true,
				'placeholder' => false,
				'glue'        => ', ',
			)
		);

		$original = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		$empty    = array(
			'text'        => $args['placeholder'] ? self::PLACEHOLDER : '',
			'original'    => (string) $original,
			'has_value'   => false,
			'placeholder' => (bool) $args['placeholder'],
			'method'      => 'none',
			'converted'   => false,
		);

		if ( ! self::has_value( $value ) ) {
			return $empty;
		}

		// Arrays are rendered as a flat list; recursion stops at scalars.
		if ( is_array( $value ) ) {
			$parts   = array();
			$methods = array();
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					$item = wp_json_encode( $item );
				}
				$part = self::display(
					$item,
					array( 'translate' => $args['translate'], 'placeholder' => false, 'glue' => $args['glue'] )
				);
				if ( '' !== $part['text'] ) {
					$parts[]   = $part['text'];
					$methods[] = $part['method'];
				}
			}
			if ( empty( $parts ) ) {
				return $empty;
			}
			return array(
				'text'        => implode( $args['glue'], $parts ),
				'original'    => (string) $original,
				'has_value'   => true,
				'placeholder' => false,
				'method'      => self::strongest_method( $methods ),
				'converted'   => in_array( 'transliterate', $methods, true ) || in_array( 'glossary', $methods, true ) || in_array( 'dictionary', $methods, true ),
			);
		}

		$text = (string) $value;
		$text = self::strip_control_characters( $text );

		if ( ! $args['translate'] || ! self::has_cjk( $text ) ) {
			return array(
				'text'        => $text,
				'original'    => (string) $original,
				'has_value'   => true,
				'placeholder' => false,
				'method'      => 'none',
				'converted'   => false,
			);
		}

		$result = self::to_latin( $text );

		return array(
			'text'        => $result['text'],
			'original'    => (string) $original,
			'has_value'   => true,
			'placeholder' => false,
			'method'      => $result['method'],
			'converted'   => 'none' !== $result['method'],
		);
	}

	/** Shortcut: display text only, with an optional placeholder. */
	public static function text( $value, $placeholder = false ) {
		$out = self::display( $value, array( 'placeholder' => $placeholder ) );
		return $out['text'];
	}

	/**
	 * Translate / transliterate a Chinese string into readable Latin script.
	 *
	 * @param string $text Raw text.
	 * @return array { text: string, method: string }
	 */
	public static function to_latin( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) || ! self::has_cjk( $text ) ) {
			return array( 'text' => $text, 'method' => 'none' );
		}

		$dictionary = self::dictionary();

		// Exact phrase hit: highest quality translation, no guessing.
		$key = self::normalize_lookup( $text );
		if ( isset( $dictionary[ $key ] ) ) {
			return array( 'text' => $dictionary[ $key ], 'method' => 'dictionary' );
		}

		$out    = $text;
		$method = 'none';

		// Longest-first replacement so phrases win over single characters.
		foreach ( self::sorted_keys( $dictionary ) as $zh ) {
			if ( '' === $zh ) {
				continue;
			}
			if ( false !== strpos( $out, $zh ) ) {
				$out    = str_replace( $zh, ' ' . $dictionary[ $zh ] . ' ', $out );
				$method = 'glossary';
			}
		}

		$out = self::clean_punctuation( $out );

		if ( self::has_cjk( $out ) ) {
			$latin = self::transliterate( $out );
			if ( '' !== $latin ) {
				$out    = $latin;
				$method = 'none' === $method ? 'transliterate' : 'glossary';
			} else {
				$method = 'partial';
			}
		}

		return array( 'text' => self::tidy( $out ), 'method' => $method );
	}

	/** Han -> Latin. ICU first, bundled pinyin map as fallback. */
	private static function transliterate( $text ) {
		if ( class_exists( 'Transliterator' ) ) {
			$transliterator = @Transliterator::create( 'Han-Latin; Latin-ASCII; Lower' );
			if ( $transliterator ) {
				$out = @$transliterator->transliterate( $text );
				if ( is_string( $out ) && '' !== trim( $out ) ) {
					return self::sentence_case( $out );
				}
			}
		}
		return self::pinyin_fallback( $text );
	}

	/**
	 * Minimal pinyin map used only when the intl extension is unavailable.
	 *
	 * Characters that are not listed are preserved as-is and the caller marks
	 * the result as only partially converted.
	 */
	private static function pinyin_fallback( $text ) {
		$map   = self::pinyin_map();
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out   = '';
		if ( ! is_array( $chars ) ) {
			return '';
		}
		foreach ( $chars as $char ) {
			if ( isset( $map[ $char ] ) ) {
				$out .= ' ' . $map[ $char ] . ' ';
			} elseif ( self::has_cjk( $char ) ) {
				$out .= $char; // Unknown Han: preserve, do not invent a reading.
			} else {
				$out .= $char;
			}
		}
		return self::sentence_case( $out );
	}

	/** Replace CJK punctuation with Latin equivalents. */
	private static function clean_punctuation( $text ) {
		$pairs = array(
			'【' => ' ', '】' => ' ', '［' => ' ', '］' => ' ',
			'（' => ' (', '）' => ') ', '《' => ' ', '》' => ' ',
			'：' => ': ', '；' => '; ',
			'，' => ', ', '。' => '. ', '！' => '! ', '？' => '? ',
			'、' => ', ', '～' => '-', '“' => '"', '”' => '"',
		);
		return strtr( $text, $pairs );
	}

	/** Collapse repeated whitespace produced by term replacement. */
	private static function tidy( $text ) {
		$text = preg_replace( '/[ \t]{2,}/', ' ', (string) $text );
		$text = preg_replace( '/\s+([,.;:!?])/', '$1', (string) $text );
		$text = preg_replace( '/([(])\s+/', '$1', (string) $text );
		$text = preg_replace( '/\s+([)])/', '$1', (string) $text );
		return trim( (string) $text );
	}

	/** Capitalize the start of each sentence of a pinyin string. */
	private static function sentence_case( $text ) {
		$text = self::tidy( $text );
		if ( '' === $text ) {
			return '';
		}
		return preg_replace_callback(
			'/(^|[.!?]\s+|\:\s+)([a-z])/',
			function ( $matches ) {
				return $matches[1] . strtoupper( $matches[2] );
			},
			$text
		);
	}

	private static function strip_control_characters( $text ) {
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text );
	}

	private static function normalize_lookup( $text ) {
		return trim( (string) preg_replace( '/\s+/u', '', (string) $text ) );
	}

	private static function strongest_method( $methods ) {
		foreach ( array( 'partial', 'transliterate', 'glossary', 'dictionary' ) as $candidate ) {
			if ( in_array( $candidate, $methods, true ) ) {
				return $candidate;
			}
		}
		return 'none';
	}

	/** Dictionary keys, longest first, so phrases beat single characters. */
	private static function sorted_keys( $dictionary ) {
		$keys = array_keys( $dictionary );
		usort(
			$keys,
			function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
		return $keys;
	}

	/** Expand translation overrides supplied by integrators. */
	private static function dictionary() {
		$base = self::base_dictionary();
		$extra = (array) apply_filters( 'lwc_jtc_text_dictionary', array() );
		foreach ( $extra as $zh => $id ) {
			if ( is_string( $zh ) && '' !== $zh && is_string( $id ) ) {
				$base[ $zh ] = $id;
			}
		}
		return $base;
	}

	/**
	 * Chinese logistics vocabulary -> Indonesian.
	 *
	 * Phrases are matched first; leftover characters fall through to
	 * transliteration so city names stay readable (Shanghai Shi -> "Shanghai shi").
	 */
	private static function base_dictionary() {
		return array(
			// --- Tracking status phrases -------------------------------------
			'已签收'            => 'Telah diterima',
			'已揽收'            => 'Telah dijemput kurir',
			'已揽件'            => 'Telah dijemput kurir',
			'揽收成功'          => 'Penjemputan berhasil',
			'揽收失败'          => 'Penjemputan gagal',
			'待揽收'            => 'Menunggu penjemputan',
			'待发货'            => 'Menunggu pengiriman',
			'已发货'            => 'Telah dikirim',
			'运输中'            => 'Dalam perjalanan',
			'干线运输中'        => 'Dalam perjalanan antar-kota',
			'派送中'            => 'Sedang diantar',
			'派件中'            => 'Sedang diantar',
			'正在派送'          => 'Sedang diantar',
			'派送失败'          => 'Pengantaran gagal',
			'已到达'            => 'Telah tiba',
			'已离开'            => 'Telah berangkat',
			'已入库'            => 'Telah masuk gudang',
			'已出库'            => 'Telah keluar gudang',
			'已取消'            => 'Dibatalkan',
			'已退回'            => 'Dikembalikan',
			'退回中'            => 'Dalam proses pengembalian',
			'拒收'              => 'Ditolak penerima',
			'问题件'            => 'Paket bermasalah',
			'滞留'              => 'Tertahan',
			'超区'              => 'Di luar area layanan',
			'分拣中'            => 'Sedang disortir',
			'中转中'            => 'Sedang transit',
			'清关中'            => 'Dalam proses bea cukai',
			'清关完成'          => 'Bea cukai selesai',

			// --- Full sentences seen in J&T trace feeds -----------------------
			'快件已被'          => 'Paket telah dijemput di',
			'快件已到达'        => 'Paket telah tiba di',
			'快件已离开'        => 'Paket telah berangkat dari',
			'快件已发出'        => 'Paket telah dikirim dari',
			'请保持电话畅通'    => 'Mohon pastikan telepon dapat dihubungi',
			'请轻拿轻放'        => 'Mohon tangani dengan hati-hati',
			'包装已加固'        => 'Kemasan sudah diperkuat',
			'请放心签收'        => 'Silakan terima dengan aman',
			'避免阳光直射'      => 'hindari sinar matahari langsung',
			'客户拒收'          => 'Ditolak penerima',
			'联系不上收件人'    => 'Penerima tidak dapat dihubungi',
			'地址不详'          => 'Alamat tidak lengkap',
			'正在派送中'        => 'Sedang diantar',

			// --- Tracking vocabulary -----------------------------------------
			'快件'              => 'Paket',
			'已被'              => 'telah di',
			'标准快递'          => 'Reguler',
			'特惠件'            => 'Ekonomi',
			'次日达'            => 'Sampai besok',
			'签收人'            => 'Penerima',
			'收件人'            => 'Penerima',
			'寄件人'            => 'Pengirim',
			'派件员'            => 'Kurir',
			'快递员'            => 'Kurir',
			'业务员'            => 'Kurir',
			'本人'              => 'yang bersangkutan',
			'他人代收'          => 'diterima orang lain',
			'代收'              => 'diterima oleh',
			'扫描时间'          => 'Waktu pemindaian',
			'扫描类型'          => 'Jenis pemindaian',
			'扫描地点'          => 'Lokasi pemindaian',
			'时间'              => 'Waktu',
			'地点'              => 'Lokasi',
			'状态'              => 'Status',
			'描述'              => 'Keterangan',
			'备注'              => 'Catatan',
			'网点'              => 'Gerai',
			'中转部'            => 'Pusat transit',
			'分拨中心'          => 'Pusat distribusi',
			'转运中心'          => 'Pusat transfer',
			'集散中心'          => 'Pusat konsolidasi',
			'航空部'            => 'Unit udara',
			'装车'              => 'dimuat ke kendaraan',
			'卸车'              => 'diturunkan dari kendaraan',
			'分拣'              => 'disortir',
			'中转'              => 'transit',
			'出库'              => 'keluar gudang',
			'入库'              => 'masuk gudang',
			'到达'              => 'tiba di',
			'离开'              => 'berangkat dari',
			'发货'              => 'dikirim',
			'揽收'              => 'dijemput',
			'签收'              => 'diterima',
			'派送'              => 'diantar',
			'派件'              => 'diantar',
			'运输'              => 'dikirim',
			'退回'              => 'dikembalikan',

			// --- Money, weight, service --------------------------------------
			'运费'              => 'Ongkos kirim',
			'总费用'            => 'Total biaya',
			'费用'              => 'Biaya',
			'保价费'            => 'Biaya asuransi',
			'保价金额'          => 'Nilai asuransi',
			'保价'              => 'Asuransi',
			'代收货款'          => 'Cash on delivery (COD)',
			'到付'              => 'Bayar di tujuan',
			'寄付'              => 'Bayar di pengirim',
			'月结'              => 'Tagihan bulanan',
			'折扣'              => 'Diskon',
			'优惠'              => 'Potongan',
			'金额'              => 'Jumlah',
			'币种'              => 'Mata uang',
			'重量'              => 'Berat',
			'计费重量'          => 'Berat tagihan',
			'实际重量'          => 'Berat aktual',
			'体积重量'          => 'Berat volumetrik',
			'毛重'              => 'Berat kotor',
			'净重'              => 'Berat bersih',
			'公斤'              => 'kg',
			'千克'              => 'kg',
			'克'                => 'g',
			'件数'              => 'Jumlah koli',
			'件'                => 'koli',
			'包裹'              => 'Paket',
			'时效'              => 'Estimasi waktu',
			'预计到达时间'      => 'Perkiraan tiba',
			'预计到达'          => 'Perkiraan tiba',
			'预计'              => 'Perkiraan',
			'天'                => 'hari',
			'天数'              => 'hari',
			'小时'              => 'jam',
			'工作日'            => 'hari kerja',

			// --- Parties and addresses ---------------------------------------
			'收件地址'          => 'Alamat penerima',
			'寄件地址'          => 'Alamat pengirim',
			'地址'              => 'Alamat',
			'收件省'            => 'Provinsi penerima',
			'寄件省'            => 'Provinsi pengirim',
			'省'                => 'Provinsi',
			'省份'              => 'Provinsi',
			'市'                => 'Kota',
			'城市'              => 'Kota',
			'区'                => 'Kecamatan',
			'区县'              => 'Kecamatan',
			'县'                => 'Kabupaten',
			'镇'                => 'Kecamatan',
			'街道'              => 'Jalan',
			'联系人'            => 'Nama kontak',
			'联系电话'          => 'Telepon',
			'手机'              => 'Telepon',
			'电话'              => 'Telepon',
			'公司'              => 'Perusahaan',
			'姓名'              => 'Nama',
			'邮编'              => 'Kode pos',

			// --- Documents and codes -----------------------------------------
			'运单号'            => 'Nomor resi',
			'订单号'            => 'Nomor pesanan',
			'客户单号'          => 'Nomor referensi',
			'三段码'            => 'Kode tiga segmen',
			'大头笔'            => 'Kode tujuan',
			'集包'              => 'Konsolidasi',
			'子单号'            => 'Nomor resi turunan',
			'母单号'            => 'Nomor resi induk',
			'打印时间'          => 'Waktu cetak',
			'下单时间'          => 'Waktu pemesanan',
			'创建时间'          => 'Waktu dibuat',
			'更新时间'          => 'Waktu diperbarui',

			// --- Generic outcome words ---------------------------------------
			'成功'              => 'Berhasil',
			'失败'              => 'Gagal',
			'无'                => 'Tidak ada',
			'暂无'              => 'Belum tersedia',
			'无数据'            => 'Tidak ada data',
			'暂无数据'          => 'Belum ada data',
			'查询成功'          => 'Permintaan berhasil',
			'查询失败'          => 'Permintaan gagal',
			'参数错误'          => 'Parameter tidak valid',
			'签名错误'          => 'Tanda tangan tidak valid',
			'系统异常'          => 'Gangguan sistem',
			'运单不存在'        => 'Nomor resi tidak ditemukan',
		);
	}

	/** Compact pinyin table for environments without the intl extension. */
	private static function pinyin_map() {
		return array(
			'收' => 'shou', '寄' => 'ji', '件' => 'jian', '人' => 'ren', '已' => 'yi',
			'签' => 'qian', '派' => 'pai', '送' => 'song', '揽' => 'lan', '到' => 'dao',
			'达' => 'da', '离' => 'li', '开' => 'kai', '运' => 'yun', '输' => 'shu',
			'中' => 'zhong', '转' => 'zhuan', '分' => 'fen', '拣' => 'jian', '出' => 'chu',
			'入' => 'ru', '库' => 'ku', '退' => 'tui', '回' => 'hui', '问' => 'wen',
			'题' => 'ti', '滞' => 'zhi', '留' => 'liu', '取' => 'qu', '消' => 'xiao',
			'单' => 'dan', '号' => 'hao', '时' => 'shi', '间' => 'jian', '地' => 'di',
			'点' => 'dian', '址' => 'zhi', '省' => 'sheng', '市' => 'shi', '区' => 'qu',
			'县' => 'xian', '镇' => 'zhen', '街' => 'jie', '道' => 'dao', '路' => 'lu',
			'公' => 'gong', '司' => 'si', '名' => 'ming', '电' => 'dian', '话' => 'hua',
			'重' => 'zhong', '量' => 'liang', '费' => 'fei', '用' => 'yong', '金' => 'jin',
			'额' => 'e', '保' => 'bao', '价' => 'jia', '代' => 'dai', '付' => 'fu',
			'网' => 'wang', '包' => 'bao', '裹' => 'guo', '状' => 'zhuang', '态' => 'tai',
			'描' => 'miao', '述' => 'shu', '备' => 'bei', '注' => 'zhu', '成' => 'cheng',
			'功' => 'gong', '失' => 'shi', '败' => 'bai', '无' => 'wu', '的' => 'de',
			'在' => 'zai', '为' => 'wei', '是' => 'shi', '不' => 'bu', '有' => 'you',
			'上' => 'shang', '下' => 'xia', '海' => 'hai', '北' => 'bei', '京' => 'jing',
			'广' => 'guang', '州' => 'zhou', '深' => 'shen', '圳' => 'zhen', '杭' => 'hang',
			'江' => 'jiang', '苏' => 'su', '浙' => 'zhe', '东' => 'dong', '西' => 'xi',
			'南' => 'nan', '部' => 'bu', '心' => 'xin', '站' => 'zhan',
			'员' => 'yuan', '快' => 'kuai', '递' => 'di', '物' => 'wu', '流' => 'liu',
			'货' => 'huo', '款' => 'kuan', '装' => 'zhuang',
			'车' => 'che', '航' => 'hang', '空' => 'kong', '清' => 'qing', '关' => 'guan',
			'本' => 'ben', '他' => 'ta', '超' => 'chao', '范' => 'fan', '围' => 'wei',
		);
	}
}
