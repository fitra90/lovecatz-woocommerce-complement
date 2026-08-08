from pathlib import Path

root = Path(__file__).resolve().parent
includes = root / 'includes'
membership = root / 'membership'
shipping = root / 'shipping'

membership.mkdir(exist_ok=True)
shipping.mkdir(exist_ok=True)

admin_file = includes / 'class-lwc-admin-settings.php'
core_file = includes / 'class-lwc-core.php'
shipping_src = includes / 'class-lwc-shipping-jt.php'
shipping_dest = shipping / 'class-lwc-shipping-jt.php'
new_membership_file = membership / 'class-lwc-membership-admin.php'

text = admin_file.read_text(encoding='utf-8')
start_marker = 'public function handle_user_import()'
index = text.find(start_marker)
if index == -1:
    raise SystemExit('start marker not found')

# Find the class closing brace using brace counting from the start of the class.
class_start = text.find('class LWC_Admin_Settings')
if class_start == -1:
    raise SystemExit('class LWC_Admin_Settings not found')
brace_index = text.find('{', class_start)
if brace_index == -1:
    raise SystemExit('opening brace for class not found')

count = 1
pos = brace_index + 1
while pos < len(text) and count > 0:
    if text[pos] == '{':
        count += 1
    elif text[pos] == '}':
        count -= 1
    pos += 1

if count != 0:
    raise SystemExit('class closing brace not found')

end = pos
membership_code = text[index:end]
remaining = text[:index] + "\n    /**\n     * Membership methods moved to membership/class-lwc-membership-admin.php.\n     */\n    // Membership methods removed during refactor.\n}\n"

new_membership_content = """<?php
/**
 * Membership admin class for LoveCatz WooCommerce Complement.
 *
 * @package LoveCatzWC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LWC_Membership_Admin {

    /**
     * Initialize membership admin hooks.
     */
    public function init() {
        add_action( 'admin_init', array( $this, 'handle_user_import' ) );
        add_action( 'admin_post_lwc_print_member_card', array( $this, 'print_member_card' ) );
        add_action( 'admin_post_lwc_print_own_member_card', array( $this, 'print_own_member_card' ) );
        add_action( 'admin_post_lwc_delete_store_member', array( $this, 'delete_store_member' ) );
        add_action( 'admin_post_lwc_download_member_import_template', array( $this, 'download_member_import_template' ) );
        add_action( 'woocommerce_account_dashboard', array( $this, 'render_customer_member_card_button' ) );
    }

""" + membership_code + "\n"

new_membership_file.write_text(new_membership_content, encoding='utf-8')
admin_file.write_text(remaining, encoding='utf-8')

core_text = core_file.read_text(encoding='utf-8')
core_text = core_text.replace(
    "require_once LWC_PLUGIN_DIR . 'includes/class-lwc-admin-settings.php';\n        require_once LWC_PLUGIN_DIR . 'includes/class-lwc-shipping-jt.php';",
    "require_once LWC_PLUGIN_DIR . 'includes/class-lwc-admin-settings.php';\n        require_once LWC_PLUGIN_DIR . 'membership/class-lwc-membership-admin.php';\n        require_once LWC_PLUGIN_DIR . 'shipping/class-lwc-shipping-jt.php';"
)
core_file.write_text(core_text, encoding='utf-8')

if shipping_src.exists():
    shipping_dest.write_text(shipping_src.read_text(encoding='utf-8'), encoding='utf-8')
    shipping_src.unlink()

print('Done')
