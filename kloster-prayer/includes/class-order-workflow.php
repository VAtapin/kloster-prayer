<?php
declare(strict_types=1);
namespace KlosterPrayer;

final class Order_Workflow
{
    public static function log(int $id, string $type, string $message): void
    {
        global $wpdb;
        $wpdb->insert(Database::table('order_history'), [
            'order_id'=>$id, 'event_type'=>$type, 'message'=>$message,
            'user_id'=>get_current_user_id(), 'created_at'=>current_time('mysql'),
        ]);
    }

    public static function pdf_generated(array $cards): void
    {
        global $wpdb;
        foreach (array_unique(array_column($cards, 'order_id')) as $id) {
            $id=(int)$id;
            if ($id < 1) continue;
            if ($wpdb->update(Database::table('orders'), ['printed_at'=>current_time('mysql')], ['id'=>$id]) === false) {
                wp_die('PDF готов, но не удалось сохранить отметку печати. Попробуйте ещё раз.');
            }
            self::log($id,'pdf_printed','PDF сформирован; отметка «Распечатано» установлена автоматически.');
        }
    }

    public static function period(string $start, int $days): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        if (!$date || $date->format('Y-m-d') !== $start || $days < 1 || $days > 36500) {
            throw new \InvalidArgumentException('Укажите корректную дату и срок от 1 до 36500 дней.');
        }
        return [$start, $date->modify('+'.($days-1).' days')->format('Y-m-d')];
    }

    public static function status(?string $start, ?string $end, string $today): string
    {
        if (!$start || !$end) return 'Не назначено';
        if ($today < $start) return 'Начнётся '.$start;
        if ($today > $end) return 'Завершено';
        $left = (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($end))->days + 1;
        return 'В работе · осталось дней: '.$left;
    }

    /** Called only after Admin has checked capability and nonce. */
    public static function save_period(): void
    {
        global $wpdb;
        $id=absint(Input::post('order_id')??0); $item=absint(Input::post('item_id')??0);
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND order_id=%d', Database::table('order_items'),$item,$id));
        if (!$row) wp_die('Треба не найдена.');
        $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'),$id));
        if (!$order || !empty($order->archived_at)) wp_die('Сначала восстановите записку из архива.');
        if (!empty(Input::post('clear_period'))) {
            $start=$end=null;
        } else {
            try { [$start,$end]=self::period(sanitize_text_field(Input::post('start')??''), (int)(Input::post('days')??0)); }
            catch (\InvalidArgumentException $e) { wp_die(esc_html($e->getMessage())); }
        }
        if ($wpdb->update(Database::table('order_items'),['remembrance_start'=>$start,'remembrance_end'=>$end],['id'=>$item,'order_id'=>$id]) === false) wp_die('Не удалось сохранить срок.');
        self::log($id,'period', $row->service_title.': '.($row->remembrance_start??'—').' — '.($row->remembrance_end??'—').' → '.($start?$start.' — '.$end:'срок снят'));
    }

    public static function change(int $id, string $action): void
    {
        global $wpdb;
        $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'),$id));
        if (!$order) return;
        $archive=in_array($action,['delete_order','archive_order'],true);
        $restore=$action==='restore_order';
        if ($archive || $restore) {
            if ($archive === !empty($order->archived_at)) return;
            $data=['archived_at'=>$archive?current_time('mysql'):null];
            $message=$archive?'Перемещено в архив':'Восстановлено из архива';
        } else {
            $data=['printed_at'=>$action==='mark_printed'?current_time('mysql'):null];
            $message=$action==='mark_printed'?'Сотрудник подтвердил печать':'Отметка печати снята';
        }
        if ($wpdb->update(Database::table('orders'),$data,['id'=>$id]) === false) wp_die('Не удалось сохранить изменение.');
        self::log($id,$action,$message);
    }

    public static function detail(object $order): void
    {
        global $wpdb;
        $id=(int)$order->id;
        echo '<div class="klp-card"><h2>Печать и архив</h2><p>Последнее формирование PDF / печать: '.esc_html($order->printed_at??'—').'. Архив: '.esc_html($order->archived_at??'нет').'.</p><form method="post">';
        wp_nonce_field('klp_admin_action','_klp_nonce');
        echo '<input type="hidden" name="order_ids[]" value="'.(int)$id.'">';
        foreach(['mark_unprinted'=>'Снять отметку',empty($order->archived_at)?'archive_order':'restore_order'=>empty($order->archived_at)?'В архив':'Восстановить'] as $action=>$label) echo '<button class="button" name="klp_action" value="'.esc_attr($action).'">'.esc_html($label).'</button> ';
        echo '</form></div><div class="klp-card"><h2>История записки</h2><p>Для старых записок показаны сохранившиеся даты; подробный журнал ведётся после обновления.</p><table class="widefat striped"><thead><tr><th>Дата</th><th>Событие</th><th>Сотрудник</th></tr></thead><tbody>';
        foreach(['created_at'=>'Записка получена','paid_at'=>'Оплата подтверждена','confirmation_sent_at'=>'Последнее подтверждение отправлено','printed_at'=>'Последняя отметка печати'] as $field=>$label) if(!empty($order->$field)) echo '<tr><td>'.esc_html($order->$field).'</td><td>'.esc_html($label).'</td><td>—</td></tr>';
        $events=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d ORDER BY id DESC', Database::table('order_history'),$id));
        foreach($events as $event) {
            $user=!empty($event->user_id)?get_userdata((int)$event->user_id):false;
            echo '<tr><td>'.esc_html($event->created_at).'</td><td>'.esc_html($event->message??'').'</td><td>'.esc_html($user?$user->display_name:'Система').'</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function calendar(): void
    {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $date=sanitize_text_field(Input::get('date')??wp_date('Y-m-d'));
        try { self::period($date,1); } catch (\InvalidArgumentException $e) { $date=wp_date('Y-m-d'); }
        $mode=in_array(Input::get('period')??'', ['active','ending','future','finished','unset'],true)?Input::get('period'):'active';
        $clauses=['active'=>'i.remembrance_start<=%s AND i.remembrance_end>=%s','ending'=>'i.remembrance_end=%s','future'=>'i.remembrance_start>%s','finished'=>'i.remembrance_end<%s','unset'=>'i.remembrance_start IS NULL'];
        $args=$mode==='active'?[$date,$date]:($mode==='unset'?[]:[$date]);
        $page=max(1,absint(Input::get('paged')??1));
        $sql='SELECT i.*,o.names,o.intention,o.payment_status FROM %i i JOIN %i o ON o.id=i.order_id WHERE o.archived_at IS NULL AND o.payment_status=\'paid\' AND '.$clauses[$mode].' ORDER BY i.remembrance_end,i.id LIMIT 51 OFFSET %d';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Clause selected from fixed whitelist; identifiers, dates and offset use placeholders.
        $rows=$wpdb->get_results($wpdb->prepare($sql,...array_merge([Database::table('order_items'),Database::table('orders')],$args,[($page-1)*50])));
        echo '<div class="wrap klp-admin"><h1>Поминовение</h1><p>Оплаченные записки вне архива. Окончание срока не архивирует записку автоматически.</p><form method="get"><input type="hidden" name="page" value="kloster-prayer-calendar"><input type="date" name="date" value="'.esc_attr($date).'"> <select name="period">';
        foreach(['active'=>'Читать в этот день','ending'=>'Заканчиваются в этот день','future'=>'Предстоящие','finished'=>'Завершённые','unset'=>'Без срока'] as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($mode,$key,false).'>'.esc_html($label).'</option>';
        echo '</select> <button class="button">Показать</button></form><p></p><table class="widefat striped"><thead><tr><th>Записка / треба</th><th>Тип</th><th>Имена</th><th>Начало</th><th>Окончание</th><th>Состояние на выбранную дату</th></tr></thead><tbody>';
        foreach(array_slice($rows,0,50) as $row) echo '<tr><td><a href="'.esc_url(admin_url('admin.php?page=kloster-prayer&view='.(int)$row->order_id)).'">#'.(int)$row->order_id.' · '.esc_html($row->service_title).'</a></td><td>'.($row->intention==='rest'?'Об упокоении':'О здравии').'</td><td>'.nl2br(esc_html($row->names)).'</td><td>'.esc_html($row->remembrance_start??'—').'</td><td>'.esc_html($row->remembrance_end??'—').'</td><td>'.esc_html(self::status($row->remembrance_start??null,$row->remembrance_end??null,$date)).'</td></tr>';
        if(!$rows) echo '<tr><td colspan="6">Записок нет.</td></tr>';
        echo '</tbody></table><p>';
        foreach([$page-1=>'← Назад',$page+1=>'Далее →'] as $target=>$label) if($target>0&&($target<$page||count($rows)>50)) echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'kloster-prayer-calendar','date'=>$date,'period'=>$mode,'paged'=>$target],admin_url('admin.php'))).'">'.esc_html($label).'</a> ';
        echo '</p></div>';
    }
}
