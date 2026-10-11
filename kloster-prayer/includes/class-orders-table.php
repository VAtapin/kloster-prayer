<?php
declare(strict_types=1);
namespace KlosterPrayer;
if (!defined('ABSPATH')) exit;
if(!class_exists('\WP_List_Table'))require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
final class Orders_Table extends \WP_List_Table
{
    public function __construct(){parent::__construct(['singular'=>'klp-order','plural'=>'klp-orders','ajax'=>false]);}
    public function get_columns(){return ['cb'=>'<input type="checkbox">','id'=>'Записка','donor'=>'Плательщик','services'=>'Требы','names'=>'Имена','amount_cents'=>'Сумма','payment_status'=>'Оплата','printed_at'=>'Печать','created_at'=>'Дата'];}
    protected function get_sortable_columns(){return ['id'=>['id',true],'amount_cents'=>['amount_cents',false],'created_at'=>['created_at',true]];}
    protected function get_bulk_actions(){return ['mark_unprinted'=>'Снять отметку','archive_order'=>'В архив','restore_order'=>'Восстановить из архива'];}
    public function column_cb($item){return '<input type="checkbox" name="order_ids[]" value="'.(int)$item->id.'" aria-label="Записка '.(int)$item->id.'">';}
    public function column_id($item){return '<strong>'.$this->link($item,'#'.$item->id).'</strong>'.$this->row_actions(['view'=>$this->link($item,'Открыть')]);}
    private function link($item,string $text): string{return '<a href="'.esc_url(admin_url('admin.php?page=kloster-prayer&view='.(int)$item->id)).'">'.esc_html($text).'</a>';}
    public function column_default($item,$column){
        global $wpdb;
        switch($column){
            case 'donor': return $this->link($item,$item->billing_name?:$item->donor_name?:'—').'<br>'.esc_html($item->billing_email?:$item->donor_email?:'—');
            case 'services':return $this->link($item,implode(', ',$wpdb->get_col($wpdb->prepare('SELECT service_title FROM %i WHERE order_id=%d', Database::table('order_items'),$item->id))));
            case 'names':return '<div class="klp-names">'.nl2br(esc_html($item->names)).'</div>'.self::comment_html((string)($item->note??''));
            case 'amount_cents':return $this->link($item,number_format_i18n($item->amount_cents/100,2).' €');
            case 'payment_status':return $this->link($item,['paid'=>'Оплачено','pending'=>'Ожидает','failed'=>'Ошибка','refunded'=>'Возвращено','cancelled'=>'Отменено'][$item->payment_status]??$item->payment_status).'<br>'.$this->link($item,ucfirst($item->payment_provider));
            case 'printed_at':return $item->printed_at?'Распечатано<br>'.esc_html($item->printed_at):'—';
            case 'created_at':return esc_html($item->created_at);
        }return '';
    }
    public static function comment_html(string $note): string {
        $note=trim($note);
        if($note==='')return '';
        return '<div class="klp-order-comment"><strong>'.esc_html__('Комментарий к записке','kloster-prayer').'</strong><div>'.nl2br(esc_html($note)).'</div></div>';
    }
    public static function where(array $input): array {
        global $wpdb;$where=[($input['archive']??'')==='yes'?'archived_at IS NOT NULL':'archived_at IS NULL'];$args=[];
        foreach(['payment_status'=>['pending','paid','failed','refunded','cancelled'],'intention'=>['health','rest'],'payment_provider'=>['stripe','paypal']] as $key=>$allowed){$value=$input[$key==='payment_provider'?'provider':$key]??'';if(in_array($value,$allowed,true)){$where[]=$key.'=%s';$args[]=$value;}}
        if(($input['printed']??'')==='yes')$where[]='printed_at IS NOT NULL';elseif(($input['printed']??'')==='no')$where[]='printed_at IS NULL';
        if(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)($input['month']??''))){$where[]='created_at >= %s AND created_at < %s';$args[]=$input['month'].'-01';$args[]=gmdate('Y-m-d',strtotime($input['month'].'-01 +1 month'));}
        if(!empty($input['service_id'])){$where[]='id IN (SELECT order_id FROM '.Database::table('order_items').' WHERE service_id=%d)';$args[]=absint($input['service_id']);}
        $s=sanitize_text_field(wp_unslash($input['s']??''));if($s!==''){$where[]='(names LIKE %s OR donor_email LIKE %s OR donor_name LIKE %s OR billing_name LIKE %s OR billing_email LIKE %s OR public_id LIKE %s)';array_push($args,...array_fill(0,6,'%'.$wpdb->esc_like($s).'%'));}
        return [implode(' AND ',$where),$args];
    }
    public function prepare_items(){
        global $wpdb;[$where,$args]=self::where(Input::get_all());$from=' FROM %i WHERE '.$where;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed clauses from where(); identifiers and values are bound.
        $total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*)'.$from,...array_merge([Database::table('orders')],$args)));
        $sort=in_array(Input::get('orderby')??'', ['id','amount_cents','created_at'],true)?Input::get('orderby'):'id';$dir=strtolower(Input::get('order')??'desc')==='asc'?'ASC':'DESC';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Sort/direction whitelist; identifiers, filter values and paging are bound.
        $this->items=$wpdb->get_results($wpdb->prepare('SELECT *'.$from." ORDER BY {$sort} {$dir} LIMIT %d OFFSET %d",...array_merge([Database::table('orders')],$args,[30,($this->get_pagenum()-1)*30])));
        $this->_column_headers=[$this->get_columns(),[],$this->get_sortable_columns(),'id'];$this->set_pagination_args(['total_items'=>$total,'per_page'=>30]);
    }
    protected function extra_tablenav($which){
        if($which!=='top')return;
        echo '<div class="alignleft actions klp-order-filters">';
        foreach(['archive'=>[''=>'Текущие записки','yes'=>'Архив'],'payment_status'=>[''=>'Все оплаты','paid'=>'Оплачено','pending'=>'Ожидает','failed'=>'Ошибка','refunded'=>'Возвращено','cancelled'=>'Отменено'],'intention'=>[''=>'Оба типа','health'=>'О здравии','rest'=>'Об упокоении'],'printed'=>[''=>'Вся печать','yes'=>'Распечатано','no'=>'Не распечатано']] as $name=>$options){echo '<select aria-label="'.esc_attr($options['']).'" name="'.esc_attr($name).'">';foreach($options as $v=>$label)echo '<option value="'.esc_attr($v).'" '.selected(Input::get($name)??'',$v,false).'>'.esc_html($label).'</option>';echo '</select>';}
        echo '<button class="button" formmethod="get">Фильтр</button> <button class="button" name="klp_action" value="generate_pdf">PDF</button> <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=klp_csv'),'klp_csv')).'">CSV</a></div>';
    }
}
