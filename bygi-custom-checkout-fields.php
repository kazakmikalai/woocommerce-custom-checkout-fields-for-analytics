<?php
/*
Plugin Name: BYGI Custom Checkout Fields for Analytics (Final)
Description: Добавляет новые поля в WooCommerce Checkout, Analytics и CSV.
Version: 2.0
Author: Mikalai Kazak
*/

if (!defined('ABSPATH')) exit;

// ------------------
// 0. Принудительное логирование
// ------------------
ini_set('log_errors', 1);
ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
error_log("BYGI DEBUG: плагин загружен v2.0");

// ------------------
// 1. Регистрируем кастомные поля
// ------------------
add_action('woocommerce_init', function () {
    error_log("BYGI DEBUG: woocommerce_init hook triggered");
    if (function_exists('woocommerce_register_additional_checkout_field')) {
        error_log("BYGI DEBUG: registering custom fields");
        
        woocommerce_register_additional_checkout_field([
            'id'       => 'custom/checkout_full_name',
            'label'    => 'ФИО',
            'location' => 'contact',
            'type'     => 'text',
            'required' => true,
        ]);

        woocommerce_register_additional_checkout_field([
            'id'       => 'custom/checkout_room_number',
            'label'    => 'Номер комнаты',
            'location' => 'contact',
            'type'     => 'text',
            'required' => true,
        ]);
        
        error_log("BYGI DEBUG: custom fields registered");
    }
});

// ------------------
// 2. Сохраняем мета заказа
// ------------------
add_action('woocommerce_checkout_update_order_meta', function($order_id) {
    error_log("BYGI DEBUG: woocommerce_checkout_update_order_meta triggered for order #$order_id");
    
    $full_name   = sanitize_text_field($_POST['custom/checkout_full_name'] ?? '');
    $room_number = sanitize_text_field($_POST['custom/checkout_room_number'] ?? '');

    error_log("BYGI DEBUG: POST data - full_name='$full_name', room_number='$room_number'");

    // Сохраняем с правильными ключами
    update_post_meta($order_id, '_wc_other/custom/checkout_full_name', $full_name);
    update_post_meta($order_id, '_wc_other/custom/checkout_room_number', $room_number);

    error_log("BYGI DEBUG Order #$order_id saved: full_name='$full_name', room_number='$room_number'");
}, 10, 1);

// ------------------
// 3. ДОБАВЛЯЕМ ПОЛЯ В АНАЛИТИКУ - ПРАВИЛЬНЫЙ ПОДХОД ИЗ ДОКУМЕНТАЦИИ
// ------------------

// SELECT clauses
function bygi_add_select_clauses($clauses) {
    error_log("BYGI DEBUG: bygi_add_select_clauses triggered");
    $clauses[] = ", full_name.meta_value AS custom_full_name";
    $clauses[] = ", room_number.meta_value AS custom_room_number";
    error_log("BYGI DEBUG: select clauses = " . print_r($clauses, true));
    return $clauses;
}

// JOIN clauses
function bygi_add_join_clauses($clauses) {
    error_log("BYGI DEBUG: bygi_add_join_clauses triggered");
    global $wpdb;
    
    // Проверим, используем ли мы HPOS или классические заказы
    if (method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled') && 
        Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        // HPOS
        $clauses[] = "LEFT JOIN {$wpdb->prefix}wc_orders_meta AS full_name ON {$wpdb->prefix}wc_order_stats.order_id = full_name.order_id AND full_name.meta_key = '_wc_other/custom/checkout_full_name'";
        $clauses[] = "LEFT JOIN {$wpdb->prefix}wc_orders_meta AS room_number ON {$wpdb->prefix}wc_order_stats.order_id = room_number.order_id AND room_number.meta_key = '_wc_other/custom/checkout_room_number'";
    } else {
        // Классические заказы
        $clauses[] = "LEFT JOIN {$wpdb->postmeta} AS full_name ON {$wpdb->prefix}wc_order_stats.order_id = full_name.post_id AND full_name.meta_key = '_wc_other/custom/checkout_full_name'";
        $clauses[] = "LEFT JOIN {$wpdb->postmeta} AS room_number ON {$wpdb->prefix}wc_order_stats.order_id = room_number.post_id AND room_number.meta_key = '_wc_other/custom/checkout_room_number'";
    }
    
    error_log("BYGI DEBUG: join clauses = " . print_r($clauses, true));
    return $clauses;
}

