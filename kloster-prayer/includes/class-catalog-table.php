<?php
declare(strict_types=1);
namespace KlosterPrayer;
if (!defined('ABSPATH')) exit;

if (!class_exists('\WP_List_Table')) require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';

final class Catalog_Table extends \WP_List_Table
{
    private string $kind;
    public function __construct(string $kind) { $this->kind=$kind; parent::__construct(['singular'=>'klp-item','plural'=>'klp-items','ajax'=>false]); }
    public function get_columns() { return ['cb'=>'<input type="checkbox">','title'=>'Название','slug'=>'Код','details'=>$this->kind==='forms'?'Шорткод':'Пожертвование','status'=>'Статус','updated_at'=>'Изменено']; }
    protected function get_sortable_columns() { return ['title'=>['title_ru',false],'slug'=>['slug',false],'updated_at'=>['updated_at',true]]; }
    protected function get_bulk_actions() { return ['enable'=>'Включить','disable'=>'Отключить']; }
    public function column_cb($item) { return '<input type="checkbox" name="ids[]" value="'.(int)$item->id.'" aria-label="'.esc_attr('Выбрать '.$item->title_ru).'">'; }
    public function column_title($item) {
        $url=admin_url('admin.php?page=kloster-prayer-'.$this->kind.'&edit='.(int)$item->id);
        $lang=I18n::language();$title=$item->{'title_'.$lang}?:$item->title_ru?:$item->title_de;
        return '<strong><a class="row-title" href="'.esc_url($url).'">'.esc_html($title).'</a></strong>'.$this->row_actions(['edit'=>'<a href="'.esc_url($url).'">Редактировать</a>']);
    }
    public function column_default($item,$column) {
        if($column==='slug')return '<code>'.esc_html($item->slug).'</code>';
        if($column==='status')return $item->is_active?'Активна':'Отключена';
        if($column==='updated_at')return esc_html($item->updated_at);
        if($column==='details')return $this->kind==='forms'?'<code>[kloster_form id="'.(int)$item->id.'"]</code>':esc_html(number_format_i18n($item->price_cents/100,2)).' €';
        return '';
    }
    protected function get_views() {
        global $wpdb;$table=Database::table($this->kind);$counts=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) total,SUM(is_active=1) active,SUM(is_active=0) inactive FROM %i", $table));$views=[];
        foreach([''=>'Все','active'=>'Активные','inactive'=>'Отключённые'] as $key=>$label){$n=$key===''?$counts->total:($key==='active'?$counts->active:$counts->inactive);$views[$key?:'all']='<a href="'.esc_url(add_query_arg(['page'=>'kloster-prayer-'.$this->kind,'status'=>$key],admin_url('admin.php'))).'"'.((Input::get('status')??'')===$key?' class="current" aria-current="page"':'').'>'.esc_html($label).' <span class="count">('.(int)$n.')</span></a>';}
        return $views;
    }
    public function prepare_items() {
        global $wpdb;$table=Database::table($this->kind);$where=['1=1'];$args=[];
        $search=sanitize_text_field(Input::request('s')??'');
        if($search!==''){$where[]='(title_ru LIKE %s OR title_de LIKE %s OR title_uk LIKE %s OR title_pl LIKE %s OR slug LIKE %s)';$like='%'.$wpdb->esc_like($search).'%';array_push($args,$like,$like,$like,$like,$like);}
        if(in_array(Input::get('status')??'', ['active','inactive'],true)){$where[]='is_active=%d';$args[]=Input::get('status')==='active'?1:0;}
        $sql=' FROM %i WHERE '.implode(' AND ',$where);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed filter clauses, bound table identifier and search values.
        $total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*)'.$sql,...array_merge([$table],$args)));
        $sort=in_array(Input::get('orderby')??'', ['title_ru','slug','updated_at'],true)?Input::get('orderby'):'id';$dir=strtolower(Input::get('order')??'asc')==='desc'?'DESC':'ASC';$per=20;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ORDER BY and direction are whitelist values; identifiers, filters and paging are bound.
        $this->items=$wpdb->get_results($wpdb->prepare('SELECT *'.$sql." ORDER BY {$sort} {$dir} LIMIT %d OFFSET %d",...array_merge([$table],$args,[$per,($this->get_pagenum()-1)*$per])));
        $this->_column_headers=[$this->get_columns(),[], $this->get_sortable_columns(),'title'];$this->set_pagination_args(['total_items'=>$total,'per_page'=>$per]);
    }
}
