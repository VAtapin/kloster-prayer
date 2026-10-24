<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;
if (!defined('ABSPATH')) exit;

final class Donors_Table extends \WP_List_Table
{
    public function __construct()
    {
        parent::__construct(['singular' => 'gkod-donor', 'plural' => 'gkod-donors', 'ajax' => false]);
    }

    public function get_columns(): array
    {
        return [
            'name' => esc_html__('Donor', 'georg-kloster-orthodox-donations'),
            'email' => esc_html__('Email address', 'georg-kloster-orthodox-donations'),
            'donation_count' => esc_html__('Donations', 'georg-kloster-orthodox-donations'),
            'total_paid' => esc_html__('Paid total', 'georg-kloster-orthodox-donations'),
            'latest' => esc_html__('Latest donation', 'georg-kloster-orthodox-donations'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return ['name' => ['name', false], 'email' => ['email', false], 'donation_count' => ['donation_count', true], 'total_paid' => ['total_paid', true], 'latest' => ['latest', true]];
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $search = sanitize_text_field((string) (Input::request('s') ?? ''));
        $where = "donor_email<>''";
        $args = [Database::table('orders')];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (donor_email LIKE %s OR donor_name LIKE %s OR billing_name LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Directory aggregates existing payments by email; no duplicate donor data is stored.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT LOWER(donor_email)) FROM %i WHERE ' . $where, ...$args));
        if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        $sortable = ['name', 'email', 'donation_count', 'total_paid', 'latest'];
        $orderby = (string) (Input::get('orderby') ?? 'latest');
        if (!in_array($orderby, $sortable, true)) $orderby = 'latest';
        $order = strtolower((string) (Input::get('order') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $per_page = 30;
        $page = max(1, $this->get_pagenum());
        $query_args = array_merge($args, [$per_page, ($page - 1) * $per_page]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ORDER BY uses a whitelist; all values and pagination are prepared.
        $this->items = $wpdb->get_results($wpdb->prepare("SELECT MAX(donor_email) email,MAX(COALESCE(NULLIF(billing_name,''),NULLIF(donor_name,''))) name,COUNT(*) donation_count,SUM(CASE WHEN payment_status='paid' THEN amount_cents-refunded_cents ELSE 0 END) total_paid,MAX(created_at) latest FROM %i WHERE {$where} GROUP BY LOWER(donor_email) ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", ...$query_args));
        if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        if (!is_array($this->items)) $this->items = [];
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'name'];
        $this->set_pagination_args(['total_items' => $total, 'per_page' => $per_page]);
    }

    public function column_name(object $item): string
    {
        $donor = Donors::ensure((string) $item->email);
        if (!$donor) return esc_html($item->name ?: '—');
        $url = add_query_arg(['page' => 'georg-kloster-orthodox-donations-donors', 'donor' => (int) $donor->id], admin_url('admin.php'));
        return '<strong><a class="row-title" href="' . esc_url($url) . '">' . esc_html($item->name ?: '—') . '</a></strong>' . $this->row_actions(['view' => '<a href="' . esc_url($url) . '">' . esc_html__('View donations', 'georg-kloster-orthodox-donations') . '</a>']);
    }

    public function column_default($item, $column): string
    {
        if ($column === 'email') return esc_html((string) $item->email);
        if ($column === 'donation_count') return number_format_i18n((int) $item->donation_count);
        if ($column === 'total_paid') return esc_html(number_format_i18n((int) $item->total_paid / 100, 2) . ' EUR');
        if ($column === 'latest') return esc_html((string) $item->latest);
        return '';
    }
}

final class Donor_Orders_Table extends \WP_List_Table
{
    public function __construct(private string $email, private int $donor_id)
    {
        parent::__construct(['singular' => 'gkod-donation', 'plural' => 'gkod-donations', 'ajax' => false]);
    }

    public function get_columns(): array
    {
        return [
            'date' => esc_html__('Date', 'georg-kloster-orthodox-donations'),
            'purpose' => esc_html__('Donation', 'georg-kloster-orthodox-donations'),
            'amount' => esc_html__('Amount', 'georg-kloster-orthodox-donations'),
            'status' => esc_html__('Status', 'georg-kloster-orthodox-donations'),
            'letter' => esc_html__('Thank-you letter', 'georg-kloster-orthodox-donations'),
        ];
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $per_page = 20;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Show a donor's payment ledger using the standard WordPress list-table navigation.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE LOWER(donor_email)=LOWER(%s)', Database::table('orders'), $this->email));
        if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        $page = max(1, $this->get_pagenum());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated donor payment history from the plugin ledger.
        $this->items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE LOWER(donor_email)=LOWER(%s) ORDER BY id DESC LIMIT %d OFFSET %d', Database::table('orders'), $this->email, $per_page, ($page - 1) * $per_page));
        if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        if (!is_array($this->items)) $this->items = [];
        $this->_column_headers = [$this->get_columns(), [], [], 'date'];
        $this->set_pagination_args(['total_items' => $total, 'per_page' => $per_page]);
    }

    public function column_default($item, $column): string
    {
        global $wpdb;
        if ($column === 'date') return esc_html((string) $item->created_at) . ' (#' . (int) $item->id . ')';
        if ($column === 'amount') return esc_html(number_format_i18n((int) $item->amount_cents / 100, 2) . ' ' . $item->currency);
        if ($column === 'status') return esc_html((string) $item->payment_status);
        if ($column === 'purpose') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolve the displayed label from its existing related records.
            $titles = $wpdb->get_col($wpdb->prepare('SELECT service_title FROM %i WHERE order_id=%d ORDER BY id', Database::table('order_items'), (int) $item->id));
            if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
            $campaign = $item->campaign_id ? Campaigns::get((int) $item->campaign_id) : null;
            $purpose = $campaign ? Campaigns::title($campaign, $item->language) : ($titles ? implode(', ', $titles) : I18n::donation_text('title', $item->language));
            return esc_html($purpose);
        }
        if ($column === 'letter' && $item->payment_status === 'paid') {
            $id = (int) $item->id;
            $preview_nonce = wp_nonce_field('gkod_preview_thank_you_' . $id, 'gkod_nonce_gkod_preview_thank_you', true, false);
            $send_nonce = wp_nonce_field('gkod_send_thank_you_' . $id, 'gkod_nonce_gkod_send_thank_you', true, false);
            return '<form class="gkod-donor-letter-actions" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="donor_id" value="' . $this->donor_id . '"><input type="hidden" name="order_id" value="' . $id . '">' . $preview_nonce . $send_nonce . Donor_Admin::template_selector() . ' <button class="button-link gkod-donor-letter-icon" type="submit" name="action" value="gkod_preview_thank_you" formtarget="_blank" aria-label="' . esc_attr__('Preview PDF', 'georg-kloster-orthodox-donations') . '" title="' . esc_attr__('Preview PDF', 'georg-kloster-orthodox-donations') . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button> <button class="button-link gkod-donor-letter-icon" type="submit" name="action" value="gkod_send_thank_you" aria-label="' . esc_attr__('Send thank-you letter', 'georg-kloster-orthodox-donations') . '" title="' . esc_attr__('Send thank-you letter', 'georg-kloster-orthodox-donations') . '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span></button></form>';
        }
        return $column === 'letter' ? '—' : '';
    }
}