// Регистрируем хуки для всех типов запросов
$hooks = [
    'woocommerce_analytics_clauses_select_orders_subquery',
    'woocommerce_analytics_clauses_select_orders_stats_total',
    'woocommerce_analytics_clauses_select_orders_stats_interval',
    
    'woocommerce_analytics_clauses_join_orders_subquery',
    'woocommerce_analytics_clauses_join_orders_stats_total',
    'woocommerce_analytics_clauses_join_orders_stats_interval',
];

foreach ($hooks as $hook) {
    if (strpos($hook, 'select') !== false) {
        add_filter($hook, 'bygi_add_select_clauses');
    } else {
        add_filter($hook, 'bygi_add_join_clauses');
    }
}

// ------------------
// ------------------
// 4. Серверный CSV (ИСПРАВЛЕННЫЙ)
// ------------------
add_filter('woocommerce_filter_orders_export_columns', function($export_columns) {
    error_log("BYGI DEBUG: woocommerce_filter_orders_export_columns triggered");
    $export_columns['custom_full_name']   = 'ФИО';
    $export_columns['custom_room_number'] = 'Номер комнаты';
    return $export_columns;
});

add_filter('woocommerce_report_orders_prepare_export_item', function($export_item, $order) {
    error_log("BYGI DEBUG: woocommerce_report_orders_prepare_export_item triggered. Type of \$order: " . gettype($order));

    // --- ИСПРАВЛЕНИЕ: Обработка случая, когда $order может быть массивом ---
    $order_object = null;
    $order_id = null;

    if (is_a($order, 'WC_Order')) {
        // Если это уже объект WC_Order (старое поведение)
        $order_object = $order;
        $order_id = $order->get_id();
    } elseif (is_array($order) && isset($order['order_id'])) {
        // Если это массив с order_id (новое возможное поведение)
        $order_id = $order['order_id'];
        $order_object = wc_get_order($order_id);
        error_log("BYGI DEBUG: Converted array to WC_Order object for order ID: $order_id");
    } elseif (is_numeric($order)) {
        // Если передан только ID заказа (другая возможная вариация)
        $order_id = $order;
        $order_object = wc_get_order($order_id);
        error_log("BYGI DEBUG: Converted ID to WC_Order object for order ID: $order_id");
    }

    if (!$order_object || !is_a($order_object, 'WC_Order')) {
        error_log("BYGI DEBUG: Could not get WC_Order object. Skipping custom fields for this item.");
        return $export_item; // Возвращаем без изменений, если не удалось получить заказ
    }

    error_log("BYGI DEBUG: Processing order ID: " . $order_object->get_id());

    // Получаем мета-данные из объекта заказа
    $full_name   = $order_object->get_meta('_wc_other/custom/checkout_full_name');
    $room_number = $order_object->get_meta('_wc_other/custom/checkout_room_number');

    error_log("BYGI DEBUG Order #" . $order_object->get_id() . " CSV export: full_name='$full_name', room_number='$room_number'");

    $export_item['custom_full_name']   = $full_name;
    $export_item['custom_room_number'] = $room_number;

    return $export_item;
}, 10, 2); // Убедитесь, что приоритет 10 и принимаются 2 аргумента

// ------------------
// 5. JS для React Analytics
// ------------------
add_action('admin_enqueue_scripts', function($hook) {
    error_log("BYGI DEBUG: admin_enqueue_scripts triggered, hook=$hook");
    if (strpos($hook, 'wc-admin') === false && strpos($hook, 'woocommerce_page_wc-admin') === false) {
        return;
    }
    
    error_log("BYGI DEBUG: Enqueuing admin.js");
    wp_enqueue_script(
        'bygi-analytics-custom-fields',
        plugin_dir_url(__FILE__) . 'admin.js',
        ['wp-hooks'],
        '2.0',
        true
    );
});