<?php
/**
 * Cora for WooCommerce.
 *
 * @package   WC_Cora_Gateway
 * @author    Divox Tecnologia <suporte@divox.com.br>
 * @license   AGPL-3.0
 * @copyright 2020 Divox Tecnologia
 *
 * @wordpress-plugin
 * Plugin Name:       Boleto Cora para Woocommerce©
 * Plugin URI:        https://cora.divox.com.br/
 * Description:       Serviços financeiros poderosamente simples
 * Version:           1.1.8
 * Author:            Divox Tecnologia <suporte@divox.com.br>
 * Author URI:        https://www.divox.com.br/
 * Text Domain:       cora
 * License:           AGPL-3.0
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.txt
 * Domain Path:       /languages
 */

/**
 * WooCommerce is missing notice.
 *
 * @since  1.0.0
 *
 * @return string WooCommerce is missing notice.
 */
function wc_cora_woocommerce_is_missing() {
    echo '<div class="error"><p>' . sprintf(__('Boleto Cora para WooCommerce depende da última versão do %s para funcionar!', 'cora-woocommerce'), '<a href="http://wordpress.org/extend/plugins/woocommerce/">' . __('WooCommerce', 'cora-woocommerce') . '</a>') . '</p></div>';
}

/**
 * Initialize the Cora gateway.
 *
 * @since  1.0.0
 *
 * @return void
 */
function wc_cora_gateway_init() {

    

    // Checks with WooCommerce is installed.
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'wc_cora_woocommerce_is_missing');

        return;
    }

    /**
     * Load textdomain.
     */
    load_plugin_textdomain('cora-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    /**
     * Add the Cora gateway to WooCommerce.
     *
     * @param  array $methods WooCommerce payment methods.
     *
     * @return array          Payment methods with Cora.
     */
    function wc_cora_add_gateway($methods) {
        
        $methods[] = 'WC_Cora_Gateway';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'wc_cora_add_gateway');
    // Include the WC_Cora_Gateway class.
    require_once plugin_dir_path(__FILE__) . 'includes/CoraMessagePlugin.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-cora-gateway.php';
}

add_action('plugins_loaded', 'wc_cora_gateway_init', 0);

/**
 * Hides the Core with payment method with the customer lives outside Brazil.
 *
 * @param  array $available_gateways Default Available Gateways.
 *
 * @return array                     New Available Gateways.
 */
function wc_cora_hides_when_is_outside_brazil($available_gateways) {

    // Remove standard shipping option.
    if (isset($_REQUEST['country']) && 'BR' != $_REQUEST['country']) {
        unset($available_gateways['cora']);
    }

    return $available_gateways;
}

add_filter('woocommerce_available_payment_gateways', 'wc_cora_hides_when_is_outside_brazil');

/**
 * Display pending payment instructions in order details.
 *
 * @param  int $order_id Order ID.
 *
 * @return string        Message HTML.
 */
function wc_cora_pending_payment_instructions($order_id) {
    $order = new WC_Order($order_id);

    if ('on-hold' === $order->status && 'cora' == $order->payment_method) {
        $html = '<div class="woocommerce-info">';
        $html .= sprintf('<a class="button" href="%s" target="_blank">%s</a>', get_post_meta($order->id, 'cora_url', true), __('Imprimir Boleto Cora', 'cora-woocommerce'));

        $message = sprintf(__('%sAtenção!%s Ainda não foi registrado o pagamento do boleto Cora para este pedido.', 'cora-woocommerce'), '<strong>', '</strong>') . '<br />';
        $message .= __('Clique no botão para visualizar seu boleto Cora, vocÊ pode pagar pelo Internet Banking ou aplicativo.', 'cora-woocommerce') . '<br />';
        $message .= __('Você também pode imprimir e pagar em qualquer banco.', 'cora-woocommerce') . '<br />';
        $message .= __('Ignore este aviso se você já fez o pagamento, pode levar até 2 dias para seu boleto Cora ser compensado.', 'cora-woocommerce') . '<br />';

        $html .= apply_filters('woocommerce_cora_pending_payment_instructions', $message, $order);

        $html .= '</div>';

        echo $html;
    }
}

add_action('woocommerce_view_order', 'wc_cora_pending_payment_instructions');
