<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;
if (!defined('ABSPATH')) exit;
if (!class_exists('\WP_List_Table')) require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';

/** Native WordPress lists for the two donation administration screens. */
final class Donation_Admin_Table extends \WP_List_Table
{
    public function __construct(private string $kind, private int $campaign_id=0)
    {
        if (!in_array($kind,['campaigns','subscriptions','campaign_expenses'],true)) throw new \InvalidArgumentException('Unknown list');
        parent::__construct(['singular'=>'gkod-donation-record','plural'=>'gkod-donation-records','ajax'=>false]);
    }
    public function get_columns()
    {
        if ($this->kind==='campaign_expenses') return ['date'=>__('Date','georg-kloster-orthodox-donate'),'description'=>__('Description','georg-kloster-orthodox-donate'),'expense'=>__('Amount','georg-kloster-orthodox-donate')];
        return $this->kind==='campaigns'
            ? ['title'=>__('Campaign','georg-kloster-orthodox-donate'),'status'=>__('Status','georg-kloster-orthodox-donate'),'report'=>__('Report','georg-kloster-orthodox-donate')]
            : ['account'=>__('User','georg-kloster-orthodox-donate'),'amount'=>__('Amount','georg-kloster-orthodox-donate'),'status'=>__('Status','georg-kloster-orthodox-donate'),'provider'=>__('Payment method','georg-kloster-orthodox-donate'),'actions'=>__('Actions','georg-kloster-orthodox-donate')];
    }
    public function prepare_items()
    {
        global $wpdb;
        $table=Database::table($this->kind);$per=20;
        $total=(int)($this->kind==='campaign_expenses'
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated custom campaign/subscription ledger; current expense/payment/lifecycle state is required.
            ? $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE campaign_id=%d',$table,$this->campaign_id))
            : $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$table)));
        $this->set_pagination_args(['total_items'=>$total,'per_page'=>$per]);
        $page=min(max(1,(int)ceil($total/$per)),$this->get_pagenum());
        $offset=($page-1)*$per;
        $this->items=$this->kind==='campaign_expenses'
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated custom campaign/subscription ledger; current expense/payment/lifecycle state is required.
            ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE campaign_id=%d ORDER BY id DESC LIMIT %d OFFSET %d',$table,$this->campaign_id,$per,$offset))
            : $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',$table,$per,$offset));
        $this->_column_headers=[$this->get_columns(),[],[],array_key_first($this->get_columns())];
    }
    public function column_default($item,$column)
    {
        if ($column==='date') return esc_html($item->incurred_on);
        if ($column==='description') return esc_html($item->description);
        if ($column==='expense') return esc_html(number_format_i18n($item->amount_cents/100,2).' EUR');
        if ($column==='title') {
            $url=add_query_arg(['page'=>'georg-kloster-orthodox-donate-campaigns','campaign_id'=>(int)$item->id],admin_url('admin.php'));
            return '<strong><a class="row-title" href="'.esc_url($url).'">'.esc_html(Campaigns::title($item,I18n::content_language())).'</a></strong>'.$this->row_actions(['edit'=>'<a href="'.esc_url($url).'">'.esc_html__('Edit','georg-kloster-orthodox-donate').'</a>']);
        }
        if ($column==='report') {
            $totals=Campaigns::totals((int)$item->id);
            return esc_html__('Goal','georg-kloster-orthodox-donate').': '.esc_html(number_format_i18n($item->goal_cents/100,2).' EUR').'<br>'.esc_html__('Donations received','georg-kloster-orthodox-donate').': '.esc_html(number_format_i18n($totals['received']/100,2).' EUR').'<br>'.esc_html__('Expenses','georg-kloster-orthodox-donate').': '.esc_html(number_format_i18n($totals['spent']/100,2).' EUR').'<br>'.esc_html__('Balance','georg-kloster-orthodox-donate').': '.esc_html(number_format_i18n($totals['balance']/100,2).' EUR');
        }
        if ($column==='account') return '<strong>#'.(int)$item->donor_user_id.'</strong><br>#'.(int)$item->id;
        if ($column==='amount') return esc_html(number_format_i18n($item->amount_cents/100,2).' EUR').' / '.esc_html($item->billing_interval==='year'?__('Yearly','georg-kloster-orthodox-donate'):__('Monthly','georg-kloster-orthodox-donate'));
        if ($column==='provider') return esc_html(ucfirst($item->provider));
        if ($column==='actions') return Support_Pages::cancel_form($item);
        if ($column==='status') {
            $labels=['draft'=>__('Draft','georg-kloster-orthodox-donate'),'open'=>__('Open','georg-kloster-orthodox-donate'),'closed'=>__('Closed','georg-kloster-orthodox-donate'),'pending'=>__('Pending','georg-kloster-orthodox-donate'),'active'=>__('Active','georg-kloster-orthodox-donate'),'past_due'=>__('Payment overdue','georg-kloster-orthodox-donate'),'failed'=>__('Error','georg-kloster-orthodox-donate'),'cancelled'=>__('Canceled','georg-kloster-orthodox-donate')];
            return esc_html($labels[$item->status]??$item->status);
        }
        return '';
    }
}
