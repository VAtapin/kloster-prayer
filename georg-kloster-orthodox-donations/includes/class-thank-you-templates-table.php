<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;
if (!defined('ABSPATH')) exit;

final class Thank_You_Templates_Table extends \WP_List_Table
{
    private int $total_items = 0;

    public function __construct()
    {
        parent::__construct(['singular' => 'gkod-thank-you-template', 'plural' => 'gkod-thank-you-templates', 'ajax' => false]);
    }

    public function get_columns(): array
    {
        return [
            'template_name' => esc_html__('Template name', 'georg-kloster-orthodox-donations'),
            'language' => esc_html__('Language', 'georg-kloster-orthodox-donations'),
            'occasion' => esc_html__('Donation case', 'georg-kloster-orthodox-donations'),
            'updated_at' => esc_html__('Updated', 'georg-kloster-orthodox-donations'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return ['template_name' => ['template_name', false], 'updated_at' => ['updated_at', true]];
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $table = Database::table('thank_you_templates');
        $search = sanitize_text_field((string) (Input::request('s') ?? ''));
        $where = '';
        $args = [$table];
        if ($search !== '') {
            $where = ' WHERE template_name LIKE %s OR language LIKE %s OR occasion LIKE %s';
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($args, $like, $like, $like);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name and search values are prepared; the optional WHERE clause is fixed.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i' . $where, ...$args));
        if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        $this->total_items = $total;
        $per_page = 20;
        $sortable = ['template_name', 'updated_at'];
        $orderby = (string) (Input::get('orderby') ?? 'id');
        if (!in_array($orderby, $sortable, true)) $orderby = 'id';
        $order = strtolower((string) (Input::get('order') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, $this->get_pagenum());
        $args[] = $per_page;
        $args[] = ($page - 1) * $per_page;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ORDER BY uses a fixed whitelist; all data values and pagination are prepared.
        $this->items = $wpdb->get_results($wpdb->prepare('SELECT id,template_name,language,occasion,updated_at FROM %i' . $where . " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", ...$args));
        if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        if (!is_array($this->items)) $this->items = [];
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'template_name'];
        $this->set_pagination_args(['total_items' => $total, 'per_page' => $per_page]);
    }

    public function column_template_name(object $item): string
    {
        $edit = add_query_arg(['page' => 'georg-kloster-orthodox-donations-thank-you', 'template_id' => (int) $item->id], admin_url('admin.php'));
        $actions = ['edit' => '<a href="' . esc_url($edit) . '">' . esc_html__('Edit', 'georg-kloster-orthodox-donations') . '</a>'];
        if ($this->total_items > 1) {
            $delete = wp_nonce_url(add_query_arg(['action' => 'gkod_delete_thank_you_template', 'template_id' => (int) $item->id], admin_url('admin-post.php')), 'gkod_delete_thank_you_template_' . (int) $item->id);
            $actions['delete'] = '<a href="' . esc_url($delete) . '" onclick="return confirm(\'' . esc_js(__('Delete this template?', 'georg-kloster-orthodox-donations')) . '\')">' . esc_html__('Delete', 'georg-kloster-orthodox-donations') . '</a>';
        }
        return '<strong><a class="row-title" href="' . esc_url($edit) . '">' . esc_html($item->template_name) . '</a></strong>' . $this->row_actions($actions);
    }

    public function column_default($item, $column): string
    {
        if ($column === 'language') return esc_html(Thank_You_Template_Admin::language_options()[$item->language] ?? $item->language);
        if ($column === 'occasion') return esc_html(Thank_You_Template_Admin::occasion_options()[$item->occasion] ?? $item->occasion);
        if ($column === 'updated_at') return esc_html((string) $item->updated_at);
        return '';
    }
}
