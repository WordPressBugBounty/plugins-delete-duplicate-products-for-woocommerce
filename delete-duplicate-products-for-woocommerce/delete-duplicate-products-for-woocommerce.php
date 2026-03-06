<?php

/**
 * Plugin Name: Delete Duplicate Products for WooCommerce
 * Description: Find and manage duplicate products by title or SKU in WooCommerce. Features include bulk actions for deleting, moving to trash/draft, managing product images, action logging, and automatic 301 redirects.
 * Version: 1.4.0
 * Author: Luis Peel
 * License: GPL2
 * Text Domain: delete-duplicate-products-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 *
 * @package CPTSM2_Duplicate_Products
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'ddpfw_fs' ) ) {
    // Create a helper function for easy SDK access.
    function ddpfw_fs() {
        global $ddpfw_fs;
        if ( !isset( $ddpfw_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
            $ddpfw_fs = fs_dynamic_init( array(
                'id'               => '24612',
                'slug'             => 'delete-duplicate-products-for-woocommerce',
                'type'             => 'plugin',
                'public_key'       => 'pk_860a585810a05e4a8e731b39b1ede',
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'delete-duplicate-products-for-woocommerce',
                    'support' => false,
                ),
                'is_live'          => true,
            ) );
        }
        return $ddpfw_fs;
    }

    // Init Freemius.
    ddpfw_fs();
    // Signal that SDK was initiated.
    do_action( 'ddpfw_fs_loaded' );
}
// Prevent direct access.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
/**
 * Main class for handling duplicate products
 */
class CPTSM2_Duplicate_Products {
    /**
     * @var array Store error messages
     */
    private $error_messages = array();

    /**
     * @var string Plugin version
     */
    private $version = '1.4.0';

    /**
     * Add error message
     */
    private function add_error( $message ) {
        $this->error_messages[] = $message;
    }

