<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;
if (!defined('ABSPATH')) exit;
if(!class_exists('\WP_List_Table'))require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
final class Orders_Table extends \WP_List_Table
{
    public function __construct(){parent::__construct(['singular'=>'gkod-order','plural'=>'gkod-orders','ajax'=>false]);}
    public function get_columns(){return ['cb'=>'<input type="checkbox">','id'=>(esc_html__('Request', 'georg-kloster-orthodox-donations')),'donor'=>(esc_html__('Payer', 'georg-kloster-orthodox-donations')),'services'=>(esc_html__('Services', 'georg-kloster-orthodox-donations')),'names'=>(esc_html__('Names', 'georg-kloster-orthodox-donations')),'amount_cents'=>(esc_html__('Amount', 'georg-kloster-orthodox-donations')),'payment_status'=>(esc_html__('Payment', 'georg-kloster-orthodox-donations')),'printed_at'=>(esc_html__('Printing', 'georg-kloster-orthodox-donations')),'created_at'=>(esc_html__('Date', 'georg-kloster-orthodox-donations'))];}
    protected function get_sortable_columns(){return ['id'=>['id',true],'amount_cents'=>['amount_cents',false],'created_at'=>['created_at',true]];}
    protected function get_bulk_actions(){return ['mark_unprinted'=>(esc_html__('Clear print status', 'georg-kloster-orthodox-donations')),'archive_order'=>(esc_html__('Archive request', 'georg-kloster-orthodox-donations')),'restore_order'=>(esc_html__('Restore from archive', 'georg-kloster-orthodox-donations'))];}
    public function column_cb($item){return '<input type="checkbox" name="order_ids[]" value="'.(int)$item->id.('" aria-label="'.esc_attr__('Request', 'georg-kloster-orthodox-donations').' ').(int)$item->id.'">';}
    public function column_id($item){return '<strong>'.$this->link($item,'#'.$item->id).'</strong>'.$this->row_actions(['view'=>$this->link($item,(__('Open', 'georg-kloster-orthodox-donations')))]);}
    private function link($item,string $text): string{return '<a href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations&view='.(int)$item->id)).'">'.esc_html($text).'</a>';}
    public function column_default($item,$column){
        global $wpdb;
        switch($column){
            case 'donor': return $this->link($item,$item->billing_name?:$item->donor_name?:'—').'<br>'.esc_html($item->billing_email?:$item->donor_email?:'—');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated custom request ledger; current payment/archive state is required.
            case 'services':if (($item->intention ?? '') === 'none') return $this->link($item,I18n::donation_text('title')); return $this->link($item,implode(', ',$wpdb->get_col($wpdb->prepare('SELECT service_title FROM %i WHERE order_id=%d', Database::table('order_items'),$item->id))));
            case 'names':return '<div class="gkod-names">'.nl2br(esc_html($item->names)).'</div>'.self::comment_html((string)($item->note??''));
            case 'amount_cents':return $this->link($item,number_format_i18n($item->amount_cents/100,2).' €');
            case 'payment_status':return $this->link($item,['paid'=>(__('Paid', 'georg-kloster-orthodox-donations')),'pending'=>(__('Pending', 'georg-kloster-orthodox-donations')),'failed'=>(__('Error', 'georg-kloster-orthodox-donations')),'refunded'=>(__('Refunded', 'georg-kloster-orthodox-donations')),'cancelled'=>(__('Canceled', 'georg-kloster-orthodox-donations'))][$item->payment_status]??$item->payment_status).'<br>'.$this->link($item,ucfirst($item->payment_provider));
            case 'printed_at':return $item->printed_at?(esc_html__('Printed', 'georg-kloster-orthodox-donations').'<br>').esc_html($item->printed_at):'—';
            case 'created_at':return esc_html($item->created_at);
        }return '';
    }
    public static function comment_html(string $note): string {
        $note=trim($note);
        if($note==='')return '';
        return '<div class="gkod-order-comment"><strong>'.esc_html__('Request comment','georg-kloster-orthodox-donations').'</strong><div>'.nl2br(esc_html($note)).'</div></div>';
    }
    public static function where(array $input): array {
        global $wpdb;$where=[($input['archive']??'')==='yes'?'archived_at IS NOT NULL':'archived_at IS NULL'];$args=[];
        foreach(['payment_status'=>['pending','paid','failed','refunded','cancelled'],'intention'=>['health','rest','none'],'payment_provider'=>['stripe','paypal']] as $key=>$allowed){$value=$input[$key==='payment_provider'?'provider':$key]??'';if(in_array($value,$allowed,true)){$where[]=$key.'=%s';$args[]=$value;}}
        if(($input['printed']??'')==='yes')$where[]='printed_at IS NOT NULL';elseif(($input['printed']??'')==='no')$where[]='printed_at IS NULL';
        if(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)($input['month']??''))){$where[]='created_at >= %s AND created_at < %s';$args[]=$input['month'].'-01';$args[]=gmdate('Y-m-d',strtotime($input['month'].'-01 +1 month'));}
        if(!empty($input['service_id'])){$where[]='id IN (SELECT order_id FROM '.Database::table('order_items').' WHERE service_id=%d)';$args[]=absint($input['service_id']);}
        $s=sanitize_text_field(wp_unslash($input['s']??''));if($s!==''){$where[]='(names LIKE %s OR donor_email LIKE %s OR donor_name LIKE %s OR billing_name LIKE %s OR billing_email LIKE %s OR public_id LIKE %s)';array_push($args,...array_fill(0,6,'%'.$wpdb->esc_like($s).'%'));}
        return [implode(' AND ',$where),$args];
    }
    public function prepare_items(){
        global $wpdb;[$where,$args]=self::where(Input::get_all());$from=' FROM %i WHERE '.$where;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed clauses from where(); identifiers and values are bound. Paginated custom request ledger; current payment/archive state is required.
        $total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*)'.$from,...array_merge([Database::table('orders')],$args)));
        $sort=in_array(Input::get('orderby')??'', ['id','amount_cents','created_at'],true)?Input::get('orderby'):'id';$dir=strtolower(Input::get('order')??'desc')==='asc'?'ASC':'DESC';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Sort/direction whitelist; identifiers, filter values and paging are bound. Paginated custom request ledger; current payment/archive state is required.
        $this->items=$wpdb->get_results($wpdb->prepare('SELECT *'.$from." ORDER BY {$sort} {$dir} LIMIT %d OFFSET %d",...array_merge([Database::table('orders')],$args,[30,($this->get_pagenum()-1)*30])));
        $this->_column_headers=[$this->get_columns(),[],$this->get_sortable_columns(),'id'];$this->set_pagination_args(['total_items'=>$total,'per_page'=>30]);
    }
    protected function extra_tablenav($which){
        if($which!=='top')return;
        echo '<div class="alignleft actions gkod-order-filters">';
        foreach(['archive'=>[''=>(__('Current requests', 'georg-kloster-orthodox-donations')),'yes'=>(__('Archive', 'georg-kloster-orthodox-donations'))],'payment_status'=>[''=>(__('All payments', 'georg-kloster-orthodox-donations')),'paid'=>(__('Paid', 'georg-kloster-orthodox-donations')),'pending'=>(__('Pending', 'georg-kloster-orthodox-donations')),'failed'=>(__('Error', 'georg-kloster-orthodox-donations')),'refunded'=>(__('Refunded', 'georg-kloster-orthodox-donations')),'cancelled'=>(__('Canceled', 'georg-kloster-orthodox-donations'))],'intention'=>['none'=>I18n::donation_text('title'),''=>(__('Both types', 'georg-kloster-orthodox-donations')),'health'=>(__('For the living', 'georg-kloster-orthodox-donations')),'rest'=>(__('For the departed', 'georg-kloster-orthodox-donations'))],'printed'=>[''=>(__('All print statuses', 'georg-kloster-orthodox-donations')),'yes'=>(__('Printed', 'georg-kloster-orthodox-donations')),'no'=>(__('Not printed', 'georg-kloster-orthodox-donations'))]] as $name=>$options){echo '<select aria-label="'.esc_attr($options['']).'" name="'.esc_attr($name).'">';foreach($options as $v=>$label)echo '<option value="'.esc_attr($v).'" '.selected(Input::get($name)??'',$v,false).'>'.esc_html($label).'</option>';echo '</select>';}
        echo ('<button class="button" formmethod="get">'.esc_html__('Filter', 'georg-kloster-orthodox-donations').'</button> <button class="button" name="gkod_action" value="generate_pdf">PDF</button> <a class="button" href="').esc_url(wp_nonce_url(admin_url('admin-post.php?action=gkod_csv'),'gkod_csv')).'">CSV</a></div>';
    }
}
