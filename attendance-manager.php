<?php
/**
 * Plugin Name: 勤怠管理
 * Description: 長距離ドライバー・事務・地場の勤怠データを管理するプラグイン
 * Version:     1.0.0
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'AM_VERSION' ) )    define( 'AM_VERSION',    '1.0.0' );
if ( ! defined( 'AM_PLUGIN_DIR' ) ) define( 'AM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'AM_PLUGIN_URL' ) ) define( 'AM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AM_PLUGIN_DIR . 'includes/class-am-db.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-compute-chokyo.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-compute-jiba.php';
require_once AM_PLUGIN_DIR . 'includes/class-am-ajax.php';

if ( ! class_exists( 'Tanpopo_AttendanceManager' ) ) :

class Tanpopo_AttendanceManager {

    const KINTAI_TYPES = [ '出勤', '法定休', '法定振替休', '所定休', '所定振替休', '有給', '欠勤', '緊急出動' ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'migrate_existing_tables' ] );
        register_activation_hook( __FILE__,  [ $this, 'activate' ] );

        // --- 長距離 AJAX ---
        add_action( 'wp_ajax_am_chokyo_kintai_save',        [ 'AM_Ajax', 'chokyo_kintai_save' ] );
        add_action( 'wp_ajax_am_chokyo_get_monthly_summary', [ 'AM_Ajax', 'chokyo_get_monthly_summary' ] );
        add_action( 'wp_ajax_am_chokyo_get_daily_rows',     [ 'AM_Ajax', 'chokyo_get_daily_rows' ] );
        add_action( 'wp_ajax_am_chokyo_get_weekly_rows',    [ 'AM_Ajax', 'chokyo_get_weekly_rows' ] );

        // --- 地場・事務 AJAX ---
        add_action( 'wp_ajax_am_jiba_kintai_save',          [ 'AM_Ajax', 'jiba_kintai_save' ] );
        add_action( 'wp_ajax_am_jiba_get_monthly_summary',  [ 'AM_Ajax', 'jiba_get_monthly_summary' ] );
        add_action( 'wp_ajax_am_jiba_get_daily_rows',       [ 'AM_Ajax', 'jiba_get_daily_rows' ] );
        add_action( 'wp_ajax_am_jiba_get_weekly_rows',      [ 'AM_Ajax', 'jiba_get_weekly_rows' ] );

        // --- 休日マスタ AJAX（共通） ---
        add_action( 'wp_ajax_am_holiday_get_rules',   [ 'AM_Ajax', 'holiday_get_rules' ] );
        add_action( 'wp_ajax_am_holiday_save_rule',   [ 'AM_Ajax', 'holiday_save_rule' ] );
        add_action( 'wp_ajax_am_holiday_delete_rule', [ 'AM_Ajax', 'holiday_delete_rule' ] );
        add_action( 'wp_ajax_am_holiday_toggle_rule', [ 'AM_Ajax', 'holiday_toggle_rule' ] );
    }

    public static function format_min( $min ) {
        return AM_Compute_Chokyo::format_min( $min );
    }

    /* ---------------------------------------------------------------
     * プラグイン有効化：テーブル作成
     * ------------------------------------------------------------- */
    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 長距離用繰越
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_chokyo_carryover` (
            `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `crew_code`         VARCHAR(20)      NOT NULL,
            `year_month`        CHAR(7)          NOT NULL,
            `labor_min`         INT              NOT NULL DEFAULT 0,
            `drive_min`         INT              NOT NULL DEFAULT 0,
            `cargo_min`         INT              NOT NULL DEFAULT 0,
            `kousoku_min`       INT              NOT NULL DEFAULT 0,
            `midnight_min`      INT              NOT NULL DEFAULT 0,
            `overtime_min`      INT              NOT NULL DEFAULT 0,
            `week_overtime_min` INT              NOT NULL DEFAULT 0,
            `days`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_month` (`crew_code`(20), `year_month`)
        ) {$charset};" );

        // 長距離用勤怠ログ
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_chokyo_kintai_log` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `crew_code`      VARCHAR(20)  NOT NULL,
            `work_date`      DATE         NOT NULL,
            `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
            `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
            `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
            `jiba`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '地場フラグ',
            `hayatai_min`    INT          NOT NULL DEFAULT 0,
            `note`           VARCHAR(100) NOT NULL DEFAULT '',
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_date` (`crew_code`(20), `work_date`)
        ) {$charset};" );

        // 地場・事務用繰越
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_jiba_carryover` (
            `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `employee_code`     VARCHAR(20)      NOT NULL,
            `year_month`        CHAR(7)          NOT NULL,
            `labor_min`         INT              NOT NULL DEFAULT 0,
            `drive_min`         INT              NOT NULL DEFAULT 0,
            `cargo_min`         INT              NOT NULL DEFAULT 0,
            `kousoku_min`       INT              NOT NULL DEFAULT 0,
            `midnight_min`      INT              NOT NULL DEFAULT 0,
            `overtime_min`      INT              NOT NULL DEFAULT 0,
            `week_overtime_min` INT              NOT NULL DEFAULT 0,
            `days`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_emp_month` (`employee_code`(20), `year_month`)
        ) {$charset};" );

        // 地場・事務用勤怠ログ
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_jiba_kintai_log` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_code`  VARCHAR(20)  NOT NULL,
            `work_date`      DATE         NOT NULL,
            `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
            `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
            `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
            `chokyo`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '長距離フラグ',
            `hayatai_min`    INT          NOT NULL DEFAULT 0,
            `note`           VARCHAR(100) NOT NULL DEFAULT '',
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_emp_date` (`employee_code`(20), `work_date`)
        ) {$charset};" );

        // 共通休日ルール
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}am_holiday_rules` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `affiliation_id` INT UNSIGNED NOT NULL,
            `day_of_week`    TINYINT      NOT NULL,
            `week_numbers`   VARCHAR(20)  NOT NULL,
            `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_affil_rule` (`affiliation_id`, `day_of_week`, `week_numbers`)
        ) {$charset};" );
    }

    /* ---------------------------------------------------------------
     * マイグレーション
     * ------------------------------------------------------------- */
    public function migrate_existing_tables() {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'am_chokyo_carryover',
            $wpdb->prefix . 'am_chokyo_kintai_log',
            $wpdb->prefix . 'am_jiba_carryover',
            $wpdb->prefix . 'am_jiba_kintai_log',
            $wpdb->prefix . 'am_holiday_rules',
        ];
        foreach ( $tables as $t ) {
            if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) ) {
                $this->activate();
                break;
            }
        }
    }

    /* ---------------------------------------------------------------
     * メニュー登録
     * ------------------------------------------------------------- */
    public function add_menu() {
        add_menu_page(
            '勤怠管理',
            '勤怠管理',
            'manage_options',
            'attendance-manager',
            [ $this, 'render_chokyo_page' ],
            'dashicons-car',
            28
        );
        add_submenu_page(
            'attendance-manager', '長距離', '長距離',
            'manage_options', 'attendance-manager',
            [ $this, 'render_chokyo_page' ]
        );
        add_submenu_page(
            'attendance-manager', '地場・事務', '地場・事務',
            'manage_options', 'attendance-manager-jiba',
            [ $this, 'render_jiba_page' ]
        );
        add_submenu_page(
            'attendance-manager', '休日マスタ設定', '休日マスタ設定',
            'manage_options', 'attendance-manager-holiday',
            [ $this, 'render_holiday_page' ]
        );
    }

    /* ---------------------------------------------------------------
     * アセット読み込み
     * ------------------------------------------------------------- */
    public function enqueue_assets() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
        $pages = [ 'attendance-manager', 'attendance-manager-jiba', 'attendance-manager-holiday' ];
        if ( ! in_array( $page, $pages, true ) ) return;

        wp_enqueue_style(  'am-admin', AM_PLUGIN_URL . 'assets/css/admin.css', [], AM_VERSION );
        wp_enqueue_script( 'am-admin', AM_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], AM_VERSION, true );
        wp_localize_script( 'am-admin', 'amData', [
            'defaultMonth' => date( 'Y-m' ),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'am_nonce' ),
            'currentPage'  => $page,
        ] );
    }

    /* ---------------------------------------------------------------
     * 長距離 集計表示
     * ------------------------------------------------------------- */
    public function render_chokyo_page() {
        $result    = AM_DB::get_employees_from_kousoku();
        $employees = $result['employees'];
        $db_error  = $result['error'];

        $selected_crew  = isset( $_GET['am_crew'] )  ? sanitize_text_field( wp_unslash( $_GET['am_crew'] ) )  : '';
        $selected_month = isset( $_GET['am_month'] ) ? sanitize_text_field( wp_unslash( $_GET['am_month'] ) ) : date( 'Y-m' );

        $emp_info        = null;
        $monthly_rows    = [];
        $weekly          = null;
        $monthly_summary = null;

        if ( $selected_crew !== '' && $selected_month !== '' ) {
            $emp_info        = AM_DB::get_emp_info_by_crew( $selected_crew );
            $monthly_rows    = AM_Compute_Chokyo::get_monthly_rows( $selected_crew, $selected_month, $emp_info['name'] );
            $weekly          = AM_Compute_Chokyo::get_weekly_summary( $selected_crew, $selected_month, $monthly_rows );
            if ( ! empty( $monthly_rows ) ) {
                $monthly_summary = AM_Compute_Chokyo::get_monthly_summary( $monthly_rows, $weekly, $selected_crew, $selected_month );
            }
        }

        $kintai_types = self::KINTAI_TYPES;
        include AM_PLUGIN_DIR . 'templates/chokyo-page.php';
    }

    /* ---------------------------------------------------------------
     * 地場・事務 集計表示
     * ------------------------------------------------------------- */
    public function render_jiba_page() {
        $result    = AM_DB::get_employees_from_mat();
        $employees = $result['employees'];
        $db_error  = $result['error'];

        $selected_emp   = isset( $_GET['am_emp'] )   ? sanitize_text_field( wp_unslash( $_GET['am_emp'] ) )   : '';
        $selected_month = isset( $_GET['am_month'] ) ? sanitize_text_field( wp_unslash( $_GET['am_month'] ) ) : date( 'Y-m' );

        $emp_info        = null;
        $monthly_rows    = [];
        $weekly          = null;
        $monthly_summary = null;

        if ( $selected_emp !== '' && $selected_month !== '' ) {
            $emp_info        = AM_DB::get_emp_info_by_code( $selected_emp );
            $monthly_rows    = AM_Compute_Jiba::get_monthly_rows( $selected_emp, $selected_month, $emp_info['name'] );
            $weekly          = AM_Compute_Jiba::get_weekly_summary( $selected_emp, $selected_month, $monthly_rows );
            if ( ! empty( $monthly_rows ) ) {
                $monthly_summary = AM_Compute_Jiba::get_monthly_summary( $monthly_rows, $weekly, $selected_emp, $selected_month );
            }
        }

        $kintai_types = self::KINTAI_TYPES;
        include AM_PLUGIN_DIR . 'templates/jiba-page.php';
    }

    /* ---------------------------------------------------------------
     * 休日マスタ設定
     * ------------------------------------------------------------- */
    public function render_holiday_page() {
        global $wpdb;
        if ( function_exists( 'emp_get_affiliations' ) ) {
            $affiliations = emp_get_affiliations();
        } else {
            $affiliations = $wpdb->get_results(
                "SELECT id, name FROM `{$wpdb->prefix}mst_affiliation` WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
            );
        }
        $rules      = AM_DB::get_holiday_rules();
        $dow_labels = [ '日', '月', '火', '水', '木', '金', '土' ];
        include AM_PLUGIN_DIR . 'templates/holiday-page.php';
    }
}

new Tanpopo_AttendanceManager();

endif;