    /**
     * Display error messages
     */
    private function display_errors() {
        if ( !empty( $this->error_messages ) ) {
            foreach ( $this->error_messages as $message ) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php 
                echo esc_html( $message );
                ?></p>
                </div>
                <?php 
            }
        }
    }

    /**
     * Check if the current user has an active Pro license
     */
    private function cptsm2_is_pro() {
        return function_exists( 'ddpfw_fs' ) && ddpfw_fs()->can_use_premium_code();
    }

    /**
     * Get the Freemius upgrade URL safely
     */
    private function cptsm2_get_upgrade_url() {
        return ( function_exists( 'ddpfw_fs' ) ? ddpfw_fs()->get_upgrade_url() : '#' );
    }

    /**
     * Free plan: maximum number of unique duplicate groups a user may process.
     *
     * @var int
     */
    private $free_groups_limit = 10;

    /**
     * Get the count of unique duplicate groups acted upon today on the free plan.
     * Resets automatically each new calendar day (UTC).
     *
     * @return int
     */
    private function cptsm2_get_free_groups_count() {
        $data = get_option( 'cptsm2_free_groups_processed', array() );
        $today = gmdate( 'Y-m-d' );
        if ( !is_array( $data ) || !isset( $data['date'] ) || $data['date'] !== $today ) {
            return 0;
        }
        return ( is_array( $data['identifiers'] ) ? count( $data['identifiers'] ) : 0 );
    }

    /**
     * Track which group identifiers were acted upon by free users today.
     * Counter resets each new calendar day (UTC).
     *
     * @param int[]  $product_ids Product IDs included in the bulk action.
     * @param string $group_by    'title' or 'sku'.
     */
    private function cptsm2_track_free_groups( $product_ids, $group_by ) {
        $data = get_option( 'cptsm2_free_groups_processed', array() );
        $today = gmdate( 'Y-m-d' );
        if ( !is_array( $data ) || !isset( $data['date'] ) || $data['date'] !== $today ) {
            $data = array(
                'date'        => $today,
                'identifiers' => array(),
            );
        }
        foreach ( $product_ids as $pid ) {
            $pid = absint( $pid );
            if ( $group_by === 'sku' ) {
                $product = wc_get_product( $pid );
                $identifier = ( $product ? $product->get_sku() : '' );
            } else {
                $identifier = get_the_title( $pid );
            }
            if ( !empty( $identifier ) && !in_array( $identifier, $data['identifiers'], true ) ) {
                $data['identifiers'][] = $identifier;
            }
        }
        update_option( 'cptsm2_free_groups_processed', $data );
    }

    /**
     * Initialize the plugin
     */
    public function __construct() {
        add_action( 'admin_menu', array($this, 'cptsm2_add_menu_page') );
        add_action( 'admin_enqueue_scripts', array($this, 'cptsm2_enqueue_admin_scripts') );
        // load_plugin_textdomain() not needed: WP 4.6+ auto-loads translations for wp.org plugins.
        // Action handlers
        add_action( 'admin_post_cptsm2_delete_products', array($this, 'cptsm2_handle_delete_products') );
        add_action( 'admin_post_cptsm2_export_csv', array($this, 'cptsm2_handle_export_csv') );
        // Cache invalidation
        add_action( 'save_post_product', array($this, 'cptsm2_clear_cache') );
        add_action( 'deleted_post', array($this, 'cptsm2_clear_cache') );
        add_action( 'woocommerce_save_product_variation', array($this, 'cptsm2_clear_cache') );
        // Initialize database tables
        add_action( 'init', array($this, 'cptsm2_init_database_tables') );
        // Handle 301 redirects
        add_action( 'template_redirect', array($this, 'cptsm2_handle_301_redirects') );
    }

    /**
     * Initialize database tables for logging and redirects
     */
    public function cptsm2_init_database_tables() {
        self::cptsm2_activate_plugin();
    }

    /**
     * Plugin activation hook - Initialize database tables
     */
    public static function cptsm2_activate_plugin() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        // Table for action logging
        $table_name_logs = $wpdb->prefix . 'cptsm2_action_logs';
        $sql_logs = "CREATE TABLE {$table_name_logs} (\n            id mediumint(9) NOT NULL AUTO_INCREMENT,\n            user_id bigint(20) NOT NULL,\n            action_type varchar(50) NOT NULL,\n            product_ids text NOT NULL,\n            action_details text,\n            created_at datetime DEFAULT CURRENT_TIMESTAMP,\n            PRIMARY KEY (id)\n        ) {$charset_collate};";
        // Table for 301 redirects
        $table_name_redirects = $wpdb->prefix . 'cptsm2_redirects';
        $sql_redirects = "CREATE TABLE {$table_name_redirects} (\n            id mediumint(9) NOT NULL AUTO_INCREMENT,\n            from_url varchar(500) NOT NULL,\n            to_url varchar(500) NOT NULL,\n            product_id bigint(20) NOT NULL,\n            created_at datetime DEFAULT CURRENT_TIMESTAMP,\n            PRIMARY KEY (id),\n            UNIQUE KEY from_url (from_url)\n        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_logs );
        dbDelta( $sql_redirects );
        // Set default options
        add_option( 'cptsm2_enable_301_redirects', false );
        add_option( 'cptsm2_redirect_type', 'product' );
        // Flush rewrite rules for 301 redirects
        flush_rewrite_rules();
    }

    /**
     * Log action to database
     */
    private function cptsm2_log_action( $action_type, $product_ids, $action_details = '' ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert( $wpdb->prefix . 'cptsm2_action_logs', array(
            'user_id'        => get_current_user_id(),
            'action_type'    => $action_type,
            'product_ids'    => implode( ',', $product_ids ),
            'action_details' => $action_details,
        ), array(
            '%d',
            '%s',
            '%s',
            '%s'
        ) );
    }

    /**
     * Create 301 redirect for deleted product
     */
    private function cptsm2_create_301_redirect( $from_url, $canonical_product_id ) {
        if ( !get_option( 'cptsm2_enable_301_redirects', false ) ) {
            return;
        }
        global $wpdb;
        $canonical_product = get_post( $canonical_product_id );
        if ( !$canonical_product ) {
            return;
        }
        $redirect_type = get_option( 'cptsm2_redirect_type', 'product' );
        $to_url = '';
        switch ( $redirect_type ) {
            case 'product':
                $to_url = get_permalink( $canonical_product_id );
                break;
            case 'category':
                $categories = get_the_terms( $canonical_product_id, 'product_cat' );
                if ( $categories && !is_wp_error( $categories ) ) {
                    $to_url = get_term_link( $categories[0] );
                } else {
                    $to_url = home_url();
                }
                break;
            case 'home':
                $to_url = home_url();
                break;
            default:
                $to_url = get_permalink( $canonical_product_id );
                break;
        }
        $from_path = wp_parse_url( $from_url, PHP_URL_PATH );
        if ( strlen( $from_path ) > 1 ) {
            $from_path = rtrim( $from_path, '/' );
        }
        $to_path = wp_parse_url( $to_url, PHP_URL_PATH );
        if ( strlen( $to_path ) > 1 ) {
            $to_path = rtrim( $to_path, '/' );
        }
        if ( $from_path && $to_url && $from_path !== $to_path ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->replace( $wpdb->prefix . 'cptsm2_redirects', array(
                'from_url'   => $from_path,
                'to_url'     => $to_url,
                'product_id' => $canonical_product_id,
            ), array('%s', '%s', '%d') );
        }
    }

    /**
     * Handle 301 redirects on frontend — Pro only
     */
    public function cptsm2_handle_301_redirects() {
        if ( is_admin() || !$this->cptsm2_is_pro() || !get_option( 'cptsm2_enable_301_redirects', false ) ) {
            return;
        }
        global $wpdb;
        $request_uri = ( isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '' );
        if ( empty( $request_uri ) ) {
            return;
        }
        $normalized_path = ( strlen( $request_uri ) > 1 ? rtrim( $request_uri, '/' ) : $request_uri );
        $host = ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );
        $full_current_url = '';
        if ( !empty( $host ) ) {
            $full_current_url = (( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' )) . '://' . $host . $request_uri;
        }
        $url_with_slash = '';
        $url_without_slash = '';
        if ( !empty( $full_current_url ) ) {
            $url_with_slash = rtrim( $full_current_url, '/' ) . '/';
            $url_without_slash = rtrim( $full_current_url, '/' );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $redirect = $wpdb->get_row( $wpdb->prepare(
            "SELECT to_url FROM " . $wpdb->prefix . "cptsm2_redirects WHERE from_url IN (%s, %s, %s)",
            $normalized_path,
            $url_with_slash,
            $url_without_slash
        ) );
        if ( $redirect && !empty( $redirect->to_url ) ) {
            $to_url_path = wp_parse_url( $redirect->to_url, PHP_URL_PATH );
            $normalized_to_path = ( strlen( $to_url_path ) > 1 ? rtrim( $to_url_path, '/' ) : $to_url_path );
            if ( $normalized_path !== $normalized_to_path ) {
                wp_safe_redirect( $redirect->to_url, 301 );
                exit;
            }
        }
    }

    /**
     * Load plugin textdomain
     * No-op: WP 4.6+ auto-loads translations for wp.org-hosted plugins.
     */
    public function cptsm2_load_textdomain() {
    }

    /**
     * Add menu page to WordPress admin
     */
    public function cptsm2_add_menu_page() {
        add_menu_page(
            esc_html__( 'Duplicate Products', 'delete-duplicate-products-for-woocommerce' ),
            esc_html__( 'Duplicate Products', 'delete-duplicate-products-for-woocommerce' ),
            'manage_woocommerce',
            'delete-duplicate-products-for-woocommerce',
            array($this, 'cptsm2_render_admin_page'),
            'dashicons-admin-generic',
            56
        );
        add_submenu_page(
            'delete-duplicate-products-for-woocommerce',
            esc_html__( 'Action Logs', 'delete-duplicate-products-for-woocommerce' ),
            esc_html__( 'Action Logs', 'delete-duplicate-products-for-woocommerce' ),
            'manage_woocommerce',
            'cptsm2-action-logs',
            array($this, 'cptsm2_render_action_logs_page')
        );
        add_submenu_page(
            'delete-duplicate-products-for-woocommerce',
            esc_html__( '301 Redirects', 'delete-duplicate-products-for-woocommerce' ),
            esc_html__( '301 Redirects', 'delete-duplicate-products-for-woocommerce' ),
            'manage_woocommerce',
            'cptsm2-301-redirects',
            array($this, 'cptsm2_render_301_redirects_page')
        );
    }

    /**
     * Get duplicate products with pagination
     *
     * @param array $args Filter arguments.
     * @return array Duplicate products and pagination data.
     */
    private function cptsm2_get_duplicate_products( $args = array() ) {
        $defaults = array(
            'group_by' => 'title',
            'paged'    => 1,
            'per_page' => 10,
            'status'   => 'publish',
            'category' => '',
        );
        $args = wp_parse_args( $args, $defaults );
        $duplicate_identifiers = $this->cptsm2_get_duplicate_identifiers( $args['group_by'], $args['status'], $args['category'] );
        if ( empty( $duplicate_identifiers ) ) {
            return array(
                'items'        => array(),
                'total_items'  => 0,
                'total_pages'  => 0,
                'current_page' => 1,
            );
        }
        $total_items = count( $duplicate_identifiers );
        $total_pages = ceil( $total_items / $args['per_page'] );
        $offset = ($args['paged'] - 1) * $args['per_page'];
        $paginated_identifiers = array_slice( $duplicate_identifiers, $offset, $args['per_page'] );
        $duplicate_groups = array();
        foreach ( $paginated_identifiers as $identifier ) {
            if ( $args['group_by'] === 'sku' ) {
                // BUG FIX: wc_get_products('sku') uses LIKE matching.
                // Use exact SQL to avoid false positives (e.g. SHIRT-001 matching SHIRT-001-M).
                $products = $this->cptsm2_get_products_by_sku_exact( $identifier, $args['status'] );
            } else {
                $status_arg = ( $args['status'] === 'all' ? array('publish', 'draft', 'trash') : $args['status'] );
                $products = wc_get_products( array(
                    'limit'   => -1,
                    'status'  => $status_arg,
                    'title'   => $identifier,
                    'orderby' => 'date',
                    'order'   => 'DESC',
                ) );
            }
            // Only include groups that still have more than 1 product
            if ( count( $products ) > 1 ) {
                $duplicate_groups[$identifier] = $products;
            }
        }
        return array(
            'items'        => $duplicate_groups,
            'total_items'  => $total_items,
            'total_pages'  => $total_pages,
            'current_page' => $args['paged'],
        );
    }

    /**
     * Get products by exact SKU using direct SQL.
     *
     * Replaces wc_get_products(['sku' => $sku]) which uses LIKE matching and
     * would incorrectly return SHIRT-001-M when searching for SHIRT-001.
     *
     * @param string       $sku    Exact SKU to search.
     * @param string|array $status Post status.
     * @return WC_Product[]
     */
    private function cptsm2_get_products_by_sku_exact( $sku, $status ) {
        global $wpdb;
        if ( $status === 'all' || is_array( $status ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $product_ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID\n                    FROM {$wpdb->posts} p\n                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id\n                    WHERE p.post_type = 'product'\n                    AND p.post_status IN ('publish', 'draft', 'trash')\n                    AND pm.meta_key = '_sku'\n                    AND pm.meta_value = %s\n                    ORDER BY p.post_date DESC", $sku ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $product_ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID\n                    FROM {$wpdb->posts} p\n                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id\n                    WHERE p.post_type = 'product'\n                    AND p.post_status = %s\n                    AND pm.meta_key = '_sku'\n                    AND pm.meta_value = %s\n                    ORDER BY p.post_date DESC", $status, $sku ) );
        }
        if ( empty( $product_ids ) ) {
            return array();
        }
        // Fetch product objects preserving the date-DESC order from SQL
        return wc_get_products( array(
            'include' => $product_ids,
            'limit'   => -1,
            'orderby' => 'include',
        ) );
    }

    /**
     * Handle product deletion / bulk actions
     */
    public function cptsm2_handle_delete_products() {
        if ( !current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions', 'delete-duplicate-products-for-woocommerce' ) );
        }
        $nonce = ( isset( $_POST['cptsm2_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cptsm2_nonce'] ) ) : '' );
        if ( !wp_verify_nonce( $nonce, 'cptsm2_delete_products' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'delete-duplicate-products-for-woocommerce' ) );
        }
        if ( isset( $_POST['products'] ) && is_array( $_POST['products'] ) ) {
            $selected_product_ids = array_map( 'absint', wp_unslash( $_POST['products'] ) );
            $product_action = ( isset( $_POST['product_action'] ) ? sanitize_text_field( wp_unslash( $_POST['product_action'] ) ) : '' );
            $image_action = ( isset( $_POST['image_action'] ) ? sanitize_text_field( wp_unslash( $_POST['image_action'] ) ) : '' );
            $current_status = ( isset( $_POST['current_status'] ) ? sanitize_text_field( wp_unslash( $_POST['current_status'] ) ) : 'publish' );
            $current_page = ( isset( $_POST['current_page'] ) ? absint( wp_unslash( $_POST['current_page'] ) ) : 1 );
            $per_page = ( isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 10 );
            $group_by = ( isset( $_POST['group_by'] ) ? sanitize_text_field( wp_unslash( $_POST['group_by'] ) ) : 'title' );
            $category = ( isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '' );
            $action_details = array();
            $deleted_product_data = array();
            // Free plan: enforce daily group limit (resets at UTC midnight) before processing any action.
            if ( !$this->cptsm2_is_pro() && $this->cptsm2_get_free_groups_count() >= $this->free_groups_limit ) {
                wp_safe_redirect( add_query_arg( array(
                    'page'             => 'delete-duplicate-products-for-woocommerce',
                    'status'           => $current_status,
                    'paged'            => $current_page,
                    'per_page'         => $per_page,
                    'group_by'         => $group_by,
                    'category'         => $category,
                    '_wpnonce'         => wp_create_nonce( 'cptsm2_filter_action' ),
                    'free_limit_error' => '1',
                ), admin_url( 'admin.php' ) ) );
                exit;
            }
            if ( $product_action === 'delete' && get_option( 'cptsm2_enable_301_redirects', false ) && $this->cptsm2_is_pro() ) {
                $all_products = wc_get_products( array(
                    'include' => $selected_product_ids,
                    'limit'   => -1,
                ) );
                foreach ( $all_products as $product ) {
                    $deleted_product_data[$product->get_id()] = array(
                        'url'              => get_permalink( $product->get_id() ),
                        'name'             => $product->get_name(),
                        'group_identifier' => ( $group_by === 'title' ? $product->get_name() : $product->get_sku() ),
                    );
                }
            }
            foreach ( $selected_product_ids as $product_id ) {
                if ( !empty( $image_action ) ) {
                    $this->cptsm2_delete_product_images( $product_id, $image_action );
                    $action_details[] = "Image action: {$image_action} for product ID: {$product_id}";
                }
                if ( !empty( $product_action ) ) {
                    switch ( $product_action ) {
                        case 'delete':
                            $product_name = ( isset( $deleted_product_data[$product_id] ) ? $deleted_product_data[$product_id]['name'] : "ID {$product_id}" );
                            wp_delete_post( $product_id, true );
                            $action_details[] = "Product permanently deleted: " . $product_name;
                            break;
                        case 'draft':
                            wp_update_post( array(
                                'ID'          => $product_id,
                                'post_status' => 'draft',
                            ) );
                            $action_details[] = "Product moved to draft: ID {$product_id}";
                            break;
                        case 'trash':
                            wp_trash_post( $product_id );
                            $action_details[] = "Product moved to trash: ID {$product_id}";
                            break;
                    }
                }
            }
            if ( !empty( $selected_product_ids ) ) {
                $this->cptsm2_log_action( ( $product_action ?: $image_action ), $selected_product_ids, implode( '; ', $action_details ) );
            }
            if ( !empty( $deleted_product_data ) ) {
                $grouped_deleted_products = array();
                foreach ( $deleted_product_data as $id => $data ) {
                    $grouped_deleted_products[$data['group_identifier']][] = $data;
                }
                foreach ( $grouped_deleted_products as $identifier => $deleted_group ) {
                    $query_args = array(
                        'limit'  => 1,
                        'status' => 'publish',
                    );
                    if ( $group_by === 'title' ) {
                        $query_args['title'] = $identifier;
                    } else {
                        $query_args['sku'] = $identifier;
                    }
                    $existing_products = wc_get_products( $query_args );
                    if ( !empty( $existing_products ) ) {
                        $canonical_product = $existing_products[0];
                        foreach ( $deleted_group as $deleted_product ) {
                            if ( !empty( $deleted_product['url'] ) ) {
                                $this->cptsm2_create_301_redirect( $deleted_product['url'], $canonical_product->get_id() );
                            }
                        }
                    }
                }
            }
            $this->cptsm2_clear_cache();
            // Free plan: track which groups were processed in this action.
            if ( !$this->cptsm2_is_pro() ) {
                $this->cptsm2_track_free_groups( $selected_product_ids, $group_by );
            }
            $redirect_args = array(
                'page'             => 'delete-duplicate-products-for-woocommerce',
                'status'           => $current_status,
                'paged'            => $current_page,
                'per_page'         => $per_page,
                'group_by'         => $group_by,
                'category'         => $category,
                '_wpnonce'         => wp_create_nonce( 'cptsm2_filter_action' ),
                'action_completed' => ( $product_action ?: $image_action ),
            );
            wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    /**
     * Encode a single CSV row as a string (RFC 4180).
     *
     * @param array $fields Row values.
     * @return string
     */
    private function cptsm2_csv_line( $fields ) {
        $escaped = array_map( function ( $field ) {
            return '"' . str_replace( '"', '""', (string) $field ) . '"';
        }, $fields );
        return implode( ',', $escaped ) . "\r\n";
    }

    /**
     * Handle CSV export — Pro feature
     */
    public function cptsm2_handle_export_csv() {
        if ( !current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions', 'delete-duplicate-products-for-woocommerce' ) );
        }
        $nonce = ( isset( $_POST['cptsm2_export_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cptsm2_export_nonce'] ) ) : '' );
        if ( !wp_verify_nonce( $nonce, 'cptsm2_export_csv' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'delete-duplicate-products-for-woocommerce' ) );
        }
        if ( !$this->cptsm2_is_pro() ) {
            wp_die( esc_html__( 'This feature requires a Pro license.', 'delete-duplicate-products-for-woocommerce' ) );
        }
        $group_by = ( isset( $_POST['group_by'] ) ? sanitize_text_field( wp_unslash( $_POST['group_by'] ) ) : 'title' );
        $status = ( isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'publish' );
        $category = ( isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '' );
        $results = $this->cptsm2_get_duplicate_products( array(
            'group_by' => $group_by,
            'paged'    => 1,
            'per_page' => 99999,
            'status'   => $status,
            'category' => $category,
        ) );
        $filename = 'duplicate-products-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        // BOM for UTF-8 Excel compatibility — raw bytes, not HTML context.
        $bom = chr( 0xef ) . chr( 0xbb ) . chr( 0xbf );
        echo $bom;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $csv_header = $this->cptsm2_csv_line( array(
            __( 'Group', 'delete-duplicate-products-for-woocommerce' ),
            __( 'Product ID', 'delete-duplicate-products-for-woocommerce' ),
            __( 'Title', 'delete-duplicate-products-for-woocommerce' ),
            __( 'SKU', 'delete-duplicate-products-for-woocommerce' ),
            __( 'Price', 'delete-duplicate-products-for-woocommerce' ),
            __( 'Status', 'delete-duplicate-products-for-woocommerce' ),
            __( 'Categories', 'delete-duplicate-products-for-woocommerce' ),
            __( 'Date Created', 'delete-duplicate-products-for-woocommerce' ),
            __( 'URL', 'delete-duplicate-products-for-woocommerce' )
        ) );
        echo $csv_header;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        foreach ( $results['items'] as $identifier => $products ) {
            foreach ( $products as $product ) {
                $categories = get_the_terms( $product->get_id(), 'product_cat' );
                $cat_names = ( $categories && !is_wp_error( $categories ) ? implode( ', ', wp_list_pluck( $categories, 'name' ) ) : '' );
                $csv_row = $this->cptsm2_csv_line( array(
                    $identifier,
                    $product->get_id(),
                    $product->get_name(),
                    $product->get_sku(),
                    $product->get_price(),
                    get_post_status( $product->get_id() ),
                    $cat_names,
                    get_the_date( 'Y-m-d H:i:s', $product->get_id() ),
                    get_permalink( $product->get_id() )
                ) );
                echo $csv_row;
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
        exit;
    }

    /**
     * Get and sanitize request parameters
     *
     * @return array Basic parameters.
     */
    private function cptsm2_get_request_params() {
        $nonce = ( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '' );
        if ( !wp_verify_nonce( $nonce, 'cptsm2_filter_action' ) ) {
            $status = ( isset( $_POST['current_status'] ) ? sanitize_text_field( wp_unslash( $_POST['current_status'] ) ) : 'publish' );
            return array(
                'group_by' => 'title',
                'paged'    => 1,
                'per_page' => 10,
                'status'   => $status,
                'category' => '',
            );
        }
        return array(
            'group_by' => sanitize_text_field( ( isset( $_GET['group_by'] ) ? wp_unslash( $_GET['group_by'] ) : 'title' ) ),
            'paged'    => max( 1, absint( ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) ) ),
            'per_page' => max( 5, min( 100, absint( ( isset( $_GET['per_page'] ) ? $_GET['per_page'] : 10 ) ) ) ),
            'status'   => sanitize_text_field( ( isset( $_GET['status'] ) ? wp_unslash( $_GET['status'] ) : 'publish' ) ),
            'category' => sanitize_text_field( ( isset( $_GET['category'] ) ? wp_unslash( $_GET['category'] ) : '' ) ),
        );
    }

    /**
     * Render the admin page
     */
    public function cptsm2_render_admin_page() {
        if ( !current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'delete-duplicate-products-for-woocommerce' ) );
        }
        $this->display_errors();
        // Success message after bulk action
        if ( isset( $_GET['action_completed'] ) ) {
            $nonce = ( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '' );
            if ( wp_verify_nonce( $nonce, 'cptsm2_filter_action' ) ) {
                $action_type = sanitize_text_field( wp_unslash( $_GET['action_completed'] ) );
                $message = '';
                switch ( $action_type ) {
                    case 'delete':
                        $message = __( 'Selected products have been permanently deleted.', 'delete-duplicate-products-for-woocommerce' );
                        break;
                    case 'draft':
                        $message = __( 'Selected products have been moved to draft status.', 'delete-duplicate-products-for-woocommerce' );
                        break;
                    case 'trash':
                        $message = __( 'Selected products have been moved to trash.', 'delete-duplicate-products-for-woocommerce' );
                        break;
                    case 'remove_featured':
                        $message = __( 'Featured images have been removed from selected products.', 'delete-duplicate-products-for-woocommerce' );
                        break;
                    case 'remove_gallery':
                        $message = __( 'Gallery images have been removed from selected products.', 'delete-duplicate-products-for-woocommerce' );
                        break;
                    case 'remove_all_images':
                        $message = __( 'All images have been removed from selected products.', 'delete-duplicate-products-for-woocommerce' );
                        break;
                }
                if ( $message ) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
                }
            }
        }
        $params = $this->cptsm2_get_request_params();
        $results = $this->cptsm2_get_duplicate_products( $params );
        $is_pro = $this->cptsm2_is_pro();
        $free_groups_used = ( !$is_pro ? $this->cptsm2_get_free_groups_count() : 0 );
        $free_limit = $this->free_groups_limit;
        $free_limit_reached = !$is_pro && $free_groups_used >= $free_limit;
        ?>
        <div class="wrap">
            <h1><?php 
        echo esc_html( get_admin_page_title() );
        ?></h1>

            <?php 
        if ( !$is_pro && isset( $_GET['free_limit_error'] ) ) {
            ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php 
            esc_html_e( 'Action blocked — free plan daily limit reached.', 'delete-duplicate-products-for-woocommerce' );
            ?></strong>
                        <?php 
            echo wp_kses_post( sprintf(
                /* translators: %1$d: daily limit, %2$s: opening link tag, %3$s: closing link tag */
                __( 'You have used all %1$d free group cleanups for today. The counter resets at midnight (UTC). %2$sUpgrade to Pro%3$s to continue without daily limits.', 'delete-duplicate-products-for-woocommerce' ),
                $free_limit,
                '<a href="' . esc_url( $this->cptsm2_get_upgrade_url() ) . '" target="_blank">',
                '</a>'
            ) );
            ?>
                    </p>
                </div>
            <?php 
        }
        ?>

            <!-- Filters -->
            <div class="cptsm2-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="delete-duplicate-products-for-woocommerce">
                    <?php 
        wp_nonce_field( 'cptsm2_filter_action' );
        ?>

                    <div class="cptsm2-filter-section">
                        <!-- Status filter -->
                        <label for="status-filter"><?php 
        esc_html_e( 'Status:', 'delete-duplicate-products-for-woocommerce' );
        ?></label>
                        <select name="status" id="status-filter" onchange="this.form.submit()">
                            <option value="all"     <?php 
        selected( $params['status'], 'all' );
        ?>><?php 
        esc_html_e( 'All Statuses', 'delete-duplicate-products-for-woocommerce' );
        ?></option>
                            <option value="publish" <?php 
        selected( $params['status'], 'publish' );
        ?>><?php 
        esc_html_e( 'Published', 'delete-duplicate-products-for-woocommerce' );
        ?></option>
                            <option value="draft"   <?php 
        selected( $params['status'], 'draft' );
        ?>><?php 
        esc_html_e( 'Draft', 'delete-duplicate-products-for-woocommerce' );
        ?></option>
                            <option value="trash"   <?php 
        selected( $params['status'], 'trash' );
        ?>><?php 
        esc_html_e( 'Trash', 'delete-duplicate-products-for-woocommerce' );
        ?></option>
                        </select>

                        <!-- Category filter (Pro) -->
                        <?php 
        if ( $is_pro ) {
            ?>
                            <?php 
            $all_categories = get_terms( array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ) );
            ?>
                            <label for="category-filter" style="margin-left:15px;"><?php 
            esc_html_e( 'Category:', 'delete-duplicate-products-for-woocommerce' );
            ?></label>
                            <select name="category" id="category-filter" onchange="this.form.submit()">
                                <option value=""><?php 
            esc_html_e( 'All Categories', 'delete-duplicate-products-for-woocommerce' );
            ?></option>
                                <?php 
            foreach ( $all_categories as $cat ) {
                ?>
                                    <option value="<?php 
                echo esc_attr( $cat->term_id );
                ?>" <?php 
                selected( $params['category'], $cat->term_id );
                ?>>
                                        <?php 
                echo esc_html( $cat->name );
                ?>
                                    </option>
                                <?php 
            }
            ?>
                            </select>
                        <?php 
        } else {
            ?>
                            <span style="margin-left:15px;opacity:0.65;" title="<?php 
            esc_attr_e( 'Available in Pro version', 'delete-duplicate-products-for-woocommerce' );
            ?>">
                                <span class="dashicons dashicons-lock" style="vertical-align:middle;font-size:16px;height:16px;width:16px;"></span>
                                <?php 
            esc_html_e( 'Category filter', 'delete-duplicate-products-for-woocommerce' );
            ?>
                                <a href="<?php 
            echo esc_url( $this->cptsm2_get_upgrade_url() );
            ?>" target="_blank" class="button button-small" style="margin-left:5px; margin-top: 5px;">
                                    <?php 
            esc_html_e( 'Upgrade to Pro', 'delete-duplicate-products-for-woocommerce' );
            ?>
                                </a>
                            </span>
                        <?php 
        }
        ?>

                        <!-- Preserve other params when status/category changes -->
                        <input type="hidden" name="group_by" value="<?php 
        echo esc_attr( $params['group_by'] );
        ?>">
                        <input type="hidden" name="per_page" value="<?php 
        echo esc_attr( $params['per_page'] );
        ?>">
                    </div>

                    <!-- Group by tabs -->
                    <div class="nav-tab-wrapper" style="margin-top:10px;">
                        <a href="<?php 
        echo esc_url( add_query_arg( array(
            'group_by' => 'title',
            'status'   => $params['status'],
            'category' => $params['category'],
            'per_page' => $params['per_page'],
            '_wpnonce' => wp_create_nonce( 'cptsm2_filter_action' ),
        ) ) );
        ?>" class="nav-tab <?php 
        echo ( $params['group_by'] === 'title' ? 'nav-tab-active' : '' );
        ?>">
                            <?php 
        esc_html_e( 'Group by Title', 'delete-duplicate-products-for-woocommerce' );
        ?>
                        </a>
                        <a href="<?php 
        echo esc_url( add_query_arg( array(
            'group_by' => 'sku',
            'status'   => $params['status'],
            'category' => $params['category'],
            'per_page' => $params['per_page'],
            '_wpnonce' => wp_create_nonce( 'cptsm2_filter_action' ),
        ) ) );
        ?>" class="nav-tab <?php 
        echo ( $params['group_by'] === 'sku' ? 'nav-tab-active' : '' );
        ?>">
                            <?php 
        esc_html_e( 'Group by SKU', 'delete-duplicate-products-for-woocommerce' );
        ?>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Total found + CSV Export -->
            <div class="cptsm2-total-products" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div>
                    <h2 style="margin:0 0 6px;">
                        <?php 
        echo esc_html( sprintf( 
            /* translators: %d: number of duplicate groups found */
            __( 'Found %d duplicate groups', 'delete-duplicate-products-for-woocommerce' ),
            $results['total_items']
         ) );
        ?>
                    </h2>
                    <?php 
        if ( !$is_pro ) {
            ?>
                        <p style="margin:0;font-size:13px;color:<?php 
            echo ( $free_limit_reached ? '#d63638' : '#555' );
            ?>;">
                            <?php 
            if ( $free_limit_reached ) {
                ?>
                                <span class="dashicons dashicons-lock" style="vertical-align:middle;font-size:14px;height:14px;width:14px;"></span>
                                <?php 
                echo wp_kses_post( sprintf(
                    /* translators: %1$d: groups used, %2$d: daily limit, %3$s: opening link, %4$s: closing link */
                    __( '<strong>Free plan daily limit reached</strong> (%1$d/%2$d group cleanups used today — resets at midnight). %3$sUpgrade to Pro%4$s to continue without daily limits.', 'delete-duplicate-products-for-woocommerce' ),
                    $free_groups_used,
                    $free_limit,
                    '<a href="' . esc_url( $this->cptsm2_get_upgrade_url() ) . '" target="_blank">',
                    '</a>'
                ) );
                ?>
                            <?php 
            } else {
                ?>
                                <?php 
                echo esc_html( sprintf(
                    /* translators: %1$d: groups used today, %2$d: daily limit, %3$d: remaining today */
                    __( 'Free plan: %1$d of %2$d group cleanups used today (%3$d remaining — resets at midnight).', 'delete-duplicate-products-for-woocommerce' ),
                    $free_groups_used,
                    $free_limit,
                    $free_limit - $free_groups_used
                ) );
                ?>
                            <?php 
            }
            ?>
                        </p>
                    <?php 
        }
        ?>
                </div>

                <?php 
        if ( $results['total_items'] > 0 ) {
            ?>
                    <?php 
            if ( $is_pro ) {
                ?>
                        <form method="post" action="<?php 
                echo esc_url( admin_url( 'admin-post.php' ) );
                ?>" style="margin:0;">
                            <input type="hidden" name="action"   value="cptsm2_export_csv">
                            <input type="hidden" name="group_by" value="<?php 
                echo esc_attr( $params['group_by'] );
                ?>">
                            <input type="hidden" name="status"   value="<?php 
                echo esc_attr( $params['status'] );
                ?>">
                            <input type="hidden" name="category" value="<?php 
                echo esc_attr( $params['category'] );
                ?>">
                            <?php 
                wp_nonce_field( 'cptsm2_export_csv', 'cptsm2_export_nonce' );
                ?>
                            <button type="submit" class="button button-secondary">
                                <span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:3px;line-height:14px;"></span>
                                <?php 
                esc_html_e( 'Export to CSV', 'delete-duplicate-products-for-woocommerce' );
                ?>
                            </button>
                        </form>
                    <?php 
            } else {
                ?>
                        <a href="<?php 
                echo esc_url( $this->cptsm2_get_upgrade_url() );
                ?>" target="_blank" class="button button-secondary" style="opacity:0.7;">
                            <span class="dashicons dashicons-lock" style="vertical-align:middle;margin-right:3px;line-height:14px;"></span>
                            <?php 
                esc_html_e( 'Export to CSV', 'delete-duplicate-products-for-woocommerce' );
                ?>
                            <span style="background:#f0a30a;color:#fff;font-size:10px;padding:1px 6px;border-radius:3px;margin-left:5px;font-weight:700;">PRO</span>
                        </a>
                    <?php 
            }
            ?>
                <?php 
        }
        ?>
            </div>

            <!-- Pagination top -->
            <?php 
        $this->cptsm2_render_pagination(
            $results['total_pages'],
            $results['current_page'],
            $params['per_page'],
            $params['status'],
            $params['group_by'],
            $params['category']
        );
        ?>

            <!-- Product list -->
            <?php 
        if ( !empty( $results['items'] ) ) {
            ?>
                <?php 
            if ( $free_limit_reached ) {
                ?>
                    <div class="notice notice-warning" style="margin-bottom:10px;">
                        <p>
                            <span class="dashicons dashicons-lock" style="vertical-align:middle;"></span>
                            <?php 
                echo wp_kses_post( sprintf(
                    /* translators: %1$d: groups used today, %2$d: daily limit, %3$s: opening link, %4$s: closing link */
                    __( '<strong>Free plan daily limit reached</strong> — %1$d of %2$d group cleanups used today. You can still view all duplicate groups below, but bulk actions are disabled until tomorrow (resets at midnight) or until you %3$supgrade to Pro%4$s.', 'delete-duplicate-products-for-woocommerce' ),
                    $free_groups_used,
                    $free_limit,
                    '<a href="' . esc_url( $this->cptsm2_get_upgrade_url() ) . '" target="_blank">',
                    '</a>'
                ) );
                ?>
                        </p>
                    </div>
                <?php 
            }
            ?>
                <form method="post" action="<?php 
            echo esc_url( admin_url( 'admin-post.php' ) );
            ?>" id="cptsm2-delete-form">
                    <input type="hidden" name="action"         value="cptsm2_delete_products">
                    <?php 
            wp_nonce_field( 'cptsm2_delete_products', 'cptsm2_nonce' );
            ?>
                    <input type="hidden" name="current_status" value="<?php 
            echo esc_attr( $params['status'] );
            ?>">
                    <input type="hidden" name="current_page"   value="<?php 
            echo esc_attr( $params['paged'] );
            ?>">
                    <input type="hidden" name="per_page"       value="<?php 
            echo esc_attr( $params['per_page'] );
            ?>">
                    <input type="hidden" name="group_by"       value="<?php 
            echo esc_attr( $params['group_by'] );
            ?>">
                    <input type="hidden" name="category"       value="<?php 
            echo esc_attr( $params['category'] );
            ?>">

                    <!-- 301 redirect status — outside tablenav to avoid WP height constraints -->
                    <?php 
            if ( $is_pro ) {
                ?>
                    <div style="margin-bottom:8px;padding:9px 12px;background:#f8fafd;border-left:4px solid #0073aa;border-radius:3px;color:#222;font-size:13px;">
                        <span class="dashicons dashicons-info" style="color:#0073aa;vertical-align:middle;"></span>
                        <?php 
                if ( get_option( 'cptsm2_enable_301_redirects', false ) ) {
                    $redirect_type = get_option( 'cptsm2_redirect_type', 'product' );
                    switch ( $redirect_type ) {
                        case 'product':
                            esc_html_e( '301 redirects enabled: deleted products will redirect to the canonical product.', 'delete-duplicate-products-for-woocommerce' );
                            break;
                        case 'category':
                            esc_html_e( '301 redirects enabled: deleted products will redirect to the product category.', 'delete-duplicate-products-for-woocommerce' );
                            break;
                        case 'home':
                            esc_html_e( '301 redirects enabled: deleted products will redirect to the homepage.', 'delete-duplicate-products-for-woocommerce' );
                            break;
                    }
                } else {
                    esc_html_e( '301 redirects are disabled. Go to 301 Redirects to enable them.', 'delete-duplicate-products-for-woocommerce' );
                }
                ?>
                    </div>
                    <?php 
            }
            ?>


                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <?php 
            if ( $free_limit_reached ) {
                ?>
                                <a href="<?php 
                echo esc_url( $this->cptsm2_get_upgrade_url() );
                ?>" target="_blank" class="button button-primary">
                                    <span class="dashicons dashicons-lock" style="vertical-align:middle;margin-right:3px;font-size:16px;height:16px;width:16px;margin-top:2px;"></span>
                                    <?php 
                esc_html_e( 'Upgrade to Pro to unlock actions', 'delete-duplicate-products-for-woocommerce' );
                ?>
                                </a>
                            <?php 
            } else {
                ?>
                                <select name="product_action">
                                    <option value=""><?php 
                esc_html_e( 'Product Actions', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                    <option value="delete"><?php 
                esc_html_e( 'Delete Permanently', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                    <option value="trash"><?php 
                esc_html_e( 'Move to Trash', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                    <option value="draft"><?php 
                esc_html_e( 'Move to Draft', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                </select>
                                <select name="image_action">
                                    <option value=""><?php 
                esc_html_e( 'Image Actions', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                    <option value="remove_featured"><?php 
                esc_html_e( 'Remove Featured Image', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                    <option value="remove_gallery"><?php 
                esc_html_e( 'Remove Gallery Images', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                    <option value="remove_all_images"><?php 
                esc_html_e( 'Remove All Images', 'delete-duplicate-products-for-woocommerce' );
                ?></option>
                                </select>
                                <input type="submit" class="button action" value="<?php 
                esc_attr_e( 'Apply', 'delete-duplicate-products-for-woocommerce' );
                ?>">
                            <?php 
            }
            ?>
                        </div>
                        <div class="alignright actions">
                            <button type="button" id="cptsm2-page-keep-newest" class="button">
                                <span class="dashicons dashicons-arrow-up-alt" style="vertical-align:middle;font-size:14px;height:14px;width:14px;margin-top:-4px;"></span>
                                <?php 
            esc_html_e( 'Select all — keep newest', 'delete-duplicate-products-for-woocommerce' );
            ?>
                            </button>
                            <button type="button" id="cptsm2-page-keep-oldest" class="button">
                                <span class="dashicons dashicons-arrow-down-alt" style="vertical-align:middle;font-size:14px;height:14px;width:14px;margin-top:-4px;"></span>
                                <?php 
            esc_html_e( 'Select all — keep oldest', 'delete-duplicate-products-for-woocommerce' );
            ?>
                            </button>
                        </div>
                        <br class="clear">
                    </div>

                    <?php 
            foreach ( $results['items'] as $identifier => $products ) {
                ?>
                        <div class="cptsm2-duplicate-group">
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                                <h3 style="margin:0;"><?php 
                echo esc_html( sprintf( 
                    /* translators: %1$s: type (Title/SKU), %2$s: identifier value */
                    __( 'Duplicate %1$s: %2$s', 'delete-duplicate-products-for-woocommerce' ),
                    ( $params['group_by'] === 'title' ? __( 'Title', 'delete-duplicate-products-for-woocommerce' ) : __( 'SKU', 'delete-duplicate-products-for-woocommerce' ) ),
                    $identifier
                 ) );
                ?></h3>

                                <!-- Keep Newest / Keep Oldest (Pro) -->
                                <?php 
                if ( $is_pro ) {
                    ?>
                                    <div>
                                        <button type="button" class="button button-small cptsm2-keep-newest">
                                            <span class="dashicons dashicons-arrow-up-alt" style="vertical-align:middle;font-size:14px;height:14px;width:14px;margin-top:-4px;"></span>
                                            <?php 
                    esc_html_e( 'Keep Newest', 'delete-duplicate-products-for-woocommerce' );
                    ?>
                                        </button>
                                        <button type="button" class="button button-small cptsm2-keep-oldest">
                                            <span class="dashicons dashicons-arrow-down-alt" style="vertical-align:middle;font-size:14px;height:14px;width:14px;margin-top:-4px;"></span>
                                            <?php 
                    esc_html_e( 'Keep Oldest', 'delete-duplicate-products-for-woocommerce' );
                    ?>
                                        </button>
                                    </div>
                                <?php 
                } else {
                    ?>
                                    <a href="<?php 
                    echo esc_url( $this->cptsm2_get_upgrade_url() );
                    ?>" target="_blank" class="button button-small" style="opacity:0.7;">
                                        <span class="dashicons dashicons-lock" style="vertical-align:middle;font-size:14px;height:14px;width:14px;margin-top:-4px;"></span>
                                        <?php 
                    esc_html_e( 'Keep Newest / Oldest', 'delete-duplicate-products-for-woocommerce' );
                    ?>
                                        <span style="background:#f0a30a;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:3px;font-weight:700;">PRO</span>
                                    </a>
                                <?php 
                }
                ?>
                            </div>

                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <td class="manage-column column-cb check-column">
                                            <label class="screen-reader-text" for="cb-select-all-<?php 
                echo esc_attr( $identifier );
                ?>">
                                                <?php 
                esc_html_e( 'Select All', 'delete-duplicate-products-for-woocommerce' );
                ?>
                                            </label>
                                            <input id="cb-select-all-<?php 
                echo esc_attr( $identifier );
                ?>"
                                                   type="checkbox"
                                                   class="cb-select-all"
                                                   <?php 
                echo ( $free_limit_reached ? 'disabled="disabled"' : '' );
                ?>>
                                        </td>
                                        <th><?php 
                esc_html_e( 'Image', 'delete-duplicate-products-for-woocommerce' );
                ?></th>
                                        <th><?php 
                esc_html_e( 'Title', 'delete-duplicate-products-for-woocommerce' );
                ?></th>
                                        <th><?php 
                esc_html_e( 'SKU', 'delete-duplicate-products-for-woocommerce' );
                ?></th>
                                        <th><?php 
                esc_html_e( 'Price', 'delete-duplicate-products-for-woocommerce' );
                ?></th>
                                        <th><?php 
                esc_html_e( 'Date', 'delete-duplicate-products-for-woocommerce' );
                ?></th>
                                        <th><?php 
                esc_html_e( 'Categories', 'delete-duplicate-products-for-woocommerce' );
                ?></th>
                                        <th><?php 
                esc_html_e( 'Actions', 'delete-duplicate-products-for-woocommerce' );
                ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                foreach ( $products as $product ) {
                    ?>
                                        <tr>
                                            <th scope="row" class="check-column">
                                                <label class="screen-reader-text" for="cb-select-<?php 
                    echo esc_attr( $product->get_id() );
                    ?>">
                                                    <?php 
                    /* translators: %s: product name */
                    printf( esc_html__( 'Select %s', 'delete-duplicate-products-for-woocommerce' ), esc_html( $product->get_name() ) );
                    ?>
                                                </label>
                                                <input id="cb-select-<?php 
                    echo esc_attr( $product->get_id() );
                    ?>"
                                                       type="checkbox"
                                                       name="products[]"
                                                       value="<?php 
                    echo esc_attr( $product->get_id() );
                    ?>"
                                                       <?php 
                    echo ( $free_limit_reached ? 'disabled="disabled"' : '' );
                    ?>>
                                            </th>
                                            <td><?php 
                    echo wp_kses_post( $product->get_image( array(50, 50) ) );
                    ?></td>
                                            <td><?php 
                    echo esc_html( $product->get_name() );
                    ?></td>
                                            <td><?php 
                    echo esc_html( $product->get_sku() );
                    ?></td>
                                            <td><?php 
                    echo wp_kses_post( wc_price( $product->get_price() ) );
                    ?></td>
                                            <td><?php 
                    echo esc_html( get_the_date( 'Y-m-d', $product->get_id() ) );
                    ?></td>
                                            <td>
                                                <?php 
                    $cats = get_the_terms( $product->get_id(), 'product_cat' );
                    if ( $cats && !is_wp_error( $cats ) ) {
                        echo esc_html( implode( ', ', wp_list_pluck( $cats, 'name' ) ) );
                    }
                    ?>
                                            </td>
                                            <td>
                                                <a href="<?php 
                    echo esc_url( get_edit_post_link( $product->get_id() ) );
                    ?>" class="button button-small">
                                                    <span class="dashicons dashicons-edit" style="margin-top:4px;"></span>
                                                    <?php 
                    esc_html_e( 'Edit', 'delete-duplicate-products-for-woocommerce' );
                    ?>
                                                </a>
                                                <a href="<?php 
                    echo esc_url( get_permalink( $product->get_id() ) );
                    ?>" target="_blank" class="button button-small">
                                                    <span class="dashicons dashicons-visibility" style="margin-top:4px;"></span>
                                                    <?php 
                    esc_html_e( 'View', 'delete-duplicate-products-for-woocommerce' );
                    ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php 
                }
                ?>
                                </tbody>
                            </table>
                        </div>
                    <?php 
            }
            ?>
                </form>
            <?php 
        } else {
            ?>
                <div style="padding:30px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;text-align:center;margin-top:20px;">
                    <span class="dashicons dashicons-yes-alt" style="font-size:48px;color:#46b450;height:48px;width:48px;display:block;margin:0 auto 10px;"></span>
                    <p style="font-size:15px;color:#555;margin:0;">
                        <?php 
            esc_html_e( 'No duplicate products found with the current filters.', 'delete-duplicate-products-for-woocommerce' );
            ?>
                    </p>
                </div>
            <?php 
        }
        ?>

            <!-- Pagination bottom -->
            <?php 
        $this->cptsm2_render_pagination(
            $results['total_pages'],
            $results['current_page'],
            $params['per_page'],
            $params['status'],
            $params['group_by'],
            $params['category']
        );
        ?>

            <?php 
        $this->cptsm2_render_support_section();
        ?>
        </div>
        <?php 
    }

    /**
     * Render the action logs page
     */
    public function cptsm2_render_action_logs_page() {
        if ( !current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'delete-duplicate-products-for-woocommerce' ) );
        }
        global $wpdb;
        $per_page = 20;
        $current_page = 1;
        $offset = 0;
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cptsm2_logs_pagination' ) ) {
            $current_page = ( isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1 );
            $offset = ($current_page - 1) * $per_page;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->prefix . "cptsm2_action_logs" );
        $total_pages = ceil( $total_items / $per_page );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "cptsm2_action_logs ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
        ?>
        <div class="wrap">
            <h1><?php 
        esc_html_e( 'Action Logs', 'delete-duplicate-products-for-woocommerce' );
        ?></h1>

            <?php 
        if ( !empty( $logs ) ) {
            ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php 
            esc_html_e( 'Date', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                            <th><?php 
            esc_html_e( 'User', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                            <th><?php 
            esc_html_e( 'Action', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                            <th><?php 
            esc_html_e( 'Products Affected', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                            <th><?php 
            esc_html_e( 'Details', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
            foreach ( $logs as $log ) {
                ?>
                            <tr>
                                <td><?php 
                echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->created_at ) ) );
                ?></td>
                                <td>
                                    <?php 
                $user = get_user_by( 'id', $log->user_id );
                echo ( $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown User', 'delete-duplicate-products-for-woocommerce' ) );
                ?>
                                </td>
                                <td><?php 
                echo esc_html( ucfirst( $log->action_type ) );
                ?></td>
                                <td>
                                    <?php 
                $product_ids = explode( ',', $log->product_ids );
                foreach ( $product_ids as $product_id ) {
                    $product = wc_get_product( $product_id );
                    if ( $product ) {
                        echo '<a href="' . esc_url( get_edit_post_link( $product_id ) ) . '">' . esc_html( $product->get_name() ) . '</a><br>';
                    } else {
                        echo '<span style="color:#999;">' . esc_html__( 'Product deleted', 'delete-duplicate-products-for-woocommerce' ) . '</span><br>';
                    }
                }
                ?>
                                </td>
                                <td><?php 
                echo esc_html( $log->action_details );
                ?></td>
                            </tr>
                        <?php 
            }
            ?>
                    </tbody>
                </table>

                <?php 
            if ( $total_pages > 1 ) {
                ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php 
                $pages = paginate_links( array(
                    'base'      => add_query_arg( array(
                        'paged'    => '%#%',
                        '_wpnonce' => wp_create_nonce( 'cptsm2_logs_pagination' ),
                    ), admin_url( 'admin.php?page=cptsm2-action-logs' ) ),
                    'format'    => '',
                    'prev_text' => esc_html__( '&laquo;', 'delete-duplicate-products-for-woocommerce' ),
                    'next_text' => esc_html__( '&raquo;', 'delete-duplicate-products-for-woocommerce' ),
                    'total'     => $total_pages,
                    'current'   => $current_page,
                ) );
                echo wp_kses_post( $pages );
                ?>
                        </div>
                    </div>
                <?php 
            }
            ?>
            <?php 
        } else {
            ?>
                <p><?php 
            esc_html_e( 'No action logs found.', 'delete-duplicate-products-for-woocommerce' );
            ?></p>
            <?php 
        }
        ?>

            <?php 
        $this->cptsm2_render_support_section();
        ?>
        </div>
        <?php 
    }

    /**
     * Render the 301 redirects page — Pro only
     */
    public function cptsm2_render_301_redirects_page() {
        if ( !current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'delete-duplicate-products-for-woocommerce' ) );
        }
        // Show upgrade prompt for free users
        if ( !$this->cptsm2_is_pro() ) {
            ?>
            <div class="wrap">
                <h1><?php 
            esc_html_e( '301 Redirects', 'delete-duplicate-products-for-woocommerce' );
            ?></h1>
                <div style="margin-top:30px;padding:40px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;text-align:center;max-width:600px;">
                    <span class="dashicons dashicons-redirect" style="font-size:48px;color:#0073aa;height:48px;width:48px;display:block;margin:0 auto 15px;"></span>
                    <h2 style="margin-top:0;"><?php 
            esc_html_e( '301 Redirects — Pro Feature', 'delete-duplicate-products-for-woocommerce' );
            ?></h2>
                    <p style="font-size:14px;color:#555;margin-bottom:20px;">
                        <?php 
            esc_html_e( 'Automatically create 301 redirects when you delete duplicate products, preserving your SEO rankings and preventing broken links.', 'delete-duplicate-products-for-woocommerce' );
            ?>
                    </p>
                    <ul style="text-align:left;display:inline-block;margin-bottom:25px;color:#444;">
                        <li>✅ <?php 
            esc_html_e( 'Redirect deleted products to the canonical product', 'delete-duplicate-products-for-woocommerce' );
            ?></li>
                        <li>✅ <?php 
            esc_html_e( 'Redirect to product category', 'delete-duplicate-products-for-woocommerce' );
            ?></li>
                        <li>✅ <?php 
            esc_html_e( 'Redirect to homepage', 'delete-duplicate-products-for-woocommerce' );
            ?></li>
                        <li>✅ <?php 
            esc_html_e( 'View and manage all created redirects', 'delete-duplicate-products-for-woocommerce' );
            ?></li>
                    </ul>
                    <br>
                    <a href="<?php 
            echo esc_url( $this->cptsm2_get_upgrade_url() );
            ?>" target="_blank" class="button button-primary" style="font-size:15px;height:40px;line-height:40px;padding:0 25px;">
                        <?php 
            esc_html_e( 'Upgrade to Pro', 'delete-duplicate-products-for-woocommerce' );
            ?>
                    </a>
                </div>
            </div>
            <?php 
            return;
        }
        global $wpdb;
        if ( isset( $_POST['submit'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cptsm2_301_settings' ) ) {
            $enable_redirects = ( isset( $_POST['enable_301_redirects'] ) ? true : false );
            $redirect_type = ( isset( $_POST['redirect_type'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_type'] ) ) : 'product' );
            update_option( 'cptsm2_enable_301_redirects', $enable_redirects );
            update_option( 'cptsm2_redirect_type', $redirect_type );
            if ( isset( $_POST['delete_redirect'] ) && is_array( $_POST['delete_redirect'] ) ) {
                $redirects_to_delete = array_map( 'absint', $_POST['delete_redirect'] );
                foreach ( $redirects_to_delete as $redirect_id ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->delete( $wpdb->prefix . 'cptsm2_redirects', array(
                        'id' => $redirect_id,
                    ), array('%d') );
                }
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings updated successfully.', 'delete-duplicate-products-for-woocommerce' ) . '</p></div>';
        }
        $per_page = 20;
        $current_page = 1;
        $offset = 0;
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cptsm2_redirects_pagination' ) ) {
            $current_page = ( isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1 );
            $offset = ($current_page - 1) * $per_page;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->prefix . "cptsm2_redirects" );
        $total_pages = ceil( $total_items / $per_page );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $redirects = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "cptsm2_redirects ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
        $enable_redirects = get_option( 'cptsm2_enable_301_redirects', false );
        $redirect_type = get_option( 'cptsm2_redirect_type', 'product' );
        ?>
        <div class="wrap">
            <h1><?php 
        esc_html_e( '301 Redirects', 'delete-duplicate-products-for-woocommerce' );
        ?></h1>

            <form method="post" action="">
                <?php 
        wp_nonce_field( 'cptsm2_301_settings' );
        ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php 
        esc_html_e( 'Enable 301 Redirects', 'delete-duplicate-products-for-woocommerce' );
        ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_301_redirects" value="1" <?php 
        checked( $enable_redirects );
        ?>>
                                <?php 
        esc_html_e( 'Automatically create 301 redirects when duplicate products are deleted', 'delete-duplicate-products-for-woocommerce' );
        ?>
                            </label>
                            <p class="description"><?php 
        esc_html_e( 'When enabled, deleted duplicate products will automatically redirect to the specified destination.', 'delete-duplicate-products-for-woocommerce' );
        ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php 
        esc_html_e( 'Redirect Destination', 'delete-duplicate-products-for-woocommerce' );
        ?></th>
                        <td>
                            <select name="redirect_type">
                                <option value="product"  <?php 
        selected( $redirect_type, 'product' );
        ?>><?php 
        esc_html_e( 'Canonical Product', 'delete-duplicate-products-for-woocommerce' );
        ?></option>
                                <option value="category" <?php 
        selected( $redirect_type, 'category' );
        ?>><?php 
        esc_html_e( 'Product Category', 'delete-duplicate-products-for-woocommerce' );
        ?></option>
                                <option value="home"     <?php 
        selected( $redirect_type, 'home' );
        ?>><?php 
        esc_html_e( 'Homepage', 'delete-duplicate-products-for-woocommerce' );
        ?></option>
                            </select>
                            <p class="description"><?php 
        esc_html_e( 'Choose where deleted duplicate products should redirect to.', 'delete-duplicate-products-for-woocommerce' );
        ?></p>
                        </td>
                    </tr>
                </table>
                <?php 
        submit_button();
        ?>
            </form>

            <h2><?php 
        esc_html_e( 'Existing Redirects', 'delete-duplicate-products-for-woocommerce' );
        ?></h2>

            <?php 
        if ( !empty( $redirects ) ) {
            ?>
                <form method="post" action="">
                    <?php 
            wp_nonce_field( 'cptsm2_301_settings' );
            ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="cb-select-all"></th>
                                <th><?php 
            esc_html_e( 'From URL', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                                <th><?php 
            esc_html_e( 'To URL', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                                <th><?php 
            esc_html_e( 'Product', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                                <th><?php 
            esc_html_e( 'Created', 'delete-duplicate-products-for-woocommerce' );
            ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
            foreach ( $redirects as $redirect ) {
                ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="delete_redirect[]" value="<?php 
                echo esc_attr( $redirect->id );
                ?>">
                                    </th>
                                    <td><?php 
                echo esc_url( $redirect->from_url );
                ?></td>
                                    <td><?php 
                echo esc_url( $redirect->to_url );
                ?></td>
                                    <td>
                                        <?php 
                $product = wc_get_product( $redirect->product_id );
                if ( $product ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $redirect->product_id ) ) . '">' . esc_html( $product->get_name() ) . '</a>';
                } else {
                    echo '<span style="color:#999;">' . esc_html__( 'Product not found', 'delete-duplicate-products-for-woocommerce' ) . '</span>';
                }
                ?>
                                    </td>
                                    <td><?php 
                echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $redirect->created_at ) ) );
                ?></td>
                                </tr>
                            <?php 
            }
            ?>
                        </tbody>
                    </table>
                    <p class="submit">
                        <input type="submit" name="submit" class="button button-primary" value="<?php 
            esc_attr_e( 'Delete Selected Redirects', 'delete-duplicate-products-for-woocommerce' );
            ?>">
                    </p>
                </form>

                <?php 
            if ( $total_pages > 1 ) {
                ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php 
                $pages = paginate_links( array(
                    'base'      => add_query_arg( array(
                        'paged'    => '%#%',
                        '_wpnonce' => wp_create_nonce( 'cptsm2_redirects_pagination' ),
                    ), admin_url( 'admin.php?page=cptsm2-301-redirects' ) ),
                    'format'    => '',
                    'prev_text' => esc_html__( '&laquo;', 'delete-duplicate-products-for-woocommerce' ),
                    'next_text' => esc_html__( '&raquo;', 'delete-duplicate-products-for-woocommerce' ),
                    'total'     => $total_pages,
                    'current'   => $current_page,
                ) );
                echo wp_kses_post( $pages );
                ?>
                        </div>
                    </div>
                <?php 
            }
            ?>
            <?php 
        } else {
            ?>
                <p><?php 
            esc_html_e( 'No 301 redirects found.', 'delete-duplicate-products-for-woocommerce' );
            ?></p>
            <?php 
        }
        ?>

            <?php 
        $this->cptsm2_render_support_section();
        ?>
        </div>
        <?php 
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook.
     */
    public function cptsm2_enqueue_admin_scripts( $hook ) {
        if ( !in_array( $hook, array('toplevel_page_delete-duplicate-products-for-woocommerce', 'duplicate-products_page_cptsm2-action-logs', 'duplicate-products_page_cptsm2-301-redirects') ) ) {
            return;
        }
        wp_enqueue_style(
            'cptsm2-admin-style',
            plugins_url( 'css/admin.css', __FILE__ ),
            array(),
            $this->version
        );
        wp_enqueue_script(
            'cptsm2-admin-script',
            plugins_url( 'js/admin.js', __FILE__ ),
            array('jquery'),
            $this->version,
            true
        );
        wp_localize_script( 'cptsm2-admin-script', 'cptsm2_vars', array(
            'confirm_delete'       => esc_html__( 'Are you sure you want to permanently delete the selected products? This cannot be undone.', 'delete-duplicate-products-for-woocommerce' ),
            'confirm_trash'        => esc_html__( 'Are you sure you want to move the selected products to trash?', 'delete-duplicate-products-for-woocommerce' ),
            'confirm_draft'        => esc_html__( 'Are you sure you want to move the selected products to draft?', 'delete-duplicate-products-for-woocommerce' ),
            'confirm_images'       => esc_html__( 'Are you sure you want to remove images from the selected products? This cannot be undone.', 'delete-duplicate-products-for-woocommerce' ),
            'no_products_selected' => esc_html__( 'Please select at least one product.', 'delete-duplicate-products-for-woocommerce' ),
            'select_action'        => esc_html__( 'Please select at least one action to perform.', 'delete-duplicate-products-for-woocommerce' ),
        ) );
    }

    /**
     * Get duplicate identifiers with caching.
     * Supports optional category filter (Pro).
     *
     * @param string $group_by  'title' or 'sku'.
     * @param string $status    Post status or 'all'.
     * @param string $category  Term ID for product_cat, or '' for all.
     * @return array Array of duplicate identifiers.
     */
    private function cptsm2_get_duplicate_identifiers( $group_by, $status = 'publish', $category = '' ) {
        global $wpdb;
        // Only cache when no category filter is active
        $use_cache = empty( $category );
        $cache_key = 'cptsm2_duplicate_' . $group_by . '_' . $status . '_identifiers';
        if ( $use_cache ) {
            $cached = wp_cache_get( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }
        $duplicate_identifiers = array();
        if ( $group_by === 'title' ) {
            if ( !empty( $category ) ) {
                // Title + category filter
                if ( $status === 'all' ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare( "SELECT p.post_title\n                            FROM {$wpdb->posts} p\n                            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id\n                            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id\n                            WHERE p.post_type = %s\n                            AND p.post_status IN ('publish', 'draft', 'trash')\n                            AND tt.taxonomy = 'product_cat'\n                            AND tt.term_id = %d\n                            GROUP BY p.post_title\n                            HAVING COUNT(*) > 1", 'product', absint( $category ) ) );
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare(
                        "SELECT p.post_title\n                            FROM {$wpdb->posts} p\n                            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id\n                            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id\n                            WHERE p.post_type = %s\n                            AND p.post_status = %s\n                            AND tt.taxonomy = 'product_cat'\n                            AND tt.term_id = %d\n                            GROUP BY p.post_title\n                            HAVING COUNT(*) > 1",
                        'product',
                        $status,
                        absint( $category )
                    ) );
                }
            } else {
                // Title without category filter
                if ( $status === 'all' ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare( "SELECT p.post_title\n                            FROM {$wpdb->posts} p\n                            WHERE p.post_type = %s\n                            AND p.post_status IN ('publish', 'draft', 'trash')\n                            GROUP BY p.post_title\n                            HAVING COUNT(*) > 1", 'product' ) );
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare( "SELECT p.post_title\n                            FROM {$wpdb->posts} p\n                            WHERE p.post_type = %s\n                            AND p.post_status = %s\n                            GROUP BY p.post_title\n                            HAVING COUNT(*) > 1", 'product', $status ) );
                }
            }
        } else {
            // group_by === 'sku'
            if ( !empty( $category ) ) {
                // SKU + category filter
                if ( $status === 'all' ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare(
                        "SELECT pm.meta_value\n                            FROM {$wpdb->posts} p\n                            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id\n                            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id\n                            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id\n                            WHERE p.post_type = %s\n                            AND p.post_status IN ('publish', 'draft', 'trash')\n                            AND pm.meta_key = %s\n                            AND pm.meta_value != ''\n                            AND tt.taxonomy = 'product_cat'\n                            AND tt.term_id = %d\n                            GROUP BY pm.meta_value\n                            HAVING COUNT(*) > 1",
                        'product',
                        '_sku',
                        absint( $category )
                    ) );
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare(
                        "SELECT pm.meta_value\n                            FROM {$wpdb->posts} p\n                            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id\n                            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id\n                            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id\n                            WHERE p.post_type = %s\n                            AND p.post_status = %s\n                            AND pm.meta_key = %s\n                            AND pm.meta_value != ''\n                            AND tt.taxonomy = 'product_cat'\n                            AND tt.term_id = %d\n                            GROUP BY pm.meta_value\n                            HAVING COUNT(*) > 1",
                        'product',
                        $status,
                        '_sku',
                        absint( $category )
                    ) );
                }
            } else {
                // SKU without category filter
                if ( $status === 'all' ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_value\n                            FROM {$wpdb->posts} p\n                            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id\n                            WHERE p.post_type = %s\n                            AND p.post_status IN ('publish', 'draft', 'trash')\n                            AND pm.meta_key = %s\n                            AND pm.meta_value != ''\n                            GROUP BY pm.meta_value\n                            HAVING COUNT(*) > 1", 'product', '_sku' ) );
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $duplicate_identifiers = $wpdb->get_col( $wpdb->prepare(
                        "SELECT pm.meta_value\n                            FROM {$wpdb->posts} p\n                            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id\n                            WHERE p.post_type = %s\n                            AND p.post_status = %s\n                            AND pm.meta_key = %s\n                            AND pm.meta_value != ''\n                            GROUP BY pm.meta_value\n                            HAVING COUNT(*) > 1",
                        'product',
                        $status,
                        '_sku'
                    ) );
                }
            }
        }
        if ( $use_cache ) {
            wp_cache_set(
                $cache_key,
                $duplicate_identifiers,
                '',
                HOUR_IN_SECONDS
            );
        }
        return $duplicate_identifiers;
    }

    /**
     * Clear duplicate products cache
     */
    public function cptsm2_clear_cache() {
        $statuses = array(
            'publish',
            'draft',
            'trash',
            'all'
        );
        foreach ( $statuses as $status ) {
            wp_cache_delete( 'cptsm2_duplicate_title_' . $status . '_identifiers' );
            wp_cache_delete( 'cptsm2_duplicate_sku_' . $status . '_identifiers' );
        }
    }

    /**
     * Render pagination controls
     *
     * @param int    $total_pages  Total number of pages.
     * @param int    $current_page Current page number.
     * @param int    $per_page     Number of items per page.
     * @param string $status       Current status filter.
     * @param string $group_by     Current group_by value.
     * @param string $category     Current category filter.
     */
    private function cptsm2_render_pagination(
        $total_pages,
        $current_page,
        $per_page,
        $status = 'publish',
        $group_by = 'title',
        $category = ''
    ) {
        if ( $total_pages <= 1 ) {
            return;
        }
        $base_url = add_query_arg( array(
            'page'     => 'delete-duplicate-products-for-woocommerce',
            'status'   => $status,
            'group_by' => $group_by,
            'category' => $category,
            '_wpnonce' => wp_create_nonce( 'cptsm2_filter_action' ),
        ), admin_url( 'admin.php' ) );
        $pagination_args = array(
            'base'      => add_query_arg( 'paged', '%#%', $base_url ),
            'format'    => '',
            'current'   => $current_page,
            'total'     => $total_pages,
            'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'delete-duplicate-products-for-woocommerce' ),
            'next_text' => esc_html__( 'Next', 'delete-duplicate-products-for-woocommerce' ) . ' &raquo;',
            'type'      => 'array',
            'end_size'  => 2,
            'mid_size'  => 2,
        );
        $pages = paginate_links( $pagination_args );
        if ( is_array( $pages ) ) {
            echo '<div class="cptsm2-pagination">';
            echo '<div class="cptsm2-pagination-links">';
            foreach ( $pages as $page ) {
                echo wp_kses_post( $page );
            }
            echo '</div>';
            // Per page selector — preserves all current params
            echo '<div class="cptsm2-per-page-selector">';
            echo '<form method="get" action="">';
            echo '<input type="hidden" name="page"     value="delete-duplicate-products-for-woocommerce">';
            echo '<input type="hidden" name="group_by" value="' . esc_attr( $group_by ) . '">';
            echo '<input type="hidden" name="status"   value="' . esc_attr( $status ) . '">';
            echo '<input type="hidden" name="category" value="' . esc_attr( $category ) . '">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'cptsm2_filter_action' ) ) . '">';
            echo '<select name="per_page" onchange="this.form.submit()">';
            foreach ( array(
                5,
                10,
                25,
                50,
                100
            ) as $option ) {
                printf(
                    '<option value="%d" %s>%s</option>',
                    esc_attr( $option ),
                    selected( $per_page, $option, false ),
                    esc_html( sprintf( 
                        /* translators: %d: number of items per page */
                        __( '%d per page', 'delete-duplicate-products-for-woocommerce' ),
                        $option
                     ) )
                );
            }
            echo '</select>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        }
    }

    /**
     * Delete a single image attachment
     */
    private function cptsm2_delete_single_image( $image_id, $product_id ) {
        if ( empty( $image_id ) || !wp_attachment_is_image( $image_id ) ) {
            return true;
        }
        $result = wp_delete_attachment( $image_id, true );
        if ( is_wp_error( $result ) ) {
            $this->add_error( sprintf( 
                /* translators: 1: image ID, 2: product ID */
                __( 'Failed to delete image (ID: %1$d) from product %2$d.', 'delete-duplicate-products-for-woocommerce' ),
                absint( $image_id ),
                absint( $product_id )
             ) );
            return false;
        }
        return true;
    }

    /**
     * Delete all gallery images from a product
     */
    private function cptsm2_delete_gallery_images( $product ) {
        $gallery_ids = $product->get_gallery_image_ids();
        $success = true;
        foreach ( $gallery_ids as $image_id ) {
            if ( !$this->cptsm2_delete_single_image( $image_id, $product->get_id() ) ) {
                $success = false;
            }
        }
        update_post_meta( $product->get_id(), '_product_image_gallery', '' );
        return $success;
    }

    /**
     * Delete featured image from a product
     */
    private function cptsm2_delete_featured_image( $product ) {
        $featured_id = $product->get_image_id();
        if ( !empty( $featured_id ) ) {
            delete_post_thumbnail( $product->get_id() );
            return $this->cptsm2_delete_single_image( $featured_id, $product->get_id() );
        }
        return true;
    }

    /**
     * Delete product images based on action type
     */
    private function cptsm2_delete_product_images( $product_id, $image_action ) {
        $product = wc_get_product( $product_id );
        if ( !$product ) {
            $this->add_error( sprintf( 
                /* translators: %d: product ID */
                __( 'Product not found. ID: %d', 'delete-duplicate-products-for-woocommerce' ),
                absint( $product_id )
             ) );
            return false;
        }
        switch ( $image_action ) {
            case 'remove_featured':
                return $this->cptsm2_delete_featured_image( $product );
            case 'remove_gallery':
                return $this->cptsm2_delete_gallery_images( $product );
            case 'remove_all_images':
                $featured_success = $this->cptsm2_delete_featured_image( $product );
                $gallery_success = $this->cptsm2_delete_gallery_images( $product );
                return $featured_success && $gallery_success;
            default:
                $this->add_error( sprintf( 
                    /* translators: %s: action type */
                    __( 'Invalid image action requested: %s', 'delete-duplicate-products-for-woocommerce' ),
                    esc_html( $image_action )
                 ) );
                return false;
        }
    }

    /**
     * Render the bottom section: Pro CTA for free users, or rate/support for Pro users
     */
    private function cptsm2_render_support_section() {
        $is_pro = $this->cptsm2_is_pro();
        ?>
        <div class="cptsm2-support-section" style="margin-top:40px;padding:25px;background:#fff;border-radius:6px;border:1px solid #ccd0d4;box-shadow:0 1px 3px rgba(0,0,0,.05);">

            <?php 
        if ( !$is_pro ) {
            ?>
                <!-- Pro upgrade CTA for free users -->
                <div style="display:flex;flex-wrap:wrap;gap:30px;align-items:center;">
                    <div style="flex:2;min-width:280px;">
                        <h3 style="margin-top:0;color:#0073aa;">
                            ⚡ <?php 
            esc_html_e( 'Unlock the full power — Upgrade to Pro', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </h3>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 20px;margin-bottom:20px;font-size:13px;color:#444;">
                            <span>✅ <?php 
            esc_html_e( 'Unlimited bulk actions', 'delete-duplicate-products-for-woocommerce' );
            ?></span>
                            <span>✅ <?php 
            esc_html_e( 'Filter by category', 'delete-duplicate-products-for-woocommerce' );
            ?></span>
                            <span>✅ <?php 
            esc_html_e( '301 automatic redirects', 'delete-duplicate-products-for-woocommerce' );
            ?></span>
                            <span>✅ <?php 
            esc_html_e( 'Keep Newest / Keep Oldest', 'delete-duplicate-products-for-woocommerce' );
            ?></span>
                            <span>✅ <?php 
            esc_html_e( 'Export to CSV', 'delete-duplicate-products-for-woocommerce' );
            ?></span>
                            <span>✅ <?php 
            esc_html_e( 'Priority support', 'delete-duplicate-products-for-woocommerce' );
            ?></span>
                        </div>
                        <a href="<?php 
            echo esc_url( $this->cptsm2_get_upgrade_url() );
            ?>" target="_blank"
                           class="button button-primary"
                           style="font-size:14px;height:38px;line-height:38px;padding:0 22px;">
                            <?php 
            esc_html_e( 'Upgrade to Pro →', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </a>
                        <a href="https://wordpress.org/support/plugin/delete-duplicate-products-for-woocommerce/reviews/"
                           target="_blank"
                           class="button button-secondary"
                           style="margin-left:10px;">
                            <span class="dashicons dashicons-star-filled" style="color:#ffb900;vertical-align:middle;margin-right:3px;font-size:16px;height:16px;width:16px;margin-top:6px;"></span>
                            <?php 
            esc_html_e( 'Rate the Plugin', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </a>
                    </div>
                    <div style="flex:1;min-width:180px;text-align:center;padding:20px;background:#f8fafd;border-radius:6px;border:1px solid #d0e4f7;">
                        <p style="font-size:28px;font-weight:700;color:#0073aa;margin:0 0 4px;">$19</p>
                        <p style="margin:0;color:#666;font-size:12px;"><?php 
            esc_html_e( 'per year / 1 site', 'delete-duplicate-products-for-woocommerce' );
            ?></p>
                        <p style="margin:8px 0 0;color:#555;font-size:12px;">
                            <?php 
            esc_html_e( '5 sites: $59 · Unlimited: $99', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </p>
                    </div>
                </div>
            <?php 
        } else {
            ?>
                <!-- For Pro users: just rate + support -->
                <div style="display:flex;flex-wrap:wrap;gap:20px;">
                    <div style="flex:1;min-width:250px;">
                        <h4 style="margin-top:0;color:#0073aa;">
                            <span class="dashicons dashicons-star-filled" style="color:#ffb900;"></span>
                            <?php 
            esc_html_e( 'Enjoying the Pro version?', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </h4>
                        <p><?php 
            esc_html_e( 'A review on WordPress.org helps other users discover the plugin and supports its development.', 'delete-duplicate-products-for-woocommerce' );
            ?></p>
                        <a href="https://wordpress.org/support/plugin/delete-duplicate-products-for-woocommerce/reviews/" target="_blank" class="button button-primary">
                            <span class="dashicons dashicons-external"></span>
                            <?php 
            esc_html_e( 'Leave a Review', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </a>
                    </div>
                    <div style="flex:1;min-width:250px;">
                        <h4 style="margin-top:0;color:#0073aa;">
                            <span class="dashicons dashicons-sos" style="color:#00a32a;"></span>
                            <?php 
            esc_html_e( 'Need help?', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </h4>
                        <p><?php 
            esc_html_e( 'As a Pro user you have access to priority support. We are here to help you.', 'delete-duplicate-products-for-woocommerce' );
            ?></p>
                        <a href="https://wordpress.org/support/plugin/delete-duplicate-products-for-woocommerce/" target="_blank" class="button button-secondary">
                            <span class="dashicons dashicons-external"></span>
                            <?php 
            esc_html_e( 'Priority Support', 'delete-duplicate-products-for-woocommerce' );
            ?>
                        </a>
                    </div>
                </div>
            <?php 
        }
        ?>

            <div style="margin-top:20px;padding-top:12px;border-top:1px solid #eee;text-align:center;color:#999;font-size:11px;">
                <?php 
        esc_html_e( 'Delete Duplicate Products for WooCommerce — Developed with love by Luis Peel', 'delete-duplicate-products-for-woocommerce' );
        ?>
            </div>
        </div>
        <?php 
    }

}

// Register activation hook
register_activation_hook( __FILE__, array('CPTSM2_Duplicate_Products', 'cptsm2_activate_plugin') );
// Initialize the plugin.
new CPTSM2_Duplicate_Products();