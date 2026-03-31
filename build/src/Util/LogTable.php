<?php

declare (strict_types=1);
namespace LockmeDep\LockmeIntegration\Util;

defined('ABSPATH') or die('No script kiddies please!');
if (!class_exists('LockmeDep\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class LogTable extends \LockmeDep\WP_List_Table
{
    public function get_columns(): array
    {
        return ['id' => 'Log ID', 'time' => 'Time', 'method' => 'HTTP method', 'uri' => 'HTTP URI', 'params' => 'Request body', 'response' => 'Response'];
    }
    protected function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'params':
            case 'response':
                return '<pre>' . esc_html((string) ($item[$column_name] ?? '')) . '</pre>';
            default:
                return esc_html((string) ($item[$column_name] ?? ''));
        }
    }
    protected function get_table_classes(): array
    {
        return array('widefat', 'striped');
    }
    public function no_items(): void
    {
        echo esc_html__('The logs are empty. And this is a good thing!', 'lockme-calendars-integration');
    }
    public function prepare_items(): void
    {
        global $wpdb;
        // code to handle bulk actions
        //used by WordPress to build and fetch the _column_headers property
        $this->_column_headers = [$this->get_columns(), [], []];
        $wpdb_table = $wpdb->prefix . 'lockme_log';
        $items = 10;
        $page = ($this->get_pagenum() - 1) * $items;
        $query = $wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $wpdb_table, $items, $page);
        // query output_type will be an associative array with ARRAY_A.
        $this->items = $wpdb->get_results($query, ARRAY_A);
        $total = $wpdb->get_var($wpdb->prepare('SELECT count(*) FROM %i', $wpdb_table));
        // code to handle data operations like sorting and filtering
        // code to handle pagination
        $this->set_pagination_args(array('total_items' => $total, 'per_page' => $items, 'total_pages' => ceil($total / $items)));
    }
}
