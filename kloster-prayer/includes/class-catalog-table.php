<?php
declare(strict_types=1);
namespace KlosterPrayer;

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
        global $wpdb;$table=Database::table($this->kind);$counts=$wpdb->get_row("SELECT COUNT(*) total,SUM(is_active=1) active,SUM(is_active=0) inactive FROM {$table}");$views=[];
        foreach([''=>'Все','active'=>'Активные','inactive'=>'Отключённые'] as $key=>$label){$n=$key===''?$counts->total:($key==='active'?$counts->active:$counts->inactive);$views[$key?:'all']='<a href="'.esc_url(add_query_arg(['page'=>'kloster-prayer-'.$this->kind,'status'=>$key],admin_url('admin.php'))).'"'.(($_GET['status']??'')===$key?' class="current" aria-current="page"':'').'>'.esc_html($label).' <span class="count">('.(int)$n.')</span></a>';}
        return $views;
    }
    public function prepare_items() {
        global $wpdb;$table=Database::table($this->kind);$where=['1=1'];$args=[];
        $search=sanitize_text_field(wp_unslash($_REQUEST['s']??''));
        if($search!==''){$where[]='(title_ru LIKE %s OR title_de LIKE %s OR title_uk LIKE %s OR title_pl LIKE %s OR slug LIKE %s)';$like='%'.$wpdb->esc_like($search).'%';array_push($args,$like,$like,$like,$like,$like);}
        if(in_array($_GET['status']??'', ['active','inactive'],true)){$where[]='is_active=%d';$args[]=$_GET['status']==='active'?1:0;}
        $sql=' FROM '.$table.' WHERE '.implode(' AND ',$where);$total=(int)$wpdb->get_var($args?$wpdb->prepare('SELECT COUNT(*)'.$sql,...$args):'SELECT COUNT(*)'.$sql);
        $sort=in_array($_GET['orderby']??'', ['title_ru','slug','updated_at'],true)?$_GET['orderby']:'id';$dir=strtolower($_GET['order']??'asc')==='desc'?'DESC':'ASC';$per=20;
        $this->items=$wpdb->get_results($wpdb->prepare('SELECT *'.$sql." ORDER BY {$sort} {$dir} LIMIT %d OFFSET %d",...array_merge($args,[$per,($this->get_pagenum()-1)*$per])));
        $this->_column_headers=[$this->get_columns(),[], $this->get_sortable_columns(),'title'];$this->set_pagination_args(['total_items'=>$total,'per_page'=>$per]);
    }
}
