<?php
declare(strict_types=1);
require __DIR__.'/admin-render.php';
function get_current_user_id(){return 7;}
class WorkflowDB extends FixtureDB {
    public array $stored;
    public array $history=[];
    public array $item;
    public function __construct(){
        $this->stored=['id'=>1,'archived_at'=>null,'printed_at'=>null,'payment_status'=>'paid','amount_cents'=>4000];
        $this->item=['id'=>2,'order_id'=>1,'service_title'=>'Сорокоуст','remembrance_start'=>null,'remembrance_end'=>null];
    }
    public function get_row($sql){return (object)(str_contains($sql,'order_items')?$this->item:$this->stored);}
    public function update($table,$data,$where){if(str_contains($table,'order_items'))$this->item=array_merge($this->item,$data);else $this->stored=array_merge($this->stored,$data);return 1;}
    public function insert($table,$data){$this->history[]=$data;return 1;}
    public function delete(...$args){throw new Exception('Destructive delete must never be called');}
}
use KlosterPrayer\Order_Workflow as Flow;
assert(Flow::period('2026-09-08',40)===['2026-09-08','2026-10-17']);
assert(Flow::period('2028-02-28',3)===['2028-02-28','2028-03-01']);
assert(Flow::period('2026-12-31',1)===['2026-12-31','2026-12-31']);
foreach([['2026-02-30',40],['bad',1],['2026-01-01',0],['2026-01-01',36501]] as [$date,$days]){
    try {Flow::period($date,$days);throw new Exception('Invalid period accepted');}catch(InvalidArgumentException $e){}
}
assert(Flow::status('2026-09-08','2026-10-17','2026-09-08')==='В работе · осталось дней: 40');
assert(Flow::status('2026-09-08','2026-10-17','2026-10-17')==='В работе · осталось дней: 1');
assert(Flow::status('2026-09-08','2026-10-17','2026-10-18')==='Завершено');
$wpdb=new WorkflowDB();
$_POST=['order_id'=>1,'item_id'=>2,'start'=>'2026-09-08','days'=>40];
Flow::save_period();
assert($wpdb->item['remembrance_end']==='2026-10-17');
Flow::change(1,'archive_order');
assert($wpdb->stored['archived_at']!==null);
assert($wpdb->stored['payment_status']==='paid'&&$wpdb->stored['amount_cents']===4000);
assert($wpdb->item['remembrance_end']==='2026-10-17');
$count=count($wpdb->history);Flow::change(1,'archive_order');assert(count($wpdb->history)===$count);
Flow::change(1,'restore_order');assert($wpdb->stored['archived_at']===null);
Flow::change(1,'mark_printed');assert($wpdb->stored['printed_at']!==null);
Flow::change(1,'mark_unprinted');assert($wpdb->stored['printed_at']===null);
assert(count($wpdb->history)===5);
[$active]=KlosterPrayer\Orders_Table::where([]);[$archive]=KlosterPrayer\Orders_Table::where(['archive'=>'yes']);
assert(str_contains($active,'archived_at IS NULL'));
assert(str_contains($archive,'archived_at IS NOT NULL'));
echo "Periods, leap year, inclusive dates, archive/restore, payment preservation, print history OK\n";
function check_admin_referer(...$args){return true;}
function wp_die($message,...$args){throw new RuntimeException($message);}
$wpdb=new WorkflowDB();
Flow::pdf_generated([['order_id'=>1],['order_id'=>1],['names'=>[]]]);
assert($wpdb->stored['printed_at']!==null);
assert(count($wpdb->history)===1);
assert($wpdb->history[0]['event_type']==='pdf_printed');
Flow::pdf_generated([['order_id'=>1]]);
assert(count($wpdb->history)===2);
Flow::pdf_generated([['names'=>[]]]);
assert(count($wpdb->history)===2);
echo "Automatic print marking, deduplication, repeat download, blank forms OK\n";
